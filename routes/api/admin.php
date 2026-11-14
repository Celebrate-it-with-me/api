<?php
    
    use App\Http\Controllers\AuthenticationController;
    use App\Http\Controllers\UserController;
    use App\Http\Controllers\Admin\AuditController;
    use App\Http\Controllers\Admin\DashboardController;
    use App\Http\Controllers\Admin\TeamController;
    use App\Http\Controllers\Payments\AdminPaymentController;
    
    Route::post('/login', [AuthenticationController::class, 'adminLogin'])->middleware('throttle:10,1');
    // Second step when the account has two-factor on: challenge + code (or recovery code) for the token.
    Route::post('/login/two-factor', [AuthenticationController::class, 'adminTwoFactorLogin'])->middleware('throttle:10,1');

// Everything in the admin panel needs a logged-in administrator. The legacy RSVP and gallery endpoints used to be
// open to anyone (and the rest to any logged-in user): they are closed with the rest.
Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    // Staff (superadmin and support). What each role may do is in App\Support\AdminPermissions; every route says which permission it needs.
    Route::get('userInfo', [UserController::class, 'userInfo']);
    Route::get('dashboard', DashboardController::class)->middleware('admin.can:admin.dashboard.view');

    Route::prefix('payments')->group(function () {
        Route::get('', [AdminPaymentController::class, 'index'])->middleware('admin.can:admin.purchases.manage');
        Route::post('events/{event}/manual', [AdminPaymentController::class, 'manual'])->middleware('admin.can:admin.purchases.manage');
        Route::post('{purchase}/refund', [AdminPaymentController::class, 'refund'])->middleware(['admin.can:admin.refunds', 'admin.reauth']);
        Route::put('price', [AdminPaymentController::class, 'updatePrice'])->middleware(['admin.can:admin.pricing.manage', 'admin.reauth']);
        Route::get('coupons', [AdminPaymentController::class, 'coupons'])->middleware('admin.can:admin.pricing.manage');
        Route::post('coupons', [AdminPaymentController::class, 'storeCoupon'])->middleware(['admin.can:admin.pricing.manage', 'admin.reauth']);
        Route::patch('coupons/{coupon}', [AdminPaymentController::class, 'updateCoupon'])->middleware(['admin.can:admin.pricing.manage', 'admin.reauth']);
    });

    Route::get('audit', [AuditController::class, 'index'])->middleware('admin.can:admin.audit.view');

    Route::prefix('team')->middleware('admin.can:admin.team.manage')->group(function () {
        Route::get('', [TeamController::class, 'index']);
        // Changes to who has staff access (and with which role) or to their sign-in security need a recent re-authentication.
        Route::post('', [TeamController::class, 'store'])->middleware('admin.reauth');
        Route::patch('{user}', [TeamController::class, 'update'])->middleware('admin.reauth');
        Route::delete('{user}', [TeamController::class, 'destroy'])->middleware('admin.reauth');
        Route::post('{user}/two-factor/reset', [TeamController::class, 'resetTwoFactor'])->middleware(['admin.reauth', 'throttle:10,1']);
    });

    // One file per Operation module, so the agents that build them never edit the same file.
    foreach (['events', 'users', 'support', 'moderation', 'impersonation', 'revenue', 'catalog', 'health', 'templates', 'account', 'invoices'] as $module) {
        require __DIR__."/admin/{$module}.php";
    }
});
