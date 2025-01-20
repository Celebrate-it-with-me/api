<?php

use App\Http\Controllers\AuthenticationController;

Route::post('register', [AuthenticationController::class, 'appRegister']);
Route::post('login', [AuthenticationController::class, 'appLogin']);
