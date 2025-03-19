<?php

use App\Http\Controllers\AppControllers\EventsController;
use App\Http\Controllers\AppControllers\GuestController;
use App\Http\Controllers\AppControllers\RsvpController;
use App\Http\Controllers\AppControllers\SaveTheDateController;
use App\Http\Controllers\AppControllers\SuggestedMusicConfigController;
use App\Http\Controllers\AppControllers\SuggestedMusicController;
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
    
    Route::post('event/{event}/suggest-music', [SuggestedMusicController::class, 'store']);
    Route::get('event/{event}/suggest-music', [SuggestedMusicController::class, 'index']);
    Route::delete('suggest-music/{suggestedMusic}', [SuggestedMusicController::class, 'destroy']);
    
    Route::post('suggest-music/{suggestedMusic}/vote', [SuggestedMusicController::class, 'storeOrUpdate']);
    
    Route::get('event/{event}/suggest-music-config', [SuggestedMusicConfigController::class, 'index']);
    Route::post('event/{event}/suggest-music-config', [SuggestedMusicConfigController::class, 'store']);
    Route::put('suggest-music-config/{suggestedMusicConfig}', [SuggestedMusicConfigController::class, 'update']);
    Route::delete('suggest-music-config/{suggestedMusicConfig}', [SuggestedMusicConfigController::class, 'destroy']);
    
    Route::get('template/event/{event}/guest/{guestCode}', [TemplateController::class, 'getEventData']);
    
    Route::post('template/event/{event}/save-rsvp', [RsvpController::class, 'saveRsvp']);
    
    Route::post('logout', [AuthenticationController::class, 'appLogout']);
});

