<?php

/* Public WhatsApp webhook (no session): Meta's verification and its events. Included from routes/api.php (prefix api/v1). */

use App\Http\Controllers\WhatsApp\WebhookController;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;

Route::get('webhooks/whatsapp', [WebhookController::class, 'verify'])->middleware('throttle:whatsapp-webhook-verify');
Route::post('webhooks/whatsapp', [WebhookController::class, 'receive'])->middleware('throttle.unsigned:whatsapp-webhook-unsigned,whatsapp-webhook-receive,1024')
    // The group's per-IP `api` limiter (120/min) runs before the signature is checked: Meta's bursts behind one IP would be refused.
    ->withoutMiddleware(ThrottleRequests::class . ':api');
