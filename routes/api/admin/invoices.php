<?php

/*
 * Admin API: Facturas (A14). Included from routes/api/admin.php inside the staff group. Superadmin only
 * (admin.purchases.manage). Invoices are immutable: there is no edit or delete, and resending is audited.
 */

use App\Http\Controllers\Admin\Invoices\InvoiceController;
use Illuminate\Support\Facades\Route;

Route::prefix('invoices')->middleware('admin.can:admin.purchases.manage')->group(function () {
    Route::get('', [InvoiceController::class, 'index']);
    Route::get('credit-notes/{creditNote}/pdf', [InvoiceController::class, 'creditNotePdf'])->whereNumber('creditNote');
    Route::get('{invoice}', [InvoiceController::class, 'show'])->whereNumber('invoice');
    Route::get('{invoice}/pdf', [InvoiceController::class, 'pdf'])->whereNumber('invoice');
    Route::post('{invoice}/resend', [InvoiceController::class, 'resend'])->whereNumber('invoice');
});
