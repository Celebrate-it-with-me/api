<?php

/*
 * Admin API: Ingresos (A8), the global view of purchases and the price/codes. Included from routes/api/admin.php inside the
 * staff group. Superadmin only (admin.purchases.manage, admin.refunds, admin.pricing.manage). Every write is audited.
 */

use App\Http\Controllers\Admin\Revenue\RevenueController;
use Illuminate\Support\Facades\Route;

Route::prefix('revenue')->group(function () {
    Route::get('purchases', [RevenueController::class, 'purchases'])->middleware('admin.can:admin.purchases.manage');
    Route::get('purchases/{purchase}', [RevenueController::class, 'purchase'])->whereNumber('purchase')->middleware('admin.can:admin.purchases.manage');
    Route::get('pricing', [RevenueController::class, 'pricing'])->middleware('admin.can:admin.pricing.manage');
});
