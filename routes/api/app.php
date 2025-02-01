<?php

use App\Http\Controllers\AppControllers\EventsController;
use App\Http\Controllers\AppControllers\GuestController;
use App\Http\Controllers\AppControllers\SaveTheDateController;
use App\Http\Controllers\AuthenticationController;

Route::post('register', [AuthenticationController::class, 'appRegister']);
Route::post('login', [AuthenticationController::class, 'appLogin']);


Route::middleware('auth:sanctum')->group(function () {
    Route::post('event', [EventsController::class, 'store']);
    Route::get('event', [EventsController::class, 'index']);
    
    Route::get('event/{event}/save-the-date', [SaveTheDateController::class, 'index']);
    Route::post('event/{event}/save-the-date', [SaveTheDateController::class, 'store']);
    Route::put('save-the-date/{saveTheDate}', [SaveTheDateController::class, 'update']);
    
    Route::post('event/{event}/guest', [GuestController::class, 'store']);
    Route::get('event/{event}/guest', [GuestController::class, 'index']);
    
    Route::post('logout', [AuthenticationController::class, 'appLogout']);
});

