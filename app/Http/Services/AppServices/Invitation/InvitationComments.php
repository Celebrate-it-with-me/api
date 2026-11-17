<?php

namespace App\Http\Services\AppServices\Invitation;

use App\Http\Services\EventComment\EventCommentService;
use App\Models\EventComment;
use App\Models\EventConfigComment;
use App\Models\Events;
use App\Models\Guest;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Comments of the public invitation (docs/plans/invitation-premium-modules-contract.md, section 4). The section only
 * carries configuration; the list is read lazily by the page and written by the guest of the link. The comments
 * themselves (visibility, pinning, staff moderation) are the ones the organizer already manages.
 */
class InvitationComments
{
    public const MAX_LENGTH = 500;

    public const PER_PAGE = 10;

    public function __construct(private readonly EventCommentService $comments) {}

    /**
     * Configuration of the `comments` section: texts the organizer set in her comments configuration (null = the page
     * uses the section title and its own strings).
     *
     * @return array<string, mixed>
     */
    public function sectionData(Events $event): array
    {
        $config = EventConfigComment::query()->where('event_id', $event->id)->first();
        $text = fn (?string $value) => is_string($value) && trim($value) !== '' ? trim($value) : null;

        return [
            'heading' => $text($config?->title),
            'subHeading' => $text($config?->sub_title),
            'buttonText' => $text($config?->button_text),
            'maxLength' => self::MAX_LENGTH,
            'canWrite' => $this->canWrite($event),
        ];
    }

    /** An invitation kept after its owner deleted her account, and the Save the date, are read only. */
    public function canWrite(Events $event): bool
    {
        return ! $event->isAccountArchived() && $event->invitation_stage !== Events::STAGE_SAVE_THE_DATE;
    }

    public function page(Events $event): LengthAwarePaginator
    {
        return $this->comments->listPublicForEvent($event, self::PER_PAGE);
    }

    public function create(Events $event, Guest $guest, string $text): EventComment
    {
        $comment = $this->comments->createAsGuest($event, $guest, trim($text), false);

        return $comment->load('authorable');
    }

    /**
     * Only what the page draws: never the status, the ids of the author, nor any contact data.
     *
     * @return array{id: int, author: string, comment: string, isPinned: bool, createdAt: string}
     */
    public static function present(EventComment $comment): array
    {
        return [
            'id' => $comment->id,
            'author' => (string) ($comment->authorable?->name ?? ''),
            'comment' => $comment->comment,
            'isPinned' => (bool) $comment->is_pinned,
            'createdAt' => $comment->created_at->toIso8601String(),
        ];
    }
}
