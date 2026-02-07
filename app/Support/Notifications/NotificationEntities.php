<?php

namespace App\Support\Notifications;

final class NotificationEntities
{
    public const EVENT = 'event';
    public const LOCATION = 'location';
    public const RSVP = 'rsvp';
    public const COMMENT = 'comment';
    public const MUSIC_SUGGESTION = 'music_suggestion';
    public const BUDGET = 'budget';
    public const BUDGET_ITEM = 'budget_item';
    public const SAVE_THE_DATE = 'save_the_date';

    /**
     * Return a list of all supported entity types.
     * Useful for validation and testing.
     */
    public static function all(): array
    {
        return [
            self::EVENT,
            self::LOCATION,
            self::RSVP,
            self::COMMENT,
            self::MUSIC_SUGGESTION,
            self::BUDGET,
            self::BUDGET_ITEM,
            self::SAVE_THE_DATE,
        ];
    }
}
