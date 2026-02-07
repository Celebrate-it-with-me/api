<?php

namespace App\Enums;

enum EventCommentStatus: string
{
    case VISIBLE = 'visible';
    case HIDDEN = 'hidden';
    case PENDING_REVIEW = 'pending_review';

    public function label(): string
    {
        return match ($this) {
            self::VISIBLE => 'Visible',
            self::HIDDEN => 'Hidden',
            self::PENDING_REVIEW => 'Pending Review',
        };
    }
}
