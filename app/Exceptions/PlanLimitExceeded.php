<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * The event's plan does not allow what was asked. Answers 402 with the numbers the screen needs to say it
 * clearly ("your list has 47 guests and the free plan allows 30: unlock, or remove 17").
 * The interface translates by `code`; `message` is for logs and API clients.
 */
class PlanLimitExceeded extends RuntimeException
{
    public const GUEST_LIMIT = 'guest_limit_exceeded';

    public function __construct(
        public readonly string $reason,
        public readonly int $limit,
        public readonly int $total,
    ) {
        parent::__construct(sprintf(
            'The plan allows %d guests and this event would have %d. Unlock the full event or remove %d guests.',
            $limit,
            $total,
            $total - $limit,
        ));
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->reason,
            'limit' => $this->limit,
            'total' => $this->total,
            'excess' => $this->total - $this->limit,
            'upgrade' => true,
        ], 402);
    }
}
