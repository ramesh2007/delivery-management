<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);
    
    // User status list routes
    Route::get('/users/active', [UserController::class, 'activeUsers']);
    Route::get('/users/inactive', [UserController::class, 'inactiveUsers']);
    
    // Simple User CRUD routes
    Route::apiResource('users', UserController::class);
    // Profile route
    Route::get('/profile', [AuthController::class, 'profile']);
});
