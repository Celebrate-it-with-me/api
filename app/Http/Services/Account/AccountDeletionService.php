<?php

namespace App\Http\Services\Account;

use App\Http\Services\Admin\AdminAudit;
use App\Http\Services\Admin\Impersonation\ImpersonationService;
use App\Mail\AccountDeletedMail;
use App\Models\EventPurchase;
use App\Models\Events;
use App\Models\EventUserRole;
use App\Models\User;
use App\Support\AdminPermissions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Throwable;

/**
 * Deletes an organizer's account (docs/plans/account-deletion-contract.md): the person and her data go at once,
 * published events are archived for ARCHIVE_DAYS (their links keep opening, read only) and then purged by
 * `account:purge-archived`, unpublished ones go at once. Purchases and invoices stay.
 */
class AccountDeletionService
{
    public const ARCHIVE_DAYS = 30;

    /** A checkout younger than this is "in progress" (Stripe's own sessions expire after 24 hours). */
    public const PENDING_PAYMENT_HOURS = 24;

    public function __construct(private readonly EventPurger $purger) {}

    /** @return array{published: int, unpublished: int, collaborating: int} */
    public function eventCounts(User $user): array
    {
        $owned = $this->ownedEvents($user);

        return [
            'published' => $owned->where('status', 'published')->count(),
            'unpublished' => $owned->where('status', '!=', 'published')->count(),
            'collaborating' => EventUserRole::query()->where('user_id', $user->id)
                ->whereNotIn('event_id', $owned->pluck('id'))->distinct('event_id')->count('event_id'),
        ];
    }

    public function pendingPurchases(User $user): int
    {
        return EventPurchase::query()->where('user_id', $user->id)->where('status', 'pending')
            ->where('created_at', '>=', now()->subHours(self::PENDING_PAYMENT_HOURS))->count();
    }

    public function isStaff(User $user): bool
    {
        return $user->hasAnyRole(AdminPermissions::STAFF_ROLES);
    }

    /**
     * Archives or deletes her events, strips the account of everything personal and ends every session.
     *
     * @return array{deletedAt: Carbon, archivedEvents: int, deletedEvents: int}
     */
    public function delete(User $user): array
    {
        $originalEmail = $user->email;
        $counts = $this->eventCounts($user);
        $owned = $this->ownedEvents($user);

        // The goodbye goes to the address she knew, before it is overwritten. A mail failure never blocks the deletion.
        try {
            Mail::to($originalEmail)->send(new AccountDeletedMail($user, $counts['published'], self::ARCHIVE_DAYS));
        } catch (Throwable $e) {
            Log::warning('account deletion: goodbye mail not sent', ['user_id' => $user->id, 'error' => get_class($e)]);
        }

        $now = now();
        $avatar = $user->avatar;

        DB::transaction(function () use ($user, $originalEmail, $owned, $now, $counts) {
            $user->tokens()->delete();
            app(ImpersonationService::class)->finishForUser($user);

            foreach ($owned as $event) {
                if ($event->status === 'published') {
                    $event->forceFill(['status' => 'archived', 'archived_at' => $now, 'purge_after' => $now->copy()->addDays(self::ARCHIVE_DAYS)])->save();
                } else {
                    $this->purger->purge($event);
                }
            }

            // Her place in other people's events, and the invitations addressed to her.
            EventUserRole::query()->where('user_id', $user->id)->delete();
            DB::table('event_collaboration_invites')->whereRaw('LOWER(email) = ?', [Str::lower($originalEmail)])->delete();

            $this->wipePersonalData($user, $originalEmail);

            $user->forceFill([
                'name' => 'Cuenta eliminada',
                'email' => "deleted-{$user->id}@invalid.quinvite",
                'password' => Str::random(64),
                'has_password' => false,
                'remember_token' => null,
                'email_verified_at' => null,
                'phone' => null,
                'avatar' => null,
                'last_active_event_id' => null,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'two_factor_last_used_step' => null,
                'anonymized_at' => $now,
                'account_deleted_at' => $now,
            ])->save();

            // Ids and counts only: no email, no name.
            app(AdminAudit::class)->record('account.delete', $user, ['actor' => $user, 'after' => [
                'archived_events' => $counts['published'],
                'deleted_events' => $counts['unpublished'],
                'collaborations_left' => $counts['collaborating'],
            ]]);
        });

        if ($avatar && ! str_starts_with($avatar, 'http')) {
            try {
                Storage::disk('public')->delete($avatar);
            } catch (Throwable) {
                // Nothing personal is left in the database; a stray file is not worth failing the request.
            }
        }

        return [
            'deletedAt' => $now,
            'archivedEvents' => $counts['published'],
            'deletedEvents' => $counts['unpublished'],
        ];
    }

    /** Rows of the account that hold contact data or personal content. Purchases, invoices and audit rows stay. */
    private function wipePersonalData(User $user, string $originalEmail): void
    {
        DB::table('sessions')->where('user_id', $user->id)->delete();
        DB::table('user_login_sessions')->where('user_id', $user->id)->delete();
        DB::table('notifications')->where('notifiable_id', $user->id)->whereIn('notifiable_type', ['user', User::class])->delete();
        DB::table('password_reset_tokens')->whereRaw('LOWER(email) = ?', [Str::lower($originalEmail)])->delete();
        DB::table('social_accounts')->where('user_id', $user->id)->delete();
        DB::table('user_preferences')->where('user_id', $user->id)->delete();
        DB::table('budget_item_reminders')->where('user_id', $user->id)->delete();
        DB::table('announcement_dismissals')->where('user_id', $user->id)->delete();
        DB::table('billing_profiles')->where('user_id', $user->id)->delete();
        // Her help requests carry free text and contact context.
        DB::table('support_requests')->where('user_id', $user->id)->delete();
    }

    /** Events where she is the only owner: the ones that go away with her. */
    private function ownedEvents(User $user): Collection
    {
        $ownerRoleId = Role::query()->where('name', 'owner')->value('id');
        $ids = EventUserRole::query()->where('user_id', $user->id)->where('role_id', $ownerRoleId)->pluck('event_id');

        $sole = $ids->filter(fn ($eventId) => ! EventUserRole::query()
            ->where('event_id', $eventId)->where('role_id', $ownerRoleId)->where('user_id', '!=', $user->id)->exists());

        return Events::query()->whereIn('id', $sole)->get();
    }
}
