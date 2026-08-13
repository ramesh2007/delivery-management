<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\OrderManagementController;

use App\Http\Controllers\ShopifyController;

Route::post('/login', [AuthController::class, 'login']);
Route::post('/users', [UserController::class, 'store']); // Public registration
Route::get('/ping', [\App\Http\Controllers\Api\ResourceController::class, 'ping']);

// Shopify Sync & Order Management endpoints
Route::post('/shopify/sync-orders', [OrderManagementController::class, 'syncShopify']);
Route::get('/shopify/status', [ShopifyController::class, 'apiStatus']);
Route::get('/shopify/products', [ShopifyController::class, 'apiProducts']);
Route::get('/shopify/orders', [ShopifyController::class, 'apiOrders']);
Route::get('/demo/order-management/orders/{order}', [\App\Http\Controllers\Api\ResourceController::class, 'salesOrderDetail'])
    ->where('order', '.*');

// Flutter App Order Management API Endpoints (Sync, Item Status update, Picker Assignment, User Logs)
Route::get('/orders', [OrderManagementController::class, 'index']);
Route::get('/orders/{id}', [OrderManagementController::class, 'show']);
Route::post('/orders/assign-me', [OrderManagementController::class, 'assignOrder']);
Route::post('/orders/items/assign-me', [OrderManagementController::class, 'assignItems']);
Route::post('/orders/items/update-status', [OrderManagementController::class, 'updateItemStatus']);

Route::post('/orders/{id}/status', [OrderManagementController::class, 'updateOrderStatus']);
Route::get('/orders/{id}/logs', [OrderManagementController::class, 'getLogs']);


Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);
    
    // User status list routes
    Route::get('/users/active', [UserController::class, 'activeUsers']);
    Route::get('/users/inactive', [UserController::class, 'inactiveUsers']);
    
    // User CRUD routes (excluding store since it is public registration)
    Route::apiResource('users', UserController::class)->except(['store']);
    Route::post('/users/{id}/roles', [UserController::class, 'assignRoles']);
    
    // Role CRUD & Assignment routes
    Route::apiResource('roles', RoleController::class);
    Route::post('/roles/{id}/permissions', [RoleController::class, 'assignPermissions']);

    // Permission CRUD routes
    Route::apiResource('permissions', PermissionController::class);

    // Profile route
    Route::get('/profile', [AuthController::class, 'profile']);

    // Test routes for role & permission authorization middleware
    Route::get('/test-role', function () {
        return response()->json(['message' => 'Access granted. You have the admin role!']);
    })->middleware('role:admin');

    Route::get('/test-permission', function () {
        return response()->json(['message' => 'Access granted. You have the edit-users permission!']);
    })->middleware('permission:edit-users');



    // Admin Routes
    Route::get('/resource/Sales Order', [\App\Http\Controllers\Api\ResourceController::class, 'salesOrder']);
    Route::any('/resource/Sales Order/{orderId}', [\App\Http\Controllers\Api\ResourceController::class, 'salesOrderDetail'])
        ->where('orderId', '.*');
   

});
