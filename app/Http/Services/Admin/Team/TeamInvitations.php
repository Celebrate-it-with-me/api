<?php

namespace App\Http\Services\Admin\Team;

use App\Http\Services\Admin\AdminAudit;
use App\Mail\AdminInvitationMail;
use App\Models\AdminInvitation;
use App\Models\User;
use App\Support\AdminPermissions;
use App\Support\Mail\MailLinks;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Invitations to the admin team: invite, resend, revoke. The staff role is given only when the person accepts
 * (see InvitationAcceptance). The token is random, lives only in the mail, and only its hash is stored.
 */
class TeamInvitations
{
    public function __construct(private readonly AdminAudit $audit) {}

    public static function hours(): int
    {
        return (int) config('admin.invitation_hours', 72);
    }

    /** The Quinvite account of that address, if any (compared in lower case). */
    public static function accountFor(string $email): ?User
    {
        return User::query()->whereRaw('lower(email) = ?', [Str::lower($email)])->first();
    }

    /** @return array{0: AdminInvitation, 1: bool} the invitation and whether the mail went out */
    public function invite(User $actor, string $email, ?string $name, string $role, string $reason): array
    {
        $email = Str::lower(trim($email));
        $account = self::accountFor($email);

        if ($account?->hasAnyRole(AdminPermissions::STAFF_ROLES)) {
            throw ValidationException::withMessages(['email' => ['already_staff']]);
        }

        $existing = AdminInvitation::query()->where('email', $email)->first();
        if ($existing && ! $existing->isExpired()) {
            throw ValidationException::withMessages(['email' => ['invitation_pending']]);
        }

        $name = trim((string) $name) ?: $account?->name;
        if (! $name) {
            throw ValidationException::withMessages(['name' => ['name_required']]);
        }

        $token = AdminInvitation::newToken();
        $invitation = DB::transaction(function () use ($existing, $email, $name, $role, $actor, $token) {
            $attributes = [
                'name' => $name,
                'role' => $role,
                'token_hash' => AdminInvitation::hashToken($token),
                'expires_at' => now()->addHours(self::hours()),
                'sent_at' => now(),
                'resend_count' => 0,
                'invited_by' => $actor->id,
            ];

            // An expired invitation of the same address is replaced, so its old link is dead for good.
            return $existing ? tap($existing)->update($attributes) : AdminInvitation::query()->create(['email' => $email] + $attributes);
        });

        $this->audit->record('team.invite', ['invitation', $invitation->id], [
            'reason' => $reason,
            'after' => ['email' => $email, 'role' => $role, 'accountExists' => (bool) $account],
        ]);

        return [$invitation, $this->send($invitation, $token, $actor)];
    }

    /** New token and new expiry; the previous link stops working. @return array{0: AdminInvitation, 1: bool} */
    public function resend(User $actor, AdminInvitation $invitation, ?string $reason): array
    {
        if ($invitation->resend_count >= AdminInvitation::MAX_RESENDS) {
            throw ValidationException::withMessages(['user' => ['resend_limit']]);
        }

        $token = AdminInvitation::newToken();
        $invitation->forceFill([
            'token_hash' => AdminInvitation::hashToken($token),
            'expires_at' => now()->addHours(self::hours()),
            'sent_at' => now(),
            'resend_count' => $invitation->resend_count + 1,
        ])->save();

        $this->audit->record('team.invite_resend', ['invitation', $invitation->id], [
            'reason' => $reason,
            'after' => ['email' => $invitation->email, 'role' => $invitation->role, 'resendCount' => $invitation->resend_count],
        ]);

        return [$invitation, $this->send($invitation, $token, $actor)];
    }

    public function revoke(AdminInvitation $invitation, string $reason): void
    {
        $invitation->delete();

        $this->audit->record('team.invite_revoke', ['invitation', $invitation->id], [
            'reason' => $reason,
            'before' => ['email' => $invitation->email, 'role' => $invitation->role],
        ]);
    }

    /** 404 `invitation_invalid` unless the id of the team list belongs to an invitation. */
    public static function findOrFail(int|string $memberId): AdminInvitation
    {
        return AdminInvitation::findByMemberId((int) $memberId) ?? throw self::invalid();
    }

    public static function invalid(): HttpResponseException
    {
        return new HttpResponseException(response()->json(['message' => 'This invitation is not valid.', 'code' => 'invitation_invalid'], 404));
    }

    public static function expired(): HttpResponseException
    {
        return new HttpResponseException(response()->json(['message' => 'This invitation has expired.', 'code' => 'invitation_expired'], 410));
    }

    /** A mail server that is down never undoes the invitation: she can be re-sent from the list. */
    private function send(AdminInvitation $invitation, string $token, User $actor): bool
    {
        try {
            $link = MailLinks::admin('accept-invite?token='.$token);
            Mail::to($invitation->email)->send(new AdminInvitationMail($invitation, $link, $actor->name, self::hours(), self::accountFor($invitation->email)));

            return true;
        } catch (\Throwable $e) {
            Log::warning('Team invitation mail could not be sent', ['invitation' => $invitation->id, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
