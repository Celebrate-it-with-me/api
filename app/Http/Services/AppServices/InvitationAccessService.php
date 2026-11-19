<?php

namespace App\Http\Services\AppServices;

use App\Models\Events;
use App\Models\Guest;
use Illuminate\Support\Str;

/**
 * Lookups behind the invitation code gate. The guest code comes from InvitationService::findGuest, but the event must be strictly published: the account-archived
 * events that findEvent still serves read-only are treated here as non-existent (owner decision, 2026-11-19).
 */
class InvitationAccessService
{
    public function __construct(private readonly InvitationService $invitations) {}

    /**
     * First name of the event's owner, or null when the event does not exist, is not live or has no owner with a name.
     * The user only has a single `name` column, so the first name is its first word.
     */
    public function organizerFirstName(string $slug): ?string
    {
        $owner = $this->findPublishedEvent($slug)?->owner_user;
        $first = $owner ? Str::before(trim((string) preg_replace('/\s+/u', ' ', (string) $owner->name)), ' ') : '';

        return $first === '' ? null : $first;
    }

    /**
     * Guest codes are generated as 2 uppercase letters + 4 digits (CalculateAccessCodeService, e.g. VAL001 in the demo
     * is an older seeded value), stored uppercase. A person types them with spaces or in lowercase: remove every
     * whitespace/dash and uppercase.
     */
    public function normalizeCode(string $raw): string
    {
        return Str::upper((string) preg_replace('/[\s\-]+/u', '', $raw));
    }

    /** The main guest holding this code in this live event, or null (same null for every reason). */
    public function verify(string $slug, string $code): ?Guest
    {
        $event = $this->findPublishedEvent($slug);

        return ($event && $code !== '') ? $this->invitations->findGuest($event, $code) : null;
    }

    private function findPublishedEvent(string $slug): ?Events
    {
        $event = $this->invitations->findEvent($slug);

        return $event?->status === 'published' ? $event : null;
    }
}
