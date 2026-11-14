<?php

/*
 * App API: delete my account (docs/plans/account-deletion-contract.md). Included with one line from routes/api/app.php;
 * it carries its own auth middleware, the same as the protected group there. Both routes are blocked during a support
 * session (ImpersonationRules): support never deletes an account on behalf of its owner.
 */

use App\Http\Controllers\AppControllers\Account\AccountDeletionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'not.suspended', 'impersonation.guard', 'refresh.token'])->prefix('account')->name('account.')->group(function () {
    Route::get('deletion-preview', [AccountDeletionController::class, 'preview'])->name('deletion.preview');
    // Attempts are limited per account inside the controller (5 per hour, wrong password and email included).
    Route::delete('', [AccountDeletionController::class, 'destroy'])->name('deletion.destroy');
});
