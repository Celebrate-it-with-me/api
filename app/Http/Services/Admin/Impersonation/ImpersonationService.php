<?php

namespace App\Http\Services\Admin\Impersonation;

use App\Http\Services\Admin\AdminAudit;
use App\Mail\ImpersonationEndedMail;
use App\Models\ImpersonationSession;
use App\Models\User;
use App\Support\AdminPermissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

/** Starts and ends impersonation sessions. The only place that issues or revokes an impersonation token. */
class ImpersonationService
{
    public function __construct(private readonly AdminAudit $audit) {}

    /** Why this account cannot be impersonated, as [code, message], or null when it can. */
    public function refusal(User $staff, User $target, ?PersonalAccessToken $currentToken): ?array
    {
        if (self::isImpersonationToken($currentToken)) {
            return ['impersonation_chained', 'No se puede impersonar desde una sesion de impersonacion.'];
        }
        if ($staff->is($target)) {
            return ['impersonation_self', 'No puedes impersonarte a ti mismo.'];
        }
        if ($target->hasAnyRole(AdminPermissions::STAFF_ROLES)) {
            return ['impersonation_staff', 'No se puede impersonar a personal del equipo.'];
        }
        if ($target->anonymized_at) {
            return ['impersonation_anonymized', 'La cuenta fue anonimizada.'];
        }
        if ($target->suspended_at) {
            return ['impersonation_suspended', 'La cuenta esta suspendida.'];
        }

        return null;
    }

    /** @return array{0: ImpersonationSession, 1: string} the session and the plain-text token */
    public function start(User $staff, User $target, string $reason, ?int $supportRequestId, bool $notify): array
    {
        // One live session per staff member and account: asking again replaces the old one (no mail for it).
        ImpersonationSession::query()->where('staff_id', $staff->id)->where('user_id', $target->id)
            ->whereNull('ended_at')->get()
            ->each(fn (ImpersonationSession $old) => $this->finish($old, 'staff', mail: false, replaced: true));

        $expiresAt = now()->addMinutes(max(1, (int) config('impersonation.ttl_minutes', 45)));
        $token = $target->createToken('impersonation', [ImpersonationSession::ABILITY], $expiresAt);

        $session = ImpersonationSession::create([
            'staff_id' => $staff->id,
            'user_id' => $target->id,
            'reason' => $reason,
            'support_request_id' => $supportRequestId,
            'notify' => $notify,
            'token_id' => $token->accessToken->id,
            'started_at' => now(),
            'expires_at' => $expiresAt,
        ]);

        $this->record('impersonation.start', $session, [
            'reason' => $reason,
            'after' => ['sessionId' => $session->id, 'supportRequestId' => $supportRequestId, 'notify' => $notify, 'expiresAt' => $expiresAt->toIso8601String()],
        ]);

        return [$session, $token->plainTextToken];
    }

    /** Ends a session: revokes the token, marks it, audits it and (unless asked not to) tells the user. */
    public function finish(ImpersonationSession $session, string $endedBy, bool $mail = true, bool $replaced = false): void
    {
        if ($session->ended_at) {
            return;
        }

        PersonalAccessToken::query()->whereKey($session->token_id)->delete();

        $endedAt = $endedBy === 'expired' ? $session->expires_at : now();
        $session->forceFill(['ended_at' => $endedAt, 'ended_by' => $endedBy])->save();

        $this->record('impersonation.end', $session, [
            'reason' => $session->reason,
            'after' => ['sessionId' => $session->id, 'endedBy' => $endedBy, 'notify' => $session->notify, 'replaced' => $replaced,
                'minutes' => $this->minutes($session)],
        ]);

        if ($mail && $session->notify) {
            $this->notifyUser($session);
        }
    }

    public function minutes(ImpersonationSession $session): int
    {
        return max(1, (int) round($session->started_at->diffInSeconds($session->ended_at ?? now()) / 60));
    }

    public static function isImpersonationToken(mixed $token): bool
    {
        return $token instanceof PersonalAccessToken && in_array(ImpersonationSession::ABILITY, $token->abilities ?? [], true);
    }

    private function notifyUser(ImpersonationSession $session): void
    {
        try {
            $user = $session->user;
            if ($user && ! $user->anonymized_at && $user->email) {
                Mail::to($user->email)->send(new ImpersonationEndedMail($session, $user));
            }
        } catch (Throwable $e) {
            report($e); // a mail problem must not leave the session open
        }
    }

    /** Audit rows carry the staff member both as actor and as `impersonated_by`, whatever request they come from. */
    private function record(string $action, ImpersonationSession $session, array $data): void
    {
        $request = app(Request::class);
        $previous = $request->attributes->get('impersonated_by');
        $request->attributes->set('impersonated_by', $session->staff_id);

        try {
            $this->audit->record($action, $session->user, $data + ['actor' => $session->staff]);
        } finally {
            $request->attributes->set('impersonated_by', $previous);
        }
    }
}
