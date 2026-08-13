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

