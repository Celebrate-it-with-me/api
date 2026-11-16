<?php

namespace App\Http\Services\Payments;

use RuntimeException;

/** Bad or missing signature, or a body that is not an event: nothing is processed. */
class InvalidWebhookException extends RuntimeException {}
