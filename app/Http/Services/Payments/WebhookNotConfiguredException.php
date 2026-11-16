<?php

namespace App\Http\Services\Payments;

use RuntimeException;

/** There is no secret to verify with, so every webhook is refused. */
class WebhookNotConfiguredException extends RuntimeException {}
