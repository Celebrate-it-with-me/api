<?php

/*
 * Admin API: Event actions (Acciones tab). Included from routes/api/admin/events.php (inside the staff group, prefix api/v1/admin).
 * Paths start with `events/{event}/...`. Each route declares its permission and every write is audited.
 */

use App\Http\Controllers\Admin\EventActions\EventActionController;
use Illuminate\Support\Facades\Route;

Route::prefix('events/{event}')->whereNumber('event')->middleware('admin.can:admin.events.manage')->group(function () {
    Route::post('{action}', [EventActionController::class, 'lifecycle'])->whereIn('action', ['publish', 'unpublish', 'archive', 'restore']);
    Route::get('collaborator-invites', [EventActionController::class, 'invites']);
    Route::post('resend-owner-email', [EventActionController::class, 'resendOwnerEmail']);
    Route::post('resend-collaborator-invite/{invite}', [EventActionController::class, 'resendCollaboratorInvite'])->whereNumber('invite');
    Route::put('slug', [EventActionController::class, 'slug']);
    Route::post('transfer', [EventActionController::class, 'transfer']);
});
