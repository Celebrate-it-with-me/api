<?php

/*
 * Admin API: WhatsApp console (docs/plans/communications-contract.md). Included from routes/api/admin.php inside the staff
 * group. Reads need admin.events.view; every write is superadmin only (admin.whatsapp.manage), re-authenticated and audited.
 */

use App\Http\Controllers\Admin\WhatsApp\WhatsAppAdminController;
use Illuminate\Support\Facades\Route;

Route::prefix('whatsapp')->group(function () {
    Route::middleware('admin.can:admin.events.view')->group(function () {
        Route::get('status', [WhatsAppAdminController::class, 'status']);
        Route::get('summary', [WhatsAppAdminController::class, 'summary']);
        Route::get('messages', [WhatsAppAdminController::class, 'messages']);
        Route::get('templates', [WhatsAppAdminController::class, 'templates']);
    });

    Route::middleware(['admin.can:admin.whatsapp.manage', 'admin.reauth'])->group(function () {
        Route::put('templates/{id}', [WhatsAppAdminController::class, 'updateTemplate'])->whereNumber('id');
        Route::post('templates/sync', [WhatsAppAdminController::class, 'syncTemplates']);
        Route::post('templates/{id}/submit', [WhatsAppAdminController::class, 'submitTemplate'])->whereNumber('id');
        Route::post('test', [WhatsAppAdminController::class, 'test'])->middleware('throttle:admin-whatsapp-test');
    });
});
