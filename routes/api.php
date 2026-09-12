<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\ShopifyController;
//admin
use App\Http\Controllers\Api\Admin\OrderStatusApiController;
use App\Http\Controllers\Api\Admin\DriverManagementController;
use App\Http\Controllers\Api\Admin\PaymentsManagementController;

//Mobile App Controllers
use App\Http\Controllers\Api\OrderManagementController;
use App\Http\Controllers\Api\Mobile\PackerManagementController;
use App\Http\Controllers\Api\Mobile\PickerManagementController;
use App\Http\Controllers\Api\Mobile\DeliveryManagementController;
use App\Http\Controllers\Api\Mobile\DiscrepancyController;



Route::post('/login', [AuthController::class, 'login']);
Route::post('/users', [UserController::class, 'store']); // Public registration

//Admin Routes
Route::prefix('admin')->group(function(){
    Route::get('/ping', [\App\Http\Controllers\Api\ResourceController::class, 'ping']);

    // Dedicated Order Status List Endpoints (Paginated for Large Datasets)
    Route::get('/orders/status/all', [OrderStatusApiController::class, 'all']);
    Route::get('/orders/all', [OrderStatusApiController::class, 'all']);

    Route::get('/orders/status/new', [OrderStatusApiController::class, 'newOrders']);
    Route::get('/orders/new', [OrderStatusApiController::class, 'newOrders']);

    Route::get('/orders/status/ready-to-assign', [OrderStatusApiController::class, 'readyToAssign']);
    Route::get('/orders/ready-to-assign', [OrderStatusApiController::class, 'readyToAssign']);

    Route::get('/orders/status/picking', [OrderStatusApiController::class, 'picking']);
    Route::get('/orders/picking', [OrderStatusApiController::class, 'picking']);

    Route::get('/orders/status/picked', [OrderStatusApiController::class, 'picked']);
    Route::get('/orders/picked', [OrderStatusApiController::class, 'picked']);

    Route::get('/orders/status/packing', [OrderStatusApiController::class, 'packing']);
    Route::get('/orders/packing', [OrderStatusApiController::class, 'packing']);

    Route::get('/orders/status/in-delivery', [OrderStatusApiController::class, 'inDelivery']);
    Route::get('/orders/in-delivery', [OrderStatusApiController::class, 'inDelivery']);
    Route::match(['get', 'post'], '/orders/status/delivered', [OrderStatusApiController::class, 'delivered']);
    Route::match(['get', 'post'], '/orders/delivered', [OrderStatusApiController::class, 'delivered']);

    Route::match(['get', 'post'], '/orders/status/installation', [OrderStatusApiController::class, 'installationOrders']);
    Route::match(['get', 'post'], '/orders/installation', [OrderStatusApiController::class, 'installationOrders']);
    Route::match(['get', 'post'], '/orders/status/installation-items', [OrderStatusApiController::class, 'installationOrders']);
    Route::match(['get', 'post'], '/orders/installation-items', [OrderStatusApiController::class, 'installationOrders']);
    Route::match(['get', 'post'], '/orders/status/cancelled-delivery', [OrderStatusApiController::class, 'cancelledDelivery']);
    Route::match(['get', 'post'], '/orders/cancelled-delivery', [OrderStatusApiController::class, 'cancelledDelivery']);
    Route::get('/orders/status/flagged', [OrderStatusApiController::class, 'flaggedOrders']);
    Route::get('/orders/flagged', [OrderStatusApiController::class, 'flaggedOrders']);
    Route::get('/orders/flagged-items', [OrderStatusApiController::class, 'flaggedOrders']);

    //driver assignment endpoints
    Route::post('/driver/assign', [DriverManagementController::class, 'assignDriver']);
    Route::post('/orders/assign-driver', [DriverManagementController::class, 'assignDriver']);
    Route::post('/resource/sales-order/assign-driver', [DriverManagementController::class, 'assignDriver']);
    // Driver Management & Order Assignment Endpoints (React.js Frontend & Flutter App)
    Route::get('/get-drivers-list', [DriverManagementController::class, 'index']);
    Route::get('/drivers', [DriverManagementController::class, 'index']);
    // Payment Status Update Endpoint
    Route::post('/orders/update-payment-status', [PaymentsManagementController::class, 'updatePaymentStatus']);
    // Create Return / Replacement Endpoint
    Route::post('/orders/return-replacement', [PaymentsManagementController::class, 'createReturnReplacement']);

});



//Mobile App Routes
// Picker & Packer Management List Endpoints
Route::get('/get-pickers-list', [PickerManagementController::class, 'getPickersList']);
Route::get('/get-packers-list', [PackerManagementController::class, 'getPackersList']);

// Flutter App API to fetch assigned orders for a driver using driver user id
Route::get('/driver/assigned-orders/{driver_user_id}', [DeliveryManagementController::class, 'getAssignedOrders']);
Route::get('/driver-orders/{driver_user_id}', [DeliveryManagementController::class, 'getAssignedOrders']);

