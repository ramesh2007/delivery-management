<?php

namespace App\Http\Controllers;

use App\Models\ShopifyStore;
use App\Models\ShopifySyncLog;
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

        $syncLogs = [];
        $syncStats = ['total' => 0, 'success' => 0, 'failed' => 0];
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('shopify_sync_logs')) {
                $syncLogs = ShopifySyncLog::orderBy('created_at', 'desc')->limit(50)->get();
                $syncStats = [
                    'total' => ShopifySyncLog::count(),
                    'success' => ShopifySyncLog::where('status', 'success')->count(),
                    'failed' => ShopifySyncLog::where('status', 'failed')->count(),
                ];
            }
        } catch (\Throwable $e) {}

        $appBaseUrl = url('/');
        $webhookUrls = [
            'orders_create' => "{$appBaseUrl}/api/shopify/webhooks/orders-create",
            'orders_update' => "{$appBaseUrl}/api/shopify/webhooks/orders-update",
            'orders_paid' => "{$appBaseUrl}/api/shopify/webhooks/orders-paid",
            'web_fallback' => "{$appBaseUrl}/shopify/webhooks/orders-create",
        ];

        return view('shopify.index', [
            'activeStore' => $activeStore,
            'productsResult' => $productsResult,
            'ordersResult' => $ordersResult,
            'syncLogs' => $syncLogs,
            'syncStats' => $syncStats,
            'webhookUrls' => $webhookUrls,
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
     * API: Get Single Order JSON with enriched metafields
     */
    public function apiOrder(Request $request, $orderId)
    {
        $shop = $request->query('shop');
        $accessToken = $request->query('access_token');

        $result = $this->shopifyService->getOrder($orderId, $shop, $accessToken);

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
            'connected' => (bool) ($activeStore && $access_token = $activeStore->access_token),
            'shop' => $activeStore ? $activeStore->shop : null,
            'scopes' => $activeStore ? $activeStore->scopes : config('services.shopify.scopes'),
            'last_synced_at' => $activeStore ? $activeStore->last_synced_at : null,
            'config' => [
                'api_key' => config('services.shopify.api_key'),
                'redirect_uri' => config('services.shopify.redirect_uri'),
            ]
        ]);
    }

    /**
     * Webhook Handler for Shopify real-time order creation / updates / payment
     */
    public function handleOrderWebhook(Request $request)
    {
        $startTime = microtime(true);

        // 1. Extract payload from JSON body or raw request input
        $payload = $request->json()->all();
        if (empty($payload)) {
            $rawBody = $request->getContent();
            if (!empty($rawBody)) {
                $decoded = json_decode($rawBody, true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            }
        }
        if (empty($payload)) {
            $payload = $request->all();
        }

        // Unwrap { "order": { ... } } or { "data": { ... } } if nested
        if (isset($payload['order']) && is_array($payload['order'])) {
            $payload = $payload['order'];
        } elseif (isset($payload['data']) && is_array($payload['data'])) {
            $payload = $payload['data'];
        }

        // 2. Extract Shopify webhook headers
        $topic = $request->header('X-Shopify-Topic', 'orders/create');
        $shopDomain = $request->header('X-Shopify-Shop-Domain');
        $hmac = $request->header('X-Shopify-Hmac-Sha256');
        $webhookId = $request->header('X-Shopify-Webhook-Id');

        $headers = [
            'topic' => $topic,
            'shop_domain' => $shopDomain,
            'webhook_id' => $webhookId,
            'hmac_present' => !empty($hmac),
            'content_type' => $request->header('Content-Type'),
        ];

        // 3. Handle empty or ping payloads gracefully
        if (empty($payload) || (empty($payload['id']) && empty($payload['order_number']) && empty($payload['name']))) {
            $errMsg = 'Invalid or empty Shopify order webhook payload';

            $this->shopifyService->recordSyncLog([
                'event_type' => 'webhook_' . str_replace('/', '_', $topic),
                'topic' => $topic,
                'shop_domain' => $shopDomain,
                'status' => 'failed',
                'error_message' => $errMsg,
                'payload' => $payload,
                'headers' => $headers,
                'ip_address' => $request->ip(),
                'duration_ms' => (int)((microtime(true) - $startTime) * 1000),
            ]);

            return response()->json([
                'success' => false,
                'message' => $errMsg,
            ], 400);
        }

        try {
            // Process the order payload directly into local database
            $order = $this->shopifyService->processSingleOrderPayload($payload, $shopDomain);

            $durationMs = (int)((microtime(true) - $startTime) * 1000);
            $itemsCount = $order->items ? $order->items->count() : 0;

            // Record successful webhook execution
            $this->shopifyService->recordSyncLog([
                'event_type' => 'webhook_' . str_replace('/', '_', $topic),
                'topic' => $topic,
                'shop_domain' => $shopDomain,
                'shopify_order_id' => $payload['id'] ?? null,
                'order_number' => $order->order_number,
                'local_order_id' => $order->id,
                'status' => 'success',
                'payload' => $payload,
                'headers' => $headers,
                'ip_address' => $request->ip(),
                'items_count' => $itemsCount,
                'duration_ms' => $durationMs,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Order webhook processed successfully',
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'items_count' => $itemsCount,
            ], 200);
        } catch (\Throwable $e) {
            $durationMs = (int)((microtime(true) - $startTime) * 1000);
            Log::error("Shopify Order Webhook Exception: " . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => substr($e->getTraceAsString(), 0, 1000),
            ]);

            $this->shopifyService->recordSyncLog([
                'event_type' => 'webhook_' . str_replace('/', '_', $topic),
                'topic' => $topic,
                'shop_domain' => $shopDomain,
                'shopify_order_id' => $payload['id'] ?? null,
                'order_number' => $payload['name'] ?? ($payload['order_number'] ?? null),
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'error_details' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => substr($e->getTraceAsString(), 0, 1000),
                ],
                'payload' => $payload,
                'headers' => $headers,
                'ip_address' => $request->ip(),
                'duration_ms' => $durationMs,
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * API: List Sync & Webhook Logs
     */
    public function apiSyncLogs(Request $request)
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('shopify_sync_logs')) {
                return response()->json([
                    'success' => true,
                    'logs' => [],
                    'total' => 0,
                    'failed_count' => 0,
                    'success_count' => 0,
                ]);
            }

            $query = ShopifySyncLog::orderBy('created_at', 'desc');

            $status = $request->query('status');
            if ($status && in_array($status, ['success', 'failed', 'pending', 'skipped'])) {
                $query->where('status', $status);
            }

            $search = $request->query('search');
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('order_number', 'like', "%{$search}%")
                      ->orWhere('shopify_order_id', 'like', "%{$search}%")
                      ->orWhere('error_message', 'like', "%{$search}%")
                      ->orWhere('topic', 'like', "%{$search}%");
                });
            }

            $limit = min(100, max(1, (int)$request->query('limit', 50)));
            $logs = $query->limit($limit)->get();

            $totalCount = ShopifySyncLog::count();
            $failedCount = ShopifySyncLog::where('status', 'failed')->count();
            $successCount = ShopifySyncLog::where('status', 'success')->count();

            return response()->json([
                'success' => true,
                'logs' => $logs,
                'total' => $totalCount,
                'failed_count' => $failedCount,
                'success_count' => $successCount,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * API: Get Single Sync Log Detail
     */
    public function apiSyncLogDetail($id)
    {
        try {
            $log = ShopifySyncLog::with('order')->findOrFail($id);
            return response()->json([
                'success' => true,
                'log' => $log,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => 'Sync log not found: ' . $e->getMessage(),
            ], 404);
        }
    }

    /**
     * API: Retry processing a failed sync log
     */
    public function retrySyncLog($id)
    {
        try {
            $log = ShopifySyncLog::findOrFail($id);

            if (empty($log->payload) || !is_array($log->payload)) {
                return response()->json([
                    'success' => false,
                    'error' => 'No order payload available in this log to retry.',
                ], 400);
            }

            $order = $this->shopifyService->processSingleOrderPayload($log->payload, $log->shop_domain);

            $log->update([
                'status' => 'success',
                'local_order_id' => $order->id,
                'order_number' => $order->order_number,
                'items_count' => $order->items ? $order->items->count() : 0,
                'error_message' => null,
            ]);

            return response()->json([
                'success' => true,
                'message' => "Order #{$order->order_number} re-synced successfully.",
                'order_id' => $order->id,
                'order_number' => $order->order_number,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => 'Retry failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * API: Clear all or old sync logs
     */
    public function clearSyncLogs(Request $request)
    {
        try {
            $days = $request->input('days');
            if ($days && is_numeric($days)) {
                $deleted = ShopifySyncLog::where('created_at', '<', now()->subDays((int)$days))->delete();
            } else {
                $deleted = ShopifySyncLog::truncate();
            }

            return response()->json([
                'success' => true,
                'message' => 'Sync logs cleared successfully.',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}

