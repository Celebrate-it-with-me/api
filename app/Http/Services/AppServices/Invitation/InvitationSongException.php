<?php

namespace App\Http\Services\AppServices\Invitation;

use RuntimeException;

/** A rule of the public songs endpoints: the controller answers it with `status`, `code` and `errors.<field>.0 = code`. */
class InvitationSongException extends RuntimeException
{
    public function __construct(public readonly string $codeName, public readonly string $field, public readonly int $status = 422)
    {
        parent::__construct($codeName);
    }
}
