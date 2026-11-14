<?php

/*
 * App API: WhatsApp sending for the organizer (docs/plans/communications-contract.md). Included with one line from
 * routes/api/app.php; it carries its own auth middleware, the same as the protected group there.
 */

use App\Http\Controllers\AppControllers\WhatsApp\WhatsAppController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'not.suspended', 'impersonation.guard', 'refresh.token', 'event.access', 'module.access:whatsapp'])
    ->prefix('event/{event}/whatsapp')->name('event.whatsapp.')->group(function () {
        Route::post('send', [WhatsAppController::class, 'send'])->middleware('throttle:20,1')->name('send');
        Route::get('messages', [WhatsAppController::class, 'messages'])->name('messages');
        Route::get('summary', [WhatsAppController::class, 'summary'])->name('summary');
        Route::post('messages/{message}/retry', [WhatsAppController::class, 'retry'])->whereNumber('message')->middleware('throttle:30,1')->name('retry');
    });
