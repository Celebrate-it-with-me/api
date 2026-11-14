<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A pending invitation to the admin team. The token is random and never stored: only its SHA-256 hash is, and it is
 * compared with hash_equals. Accepting deletes the row, so a link works once.
 */
class AdminInvitation extends Model
{
    /** Invited people have no user id yet: in the team list their id is this offset plus the invitation id (never meets a user id). */
    public const MEMBER_ID_OFFSET = 1_000_000_000;

    public const MAX_RESENDS = 5;

    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected $casts = ['expires_at' => 'datetime', 'sent_at' => 'datetime', 'resend_count' => 'integer'];

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** A new random token (48 characters). Returns the plain token; the caller stores only its hash. */
    public static function newToken(): string
    {
        return Str::random(48);
    }

    public static function findByToken(string $token): ?self
    {
        $invitation = self::query()->where('token_hash', self::hashToken($token))->first();

        return $invitation && hash_equals($invitation->token_hash, self::hashToken($token)) ? $invitation : null;
    }

    /** The invitation behind an id of the team list (see MEMBER_ID_OFFSET), or null when that id is not an invitation. */
    public static function findByMemberId(int $memberId): ?self
    {
        return $memberId > self::MEMBER_ID_OFFSET ? self::query()->find($memberId - self::MEMBER_ID_OFFSET) : null;
    }

    public function memberId(): int
    {
        return self::MEMBER_ID_OFFSET + $this->id;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
