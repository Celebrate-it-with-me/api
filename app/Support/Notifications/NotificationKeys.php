<?php

namespace App\Support\Notifications;

final class NotificationKeys
{
    // Events
    public const EVENT_CREATED = 'event.created';
    public const EVENT_UPDATED = 'event.updated';
    public const EVENT_PUBLISHED = 'event.published';

    // Locations
    public const LOCATION_CREATED = 'location.created';
    public const LOCATION_UPDATED = 'location.updated';
    public const LOCATION_DELETED = 'location.deleted';

    // RSVP
    public const RSVP_CONFIRMED = 'rsvp.confirmed';
    public const RSVP_DECLINED = 'rsvp.declined';

    // Comments
    public const COMMENT_CREATED = 'comment.created';
    public const COMMENT_UPDATED = 'comment.updated';
    public const COMMENT_DELETED = 'comment.deleted';

    // Suggested Music
    public const MUSIC_SUGGESTED = 'music.suggested';
    public const MUSIC_REACTED = 'music.reacted';

    // Budget
    public const BUDGET_ITEM_CREATED = 'budget.item_created';
    public const BUDGET_ITEM_PAID = 'budget.item_paid';

    // Save the date
    public const SAVE_THE_DATE_UPDATED = 'save_the_date.updated';

    public static function all(): array
    {
        return [
            // Events
            self::EVENT_CREATED,
            self::EVENT_UPDATED,
            self::EVENT_PUBLISHED,

            // Locations
            self::LOCATION_CREATED,
            self::LOCATION_UPDATED,
            self::LOCATION_DELETED,

            // RSVP
            self::RSVP_CONFIRMED,
            self::RSVP_DECLINED,

            // Comments
            self::COMMENT_CREATED,
            self::COMMENT_UPDATED,
            self::COMMENT_DELETED,

            // Suggested Music
            self::MUSIC_SUGGESTED,
            self::MUSIC_REACTED,

            // Budget
            self::BUDGET_ITEM_CREATED,
            self::BUDGET_ITEM_PAID,

            // Save the date
            self::SAVE_THE_DATE_UPDATED,
        ];
    }

}
