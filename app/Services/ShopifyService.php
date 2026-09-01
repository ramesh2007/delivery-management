<?php

namespace App\Services;

use App\Models\ShopifyStore;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusLog;
use App\Models\OrderPayment;
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
     * Fetch single product details from Shopify API by Product ID
     */
    public function getProduct(string $productId, ?string $shop = null, ?string $accessToken = null): array
    {
        $store = $this->resolveCredentials($shop, $accessToken);
        if (!$store['success']) {
            return $store;
        }

        $shopDomain = $store['shop'];
        $token = $store['access_token'];
        $url = "https://{$shopDomain}/admin/api/{$this->apiVersion}/products/{$productId}.json";

        try {
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $token,
                'Content-Type' => 'application/json',
            ])->get($url);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'shop' => $shopDomain,
                    'product' => $response->json('product', []),
                    'raw' => $response->json(),
                ];
            }

            return [
                'success' => false,
                'shop' => $shopDomain,
                'error' => $response->json('errors') ?? $response->body(),
                'status_code' => $response->status(),
            ];
        } catch (\Exception $e) {
            Log::error("Shopify API getProduct Exception: " . $e->getMessage());
            return [
                'success' => false,
                'shop' => $shopDomain,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Helper to extract product image URL from Shopify Product payload
     */
    public function extractProductImageUrl(array $product, ?string $variantId = null): ?string
    {
        // 1. Try to find variant-specific image if variant_id provided
        if ($variantId && !empty($product['images']) && is_array($product['images'])) {
            foreach ($product['images'] as $img) {
                if (!empty($img['variant_ids']) && is_array($img['variant_ids']) && in_array((int)$variantId, $img['variant_ids'])) {
                    if (!empty($img['src'])) {
                        return $img['src'];
                    }
                }
            }
        }

        // 2. Check main product image object
        if (!empty($product['image'])) {
            if (is_array($product['image']) && !empty($product['image']['src'])) {
                return $product['image']['src'];
            } elseif (is_string($product['image'])) {
                return $product['image'];
            }
        }

        // 3. Fallback to first image in images array
        if (!empty($product['images']) && is_array($product['images']) && isset($product['images'][0])) {
            $firstImg = $product['images'][0];
            if (is_array($firstImg) && !empty($firstImg['src'])) {
                return $firstImg['src'];
            } elseif (is_string($firstImg)) {
                return $firstImg;
            }
        }

        return null;
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
        $productImageMap = [];

        foreach ($ordersResult['orders'] as $shopifyOrder) {
            $order = $this->processSingleOrderPayload($shopifyOrder, $shop, $accessToken, $productImageMap);
            $syncedOrdersCount++;
            $syncedItemsCount += $order->items->count();
        }

        return [
            'success' => true,
            'message' => "Synced {$syncedOrdersCount} orders and {$syncedItemsCount} items from Shopify to database.",
            'orders_count' => $syncedOrdersCount,
            'items_count' => $syncedItemsCount,
        ];
    }

    /**
     * Process and save a single Shopify order payload (e.g. from Webhook or Real-time Placement) into database
     */
    public function processSingleOrderPayload(array $shopifyOrder, ?string $shop = null, ?string $accessToken = null, array &$productImageMap = []): Order
    {
        $rawId = isset($shopifyOrder['id']) ? (string) $shopifyOrder['id'] : null;
        $rawOrderNum = isset($shopifyOrder['order_number']) ? (string) $shopifyOrder['order_number'] : null;
        $rawName = isset($shopifyOrder['name']) ? (string) $shopifyOrder['name'] : null;

        $shopifyOrderId = $rawId ?: ($rawOrderNum ?: ($rawName ?: ('ORD-' . time())));

        // Gather all candidate identifiers to find existing order without creating duplicates
        $candidateNumbers = array_values(array_unique(array_filter([
            $rawId,
            $rawOrderNum,
            $rawName,
            $rawOrderNum ? '#' . ltrim($rawOrderNum, '#') : null,
            $rawOrderNum ? ltrim($rawOrderNum, '#') : null,
            $rawName ? '#' . ltrim($rawName, '#') : null,
            $rawName ? ltrim($rawName, '#') : null,
        ])));

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

        // Query existing orders matching any candidate order identifier
        $existingOrders = Order::whereIn('order_number', $candidateNumbers)->get();

        if ($existingOrders->count() > 1) {
            // Keep first order, merge duplicate orders if any exist in database
            $order = $existingOrders->first();
            foreach ($existingOrders->slice(1) as $dupOrder) {
                OrderItem::where('order_id', $dupOrder->id)->update(['order_id' => $order->id]);
                OrderStatusLog::where('order_id', $dupOrder->id)->update(['order_id' => $order->id]);
                $dupOrder->delete();
            }
        } else {
            $order = $existingOrders->first();
        }

        if (!$order) {
            $order = Order::create([
                'order_number' => $shopifyOrderId,
                'customer_name' => $customerName,
                'customer_phone' => $customerPhone,
                'delivery_address' => $shippingAddress,
                'total_amount' => $shopifyOrder['total_price'] ?? 0.00,
                'status' => 'pending',
            ]);

            OrderStatusLog::create([
                'order_id' => $order->id,
                'action' => 'shopify_synced',
                'new_status' => 'pending',
                'notes' => "Synced from Shopify Webhook/Payload as New Order (Order Number: {$shopifyOrderId})",
            ]);
        } else {
            $order->update([
                'customer_name' => $customerName ?: $order->customer_name,
                'customer_phone' => $customerPhone ?: $order->customer_phone,
                'delivery_address' => $shippingAddress ?: $order->delivery_address,
                'total_amount' => $shopifyOrder['total_price'] ?? $order->total_amount,
            ]);
        }

        // Sync payment details into order_payments table
        $this->syncOrderPayment($order, $shopifyOrder);

        if (!empty($shopifyOrder['line_items'])) {
            foreach ($shopifyOrder['line_items'] as $item) {
                $lineItemId = !empty($item['id']) ? (string) $item['id'] : null;
                $sku = !empty($item['sku']) ? trim((string) $item['sku']) : null;
                $productIdStr = !empty($item['product_id']) ? (string) $item['product_id'] : null;

                // Deterministic product code, avoiding rand() duplicates
                if (!empty($sku)) {
                    $productCode = $sku;
                } elseif (!empty($productIdStr)) {
                    $productCode = 'PROD-' . $productIdStr;
                } elseif (!empty($lineItemId)) {
                    $productCode = 'LINE-' . $lineItemId;
                } else {
                    $productCode = 'ITEM-' . md5(($item['title'] ?? $item['name'] ?? 'product') . ($item['price'] ?? 0));
                }

                $barcode = !empty($item['barcode']) ? $item['barcode'] : ($sku ?: $productCode);

                // 1. Precise lookup by line_item_id first to prevent cross-item contamination or duplication
                $orderItem = null;
                if (!empty($lineItemId)) {
                    $existingItems = OrderItem::where('order_id', $order->id)
                        ->where('line_item_id', $lineItemId)
                        ->get();

                    if ($existingItems->count() > 1) {
                        $orderItem = $existingItems->first();
                        // Clean up existing duplicates from previous buggy syncs
                        foreach ($existingItems->slice(1) as $dup) {
                            $dup->delete();
                        }
                    } else {
                        $orderItem = $existingItems->first();
                    }
                }

                // 2. Fallback to product_code for items where line_item_id is NULL
                if (!$orderItem) {
                    $existingItems = OrderItem::where('order_id', $order->id)
                        ->whereNull('line_item_id')
                        ->where('product_code', $productCode)
                        ->get();

                    if ($existingItems->count() > 0) {
                        $orderItem = $existingItems->first();
                        if ($existingItems->count() > 1) {
                            foreach ($existingItems->slice(1) as $dup) {
                                $dup->delete();
                            }
                        }
                    }
                }

                $itemImage = null;
                if (!empty($item['image'])) {
                    $itemImage = is_array($item['image']) ? ($item['image']['src'] ?? null) : $item['image'];
                } elseif (!empty($item['image_url'])) {
                    $itemImage = $item['image_url'];
                } elseif (!empty($item['featured_image'])) {
                    $itemImage = is_array($item['featured_image']) ? ($item['featured_image']['src'] ?? null) : $item['featured_image'];
                }

                if (empty($itemImage) && $orderItem && !empty($orderItem->image)) {
                    $itemImage = $orderItem->image;
                }

                if (empty($itemImage) && !empty($productIdStr)) {
                    $variantId = !empty($item['variant_id']) ? (string) $item['variant_id'] : null;

                    if (array_key_exists($productIdStr, $productImageMap)) {
                        $itemImage = $productImageMap[$productIdStr];
                    } else {
                        $productResult = $this->getProduct($productIdStr, $shop, $accessToken);
                        if ($productResult['success'] && !empty($productResult['product'])) {
                            $itemImage = $this->extractProductImageUrl($productResult['product'], $variantId);
                            $productImageMap[$productIdStr] = $itemImage;
                        } else {
                            $productImageMap[$productIdStr] = null;
                        }
                    }
                }

                if (!$orderItem) {
                    $orderItem = OrderItem::create([
                        'order_id' => $order->id,
                        'line_item_id' => $lineItemId,
                        'product_id' => $productIdStr ?: '',
                        'product_code' => $productCode,
                        'barcode' => $barcode,
                        'product_name' => $item['title'] ?? $item['name'] ?? 'Product',
                        'image' => $itemImage,
                        'quantity' => $item['quantity'] ?? 1,
                        'unit_price' => $item['price'] ?? 0.00,
                        'status' => 'pending',
                    ]);
                } else {
                    $orderItem->update([
                        'line_item_id' => $lineItemId ?: $orderItem->line_item_id,
                        'product_id' => $productIdStr ?: $orderItem->product_id,
                        'barcode' => $barcode ?: $orderItem->barcode,
                        'product_name' => $item['title'] ?? $item['name'] ?? $orderItem->product_name,
                        'image' => $itemImage ?: $orderItem->image,
                        'quantity' => $item['quantity'] ?? $orderItem->quantity,
                        'unit_price' => $item['price'] ?? $orderItem->unit_price,
                    ]);
                }
            }
        }

        return $order->fresh(['items', 'payment']);
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

    /**
     * Parse and sync payment details from Shopify order payload into order_payments table and order model.
     */
    public function syncOrderPayment(Order $order, array $shopifyOrder): OrderPayment
    {
        $paymentMethod = null;
        if (!empty($shopifyOrder['payment_gateway_names'])) {
            if (is_array($shopifyOrder['payment_gateway_names'])) {
                $paymentMethod = implode(', ', array_filter($shopifyOrder['payment_gateway_names']));
            } else {
                $paymentMethod = (string) $shopifyOrder['payment_gateway_names'];
            }
        } elseif (!empty($shopifyOrder['gateway'])) {
            $paymentMethod = (string) $shopifyOrder['gateway'];
        }

        $financialStatus = $shopifyOrder['financial_status'] ?? null;
        $totalPrice = (float) ($shopifyOrder['total_price'] ?? $shopifyOrder['current_total_price'] ?? 0.00);
        $totalOutstanding = (float) ($shopifyOrder['total_outstanding'] ?? 0.00);

        if (strtolower((string) $financialStatus) === 'paid') {
            $paidAmount = max($totalPrice - $totalOutstanding, $totalPrice);
        } else {
            $paidAmount = max(0.00, $totalPrice - $totalOutstanding);
        }

        $currency = $shopifyOrder['currency'] ?? $shopifyOrder['presentment_currency'] ?? 'QAR';
        $shopifyOrderId = (string) ($shopifyOrder['id'] ?? $shopifyOrder['admin_graphql_api_id'] ?? '');

        $processedAt = !empty($shopifyOrder['processed_at']) ? date('Y-m-d H:i:s', strtotime($shopifyOrder['processed_at'])) : null;
        $createdAt = !empty($shopifyOrder['created_at']) ? date('Y-m-d H:i:s', strtotime($shopifyOrder['created_at'])) : null;
        $updatedAt = !empty($shopifyOrder['updated_at']) ? date('Y-m-d H:i:s', strtotime($shopifyOrder['updated_at'])) : null;

        $orderPayment = OrderPayment::updateOrCreate(
            ['order_id' => $order->id],
            [
                'shopify_order_id' => $shopifyOrderId,
                'payment_method' => $paymentMethod,
                'payment_status' => $financialStatus,
                'paid_amount' => $paidAmount,
                'total_price' => $totalPrice,
                'total_outstanding' => $totalOutstanding,
                'currency' => $currency,
                'processed_at' => $processedAt,
                'shopify_created_at' => $createdAt,
                'shopify_updated_at' => $updatedAt,
                'raw_payment_details' => [
                    'payment_gateway_names' => $shopifyOrder['payment_gateway_names'] ?? [],
                    'financial_status' => $financialStatus,
                    'total_price' => $totalPrice,
                    'total_outstanding' => $totalOutstanding,
                    'currency' => $currency,
                ],
            ]
        );

        // Also update parent Order model's payment columns for compatibility
        $order->update([
            'payment_method' => $paymentMethod ?: $order->payment_method,
            'payment_status' => $financialStatus ?: $order->payment_status,
            'collected_amount' => $paidAmount,
        ]);

        return $orderPayment;
    }
}

