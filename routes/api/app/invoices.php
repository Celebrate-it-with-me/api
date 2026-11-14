<?php

/*
 * App API: "Mis compras" (A14). Included with one line at the end of routes/api/app.php (that file has no per-module
 * includes); it carries its own auth middleware, the same as the protected group there.
 */

use App\Http\Controllers\AppControllers\Account\BillingProfileController;
use App\Http\Controllers\AppControllers\Account\MyPurchasesController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'not.suspended', 'impersonation.guard', 'refresh.token'])->prefix('account')->name('account.')->group(function () {
    Route::get('purchases', [MyPurchasesController::class, 'index'])->name('purchases');
    Route::get('invoices/{invoice}/pdf', [MyPurchasesController::class, 'invoicePdf'])->whereNumber('invoice')->name('invoices.pdf');
    Route::get('credit-notes/{creditNote}/pdf', [MyPurchasesController::class, 'creditNotePdf'])->whereNumber('creditNote')->name('credit-notes.pdf');
    // Resend to herself only (billing or account address); 5 per 10 minutes per user.
    Route::post('invoices/{invoice}/resend', [MyPurchasesController::class, 'resendInvoice'])->whereNumber('invoice')->middleware('throttle:5,10')->name('invoices.resend');
    Route::post('credit-notes/{creditNote}/resend', [MyPurchasesController::class, 'resendCreditNote'])->whereNumber('creditNote')->middleware('throttle:5,10')->name('credit-notes.resend');
    Route::get('billing-profile', [BillingProfileController::class, 'show'])->name('billing-profile.show');
    Route::put('billing-profile', [BillingProfileController::class, 'update'])->name('billing-profile.update');
});
