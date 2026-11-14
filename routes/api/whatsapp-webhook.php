<?php

/* Public WhatsApp webhook (no session): Meta's verification and its events. Included from routes/api.php (prefix api/v1). */

use App\Http\Controllers\WhatsApp\WebhookController;
use Illuminate\Support\Facades\Route;

Route::get('webhooks/whatsapp', [WebhookController::class, 'verify'])->middleware('throttle:60,1');
Route::post('webhooks/whatsapp', [WebhookController::class, 'receive'])->middleware('throttle:1200,1');
