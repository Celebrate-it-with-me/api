<?php

namespace App\Enums;

/**
 * Life cycle of an event.
 *
 *   draft ──publish──▶ published ──unpublish──▶ draft
 *     │                    │
 *     └──────archive───────┴──▶ archived ──restore──▶ draft
 *
 * "Completed" is not a state: it is derived from the event date.
 */
enum EventStatus: string
{
    case DRAFT = 'draft';
    case PUBLISHED = 'published';
    case ARCHIVED = 'archived';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }
}
