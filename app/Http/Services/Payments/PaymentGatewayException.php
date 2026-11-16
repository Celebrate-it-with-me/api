<?php

namespace App\Http\Services\Payments;

use RuntimeException;

/** The provider failed or rejected a call that is not the buyer's fault (answered with 502 gateway_error). */
class PaymentGatewayException extends RuntimeException {}
