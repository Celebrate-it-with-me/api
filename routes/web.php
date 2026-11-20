<?php

use App\Http\Controllers\Dev\MailGalleryController;
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

Route::get('/status', StatusController::class);

Route::prefix('oauth')->middleware('throttle:oauth')->group(function () {
    Route::get('{provider}/redirect', [SocialAuthController::class, 'redirect'])
        ->whereIn('provider', ['google', 'facebook']);

    Route::get('{provider}/callback', [SocialAuthController::class, 'callback'])
        ->whereIn('provider', ['google', 'facebook']);
});

// Development gallery of every transactional mail. The controller answers 404 unless APP_ENV=local.
Route::prefix('dev/mail')->controller(MailGalleryController::class)->group(function () {
    Route::get('/', 'index')->name('dev.mail.index');
    Route::get('{key}', 'show')->name('dev.mail.show');
    Route::get('{key}/frame', 'frame')->name('dev.mail.frame');
});
