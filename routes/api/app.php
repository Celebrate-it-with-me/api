<?php

use App\Http\Controllers\AppControllers\BackgroundMusicController;
use App\Http\Controllers\AppControllers\CompanionController;
use App\Http\Controllers\AppControllers\EventCommentsController;
use App\Http\Controllers\AppControllers\EventConfigCommentsController;
use App\Http\Controllers\AppControllers\EventsController;
use App\Http\Controllers\AppControllers\GuestController;
use App\Http\Controllers\AppControllers\RsvpController;
use App\Http\Controllers\AppControllers\SaveTheDateController;
use App\Http\Controllers\AppControllers\SuggestedMusicConfigController;
use App\Http\Controllers\AppControllers\SuggestedMusicController;
    use App\Http\Controllers\AppControllers\SweetMemoriesConfigController;
    use App\Http\Controllers\AppControllers\SweetMemoriesImageController;
    use App\Http\Controllers\AppControllers\TemplateController;
use App\Http\Controllers\AuthenticationController;

Route::post('register', [AuthenticationController::class, 'appRegister']);
Route::post('login', [AuthenticationController::class, 'appLogin']);


Route::middleware('auth:sanctum')->group(function () {
    Route::post('event', [EventsController::class, 'store']);
    Route::get('event', [EventsController::class, 'index']);
    Route::get('event/filters', [EventsController::class, 'filterEvents']);
    Route::delete('event/{event}', [EventsController::class, 'destroy']);
    Route::put('event/{event}', [EventsController::class, 'update']);
    
    Route::get('event/{event}/save-the-date', [SaveTheDateController::class, 'index']);
    Route::post('event/{event}/save-the-date', [SaveTheDateController::class, 'store']);
    Route::put('save-the-date/{saveTheDate}', [SaveTheDateController::class, 'update']);
    
    Route::post('event/{event}/guest', [GuestController::class, 'store']);
    Route::get('event/{event}/guest', [GuestController::class, 'index']);
    
    Route::get('event/{event}/rsvp', [RsvpController::class, 'index']);
    
    Route::patch('guest/{guest}', [GuestController::class, 'updateCompanion'])
        ->name('guest.updateCompanion');
    
    Route::post('guest/{guest}/companion', [CompanionController::class, 'store'])
        ->name('guest.storeCompanion');
    
    Route::put('companion/{companion}', [CompanionController::class, 'update'])
        ->name('companion.update');
    
    Route::delete('companion/{guestCompanion}', [CompanionController::class, 'destroy'])
        ->name('companion.destroy');
    
    Route::post('event/{event}/suggest-music', [SuggestedMusicController::class, 'store']);
    Route::get('event/{event}/suggest-music', [SuggestedMusicController::class, 'index']);
    
    Route::delete('suggest-music/{suggestedMusic}', [SuggestedMusicController::class, 'destroy']);
    Route::post('suggest-music/{suggestedMusic}/vote', [SuggestedMusicController::class, 'storeOrUpdate']);
    
    Route::get('event/{event}/suggest-music-config', [SuggestedMusicConfigController::class, 'index']);
    Route::post('event/{event}/suggest-music-config', [SuggestedMusicConfigController::class, 'store']);
    Route::put('suggest-music-config/{suggestedMusicConfig}', [SuggestedMusicConfigController::class, 'update']);
    Route::delete('suggest-music-config/{suggestedMusicConfig}', [SuggestedMusicConfigController::class, 'destroy']);
    
    Route::get('event/{event}/background-music', [BackgroundMusicController::class, 'index'])
        ->name('index.backgroundMusic');
    Route::post('event/{event}/background-music', [BackgroundMusicController::class, 'store'])
        ->name('store.backgroundMusic');
    Route::post('background-music/{backgroundMusic}', [BackgroundMusicController::class, 'update'])
        ->name('store.backgroundMusic');
    
    Route::get('event/{event}/comments-config', [EventConfigCommentsController::class, 'index'])
        ->name('index.configComments');
    Route::post('event/{event}/comments-config', [EventConfigCommentsController::class, 'store'])
        ->name('store.configComments');
    Route::put('event/{event}/comments-config/{commentConfig}', [EventConfigCommentsController::class, 'update'])
        ->name('update.configComments');
    
    Route::get('event/{event}/sweet-memories-config', [SweetMemoriesConfigController::class, 'index'])
        ->name('index.sweetMemoriesConfig');
    Route::post('event/{event}/sweet-memories-config', [SweetMemoriesConfigController::class, 'store'])
        ->name('store.sweetMemoriesConfig');
    Route::put('event/{event}/sweet-memories-config/{sweetMemoriesConfig}', [SweetMemoriesConfigController::class, 'update'])
        ->name('update.sweetMemoriesConfig');
    
    Route::post('event/{event}/sweet-memories-images', [SweetMemoriesImageController::class, 'store'])
        ->name('store.sweetMemoriesImages');
    Route::get('event/{event}/sweet-memories-images', [SweetMemoriesImageController::class, 'index'])
        ->name('index.sweetMemoriesImages');
    Route::put('event/{event}/sweet-memories-images', [SweetMemoriesImageController::class, 'update'])
        ->name('update.sweetMemoriesImages');
    Route::delete('event/{event}/sweet-memories-images/{sweetMemoriesImage}', [SweetMemoriesImageController::class, 'destroy'])
        ->name('destroy.sweetMemoriesImages');
    
    Route::patch('sweet-memories-images/{sweetMemoriesImage}', [SweetMemoriesImageController::class, 'updateName'])
        ->name('update.sweetMemoriesImages');
    
    
    Route::get('event/{event}/comments', [EventCommentsController::class, 'index'])
        ->name('index.EventComments');
    Route::post('event/{event}/comments', [EventCommentsController::class, 'store'])
        ->name('store.EventComments');
    
    
    Route::get('template/event/{event}/guest/{guestCode}', [TemplateController::class, 'getEventData']);
    Route::post('template/event/{event}/save-rsvp', [RsvpController::class, 'saveRsvp']);
    
    Route::post('logout', [AuthenticationController::class, 'appLogout']);
});