// Flutter App API to update driver status (assigned, accepted, started, delivered, cancelled, refund, exchange)
Route::post('/driver/update-status', [DeliveryManagementController::class, 'updateDriverStatus']);
Route::post('/driver-orders/update-status', [DeliveryManagementController::class, 'updateDriverStatus']);

// Flutter App Order Management API Endpoints (Sync, Item Status update, Picker Assignment, User Logs)
Route::get('/orders', [PickerManagementController::class, 'index']);
Route::get('/orders/{id}', [PickerManagementController::class, 'apiOrdersById']);
Route::get('/orders-complete/{id}', [PickerManagementController::class, 'apiOrdersComplete']);
Route::post('/orders/assign-me', [PickerManagementController::class, 'assignOrder']);
Route::post('/orders/unassign', [PickerManagementController::class, 'unassignOrder']);
Route::post('/orders/unassign-me', [PickerManagementController::class, 'unassignOrder']);
Route::post('/orders/items/assign-me', [PickerManagementController::class, 'assignItems']);
Route::post('/orders/items/unassign', [PickerManagementController::class, 'unassignItems']);
Route::post('/orders/items/unassign-me', [PickerManagementController::class, 'unassignItems']);
Route::post('/orders/items/update-status', [PickerManagementController::class, 'updateItemStatus']);

// Assign and Unassign Picker with Order Items Array API Endpoints
Route::post('/orders/assign-picker-items', [PickerManagementController::class, 'assignOrderWithItems']);
Route::post('/orders/picker/assign-items', [PickerManagementController::class, 'assignOrderWithItems']);
Route::post('/orders/assign-with-items', [PickerManagementController::class, 'assignOrderWithItems']);

Route::post('/orders/unassign-picker-items', [PickerManagementController::class, 'unassignOrderWithItems']);
Route::post('/orders/picker/unassign-items', [PickerManagementController::class, 'unassignOrderWithItems']);
Route::post('/orders/unassign-with-items', [PickerManagementController::class, 'unassignOrderWithItems']);

// Packer Workflow API Endpoints (Assignment, Barcode Verification, Bag Count & Order Lists)
Route::get('/orders/packer/ready-to-pack', [PackerManagementController::class, 'getPickedOrdersForPacker']);
Route::get('/orders/packer/picked/{user_id?}', [PackerManagementController::class, 'getPickedOrdersForPacker']);
Route::get('/orders-picked/{id?}', [PackerManagementController::class, 'getPickedOrdersForPacker']);

Route::get('/orders/packer/packed/{user_id?}', [PackerManagementController::class, 'getPackedOrders']);
Route::get('/orders-packed/{id?}', [PackerManagementController::class, 'getPackedOrders']);
Route::get('/orders/packed-completed/{id?}', [PackerManagementController::class, 'apiPackerOrdersComplete']);
Route::get('/orders/packer/complete/{id?}', [PackerManagementController::class, 'apiPackerOrdersComplete']);

Route::get('/orders/packer/{id}', [PackerManagementController::class, 'apiPackerOrdersById']);

Route::post('/orders/packer/assign-me', [PackerManagementController::class, 'assignPacker']);
Route::post('/orders/packer/assign', [PackerManagementController::class, 'assignPacker']);
Route::post('/orders/packer/unassign-me', [PackerManagementController::class, 'unassignPacker']);
Route::post('/orders/packer/unassign', [PackerManagementController::class, 'unassignPacker']);

Route::post('/orders/assign-packer-items', [PackerManagementController::class, 'assignPackerWithItems']);
Route::post('/orders/packer/assign-items', [PackerManagementController::class, 'assignPackerWithItems']);
Route::post('/orders/assign-packer-with-items', [PackerManagementController::class, 'assignPackerWithItems']);

Route::post('/orders/unassign-packer-items', [PackerManagementController::class, 'unassignPackerWithItems']);
Route::post('/orders/packer/unassign-items', [PackerManagementController::class, 'unassignPackerWithItems']);
Route::post('/orders/unassign-packer-with-items', [PackerManagementController::class, 'unassignPackerWithItems']);

Route::post('/orders/packer/verify-item', [PackerManagementController::class, 'verifyItemBarcode']);
Route::post('/orders/packer/complete-packing', [PackerManagementController::class, 'completePacking']);

// Verified Bags API
Route::post('/orders/packer/verify-bags', [DeliveryManagementController::class, 'verifyBags']);
Route::post('/orders/verify-bags', [DeliveryManagementController::class, 'verifyBags']);
Route::get('/orders/verified-bags/{order_number?}', [DeliveryManagementController::class, 'verifyBags']);

//Order Item Driver side

