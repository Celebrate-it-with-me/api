<?php

namespace App\Support\Notifications;

use InvalidArgumentException;

final class NotificationMetaMap
{
    /**
     * Meta-contract:
     * - priority: low|normal|high
     * - icon: Lucide icon key used by frontend
     * - color: Tailwind-ish token used by frontend (not an actual hex)
     */
    private const MAP = [
        // Events
        NotificationKeys::EVENT_CREATED => [
            'priority' => 'normal',
            'icon' => 'party-popper',
            'color' => 'indigo',
        ],
        NotificationKeys::EVENT_UPDATED => [
            'priority' => 'low',
            'icon' => 'edit',
            'color' => 'blue',
        ],
        NotificationKeys::EVENT_PUBLISHED => [
            'priority' => 'high',
            'icon' => 'sparkles',
            'color' => 'indigo',
        ],

        // Locations
        NotificationKeys::LOCATION_CREATED => [
            'priority' => 'normal',
            'icon' => 'map-pin',
            'color' => 'cyan',
        ],
        NotificationKeys::LOCATION_UPDATED => [
            'priority' => 'low',
            'icon' => 'map',
            'color' => 'blue',
        ],
        NotificationKeys::LOCATION_DELETED => [
            'priority' => 'normal',
            'icon' => 'map-pin-off',
            'color' => 'red',
        ],

        // RSVP
        NotificationKeys::RSVP_CONFIRMED => [
            'priority' => 'high',
            'icon' => 'user-check',
            'color' => 'green',
        ],
        NotificationKeys::RSVP_DECLINED => [
            'priority' => 'normal',
            'icon' => 'user-x',
            'color' => 'red',
        ],

        // Comments
        NotificationKeys::COMMENT_CREATED => [
            'priority' => 'normal',
            'icon' => 'message-square',
            'color' => 'blue',
        ],
        NotificationKeys::COMMENT_UPDATED => [
            'priority' => 'normal',
            'icon' => 'message-square',
            'color' => 'blue',
        ],
        NotificationKeys::COMMENT_DELETED => [
            'priority' => 'normal',
            'icon' => 'message-square',
            'color' => 'blue',
        ],

        // Suggested Music
        NotificationKeys::MUSIC_SUGGESTED => [
            'priority' => 'normal',
            'icon' => 'music',
            'color' => 'yellow',
        ],
        NotificationKeys::MUSIC_REACTED => [
            'priority' => 'low',
            'icon' => 'thumbs-up',
            'color' => 'green',
        ],

        // Budget
        NotificationKeys::BUDGET_ITEM_CREATED => [
            'priority' => 'normal',
            'icon' => 'receipt',
            'color' => 'orange',
        ],
        NotificationKeys::BUDGET_ITEM_PAID => [
            'priority' => 'normal',
            'icon' => 'badge-check',
            'color' => 'green',
        ],
        NotificationKeys::BUDGET_ITEM_REMINDER => [
            'priority' => 'high',
            'icon' => 'calendar-clock',
            'color' => 'red',
        ],

        // Save the Date
        NotificationKeys::SAVE_THE_DATE_UPDATED => [
            'priority' => 'low',
            'icon' => 'calendar',
            'color' => 'pink',
        ],
    ];

    /**
     * Get meta for a notification key.
     */
    public static function get(string $key): array
    {
        if (!isset(self::MAP[$key])) {
            throw new InvalidArgumentException("Notification meta not defined for key: {$key}");
        }

        return self::MAP[$key];
    }

    /**
     * Check if a key exists in the meta map.
     */
    public static function has(string $key): bool
    {
        return isset(self::MAP[$key]);
    }

    /**
     * Return the full meta map (useful for debugging).
     */
    public static function all(): array
    {
        return self::MAP;
    }
}
