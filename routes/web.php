<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\ShopifyController;

Route::get('/', function () {
    return view('welcome');
});

// Shopify Integration Web Routes
Route::get('/shopify', [ShopifyController::class, 'index'])->name('shopify.index');
Route::get('/shopify/connect', [ShopifyController::class, 'connect'])->name('shopify.connect');
Route::get('/shopify/callback', [ShopifyController::class, 'callback'])->name('shopify.callback');
Route::get('/public/shopify/callback', [ShopifyController::class, 'callback']); // support full URI path callback
Route::post('/shopify/save-settings', [ShopifyController::class, 'saveSettings'])->name('shopify.save-settings');

// Shopify Webhooks fallback in web routes (in case webhook URL was configured without /api prefix)
Route::post('/shopify/webhooks/orders-create', [ShopifyController::class, 'handleOrderWebhook']);
Route::post('/shopify/webhooks/orders-update', [ShopifyController::class, 'handleOrderWebhook']);
Route::post('/shopify/webhooks/orders-paid', [ShopifyController::class, 'handleOrderWebhook']);
Route::post('/shopify/webhooks/orders-fulfilled', [ShopifyController::class, 'handleOrderWebhook']);
Route::post('/shopify/webhooks', [ShopifyController::class, 'handleOrderWebhook']);
Route::post('/webhooks/shopify/orders', [ShopifyController::class, 'handleOrderWebhook']);
Route::post('/webhooks/shopify/orders-create', [ShopifyController::class, 'handleOrderWebhook']);
Route::post('/webhooks/shopify', [ShopifyController::class, 'handleOrderWebhook']);

