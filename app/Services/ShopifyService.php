<?php

namespace App\Services;

use App\Models\ShopifyStore;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShopifyService
{
    protected string $apiKey;
    protected string $apiSecret;
    protected string $scopes;
    protected string $redirectUri;
    protected string $apiVersion = '2026-07';

    public function __construct()
    {
        $this->apiKey = config('services.shopify.api_key', '');
        $this->apiSecret = config('services.shopify.api_secret', '');
        $this->scopes = config('services.shopify.scopes', 'read_products,read_orders');
        $this->redirectUri = config('services.shopify.redirect_uri', '');
    }

    /**
     * Clean and normalize shop domain to xxx.myshopify.com
     */
    public function sanitizeShopDomain(?string $shop): string
    {
        if (!$shop) {
            return '';
        }
        $shop = trim(strtolower($shop));
        $shop = preg_replace('/^https?:\/\//i', '', $shop);
        $shop = rtrim($shop, '/');

        if (!str_contains($shop, '.myshopify.com')) {
            $shop .= '.myshopify.com';
        }

        return $shop;
    }

    /**
     * Generate OAuth Install / Authorization URL for a shop
     */
    public function getAuthUrl(string $shop, string $state = ''): string
    {
        $shop = $this->sanitizeShopDomain($shop);
        $query = http_build_query([
            'client_id' => $this->apiKey,
            'scope' => $this->scopes,
            'redirect_uri' => $this->redirectUri,
            'state' => $state ?: csrf_token(),
        ]);

        return "https://{$shop}/admin/oauth/authorize?{$query}";
    }

    /**
     * Verify HMAC signature from Shopify OAuth Callback
     */
    public function verifyHmac(array $params): bool
    {
        if (empty($params['hmac'])) {
            return false;
        }

        $hmac = $params['hmac'];
        $paramsCheck = $params;
        unset($paramsCheck['hmac'], $paramsCheck['signature']);

        ksort($paramsCheck);

        $computedHmac = hash_hmac('sha256', http_build_query($paramsCheck), $this->apiSecret);

        return hash_equals($hmac, $computedHmac);
    }

    /**
     * Exchange temporary authorization code for permanent access token
     */
    // public function exchangeCodeForToken(string $shop, string $code): array
    // {
    //     $shop = $this->sanitizeShopDomain($shop);
    //     $url = "https://{$shop}/admin/oauth/access_token";

    //     try {
    //         $response = Http::post($url, [
    //             'client_id' => $this->apiKey,
    //             'client_secret' => $this->apiSecret,
    //             'code' => $code,
    //         ]);

    //         if ($response->successful()) {
    //             $data = $response->json();
    //             return [
    //                 'success' => true,
    //                 'access_token' => $data['access_token'] ?? null,
    //                 'scopes' => $data['scope'] ?? $this->scopes,
    //             ];
    //         }

    //         return [
    //             'success' => false,
    //             'error' => $response->json('error_description') ?? $response->body(),
    //         ];
    //     } catch (\Exception $e) {
    //         Log::error("Shopify OAuth Token Exchange Failed: " . $e->getMessage());
    //         return [
    //             'success' => false,
    //             'error' => $e->getMessage(),
    //         ];
    //     }
    // }
public function exchangeCodeForToken(string $shop, string $code): array
{
    $shop = $this->sanitizeShopDomain($shop);

    $url = "https://{$shop}/admin/oauth/access_token";

    $payload = [
        'client_id' => $this->apiKey,
        'client_secret' => $this->apiSecret,
        'code' => $code,
    ];

    Log::info('Shopify Token Request', [
        'url' => $url,
        'payload' => $payload,
    ]);

    $response = Http::post($url, $payload);

    Log::info('Shopify Token Response', [
        'status' => $response->status(),
        'body' => $response->body(),
    ]);

    if ($response->successful()) {
        $data = $response->json();

        return [
            'success' => true,
            'access_token' => $data['access_token'],
            'scopes' => $data['scope'],
        ];
    }

    return [
        'success' => false,
        'error' => $response->body(),
    ];
}
    /**
     * Get current active shopify store from DB
     */
    public function getActiveStore(): ?ShopifyStore
    {
        return ShopifyStore::where('is_active', true)->orderBy('updated_at', 'desc')->first();
    }

    /**
     * Fetch products list from Shopify API
     */
    public function getProducts(?string $shop = null, ?string $accessToken = null, array $params = []): array
    {
        $store = $this->resolveCredentials($shop, $accessToken);
        if (!$store['success']) {
            return $store;
        }

        $shopDomain = $store['shop'];
        $token = $store['access_token'];
        $url = "https://{$shopDomain}/admin/api/{$this->apiVersion}/products.json";

        try {
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $token,
                'Content-Type' => 'application/json',
            ])->get($url, array_merge(['limit' => 50], $params));

            if ($response->successful()) {
                return [
                    'success' => true,
                    'shop' => $shopDomain,
                    'products' => $response->json('products', []),
                    'raw' => $response->json(),
                ];
            }

            $errorMsg = $response->json('errors') ?? $response->body();
            if ($response->status() === 401) {
                $errorMsg = "[API 401 Unauthorized] Invalid Admin API Access Token. Note: 'SHOPIFY_API_SECRET' (shpss_...) is your App Secret Key used for OAuth, NOT an Admin Access Token. Please click 'Connect OAuth' to authorize your store, or set SHOPIFY_ACCESS_TOKEN (shpat_...) in .env.";
            }

            return [
                'success' => false,
                'shop' => $shopDomain,
                'error' => $errorMsg,
                'status_code' => $response->status(),
            ];
        } catch (\Exception $e) {
            Log::error("Shopify API getProducts Exception: " . $e->getMessage());
            return [
                'success' => false,
                'shop' => $shopDomain,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Fetch orders list from Shopify API
     */
    public function getOrders(?string $shop = null, ?string $accessToken = null, array $params = []): array
    {
        $store = $this->resolveCredentials($shop, $accessToken);
        if (!$store['success']) {
            return $store;
        }

        $shopDomain = $store['shop'];
        $token = $store['access_token'];
        $url = "https://{$shopDomain}/admin/api/{$this->apiVersion}/orders.json";

        try {
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $token,
                'Content-Type' => 'application/json',
            ])->get($url, array_merge([
                'status' => 'any',
                'limit' => 50,
            ], $params));

            if ($response->successful()) {
                return [
                    'success' => true,
                    'shop' => $shopDomain,
                    'orders' => $response->json('orders', []),
                    'raw' => $response->json(),
                ];
            }

            $errorMsg = $response->json('errors') ?? $response->body();
            if ($response->status() === 401) {
                $errorMsg = "[API 401 Unauthorized] Invalid Admin API Access Token. Note: 'SHOPIFY_API_SECRET' (shpss_...) is your App Secret Key used for OAuth, NOT an Admin Access Token. Please click 'Connect OAuth' to authorize your store, or set SHOPIFY_ACCESS_TOKEN (shpat_...) in .env.";
            } elseif (is_string($errorMsg) && str_contains(strtolower($errorMsg), 'protected customer data')) {
                $errorMsg = "[Protected Customer Data Error] Shopify requires app approval for accessing customer personal data. To resolve:\n1. Go to Shopify Admin -> Settings -> Apps and developer channels -> Develop apps -> [Your App] -> Configuration -> Protected Customer Data and select 'Order fulfillment / Delivery'.\n2. Or filter fields using ?fields=id,name,order_number,created_at,total_price,currency,financial_status,fulfillment_status,line_items.";
            }

            return [
                'success' => false,
                'shop' => $shopDomain,
                'error' => $errorMsg,
                'status_code' => $response->status(),
            ];
        } catch (\Exception $e) {
            Log::error("Shopify API getOrders Exception: " . $e->getMessage());
            return [
                'success' => false,
                'shop' => $shopDomain,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Sync orders from Shopify API into database tables (`orders` and `order_items`) with default 'pending' status
     */
    public function syncOrdersToDatabase(?string $shop = null, ?string $accessToken = null): array
    {
        $ordersResult = $this->getOrders($shop, $accessToken);
        if (!$ordersResult['success']) {
            return $ordersResult;
        }

        $syncedOrdersCount = 0;
        $syncedItemsCount = 0;

        foreach ($ordersResult['orders'] as $shopifyOrder) {
            // Store exact Shopify Order ID as order_number
            $shopifyOrderId = (string) ($shopifyOrder['id'] ?? $shopifyOrder['order_number'] ?? $shopifyOrder['name']);
            $orderNumber = $shopifyOrderId;
            
            $customerName = null;
            if (!empty($shopifyOrder['customer'])) {
                $customerName = trim(($shopifyOrder['customer']['first_name'] ?? '') . ' ' . ($shopifyOrder['customer']['last_name'] ?? ''));
            }
            $customerPhone = $shopifyOrder['phone'] ?? $shopifyOrder['customer']['phone'] ?? $shopifyOrder['shipping_address']['phone'] ?? null;
            
            $shippingAddress = null;
            if (!empty($shopifyOrder['shipping_address'])) {
                $addr = $shopifyOrder['shipping_address'];
                $shippingAddress = implode(', ', array_filter([
                    $addr['address1'] ?? null,
                    $addr['address2'] ?? null,
                    $addr['city'] ?? null,
                    $addr['province'] ?? null,
                    $addr['zip'] ?? null,
                    $addr['country'] ?? null,
                ]));
            }

            // Find existing order by Shopify Order ID or order_number
            $order = Order::where('order_number', $shopifyOrderId)
                ->orWhere('order_number', (string) ($shopifyOrder['name'] ?? ''))
                ->orWhere('order_number', (string) ($shopifyOrder['order_number'] ?? ''))
                ->first();

            if (!$order) {
                $order = Order::create([
                    'order_number' => $shopifyOrderId,
                    'customer_name' => $customerName,
                    'customer_phone' => $customerPhone,
                    'delivery_address' => $shippingAddress,
                    'total_amount' => $shopifyOrder['total_price'] ?? 0.00,
                    'status' => 'pending', // Default status for new orders
                ]);

                OrderStatusLog::create([
                    'order_id' => $order->id,
                    'action' => 'shopify_synced',
                    'new_status' => 'pending',
                    'notes' => "Synced from Shopify API as New Order (Shopify Order ID: {$shopifyOrderId})",
                ]);
            } else {
                // Update customer & amount details, update order_number to Shopify Order ID, but KEEP existing status intact
                $order->update([
                    'order_number' => $shopifyOrderId,
                    'customer_name' => $customerName ?: $order->customer_name,
                    'customer_phone' => $customerPhone ?: $order->customer_phone,
                    'delivery_address' => $shippingAddress ?: $order->delivery_address,
                    'total_amount' => $shopifyOrder['total_price'] ?? $order->total_amount,
                ]);
            }

            $syncedOrdersCount++;

            if (!empty($shopifyOrder['line_items'])) {
                foreach ($shopifyOrder['line_items'] as $item) {
                    $lineItemId = (string) ($item['id'] ?? '');
                    $productCode = $item['sku'] ?: ('SKU-' . ($item['product_id'] ?? $item['id']));
                    $barcode = $item['barcode'] ?? $item['sku'] ?? $productCode;

                    $orderItem = OrderItem::where('order_id', $order->id)
                        ->where(function ($q) use ($lineItemId, $productCode) {
                            if (!empty($lineItemId)) {
                                $q->where('line_item_id', $lineItemId)->orWhere('product_code', $productCode);
                            } else {
                                $q->where('product_code', $productCode);
                            }
                        })
                        ->first();

                    if (!$orderItem) {
                        OrderItem::create([
                            'order_id' => $order->id,
                            'line_item_id' => $lineItemId,
                            'product_id' => (string) ($item['product_id'] ?? ''),
                            'product_code' => $productCode,
                            'barcode' => $barcode,
                            'product_name' => $item['title'] ?? $item['name'] ?? 'Product',
                            'quantity' => $item['quantity'] ?? 1,
                            'unit_price' => $item['price'] ?? 0.00,
                            'status' => 'pending', // Default status for new item
                        ]);
                    } else {
                        // Update details including line_item_id without resetting status or timestamps
                        $orderItem->update([
                            'line_item_id' => $lineItemId ?: $orderItem->line_item_id,
                            'product_id' => (string) ($item['product_id'] ?? $orderItem->product_id),
                            'barcode' => $barcode ?: $orderItem->barcode,
                            'product_name' => $item['title'] ?? $item['name'] ?? $orderItem->product_name,
                            'quantity' => $item['quantity'] ?? $orderItem->quantity,
                            'unit_price' => $item['price'] ?? $orderItem->unit_price,
                        ]);
                    }

                    $syncedItemsCount++;
                }
            }
        }

        return [
            'success' => true,
            'message' => "Synced {$syncedOrdersCount} orders and {$syncedItemsCount} items from Shopify to database.",
            'orders_count' => $syncedOrdersCount,
            'items_count' => $syncedItemsCount,
        ];
    }

    /**
     * Resolve shop domain and access token from parameters, database, or .env configuration
     */
    protected function resolveCredentials(?string $shop = null, ?string $accessToken = null): array
    {
        // 1. Explicitly passed parameters
        if ($shop && $accessToken) {
            return [
                'success' => true,
                'shop' => $this->sanitizeShopDomain($shop),
                'access_token' => $accessToken,
            ];
        }

        // 2. Active store from database
        $activeStore = $this->getActiveStore();
        if ($activeStore && $activeStore->shop && $activeStore->access_token) {
            return [
                'success' => true,
                'shop' => $activeStore->shop,
                'access_token' => $activeStore->access_token,
            ];
        }

        // 3. Fallback from .env configuration
        $envShop = config('services.shopify.shop_domain') ?: $shop;
        //$envToken = config('services.shopify.access_token') ?: config('services.shopify.api_secret');
        $envToken = config('services.shopify.access_token');
        if ($envShop && $envToken) {
            return [
                'success' => true,
                'shop' => $this->sanitizeShopDomain($envShop),
                'access_token' => $envToken,
            ];
        }

        return [
            'success' => false,
            'error' => 'No active Shopify store connected. Please connect a shop via OAuth or set SHOPIFY_SHOP_DOMAIN and SHOPIFY_ACCESS_TOKEN in .env.',
        ];
    }
}
