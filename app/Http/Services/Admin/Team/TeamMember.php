<?php

namespace App\Http\Services\Admin\Team;

use App\Models\AdminInvitation;
use App\Models\User;
use App\Support\AdminPermissions;

/** The `Member` of docs/plans/admin-team-contract.md: a person on the team, or an invitation that is still pending. */
final class TeamMember
{
    public static function role(User $user): ?string
    {
        return collect(AdminPermissions::STAFF_ROLES)->first(fn ($r) => $user->hasRole($r));
    }

    public static function fromUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => self::role($user),
            'status' => $user->admin_suspended_at ? 'suspended' : 'active',
            'lastLoginAt' => $user->admin_last_login_at?->toIso8601String(),
            'twoFactorEnabled' => $user->hasAdminTwoFactor(),
            'invitation' => null,
        ];
    }

    public static function fromInvitation(AdminInvitation $invitation): array
    {
        return [
            'id' => $invitation->memberId(),
            'name' => $invitation->name ?: $invitation->email,
            'email' => $invitation->email,
            'role' => $invitation->role,
            'status' => 'invited',
            'lastLoginAt' => null,
            'twoFactorEnabled' => false,
            'invitation' => [
                'sentAt' => $invitation->sent_at->toIso8601String(),
                'expiresAt' => $invitation->expires_at->toIso8601String(),
                'resendCount' => $invitation->resend_count,
                'expired' => $invitation->isExpired(),
            ],
        ];
    }
}
