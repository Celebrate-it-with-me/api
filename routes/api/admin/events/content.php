<?php

/*
 * Admin API: Event content (Contenido tab) and the event log (Registro tab). Included from routes/api/admin/events.php (inside the staff group, prefix api/v1/admin).
 * Paths start with `events/{event}/...`. Each route declares its permission and every write is audited.
 */

use App\Http\Controllers\Admin\EventActions\EventContentController;
use App\Http\Controllers\Admin\EventActions\EventLogController;
use Illuminate\Support\Facades\Route;

Route::get('events/{event}/content', EventContentController::class)->withTrashed()->whereNumber('event')->middleware('admin.can:admin.events.view');
Route::get('events/{event}/log', EventLogController::class)->withTrashed()->whereNumber('event')->middleware('admin.can:admin.events.view');
