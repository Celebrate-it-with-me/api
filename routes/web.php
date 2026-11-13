<?php

use App\Http\Controllers\InvitationShareController;
use App\Http\Controllers\S3ObjectsController;
use App\Http\Controllers\SocialAuthController;
use App\Http\Controllers\StatusController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

Route::get("/status", StatusController::class);

Route::prefix('oauth')->group(function () {
    Route::get('{provider}/redirect', [SocialAuthController::class, 'redirect'])
        ->whereIn('provider', ['google', 'facebook']);

    Route::get('{provider}/callback', [SocialAuthController::class, 'callback'])
        ->whereIn('provider', ['google', 'facebook']);
});

// Link preview of an invitation (WhatsApp, Facebook, iMessage…). The app's host sends crawlers here.
Route::get('og/{slug}/{code}', [InvitationShareController::class, 'show'])->middleware('throttle:60,1')->name('invitation.share');
