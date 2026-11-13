<?php
    
    use App\Http\Controllers\AppControllers\RSVPController;
    use App\Http\Controllers\AuthenticationController;
    use App\Http\Controllers\GalleryController;
    use App\Http\Controllers\MainGuestController;
    use App\Http\Controllers\S3ObjectsController;
    use App\Http\Controllers\SMSReminderController;
    use App\Http\Controllers\TotalsController;
    use App\Http\Controllers\UserController;
    
    Route::post('/login', [AuthenticationController::class, 'adminLogin']);

// Everything in the admin panel needs a logged-in administrator. The legacy RSVP and gallery endpoints used to be
// open to anyone (and the rest to any logged-in user): they are closed with the rest.
Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('dashboard', \App\Http\Controllers\Admin\DashboardController::class);
    Route::prefix('payments')->group(function () {
        Route::get('', [\App\Http\Controllers\Payments\AdminPaymentController::class, 'index']);
        Route::post('events/{event}/manual', [\App\Http\Controllers\Payments\AdminPaymentController::class, 'manual']);
        Route::post('{purchase}/refund', [\App\Http\Controllers\Payments\AdminPaymentController::class, 'refund']);
        Route::put('price', [\App\Http\Controllers\Payments\AdminPaymentController::class, 'updatePrice']);
        Route::get('coupons', [\App\Http\Controllers\Payments\AdminPaymentController::class, 'coupons']);
        Route::post('coupons', [\App\Http\Controllers\Payments\AdminPaymentController::class, 'storeCoupon']);
        Route::patch('coupons/{coupon}', [\App\Http\Controllers\Payments\AdminPaymentController::class, 'updateCoupon']);
    });
    Route::get('userInfo', [UserController::class, 'userInfo']);
    Route::apiResource('users', UserController::class);
    Route::apiResource('main-guest', MainGuestController::class);

    Route::get('totals', [TotalsController::class, 'getTotals']);
    Route::get('totals/details/{type}', [TotalsController::class, 'totalDetails']);
    Route::get('totals/excel/{type}', [TotalsController::class, 'exportExcel']);

    Route::get('sms-reminders/get-recipients', [SMSReminderController::class, 'getRecipients'])
        ->name('smsReminders.getRecipients');

    Route::get('username-folders', [S3ObjectsController::class, 'getFolders']);
    Route::get('folder-objects/{folder}', [S3ObjectsController::class, 'getObjectsByFolder']);
    Route::get('download-file/{folder}/{key}', [S3ObjectsController::class, 'downloadFile']);
    Route::get('delete-file/{folder}/{key}', [S3ObjectsController::class, 'deleteFile']);
    Route::get('delete-folder/{folder}', [S3ObjectsController::class, 'deleteFolder']);

    Route::apiResource('sms-reminders', SMSReminderController::class);

    Route::prefix('rsvp')->group(function() {
        Route::get('{accessCode}', [RSVPController::class, 'checkAccessCode'])
            ->name('checkRsvpAccessCode');

        Route::post('/confirm', [RSVPController::class, 'guestConfirm'])
            ->name('guestConfirm');

        Route::post('/reset-access-code', [RSVPController::class, 'resetCode'])
            ->name('resetCode');
    });

    Route::prefix('gallery')->group(function() {
        Route::post('/upload-images/{userName}', [GalleryController::class, 'uploadImages'])
            ->name('upload-images');

        Route::post('/show-images/{userName}', [GalleryController::class, 'showImages'])
            ->name('show-images');
    });
});




