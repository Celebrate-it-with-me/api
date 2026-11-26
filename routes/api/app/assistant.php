<?php

/*
 * App API: asistente inteligente fase 1 (docs/plans/assistant-contract.md). Included with one line at the end of
 * routes/api/app.php; it carries its own auth middleware, the same as the protected group there, and `event.access`
 * (reads need view_event, writes edit_event).
 */

use App\Http\Controllers\AppControllers\Assistant\AssistantController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'not.suspended', 'impersonation.guard', 'refresh.token', 'event.access'])
    ->prefix('event/{event}/assistant')->whereNumber('event')->name('assistant.')->group(function () {
        Route::middleware('throttle:assistant-read')->group(function () {
            Route::get('next', [AssistantController::class, 'next'])->name('next');
            Route::get('plan', [AssistantController::class, 'plan'])->name('plan');
        });

        Route::middleware('throttle:assistant-write')->group(function () {
            foreach (['done', 'snooze', 'hide', 'not-applicable', 'reset'] as $action) {
                Route::post("tasks/{key}/{$action}", [AssistantController::class, 'act'])
                    ->defaults('action', $action)->where('key', '[a-z0-9_]+')->name('tasks.' . $action);
            }

            Route::post('custom-tasks', [AssistantController::class, 'storeCustom'])->name('custom.store');
            Route::patch('custom-tasks/{id}', [AssistantController::class, 'updateCustom'])->whereNumber('id')->name('custom.update');
            Route::delete('custom-tasks/{id}', [AssistantController::class, 'destroyCustom'])->whereNumber('id')->name('custom.destroy');
        });
    });
