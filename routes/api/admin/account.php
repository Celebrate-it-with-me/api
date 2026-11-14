<?php

/*
 * Admin API: Mi cuenta (staff account and security). Included from routes/api/admin.php inside the staff group
 * (auth:sanctum + admin, which already refuses impersonation tokens). There is NO permission here on purpose: every
 * endpoint works on the logged-in person only, and none takes a user id. Route names start with `admin.account.`
 * because EnsureAdmin lets those through when two-factor is required and the person has not enrolled yet.
 * The second step of the sign-in (`login/two-factor`) is public and lives next to `login` in admin.php.
 */

use App\Http\Controllers\Admin\Account\ActivityController;
use App\Http\Controllers\Admin\Account\PasswordController;
use App\Http\Controllers\Admin\Account\ProfileController;
use App\Http\Controllers\Admin\Account\ReauthController;
use App\Http\Controllers\Admin\Account\SessionController;
use App\Http\Controllers\Admin\Account\TwoFactorController;
use Illuminate\Support\Facades\Route;

Route::prefix('account')->name('admin.account.')->middleware('throttle:60,1,account')->group(function () {
    Route::get('', [ProfileController::class, 'show'])->name('show');
    Route::patch('profile', [ProfileController::class, 'update'])->name('profile');

    // Sensitive: they re-ask the password and have a tighter limit per person.
    Route::middleware('throttle:10,1,account-sensitive')->group(function () {
        Route::post('email', [ProfileController::class, 'requestEmailChange'])->name('email.request');
        Route::post('email/confirm', [ProfileController::class, 'confirmEmailChange'])->name('email.confirm');
        Route::post('password', [PasswordController::class, 'update'])->name('password');
        Route::post('two-factor/setup', [TwoFactorController::class, 'setup'])->name('2fa.setup');
        Route::post('two-factor/confirm', [TwoFactorController::class, 'confirm'])->name('2fa.confirm');
        Route::post('two-factor/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes'])->name('2fa.codes');
        // Step-up: password (+ TOTP code) marks this token as recently re-authenticated (`admin.reauth` routes).
        Route::post('reauth', [ReauthController::class, 'store'])->name('reauth');
        Route::post('two-factor/disable', [TwoFactorController::class, 'disable'])->name('2fa.disable');
    });
    Route::delete('email', [ProfileController::class, 'cancelEmailChange'])->name('email.cancel');
    Route::get('reauth', [ReauthController::class, 'show'])->name('reauth.show');
    Route::get('two-factor', [TwoFactorController::class, 'show'])->name('2fa.show');

    Route::get('sessions', [SessionController::class, 'index'])->name('sessions');
    Route::delete('sessions/{id}', [SessionController::class, 'destroy'])->whereNumber('id')->name('sessions.destroy');
    Route::delete('sessions', [SessionController::class, 'destroyOthers'])->name('sessions.others');
    Route::post('logout', [SessionController::class, 'logout'])->name('logout');
    Route::post('logout-all', [SessionController::class, 'logoutAll'])->name('logout-all');

    Route::get('activity', [ActivityController::class, 'index'])->name('activity');
});