Route::post('/orders/driver/assigned', [DeliveryManagementController::class, 'assignedDriver']);
Route::post('/orders/driver/accepted', [DeliveryManagementController::class, 'orderAccepted']);
Route::post('/orders/driver/unaccepted', [DeliveryManagementController::class, 'orderUnaccepted']);
Route::post('/orders/driver/start-delivery', [DeliveryManagementController::class, 'startDelivery']);
Route::get('/orders/driver/started/{driver_user_id?}', [DeliveryManagementController::class, 'getStartedOrders']);
Route::post('/orders/driver/update-status', [DeliveryManagementController::class, 'updateDriverStatus']);
Route::post('/orders/driver/delivered', [DeliveryManagementController::class, 'orderDelivered']);
Route::post('/orders/driver/mark-as-delivered', [DeliveryManagementController::class, 'markAsDelivered']);
Route::post('/orders/driver/mark-delivered', [DeliveryManagementController::class, 'markAsDelivered']);
Route::post('/orders/driver/cancelled', [DeliveryManagementController::class, 'orderCancelled']);
Route::post('/orders/driver/refund', [DeliveryManagementController::class, 'orderRefund']);
Route::post('/orders/driver/exchange', [DeliveryManagementController::class, 'orderExchange']);
Route::post('/orders/driver/flag', [DeliveryManagementController::class, 'flagDelivery']);
Route::post('/orders/driver/flag-delivery', [DeliveryManagementController::class, 'flagDelivery']);
Route::get('/orders/driver/flagged/{driver_user_id?}', [DeliveryManagementController::class, 'getFlaggedOrders']);
Route::get('/orders/driver/flagged-orders/{driver_user_id?}', [DeliveryManagementController::class, 'getFlaggedOrders']);
Route::get('/orders/driver/discrepancies/{driver_user_id?}', [DeliveryManagementController::class, 'getDeliveryDiscrepancies']);

// Order Item Discrepancy & Flagging Endpoints
Route::post('/orders/items/flag-discrepancy', [DiscrepancyController::class, 'flagItemDiscrepancy']);
Route::get('/orders/items/discrepancies', [DiscrepancyController::class, 'getDiscrepancies']);
Route::post('/orders/items/resolve-discrepancy', [DiscrepancyController::class, 'resolveDiscrepancy']);

Route::get('/order-management/orders/{id}', [OrderManagementController::class, 'show']);
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
    Route::get('/resource/sales-order', [\App\Http\Controllers\Api\ResourceController::class, 'salesOrder']);
    Route::any('/resource/Sales Order/{orderId}', [\App\Http\Controllers\Api\ResourceController::class, 'salesOrderDetail'])
        ->where('orderId', '.*');
    Route::any('/resource/sales-order/{orderId}', [\App\Http\Controllers\Api\ResourceController::class, 'salesOrderDetail'])
        ->where('orderId', '.*');

});

//Backend Processing apis to sync shopify.
// Shopify Sync & Order Management endpoints
Route::post('/shopify/sync-orders', [OrderManagementController::class, 'syncShopify']);
Route::get('/shopify/status', [ShopifyController::class, 'apiStatus']);
Route::get('/shopify/products', [ShopifyController::class, 'apiProducts']);
Route::get('/shopify/orders', [ShopifyController::class, 'apiOrders']);
Route::get('/shopify/orders/{id}', [ShopifyController::class, 'apiOrder']);
Route::post('/shopify/webhooks/orders-create', [ShopifyController::class, 'handleOrderWebhook']);
Route::post('/shopify/webhooks/orders-update', [ShopifyController::class, 'handleOrderWebhook']);
Route::post('/shopify/webhooks/orders-paid', [ShopifyController::class, 'handleOrderWebhook']);
Route::post('/shopify/webhooks/orders-fulfilled', [ShopifyController::class, 'handleOrderWebhook']);
Route::post('/shopify/webhooks', [ShopifyController::class, 'handleOrderWebhook']);
Route::post('/webhooks/shopify/orders', [ShopifyController::class, 'handleOrderWebhook']);
Route::post('/webhooks/shopify/orders-create', [ShopifyController::class, 'handleOrderWebhook']);
Route::post('/webhooks/shopify', [ShopifyController::class, 'handleOrderWebhook']);
// Shopify Sync Logs & Error Tracking endpoints
Route::get('/shopify/sync-logs', [ShopifyController::class, 'apiSyncLogs']);
Route::get('/shopify/sync-logs/{id}', [ShopifyController::class, 'apiSyncLogDetail']);
Route::post('/shopify/sync-logs/{id}/retry', [ShopifyController::class, 'retrySyncLog']);
Route::delete('/shopify/sync-logs', [ShopifyController::class, 'clearSyncLogs']);

Route::get('/demo/order-management/orders/{order}', [\App\Http\Controllers\Api\ResourceController::class, 'salesOrderDetail'])
    ->where('order', '.*');
Route::get('/admin/order/{order}', [\App\Http\Controllers\Api\ResourceController::class, 'salesOrderDetail'])
    ->where('order', '.*');
Route::get('/order-details/{order}', [\App\Http\Controllers\Api\ResourceController::class, 'salesOrderDetail'])
    ->where('order', '.*');