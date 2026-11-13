<?php

/*
 * Admin API: Plan and payment tab. Included from routes/api/admin/events.php (inside the staff group, prefix api/v1/admin).
 * Paths start with `events/{event}/...`. Each route declares its permission and every write is audited.
 */

use App\Http\Controllers\Admin\EventPlan\EventPlanController;
use Illuminate\Support\Facades\Route;

Route::get('events/{event}/purchases', [EventPlanController::class, 'purchases'])->withTrashed()->whereNumber('event')->middleware('admin.can:admin.purchases.manage');
Route::patch('events/{event}/plan', [EventPlanController::class, 'changePlan'])->withTrashed()->whereNumber('event')->middleware('admin.can:admin.events.change_plan');
