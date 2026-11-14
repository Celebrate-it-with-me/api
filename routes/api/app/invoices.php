<?php

/*
 * App API: "Mis compras" (A14). Included with one line at the end of routes/api/app.php (that file has no per-module
 * includes); it carries its own auth middleware, the same as the protected group there.
 */

use App\Http\Controllers\AppControllers\Account\MyPurchasesController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'not.suspended', 'impersonation.guard', 'refresh.token'])->prefix('account')->name('account.')->group(function () {
    Route::get('purchases', [MyPurchasesController::class, 'index'])->name('purchases');
    Route::get('invoices/{invoice}/pdf', [MyPurchasesController::class, 'invoicePdf'])->whereNumber('invoice')->name('invoices.pdf');
    Route::get('credit-notes/{creditNote}/pdf', [MyPurchasesController::class, 'creditNotePdf'])->whereNumber('creditNote')->name('credit-notes.pdf');
});
