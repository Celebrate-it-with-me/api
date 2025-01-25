<?php

use App\Http\Controllers\AppControllers\EventsController;
use App\Http\Controllers\AuthenticationController;

Route::post('register', [AuthenticationController::class, 'appRegister']);
Route::post('login', [AuthenticationController::class, 'appLogin']);


Route::middleware('auth:sanctum')->group(function () {
    Route::post('event', [EventsController::class, 'store']);
    Route::get('event', [EventsController::class, 'index']);
    
    Route::post('logout', [AuthenticationController::class, 'appLogout']);
});

