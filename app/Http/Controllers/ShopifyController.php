<?php

namespace App\Http\Controllers;

use App\Models\ShopifyStore;
use App\Services\ShopifyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ShopifyController extends Controller
{
    protected ShopifyService $shopifyService;

    public function __construct(ShopifyService $shopifyService)
    {
        $this->shopifyService = $shopifyService;
    }

    /**
     * Display Shopify Web Blade Dashboard
     */
    public function index(Request $request)
    {
        $activeStore = $this->shopifyService->getActiveStore();
        
        $productsResult = $this->shopifyService->getProducts();
        $ordersResult = $this->shopifyService->getOrders();

        return view('shopify.index', [
            'activeStore' => $activeStore,
            'productsResult' => $productsResult,
            'ordersResult' => $ordersResult,
            'config' => [
                'api_key' => config('services.shopify.api_key'),
                'scopes' => config('services.shopify.scopes'),
                'redirect_uri' => config('services.shopify.redirect_uri'),
            ],
        ]);
    }

    /**
     * Initiate OAuth installation flow redirect to Shopify
     */
    public function connect(Request $request)
    {
        $request->validate([
            'shop' => 'required|string',
        ]);

        $shop = $this->shopifyService->sanitizeShopDomain($request->input('shop'));
        $authUrl = $this->shopifyService->getAuthUrl($shop);

        return redirect()->away($authUrl);
    }

    /**
     * OAuth Callback endpoint (SHOPIFY_REDIRECT_URI)
     */
    public function callback(Request $request)
    {
        $shop = $request->input('shop');
        $code = $request->input('code');

        if (!$shop || !$code) {
            return redirect()->route('shopify.index')->with('error', 'Missing shop or code parameter in callback.');
        }

        // Verify HMAC if present
        if ($request->has('hmac') && !$this->shopifyService->verifyHmac($request->all())) {
            return redirect()->route('shopify.index')->with('error', 'HMAC verification failed for Shopify callback.');
        }

        $tokenResult = $this->shopifyService->exchangeCodeForToken($shop, $code);

        if (!$tokenResult['success']) {
            return redirect()->route('shopify.index')->with('error', 'Token exchange failed: ' . ($tokenResult['error'] ?? 'Unknown error'));
        }

        $shopDomain = $this->shopifyService->sanitizeShopDomain($shop);

        // Deactivate all previous stores and save current
        ShopifyStore::query()->update(['is_active' => false]);

        $store = ShopifyStore::updateOrCreate(
            ['shop' => $shopDomain],
            [
                'access_token' => $tokenResult['access_token'],
                'scopes' => $tokenResult['scopes'],
                'is_active' => true,
                'last_synced_at' => now(),
            ]
        );

        return redirect()->route('shopify.index')->with('success', "Successfully connected shop: {$shopDomain}");
    }

    /**
     * Save Shopify Store domain and Access Token manually
     */
    public function saveSettings(Request $request)
    {
        $request->validate([
            'shop' => 'required|string',
            'access_token' => 'required|string',
        ]);

        $shopDomain = $this->shopifyService->sanitizeShopDomain($request->input('shop'));
        $accessToken = trim($request->input('access_token'));

        ShopifyStore::query()->update(['is_active' => false]);

        ShopifyStore::updateOrCreate(
            ['shop' => $shopDomain],
            [
                'access_token' => $accessToken,
                'scopes' => config('services.shopify.scopes'),
                'is_active' => true,
                'last_synced_at' => now(),
            ]
        );

        return redirect()->route('shopify.index')->with('success', "Store credentials saved for {$shopDomain}");
    }

    /**
     * API: Get Products JSON
     */
    public function apiProducts(Request $request)
    {
        $shop = $request->query('shop');
        $accessToken = $request->query('access_token');
        
        $result = $this->shopifyService->getProducts($shop, $accessToken);

        if (!$result['success']) {
            return response()->json($result, 400);
        }

        return response()->json($result);
    }

    /**
     * API: Get Orders JSON
     */
    public function apiOrders(Request $request)
    {
        $shop = $request->query('shop');
        $accessToken = $request->query('access_token');

        $result = $this->shopifyService->getOrders($shop, $accessToken);

        if (!$result['success']) {
            return response()->json($result, 400);
        }

        return response()->json($result);
    }

    /**
     * API: Get Connection Status JSON
     */
    public function apiStatus(Request $request)
    {
        $activeStore = $this->shopifyService->getActiveStore();
        
        return response()->json([
            'connected' => (bool) ($activeStore && $activeStore->access_token),
            'shop' => $activeStore ? $activeStore->shop : null,
            'scopes' => $activeStore ? $activeStore->scopes : config('services.shopify.scopes'),
            'last_synced_at' => $activeStore ? $activeStore->last_synced_at : null,
            'config' => [
                'api_key' => config('services.shopify.api_key'),
                'redirect_uri' => config('services.shopify.redirect_uri'),
            ]
        ]);
    }
}
