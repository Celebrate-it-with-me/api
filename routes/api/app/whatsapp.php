<?php

/*
 * App API: WhatsApp sending for the organizer (docs/plans/communications-contract.md). Included with one line from
 * routes/api/app.php; it carries its own auth middleware, the same as the protected group there.
 */

use App\Http\Controllers\AppControllers\WhatsApp\WhatsAppAudienceController;
use App\Http\Controllers\AppControllers\WhatsApp\WhatsAppController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'not.suspended', 'impersonation.guard', 'refresh.token', 'event.access', 'module.access:whatsapp'])
    ->prefix('event/{event}/whatsapp')->whereNumber('event')->name('event.whatsapp.')->group(function () {
        Route::post('send', [WhatsAppController::class, 'send'])->middleware('throttle:whatsapp-send')->name('send');
        Route::get('messages', [WhatsAppController::class, 'messages'])->name('messages');
        Route::get('summary', [WhatsAppController::class, 'summary'])->name('summary');
        Route::post('messages/{message}/retry', [WhatsAppController::class, 'retry'])->whereNumber('message')->middleware('throttle:whatsapp-retry')->name('retry');
    });

// Read-only data of the send wizard: under the `guests` module, because the manual send (free plan) needs it too.
Route::middleware(['auth:sanctum', 'not.suspended', 'impersonation.guard', 'refresh.token', 'event.access', 'module.access:guests'])
    ->prefix('event/{event}/whatsapp')->whereNumber('event')->name('event.whatsapp.')->group(function () {
        Route::get('audience', [WhatsAppAudienceController::class, 'audience'])->name('audience');
        Route::get('recipients', [WhatsAppAudienceController::class, 'recipients'])->name('recipients');
        Route::post('preview', [WhatsAppAudienceController::class, 'preview'])->middleware('throttle:whatsapp-preview')->name('preview');
        Route::get('batches/active', [WhatsAppAudienceController::class, 'activeBatch'])->name('batches.active');
        Route::get('batches/{batch}', [WhatsAppAudienceController::class, 'batch'])->name('batches.show');
    });
