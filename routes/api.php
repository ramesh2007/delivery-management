<?php

use App\Http\Controllers\Api\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/users', [UserController::class, 'store']);

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/test-role', function (Request $request) {
    return response()->json([
        'message' => 'Access granted. You have the admin role!',
        'user' => $request->user()->load('roles'),
    ]);
})->middleware(['auth:sanctum', 'role:admin']);
