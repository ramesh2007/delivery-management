<?php

namespace App\Services;

use App\Models\ShopifyStore;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusLog;
use App\Models\OrderPayment;
use App\Models\OrderInstallation;
use App\Models\ShopifySyncLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

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
    // public function getProducts(?string $shop = null, ?string $accessToken = null, array $params = []): array
    // {
    //     $store = $this->resolveCredentials($shop, $accessToken);
    //     if (!$store['success']) {
    //         return $store;
    //     }

    //     $shopDomain = $store['shop'];
    //     $token = $store['access_token'];
    //     $url = "https://{$shopDomain}/admin/api/{$this->apiVersion}/products.json";

    //     try {
    //         $response = Http::withHeaders([
    //             'X-Shopify-Access-Token' => $token,
    //             'Content-Type' => 'application/json',
    //         ])->get($url, array_merge(['limit' => 50], $params));

    //         if ($response->successful()) {
    //             return [
    //                 'success' => true,
    //                 'shop' => $shopDomain,
    //                 'products' => $response->json('products', []),
    //                 'raw' => $response->json(),
    //             ];
    //         }

    //         $errorMsg = $response->json('errors') ?? $response->body();
    //         if ($response->status() === 401) {
    //             $errorMsg = "[API 401 Unauthorized] Invalid Admin API Access Token. Note: 'SHOPIFY_API_SECRET' (shpss_...) is your App Secret Key used for OAuth, NOT an Admin Access Token. Please click 'Connect OAuth' to authorize your store, or set SHOPIFY_ACCESS_TOKEN (shpat_...) in .env.";
    //         }

    //         return [
    //             'success' => false,
    //             'shop' => $shopDomain,
    //             'error' => $errorMsg,
    //             'status_code' => $response->status(),
    //         ];
    //     } catch (\Exception $e) {
    //         Log::error("Shopify API getProducts Exception: " . $e->getMessage());
    //         return [
    //             'success' => false,
    //             'shop' => $shopDomain,
    //             'error' => $e->getMessage(),
    //         ];
    //     }
    // }
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
        ])->get($url, array_merge([
            'limit' => 50
        ], $params));

        if ($response->successful()) {

            $products = $response->json('products', []);

            // Get metafields for each product
            foreach ($products as &$product) {

                $productId = $product['id'];

                $metafieldUrl = "https://{$shopDomain}/admin/api/{$this->apiVersion}/products/{$productId}/metafields.json";

                $metafieldResponse = Http::withHeaders([
                    'X-Shopify-Access-Token' => $token,
                    'Content-Type' => 'application/json',
                ])->get($metafieldUrl);

                if ($metafieldResponse->successful()) {
                    $product['metafields'] = $metafieldResponse->json('metafields', []);
                } else {
                    $product['metafields'] = [];
                }
            }

            return [
                'success' => true,
                'shop' => $shopDomain,
                'products' => $products,
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
     * Send GET request to Shopify API with automatic 429 rate limit backoff and retry
     */
    public function executeShopifyGetWithRetry(string $url, string $token, array $params = [], int $retries = 3, int $timeout = 10): \Illuminate\Http\Client\Response
    {
        for ($i = 0; $i < $retries; $i++) {
            $response = Http::timeout($timeout)->withHeaders([
                'X-Shopify-Access-Token' => $token,
                'Content-Type' => 'application/json',
            ])->get($url, $params);

            if ($response->status() === 429 && $i < $retries - 1) {
                $retryAfter = (float) ($response->header('Retry-After') ?? 2.0);
                usleep((int) (max(1.0, $retryAfter) * 1000000));
                continue;
            }

            return $response;
        }

        return $response;
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
        $cleanProductId = ltrim((string) $productId, '#');
        $url = "https://{$shopDomain}/admin/api/{$this->apiVersion}/products/{$cleanProductId}.json";

        try {
            $response = $this->executeShopifyGetWithRetry($url, $token, [], 3, 10);

            if ($response->successful()) {
                $product = $response->json('product', []);
                return [
                    'success' => true,
                    'shop' => $shopDomain,
                    'product' => $product,
                    'raw' => $response->json(),
                ];
            }

            return [
                'success' => false,
                'shop' => $shopDomain,
                'error' => $response->json('errors') ?? $response->body(),
                'status_code' => $response->status(),
            ];
        } catch (\Throwable $e) {
            Log::error("Shopify API getProduct Exception: " . $e->getMessage());
            return [
                'success' => false,
                'shop' => $shopDomain,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Fetch product metafields from Shopify API by Product ID
     */
    public function getProductMetafields(string $productId, ?string $shop = null, ?string $accessToken = null): array
    {
        $store = $this->resolveCredentials($shop, $accessToken);
        if (!$store['success']) {
            return [];
        }

        $shopDomain = $store['shop'];
        $token = $store['access_token'];
        $cleanProductId = ltrim((string) $productId, '#');
        $url = "https://{$shopDomain}/admin/api/{$this->apiVersion}/products/{$cleanProductId}/metafields.json";

        try {
            $response = $this->executeShopifyGetWithRetry($url, $token, [], 3, 10);

            if ($response->successful()) {
                return $response->json('metafields', []);
            }
        } catch (\Throwable $e) {
            Log::error("Shopify API getProductMetafields Exception: " . $e->getMessage());
        }

        return [];
    }

    /**
     * Fetch variant metafields from Shopify API by Variant ID
     */
    public function getVariantMetafields(string $variantId, ?string $shop = null, ?string $accessToken = null): array
    {
        $store = $this->resolveCredentials($shop, $accessToken);
        if (!$store['success']) {
            return [];
        }

        $shopDomain = $store['shop'];
        $token = $store['access_token'];
        $cleanVariantId = ltrim((string) $variantId, '#');
        $url = "https://{$shopDomain}/admin/api/{$this->apiVersion}/variants/{$cleanVariantId}/metafields.json";

        try {
            $response = $this->executeShopifyGetWithRetry($url, $token, [], 3, 10);

            if ($response->successful()) {
                return $response->json('metafields', []);
            }
        } catch (\Throwable $e) {
            Log::error("Shopify API getVariantMetafields Exception: " . $e->getMessage());
        }

        return [];
    }

    /**
     * Helper to reliably parse and extract installation fields from metafields & line item properties
     */
    public function extractInstallationMetafields($metafields, array $properties = []): array
    {
        $installationLevel = null;
        $installationTypeVal = null;
        $hasInstallationMetafield = false;

        // 1. Check line item custom properties (e.g. from storefront or checkout)
        if (!empty($properties) && is_array($properties)) {
            foreach ($properties as $prop) {
                if (is_array($prop)) {
                    $name = strtolower(trim(str_replace(['_', ' ', '-'], '', (string)($prop['name'] ?? ''))));
                    $val = $prop['value'] ?? null;
                    if ($name === 'installationtype' || $name === 'installtype') {
                        $hasInstallationMetafield = true;
                        $installationTypeVal = $val;
                    } elseif ($name === 'installationlevel' || $name === 'installlevel') {
                        $hasInstallationMetafield = true;
                        $installationLevel = $val;
                    }
                }
            }
        }

        // 2. Check metafields list / dictionary
        if (!empty($metafields)) {
            if (is_array($metafields)) {
                // Key-value format: ['installation_level' => 'level-1', 'installation_type' => true]
                if (isset($metafields['installation_level']) || isset($metafields['installation_type'])) {
                    $hasInstallationMetafield = true;
                    if (isset($metafields['installation_level'])) {
                        $installationLevel = $metafields['installation_level'];
                    }
                    if (isset($metafields['installation_type'])) {
                        $installationTypeVal = $metafields['installation_type'];
                    }
                } else {
                    // Standard Shopify metafield objects: [ ['namespace' => 'custom', 'key' => 'installation_type', 'value' => true], ... ]
                    foreach ($metafields as $mf) {
                        if (is_array($mf)) {
                            $key = strtolower(trim(str_replace(['_', ' ', '-'], '', (string)($mf['key'] ?? ''))));
                            $val = $mf['value'] ?? null;

                            if ($key === 'installationlevel' || $key === 'installlevel') {
                                $hasInstallationMetafield = true;
                                $installationLevel = $val;
                            } elseif ($key === 'installationtype' || $key === 'installtype' || $key === 'isinstallable' || $key === 'installation') {
                                $hasInstallationMetafield = true;
                                $installationTypeVal = $val;
                            }
                        }
                    }
                }
            }
        }

        // 3. Resolve boolean installable status
        $isInstallable = false;
        if ($installationTypeVal === true || $installationTypeVal === 1 || $installationTypeVal === 'true' || $installationTypeVal === '1') {
            $isInstallable = true;
        } elseif (!empty($installationLevel) && !in_array(strtolower((string)$installationLevel), ['false', '0', 'null', 'none', 'no'])) {
            $isInstallable = true;
        } elseif (is_string($installationTypeVal) && !empty($installationTypeVal) && !in_array(strtolower($installationTypeVal), ['false', '0', 'null', 'none', 'no'])) {
            $isInstallable = true;
        }

        // 4. Resolve normalized installation type string
        $finalInstallationType = null;
        if ($isInstallable) {
            if (is_string($installationTypeVal) && !in_array(strtolower($installationTypeVal), ['true', '1', 'false', '0', 'null', 'none', 'no'])) {
                $finalInstallationType = $installationTypeVal;
            } elseif (!empty($installationLevel)) {
                $finalInstallationType = (string)$installationLevel;
            } else {
                $finalInstallationType = 'standard';
            }
        }

        return [
            'has_installation_metafield' => $hasInstallationMetafield,
            'is_installable' => $isInstallable,
            'installation_type' => $finalInstallationType,
            'installation_level' => $installationLevel ? (string)$installationLevel : null,
        ];
    }

    /**
     * Enrich orders and their line items with product/variant metafields (installation type, installation level)
     */
    public function enrichOrdersWithMetafields(array $orders, ?string $shop = null, ?string $accessToken = null): array
    {
        if (empty($orders)) {
            return [];
        }

        $productMetafieldsMap = [];
        $variantMetafieldsMap = [];
        $productTagsMap = [];

        foreach ($orders as &$order) {
            if (empty($order['line_items']) || !is_array($order['line_items'])) {
                continue;
            }

            foreach ($order['line_items'] as &$item) {
                $productIdStr = !empty($item['product_id']) ? (string) $item['product_id'] : null;
                $variantIdStr = !empty($item['variant_id']) ? (string) $item['variant_id'] : null;

                // 1. Resolve product tags
                $itemTags = $item['product_tags'] ?? ($item['tags'] ?? ($item['product']['tags'] ?? null));
                if (empty($itemTags) && !empty($productIdStr)) {
                    if (array_key_exists($productIdStr, $productTagsMap)) {
                        $itemTags = $productTagsMap[$productIdStr];
                    } else {
                        $localTags = null;
                        try {
                            $localTags = OrderItem::where('product_id', $productIdStr)
                                ->whereNotNull('product_tags')
                                ->where('product_tags', '!=', '')
                                ->value('product_tags');
                        } catch (\Throwable $e) {}

                        if (!empty($localTags)) {
                            $itemTags = $localTags;
                        } else {
                            try {
                                $productRes = Cache::remember("shopify_prod_tags_{$productIdStr}", now()->addHours(6), function () use ($productIdStr, $shop, $accessToken) {
                                    return $this->getProduct($productIdStr, $shop, $accessToken);
                                });
                                if (!empty($productRes['success']) && !empty($productRes['product']['tags'])) {
                                    $itemTags = $productRes['product']['tags'];
                                }
                            } catch (\Throwable $e) {}
                        }
                        $productTagsMap[$productIdStr] = $itemTags;
                    }
                }

                if (is_array($itemTags)) {
                    $itemTags = implode(', ', array_filter($itemTags));
                }

                $itemMetafields = $item['metafields'] ?? ($item['product']['metafields'] ?? null);

                // Fetch product metafields from Shopify API if not present in item
                if (empty($itemMetafields) && !empty($productIdStr)) {
                    if (array_key_exists($productIdStr, $productMetafieldsMap)) {
                        $itemMetafields = $productMetafieldsMap[$productIdStr];
                    } else {
                        $itemMetafields = Cache::remember("shopify_prod_mf_{$productIdStr}", now()->addHours(6), function () use ($productIdStr, $shop, $accessToken) {
                            return $this->getProductMetafields($productIdStr, $shop, $accessToken);
                        });
                        $productMetafieldsMap[$productIdStr] = $itemMetafields;
                    }
                }

                $parsed = $this->extractInstallationMetafields($itemMetafields, $item['properties'] ?? []);

                // If not found at product level, attempt variant level
                if (!$parsed['has_installation_metafield'] && !empty($variantIdStr)) {
                    if (array_key_exists($variantIdStr, $variantMetafieldsMap)) {
                        $variantMf = $variantMetafieldsMap[$variantIdStr];
                    } else {
                        $variantMf = Cache::remember("shopify_var_mf_{$variantIdStr}", now()->addHours(6), function () use ($variantIdStr, $shop, $accessToken) {
                            return $this->getVariantMetafields($variantIdStr, $shop, $accessToken);
                        });
                        $variantMetafieldsMap[$variantIdStr] = $variantMf;
                    }

                    if (!empty($variantMf)) {
                        $variantParsed = $this->extractInstallationMetafields($variantMf, $item['properties'] ?? []);
                        if ($variantParsed['has_installation_metafield']) {
                            $parsed = $variantParsed;
                            $itemMetafields = array_merge(is_array($itemMetafields) ? $itemMetafields : [], $variantMf);
                        }
                    }
                }

                // If still not found, check local DB fallback if this product was previously saved as installable
                if (!$parsed['has_installation_metafield'] && !empty($productIdStr)) {
                    try {
                        $localInstallableItem = OrderItem::where('product_id', $productIdStr)
                            ->where('is_installable', true)
                            ->with('installation')
                            ->first();

                        if ($localInstallableItem && $localInstallableItem->installation) {
                            $parsed['is_installable'] = true;
                            $parsed['installation_type'] = $localInstallableItem->installation->installation_type;
                            $parsed['installation_level'] = $localInstallableItem->installation->installation_level;
                            $parsed['has_installation_metafield'] = true;
                        }
                    } catch (\Throwable $e) {}
                }

                // Attach metafields, tags, and resolved installation details directly to the line item
                $item['metafields'] = is_array($itemMetafields) ? $itemMetafields : [];
                $item['tags'] = $itemTags;
                $item['product_tags'] = $itemTags;
                $item['installation_type'] = $parsed['installation_type'];
                $item['installation_level'] = $parsed['installation_level'];
                $item['is_installable'] = $parsed['is_installable'];
                $item['installation'] = $parsed['is_installable'] ? [
                    'installation_type' => $parsed['installation_type'],
                    'installation_level' => $parsed['installation_level'],
                ] : null;
            }
            unset($item);
        }
        unset($order);

        return $orders;
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
     * Fetch orders list from Shopify API and enrich line items with product metafields
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
            $response = $this->executeShopifyGetWithRetry($url, $token, array_merge([
                'status' => 'any',
                'limit' => 50,
            ], $params), 3, 10);

            if ($response->successful()) {
                $orders = $response->json('orders', []);
                
                // Enrich line items with product & variant installation metafields
                $enrichedOrders = $this->enrichOrdersWithMetafields($orders, $shopDomain, $token);

                $raw = $response->json();
                if (is_array($raw) && isset($raw['orders'])) {
                    $raw['orders'] = $enrichedOrders;
                }

                return [
                    'success' => true,
                    'shop' => $shopDomain,
                    'orders' => $enrichedOrders,
                    'raw' => $raw,
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
        } catch (\Throwable $e) {
            Log::error("Shopify API getOrders Exception: " . $e->getMessage());
            return [
                'success' => false,
                'shop' => $shopDomain,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Fetch a single order from Shopify API by Order ID and enrich line items with metafields
     */
    public function getOrder(string|int $orderId, ?string $shop = null, ?string $accessToken = null): array
    {
        $store = $this->resolveCredentials($shop, $accessToken);
        if (!$store['success']) {
            return $store;
        }

        $shopDomain = $store['shop'];
        $token = $store['access_token'];
        $cleanId = ltrim((string) $orderId, '#');
        $url = "https://{$shopDomain}/admin/api/{$this->apiVersion}/orders/{$cleanId}.json";

        try {
            $response = $this->executeShopifyGetWithRetry($url, $token, [], 3, 10);

            if ($response->successful()) {
                $order = $response->json('order', []);
                $enrichedOrders = $this->enrichOrdersWithMetafields([$order], $shopDomain, $token);
                $enrichedOrder = $enrichedOrders[0] ?? $order;

                $raw = $response->json();
                if (is_array($raw) && isset($raw['order'])) {
                    $raw['order'] = $enrichedOrder;
                }

                return [
                    'success' => true,
                    'shop' => $shopDomain,
                    'order' => $enrichedOrder,
                    'raw' => $raw,
                ];
            }

            return [
                'success' => false,
                'shop' => $shopDomain,
                'error' => $response->json('errors') ?? $response->body(),
                'status_code' => $response->status(),
            ];
        } catch (\Throwable $e) {
            Log::error("Shopify API getOrder Exception: " . $e->getMessage());
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
    public function syncOrdersToDatabase(?string $shop = null, ?string $accessToken = null, array $params = []): array
    {
        $startTime = microtime(true);

        // High-performance incremental sync for 500-700 orders/day:
        // Automatically fetch orders updated since the last known update, preventing full table sweeps
        if (empty($params['updated_at_min']) && empty($params['all'])) {
            try {
                $latestUpdated = null;
                if (\Illuminate\Support\Facades\Schema::hasTable('order_payments')) {
                    $latestUpdated = OrderPayment::max('shopify_updated_at');
                }
                if (!$latestUpdated) {
                    $latestUpdated = Order::max('updated_at');
                }
                if ($latestUpdated) {
                    // 10-minute safety buffer to handle clock drift or concurrent updates
                    $params['updated_at_min'] = \Carbon\Carbon::parse($latestUpdated)->subMinutes(10)->toIso8601String();
                }
            } catch (\Throwable $e) {}
        }

        $limit = $params['limit'] ?? 50;
        $queryParams = array_merge([
            'status' => 'any',
            'limit' => $limit,
        ], $params);
        unset($queryParams['all']);

        $ordersResult = $this->getOrders($shop, $accessToken, $queryParams);
        if (!$ordersResult['success']) {
            $errorMsg = $ordersResult['error'] ?? 'Unknown error';
            Log::warning("Shopify syncOrdersToDatabase: getOrders failed: " . (is_string($errorMsg) ? $errorMsg : json_encode($errorMsg)));

            $this->recordSyncLog([
                'event_type' => 'cron_sync',
                'topic' => 'orders/bulk_sync',
                'shop_domain' => $ordersResult['shop'] ?? $shop,
                'status' => 'failed',
                'error_message' => is_string($errorMsg) ? $errorMsg : json_encode($errorMsg),
                'duration_ms' => (int)((microtime(true) - $startTime) * 1000),
            ]);

            return $ordersResult;
        }

        $syncedOrdersCount = 0;
        $syncedItemsCount = 0;
        $productImageMap = [];
        $productMetafieldsMap = [];

        foreach ($ordersResult['orders'] as $shopifyOrder) {
            try {
                $order = $this->processSingleOrderPayload($shopifyOrder, $shop, $accessToken, $productImageMap, $productMetafieldsMap);
                $syncedOrdersCount++;
                $syncedItemsCount += ($order->items ? $order->items->count() : 0);
            } catch (\Throwable $e) {
                $orderIdentifier = $shopifyOrder['name'] ?? ($shopifyOrder['order_number'] ?? ($shopifyOrder['id'] ?? 'unknown'));
                Log::error("Shopify syncOrdersToDatabase: Failed processing order #{$orderIdentifier}: " . $e->getMessage(), [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);

                $this->recordSyncLog([
                    'event_type' => 'cron_sync',
                    'topic' => 'orders/bulk_sync',
                    'shop_domain' => $ordersResult['shop'] ?? $shop,
                    'shopify_order_id' => $shopifyOrder['id'] ?? null,
                    'order_number' => $shopifyOrder['name'] ?? ($shopifyOrder['order_number'] ?? null),
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'error_details' => [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'trace' => substr($e->getTraceAsString(), 0, 1000),
                    ],
                    'payload' => $shopifyOrder,
                ]);
            }
        }

        $this->recordSyncLog([
            'event_type' => 'cron_sync',
            'topic' => 'orders/bulk_sync',
            'shop_domain' => $ordersResult['shop'] ?? $shop,
            'status' => 'success',
            'items_count' => $syncedItemsCount,
            'duration_ms' => (int)((microtime(true) - $startTime) * 1000),
        ]);

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
    public function processSingleOrderPayload(array $shopifyOrder, ?string $shop = null, ?string $accessToken = null, array &$productImageMap = [], array &$productMetafieldsMap = []): Order
    {
        $rawId = isset($shopifyOrder['id']) ? (string) $shopifyOrder['id'] : null;
        $rawOrderNum = isset($shopifyOrder['order_number']) ? (string) $shopifyOrder['order_number'] : null;
        $rawName = isset($shopifyOrder['name']) ? (string) $shopifyOrder['name'] : null;

        // Prefer human-readable order name/number (e.g. "#1005" or "1005") over internal Shopify numeric ID
        if (!empty($rawName)) {
            $primaryOrderNumber = (string) $rawName;
        } elseif (!empty($rawOrderNum)) {
            $primaryOrderNumber = '#' . ltrim((string) $rawOrderNum, '#');
        } elseif (!empty($rawId)) {
            $primaryOrderNumber = 'SO-' . $rawId;
        } else {
            $primaryOrderNumber = 'ORD-' . time();
        }

        // Gather candidate identifiers to find existing order without creating duplicates
        $candidateNumbers = array_values(array_unique(array_filter([
            $primaryOrderNumber,
            $rawName,
            $rawOrderNum,
            $rawId,
            $rawOrderNum ? '#' . ltrim($rawOrderNum, '#') : null,
            $rawOrderNum ? ltrim($rawOrderNum, '#') : null,
            $rawName ? '#' . ltrim($rawName, '#') : null,
            $rawName ? ltrim($rawName, '#') : null,
            $rawOrderNum ? 'SO-' . $rawOrderNum : null,
            $rawOrderNum && is_numeric($rawOrderNum) ? 'SO-' . sprintf('%05d', (int)$rawOrderNum) : null,
            $rawId ? 'SO-' . $rawId : null,
        ])));

        $existingOrders = Order::whereIn('order_number', $candidateNumbers)->get();

        // Also check if order is linked via order_payments.shopify_order_id
        if (!empty($rawId) && $existingOrders->isEmpty()) {
            try {
                if (\Illuminate\Support\Facades\Schema::hasTable('order_payments')) {
                    $linkedOrderId = OrderPayment::where('shopify_order_id', $rawId)->value('order_id');
                    if ($linkedOrderId) {
                        $linkedOrder = Order::find($linkedOrderId);
                        if ($linkedOrder) {
                            $existingOrders->push($linkedOrder);
                        }
                    }
                }
            } catch (\Throwable $e) {}
        }

        if ($existingOrders->count() > 1) {
            // Keep first order, merge duplicate orders if any exist in database
            $order = $existingOrders->first();
            foreach ($existingOrders->slice(1) as $dupOrder) {
                try {
                    OrderItem::where('order_id', $dupOrder->id)->update(['order_id' => $order->id]);
                    if (\Illuminate\Support\Facades\Schema::hasTable('order_status_logs')) {
                        OrderStatusLog::where('order_id', $dupOrder->id)->update(['order_id' => $order->id]);
                    }
                    if (\Illuminate\Support\Facades\Schema::hasTable('order_payments')) {
                        OrderPayment::where('order_id', $dupOrder->id)->update(['order_id' => $order->id]);
                    }
                    $dupOrder->delete();
                } catch (\Throwable $e) {
                    Log::warning("Could not merge duplicate order {$dupOrder->id}: " . $e->getMessage());
                }
            }
        } else {
            $order = $existingOrders->first();
        }

        $customerName = null;
        if (!empty($shopifyOrder['customer'])) {
            $customerName = trim(($shopifyOrder['customer']['first_name'] ?? '') . ' ' . ($shopifyOrder['customer']['last_name'] ?? ''));
        }
        if (!$customerName && !empty($shopifyOrder['shipping_address']['name'])) {
            $customerName = trim($shopifyOrder['shipping_address']['name']);
        }
        if (!$customerName && !empty($shopifyOrder['billing_address']['name'])) {
            $customerName = trim($shopifyOrder['billing_address']['name']);
        }

        $customerPhone = $shopifyOrder['phone'] 
            ?? ($shopifyOrder['customer']['phone'] ?? ($shopifyOrder['shipping_address']['phone'] ?? ($shopifyOrder['billing_address']['phone'] ?? null)));

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

        $orderNote = !empty($shopifyOrder['note']) ? trim((string)$shopifyOrder['note']) : (!empty($shopifyOrder['notes']) ? trim((string)$shopifyOrder['notes']) : null);
        $shopifyCreatedAt = !empty($shopifyOrder['created_at']) ? \Carbon\Carbon::parse($shopifyOrder['created_at']) : null;
        $shopifyUpdatedAt = !empty($shopifyOrder['updated_at']) ? \Carbon\Carbon::parse($shopifyOrder['updated_at']) : null;

        // Lean Shopify status parsing: only shopify_status and cancelled_at on orders table
        $rawFinancialStatus = !empty($shopifyOrder['financial_status']) ? strtolower((string)$shopifyOrder['financial_status']) : 'pending';
        $cancelledAt = !empty($shopifyOrder['cancelled_at']) ? \Carbon\Carbon::parse($shopifyOrder['cancelled_at']) : null;
        $cancelReason = !empty($shopifyOrder['cancel_reason']) ? (string)$shopifyOrder['cancel_reason'] : null;

        // Check cancellation
        $isCancelled = ($cancelledAt !== null)
            || !empty($cancelReason)
            || ($rawFinancialStatus === 'refunded' && $cancelledAt !== null);

        // Determine Shopify order status (cancelled, closed, or open)
        $shopifyStatus = (!empty($shopifyOrder['cancelled_at']) || !empty($cancelReason))
            ? 'cancelled'
            : (!empty($shopifyOrder['closed_at']) ? 'closed' : 'open');

        if ($shopifyStatus === 'cancelled') {
            $isCancelled = true;
        }

        if (!$order) {
            $initialStatus = $isCancelled ? 'cancelled' : 'pending';

            $orderAttributes = [
                'order_number' => $primaryOrderNumber,
                'customer_name' => $customerName,
                'customer_phone' => $customerPhone,
                'delivery_address' => $shippingAddress,
                'total_amount' => $shopifyOrder['total_price'] ?? 0.00,
                'status' => $initialStatus,
                'shopify_status' => $shopifyStatus,
                'cancelled_at' => $cancelledAt,
            ];
            if ($shopifyCreatedAt) {
                $orderAttributes['created_at'] = $shopifyCreatedAt;
            }
            if ($shopifyUpdatedAt) {
                $orderAttributes['updated_at'] = $shopifyUpdatedAt;
            }
            if ($orderNote !== null) {
                $orderAttributes['notes'] = $orderNote;
            }
            $order = Order::create($orderAttributes);

            try {
                if (\Illuminate\Support\Facades\Schema::hasTable('order_status_logs')) {
                    OrderStatusLog::create([
                        'order_id' => $order->id,
                        'action' => $isCancelled ? 'shopify_order_cancelled' : 'shopify_synced',
                        'new_status' => $initialStatus,
                        'notes' => $isCancelled
                            ? "Synced from Shopify as Cancelled Order (Reason: {$cancelReason})"
                            : "Synced from Shopify as New Order (Order Number: {$primaryOrderNumber})",
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning("Could not create OrderStatusLog for order {$order->id}: " . $e->getMessage());
            }
        } else {
            $updateFields = [
                'customer_name' => $customerName ?: $order->customer_name,
                'customer_phone' => $customerPhone ?: $order->customer_phone,
                'delivery_address' => $shippingAddress ?: $order->delivery_address,
                'total_amount' => $shopifyOrder['total_price'] ?? $order->total_amount,
                'shopify_status' => $shopifyStatus,
            ];
            if ($shopifyCreatedAt) {
                $updateFields['created_at'] = $shopifyCreatedAt;
            }
            if ($shopifyUpdatedAt) {
                $updateFields['updated_at'] = $shopifyUpdatedAt;
            }
            if ($orderNote !== null) {
                $updateFields['notes'] = $orderNote;
            }
            // If order was previously saved with internal rawId as order_number, upgrade it to human-readable format
            if ($order->order_number === $rawId && !empty($rawName) && $rawName !== $rawId) {
                $updateFields['order_number'] = $primaryOrderNumber;
            }

            // Real-time cancellation update from Shopify
            if ($isCancelled) {
                $updateFields['shopify_status'] = 'cancelled';
                $updateFields['cancelled_at'] = $cancelledAt ?: now();

                if ($order->status !== 'cancelled') {
                    $oldStatus = $order->status;
                    $updateFields['status'] = 'cancelled';

                    // Cancel line items so workers know not to pick/pack them
                    try {
                        OrderItem::where('order_id', $order->id)
                            ->where('status', '!=', 'cancelled')
                            ->update(['status' => 'cancelled']);
                    } catch (\Throwable $e) {}

                    try {
                        if (\Illuminate\Support\Facades\Schema::hasTable('order_status_logs')) {
                            OrderStatusLog::create([
                                'order_id' => $order->id,
                                'action' => 'shopify_order_cancelled',
                                'old_status' => $oldStatus,
                                'new_status' => 'cancelled',
                                'notes' => "Order cancelled on Shopify at " . ($cancelledAt ? $cancelledAt->toDateTimeString() : now()->toDateTimeString()),
                            ]);
                        }
                    } catch (\Throwable $e) {
                        Log::warning("Could not create OrderStatusLog for order {$order->id}: " . $e->getMessage());
                    }
                }
            }

            $order->update($updateFields);
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
                    if (array_key_exists($productIdStr, $productImageMap)) {
                        $itemImage = $productImageMap[$productIdStr];
                    } else {
                        // Check local database first to avoid network latency
                        $localImage = OrderItem::where('product_id', $productIdStr)
                            ->whereNotNull('image')
                            ->where('image', '!=', '')
                            ->value('image');

                        if (!empty($localImage)) {
                            $itemImage = $localImage;
                            $productImageMap[$productIdStr] = $localImage;
                        } elseif ($orderItem) {
                            // If order item already exists in DB, do not make network calls
                            $productImageMap[$productIdStr] = null;
                        } else {
                            try {
                                $variantId = !empty($item['variant_id']) ? (string) $item['variant_id'] : null;
                                $productResult = $this->getProduct($productIdStr, $shop, $accessToken);
                                if (!empty($productResult['success']) && !empty($productResult['product'])) {
                                    $itemImage = $this->extractProductImageUrl($productResult['product'], $variantId);
                                    $productImageMap[$productIdStr] = $itemImage;
                                } else {
                                    $productImageMap[$productIdStr] = null;
                                }
                            } catch (\Throwable $e) {
                                $productImageMap[$productIdStr] = null;
                            }
                        }
                    }
                }

                // Resolve installation fields from pre-enriched item or fetch product/variant metafields
                $itemMetafields = $item['metafields'] ?? ($item['product']['metafields'] ?? null);
                $hasInstallationMetafield = false;
                $installationLevel = $item['installation_level'] ?? null;
                $installationTypeVal = $item['installation_type'] ?? null;
                $isInstallable = !empty($item['is_installable']);

                if ($isInstallable || !empty($installationTypeVal) || !empty($installationLevel)) {
                    $hasInstallationMetafield = true;
                } else {
                    if (empty($itemMetafields) && !empty($productIdStr)) {
                        if (array_key_exists($productIdStr, $productMetafieldsMap)) {
                            $itemMetafields = $productMetafieldsMap[$productIdStr];
                        } else {
                            try {
                                $itemMetafields = Cache::remember("shopify_prod_mf_{$productIdStr}", now()->addHours(6), function () use ($productIdStr, $shop, $accessToken) {
                                    return $this->getProductMetafields($productIdStr, $shop, $accessToken);
                                });
                            } catch (\Throwable $e) {
                                $itemMetafields = [];
                            }
                            $productMetafieldsMap[$productIdStr] = $itemMetafields;
                        }
                    }

                    $parsed = $this->extractInstallationMetafields($itemMetafields, $item['properties'] ?? []);

                    // If not found in product metafields, check variant metafields
                    if (!$parsed['has_installation_metafield'] && !empty($item['variant_id'])) {
                        $variantIdStr = (string) $item['variant_id'];
                        try {
                            $variantMf = Cache::remember("shopify_var_mf_{$variantIdStr}", now()->addHours(6), function () use ($variantIdStr, $shop, $accessToken) {
                                return $this->getVariantMetafields($variantIdStr, $shop, $accessToken);
                            });
                        } catch (\Throwable $e) {
                            $variantMf = [];
                        }
                        if (!empty($variantMf)) {
                            $variantParsed = $this->extractInstallationMetafields($variantMf, $item['properties'] ?? []);
                            if ($variantParsed['has_installation_metafield']) {
                                $parsed = $variantParsed;
                                $itemMetafields = array_merge(is_array($itemMetafields) ? $itemMetafields : [], $variantMf);
                            }
                        }
                    }

                    // If still not found, check existing orderItem or local DB
                    if (!$parsed['has_installation_metafield']) {
                        if ($orderItem && $orderItem->installation) {
                            $parsed['is_installable'] = true;
                            $parsed['installation_type'] = $orderItem->installation->installation_type;
                            $parsed['installation_level'] = $orderItem->installation->installation_level;
                            $parsed['has_installation_metafield'] = true;
                        } elseif ($orderItem && !empty($orderItem->is_installable)) {
                            $parsed['is_installable'] = true;
                        } elseif (!empty($productIdStr)) {
                            try {
                                $localInstallableItem = OrderItem::where('product_id', $productIdStr)
                                    ->where('is_installable', true)
                                    ->with('installation')
                                    ->first();

                                if ($localInstallableItem && $localInstallableItem->installation) {
                                    $parsed['is_installable'] = true;
                                    $parsed['installation_type'] = $localInstallableItem->installation->installation_type;
                                    $parsed['installation_level'] = $localInstallableItem->installation->installation_level;
                                    $parsed['has_installation_metafield'] = true;
                                }
                            } catch (\Throwable $e) {}
                        }
                    }

                    $isInstallable = $parsed['is_installable'];
                    $installationTypeVal = $parsed['installation_type'];
                    $installationLevel = $parsed['installation_level'];
                    $hasInstallationMetafield = $parsed['has_installation_metafield'];
                }

                static $hasIsInstallableCol = null;
                if ($hasIsInstallableCol === null) {
                    try {
                        $hasIsInstallableCol = \Illuminate\Support\Facades\Schema::hasColumn('order_items', 'is_installable');
                    } catch (\Throwable $e) {
                        $hasIsInstallableCol = false;
                    }
                }

                static $hasOrderInstallationsTbl = null;
                if ($hasOrderInstallationsTbl === null) {
                    try {
                        $hasOrderInstallationsTbl = \Illuminate\Support\Facades\Schema::hasTable('order_installations');
                    } catch (\Throwable $e) {
                        $hasOrderInstallationsTbl = false;
                    }
                }

                static $hasProductTagsCol = null;
                if ($hasProductTagsCol === null) {
                    try {
                        $hasProductTagsCol = \Illuminate\Support\Facades\Schema::hasColumn('order_items', 'product_tags');
                    } catch (\Throwable $e) {
                        $hasProductTagsCol = false;
                    }
                }

                $itemTagsVal = $item['product_tags'] ?? ($item['tags'] ?? ($item['product']['tags'] ?? null));
                if (is_array($itemTagsVal)) {
                    $itemTagsVal = implode(', ', array_filter($itemTagsVal));
                }

                static $hasGiftWrapCol = null;
                if ($hasGiftWrapCol === null) {
                    try {
                        $hasGiftWrapCol = \Illuminate\Support\Facades\Schema::hasColumn('order_items', 'gift_wrap');
                    } catch (\Throwable $e) {
                        $hasGiftWrapCol = false;
                    }
                }

                static $hasGiftMessageCol = null;
                if ($hasGiftMessageCol === null) {
                    try {
                        $hasGiftMessageCol = \Illuminate\Support\Facades\Schema::hasColumn('order_items', 'gift_message');
                    } catch (\Throwable $e) {
                        $hasGiftMessageCol = false;
                    }
                }

                $giftWrap = null;
                $giftMessage = null;
                if (!empty($item['properties']) && is_array($item['properties'])) {
                    foreach ($item['properties'] as $prop) {
                        if (!is_array($prop)) continue;
                        $pName = trim((string)($prop['name'] ?? ''));
                        $lowerName = strtolower($pName);
                        if (in_array($lowerName, ['giftwrap', 'gift wrap', 'gift_wrap', 'gift-wrap'], true)) {
                            $val = trim((string)($prop['value'] ?? ''));
                            if ($val !== '') {
                                $giftWrap = $val;
                            }
                        }
                        if (in_array($lowerName, ['gift message', 'gift_message', 'giftmessage', 'gift-message'], true)) {
                            $val = trim((string)($prop['value'] ?? ''));
                            if ($val !== '') {
                                $giftMessage = $val;
                            }
                        }
                    }
                }

                $itemAttributes = [
                    'order_id' => $order->id,
                    'line_item_id' => $lineItemId,
                    'product_id' => $productIdStr ?: '',
                    'product_code' => $productCode,
                    'barcode' => $barcode,
                    'product_name' => $item['title'] ?? $item['name'] ?? 'Product',
                    'image' => $itemImage,
                    'quantity' => $item['quantity'] ?? 1,
                    'unit_price' => $item['price'] ?? 0.00,
                    'status' => $isCancelled ? 'cancelled' : 'pending',
                ];
                if ($hasIsInstallableCol) {
                    $itemAttributes['is_installable'] = $isInstallable;
                }
                if ($hasProductTagsCol && !empty($itemTagsVal)) {
                    $itemAttributes['product_tags'] = $itemTagsVal;
                }
                if ($hasGiftWrapCol && $giftWrap !== null) {
                    $itemAttributes['gift_wrap'] = $giftWrap;
                }
                if ($hasGiftMessageCol && $giftMessage !== null) {
                    $itemAttributes['gift_message'] = $giftMessage;
                }

                if (!$orderItem) {
                    $orderItem = OrderItem::create($itemAttributes);
                } else {
                    $updateData = [
                        'line_item_id' => $lineItemId ?: $orderItem->line_item_id,
                        'product_id' => $productIdStr ?: $orderItem->product_id,
                        'barcode' => $barcode ?: $orderItem->barcode,
                        'product_name' => $item['title'] ?? $item['name'] ?? $orderItem->product_name,
                        'image' => $itemImage ?: $orderItem->image,
                        'quantity' => $item['quantity'] ?? $orderItem->quantity,
                        'unit_price' => $item['price'] ?? $orderItem->unit_price,
                    ];
                    if ($isCancelled) {
                        $updateData['status'] = 'cancelled';
                    }
                    if ($hasIsInstallableCol && ($hasInstallationMetafield || $isInstallable)) {
                        $updateData['is_installable'] = $isInstallable;
                    }
                    if ($hasProductTagsCol && !empty($itemTagsVal)) {
                        $updateData['product_tags'] = $itemTagsVal;
                    }
                    if ($hasGiftWrapCol && $giftWrap !== null) {
                        $updateData['gift_wrap'] = $giftWrap;
                    }
                    if ($hasGiftMessageCol && $giftMessage !== null) {
                        $updateData['gift_message'] = $giftMessage;
                    }
                    $orderItem->update($updateData);
                }

                // If installable, create or update order_installations record safely
                if ($isInstallable && $hasOrderInstallationsTbl) {
                    try {
                        $finalInstallationType = (is_string($installationTypeVal) && !in_array(strtolower($installationTypeVal), ['true', '1', 'false', '0', 'null', 'none', 'no']))
                            ? $installationTypeVal
                            : ($installationLevel ?: 'standard');

                        OrderInstallation::updateOrCreate(
                            ['order_item_id' => $orderItem->id],
                            [
                                'installation_type' => $finalInstallationType,
                                'installation_level' => $installationLevel,
                            ]
                        );
                    } catch (\Throwable $e) {
                        Log::warning("Could not store OrderInstallation for item {$orderItem->id}: " . $e->getMessage());
                    }
                }
            }
        }

        // Handle partially refunded/cancelled items from refunds payload
        if (!empty($shopifyOrder['refunds']) && is_array($shopifyOrder['refunds'])) {
            foreach ($shopifyOrder['refunds'] as $refund) {
                if (!empty($refund['refund_line_items']) && is_array($refund['refund_line_items'])) {
                    foreach ($refund['refund_line_items'] as $rli) {
                        $rLineItemId = !empty($rli['line_item_id']) ? (string)$rli['line_item_id'] : null;
                        if ($rLineItemId) {
                            try {
                                OrderItem::where('order_id', $order->id)
                                    ->where('line_item_id', $rLineItemId)
                                    ->update(['status' => 'cancelled']);
                            } catch (\Throwable $e) {}
                        }
                    }
                }
            }
        }

        return $order->fresh(['items.installation', 'payment']);
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

        // 1b. If shop domain provided, check if that shop exists in database with an access token
        if ($shop) {
            $cleanShop = $this->sanitizeShopDomain($shop);
            $matchedStore = ShopifyStore::where('shop', $cleanShop)
                ->orWhere('shop', 'like', "%{$cleanShop}%")
                ->orderBy('is_active', 'desc')
                ->first();

            if ($matchedStore && $matchedStore->access_token) {
                return [
                    'success' => true,
                    'shop' => $matchedStore->shop,
                    'access_token' => $matchedStore->access_token,
                ];
            }
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

        // 3. Any connected store with an access token from database
        $anyStoreWithToken = ShopifyStore::whereNotNull('access_token')
            ->where('access_token', '!=', '')
            ->orderBy('is_active', 'desc')
            ->first();
        if ($anyStoreWithToken) {
            return [
                'success' => true,
                'shop' => $anyStoreWithToken->shop,
                'access_token' => $anyStoreWithToken->access_token,
            ];
        }

        // 4. Fallback from .env configuration
        $envShop = config('services.shopify.shop_domain') ?: $shop;
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
    public function syncOrderPayment(Order $order, array $shopifyOrder): ?OrderPayment
    {
        try {
            static $hasOrderPaymentsTable = null;
            if ($hasOrderPaymentsTable === null) {
                try {
                    $hasOrderPaymentsTable = \Illuminate\Support\Facades\Schema::hasTable('order_payments');
                } catch (\Throwable $e) {
                    $hasOrderPaymentsTable = false;
                }
            }

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

            $orderPayment = null;
            if ($hasOrderPaymentsTable) {
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
            }

            // Also update parent Order model's payment columns for compatibility if columns exist
            static $hasPaymentCols = null;
            if ($hasPaymentCols === null) {
                try {
                    $hasPaymentCols = \Illuminate\Support\Facades\Schema::hasColumn('orders', 'payment_method');
                } catch (\Throwable $e) {
                    $hasPaymentCols = false;
                }
            }

            if ($hasPaymentCols) {
                $order->update([
                    'payment_method' => $paymentMethod ?: $order->payment_method,
                    'payment_status' => $financialStatus ?: $order->payment_status,
                    'collected_amount' => $paidAmount,
                ]);
            }

            return $orderPayment;
        } catch (\Throwable $e) {
            Log::warning("Could not sync order payment for order {$order->id}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Record a sync or webhook attempt with execution status and errors in shopify_sync_logs
     */
    public function recordSyncLog(array $data): ?ShopifySyncLog
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('shopify_sync_logs')) {
                return null;
            }

            return ShopifySyncLog::create([
                'event_type' => $data['event_type'] ?? 'sync',
                'topic' => $data['topic'] ?? null,
                'shop_domain' => $data['shop_domain'] ?? null,
                'shopify_order_id' => isset($data['shopify_order_id']) ? (string)$data['shopify_order_id'] : null,
                'order_number' => isset($data['order_number']) ? (string)$data['order_number'] : null,
                'local_order_id' => $data['local_order_id'] ?? null,
                'status' => $data['status'] ?? 'pending',
                'error_message' => $data['error_message'] ?? null,
                'error_details' => $data['error_details'] ?? null,
                'payload' => $data['payload'] ?? null,
                'headers' => $data['headers'] ?? null,
                'ip_address' => $data['ip_address'] ?? null,
                'items_count' => (int)($data['items_count'] ?? 0),
                'duration_ms' => (int)($data['duration_ms'] ?? 0),
            ]);
        } catch (\Throwable $e) {
            Log::warning("ShopifyService::recordSyncLog Exception: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get list of registered webhooks from Shopify API
     */
    public function getWebhooks(?string $shop = null, ?string $accessToken = null): array
    {
        $store = $this->resolveCredentials($shop, $accessToken);
        if (!$store['success']) {
            return $store;
        }

        $shopDomain = $store['shop'];
        $token = $store['access_token'];
        $url = "https://{$shopDomain}/admin/api/{$this->apiVersion}/webhooks.json";

        try {
            $response = Http::timeout(10)->withHeaders([
                'X-Shopify-Access-Token' => $token,
                'Content-Type' => 'application/json',
            ])->get($url);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'shop' => $shopDomain,
                    'webhooks' => $response->json('webhooks', []),
                ];
            }

            return [
                'success' => false,
                'shop' => $shopDomain,
                'error' => $response->json('errors') ?? $response->body(),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'shop' => $shopDomain,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Register standard order webhooks with Shopify API
     */
    public function registerWebhooks(?string $shop = null, ?string $accessToken = null, ?string $webhookBaseUrl = null): array
    {
        $store = $this->resolveCredentials($shop, $accessToken);
        if (!$store['success']) {
            return $store;
        }

        $shopDomain = $store['shop'];
        $token = $store['access_token'];
        
        $baseUrl = $webhookBaseUrl ?: url('/');
        $baseUrl = rtrim($baseUrl, '/');

        $topics = [
            'orders/create' => "{$baseUrl}/api/shopify/webhooks/orders-create",
            'orders/updated' => "{$baseUrl}/api/shopify/webhooks/orders-update",
            'orders/paid' => "{$baseUrl}/api/shopify/webhooks/orders-paid",
        ];

        $results = [];
        foreach ($topics as $topic => $address) {
            $url = "https://{$shopDomain}/admin/api/{$this->apiVersion}/webhooks.json";
            try {
                $response = Http::timeout(10)->withHeaders([
                    'X-Shopify-Access-Token' => $token,
                    'Content-Type' => 'application/json',
                ])->post($url, [
                    'webhook' => [
                        'topic' => $topic,
                        'address' => $address,
                        'format' => 'json',
                    ],
                ]);

                $results[$topic] = [
                    'success' => $response->successful(),
                    'address' => $address,
                    'response' => $response->json(),
                ];
            } catch (\Throwable $e) {
                $results[$topic] = [
                    'success' => false,
                    'address' => $address,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'success' => true,
            'shop' => $shopDomain,
            'webhooks' => $results,
        ];
    }

    /**
     * Get fulfillment orders for a given Shopify order
     * GET /admin/api/{version}/orders/{order_id}/fulfillment_orders.json
     */
    public function getFulfillmentOrders(string|int $shopifyOrderId, ?string $shop = null, ?string $accessToken = null): array
    {
        $store = $this->resolveCredentials($shop, $accessToken);
        if (!$store['success']) {
            return $store;
        }

        $shopDomain = $store['shop'];
        $token = $store['access_token'];
        $url = "https://{$shopDomain}/admin/api/{$this->apiVersion}/orders/{$shopifyOrderId}/fulfillment_orders.json";

        try {
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $token,
                'Accept' => 'application/json',
            ])->get($url);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'fulfillment_orders' => $response->json('fulfillment_orders', []),
                    'status_code' => $response->status(),
                ];
            }

            return [
                'success' => false,
                'status_code' => $response->status(),
                'error' => $response->json('errors') ?? $response->body(),
                'raw' => $response->json(),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'status_code' => 500,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Create fulfillment in Shopify via FulfillmentOrders API
     * POST /admin/api/{version}/fulfillments.json
     */
    public function createFulfillment(array $payload, ?string $shop = null, ?string $accessToken = null): array
    {
        $store = $this->resolveCredentials($shop, $accessToken);
        if (!$store['success']) {
            return $store;
        }

        $shopDomain = $store['shop'];
        $token = $store['access_token'];
        $url = "https://{$shopDomain}/admin/api/{$this->apiVersion}/fulfillments.json";

        try {
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($url, ['fulfillment' => $payload]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'fulfillment' => $response->json('fulfillment', []),
                    'status_code' => $response->status(),
                ];
            }

            return [
                'success' => false,
                'status_code' => $response->status(),
                'error' => $response->json('errors') ?? $response->body(),
                'raw' => $response->json(),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'status_code' => 500,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Record a fulfillment delivery event in Shopify (status: delivered, out_for_delivery, etc.)
     * POST /admin/api/{version}/orders/{order_id}/fulfillments/{fulfillment_id}/events.json
     */
    public function createFulfillmentEvent(string|int $shopifyOrderId, string|int $fulfillmentId, string $status = 'delivered', array $eventData = [], ?string $shop = null, ?string $accessToken = null): array
    {
        $store = $this->resolveCredentials($shop, $accessToken);
        if (!$store['success']) {
            return $store;
        }

        $shopDomain = $store['shop'];
        $token = $store['access_token'];
        $url = "https://{$shopDomain}/admin/api/{$this->apiVersion}/orders/{$shopifyOrderId}/fulfillments/{$fulfillmentId}/events.json";

        $payload = array_merge([
            'status' => $status,
            'happened_at' => now()->toIso8601String(),
        ], $eventData);

        try {
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($url, ['event' => $payload]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'event' => $response->json('fulfillment_event', []),
                    'status_code' => $response->status(),
                ];
            }

            return [
                'success' => false,
                'status_code' => $response->status(),
                'error' => $response->json('errors') ?? $response->body(),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'status_code' => 500,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get fulfillments for a Shopify order
     * GET /admin/api/{version}/orders/{order_id}/fulfillments.json
     */
    public function getOrderFulfillments(string|int $shopifyOrderId, ?string $shop = null, ?string $accessToken = null): array
    {
        $store = $this->resolveCredentials($shop, $accessToken);
        if (!$store['success']) {
            return $store;
        }

        $shopDomain = $store['shop'];
        $token = $store['access_token'];
        $url = "https://{$shopDomain}/admin/api/{$this->apiVersion}/orders/{$shopifyOrderId}/fulfillments.json";

        try {
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $token,
                'Accept' => 'application/json',
            ])->get($url);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'fulfillments' => $response->json('fulfillments', []),
                    'status_code' => $response->status(),
                ];
            }

            return [
                'success' => false,
                'status_code' => $response->status(),
                'error' => $response->json('errors') ?? $response->body(),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'status_code' => 500,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Close / Complete an order in Shopify
     * POST /admin/api/{version}/orders/{order_id}/close.json
     */
    public function closeOrder(string|int $shopifyOrderId, ?string $shop = null, ?string $accessToken = null): array
    {
        $store = $this->resolveCredentials($shop, $accessToken);
        if (!$store['success']) {
            return $store;
        }

        $shopDomain = $store['shop'];
        $token = $store['access_token'];
        $url = "https://{$shopDomain}/admin/api/{$this->apiVersion}/orders/{$shopifyOrderId}/close.json";

        try {
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($url, []);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'order' => $response->json('order', []),
                    'status_code' => $response->status(),
                ];
            }

            return [
                'success' => false,
                'status_code' => $response->status(),
                'error' => $response->json('errors') ?? $response->body(),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'status_code' => 500,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Update order tags and note attributes in Shopify
     * PUT /admin/api/{version}/orders/{order_id}.json
     */
    public function updateOrderTagsAndNote(string|int $shopifyOrderId, array $tagsToAdd = [], array $noteAttributes = [], ?string $shop = null, ?string $accessToken = null): array
    {
        $store = $this->resolveCredentials($shop, $accessToken);
        if (!$store['success']) {
            return $store;
        }

        $shopDomain = $store['shop'];
        $token = $store['access_token'];

        try {
            $getUrl = "https://{$shopDomain}/admin/api/{$this->apiVersion}/orders/{$shopifyOrderId}.json?fields=id,tags,note_attributes";
            $getResponse = Http::withHeaders([
                'X-Shopify-Access-Token' => $token,
                'Accept' => 'application/json',
            ])->get($getUrl);

            $currentOrder = $getResponse->successful() ? $getResponse->json('order', []) : [];
            $existingTagsStr = $currentOrder['tags'] ?? '';
            $existingTags = array_filter(array_map('trim', explode(',', (string)$existingTagsStr)));

            $mergedTags = array_values(array_unique(array_merge($existingTags, $tagsToAdd)));
            $mergedTagsStr = implode(', ', $mergedTags);

            $existingAttributes = $currentOrder['note_attributes'] ?? [];
            $attributesMap = [];
            foreach ($existingAttributes as $attr) {
                if (isset($attr['name'])) {
                    $attributesMap[$attr['name']] = $attr['value'] ?? '';
                }
            }
            foreach ($noteAttributes as $attr) {
                if (isset($attr['name'])) {
                    $attributesMap[$attr['name']] = $attr['value'] ?? '';
                }
            }
            $mergedAttributes = [];
            foreach ($attributesMap as $name => $value) {
                $mergedAttributes[] = ['name' => $name, 'value' => (string)$value];
            }

            $putUrl = "https://{$shopDomain}/admin/api/{$this->apiVersion}/orders/{$shopifyOrderId}.json";
            $putPayload = [
                'order' => [
                    'id' => (int)$shopifyOrderId,
                    'tags' => $mergedTagsStr,
                ],
            ];
            if (!empty($mergedAttributes)) {
                $putPayload['order']['note_attributes'] = $mergedAttributes;
            }

            $putResponse = Http::withHeaders([
                'X-Shopify-Access-Token' => $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->put($putUrl, $putPayload);

            return [
                'success' => $putResponse->successful(),
                'status_code' => $putResponse->status(),
                'tags' => $mergedTagsStr,
                'order' => $putResponse->json('order', []),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'status_code' => 500,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Primary sync handler: When an order status is marked as 'delivered' or 'completed'
     * (or partially delivered), sync to Shopify and update items as fulfilled and delivered,
     * and close/complete the order in Shopify if completed.
     *
     * @param Order|int|string $order Order instance or ID/Number
     * @param array $options Options (status, item_ids, delivered_by, delivered_at, action, notes, force)
     * @return array
     */
    public function syncOrderFulfillmentToShopify(Order|int|string $order, array $options = []): array
    {
        $startTime = microtime(true);

        // 1. Resolve Order Model
        if (!($order instanceof Order)) {
            $orderRecord = Order::with(['items', 'payment'])->find($order);
            if (!$orderRecord) {
                $cleanNum = ltrim((string)$order, '#');
                $orderRecord = Order::with(['items', 'payment'])
                    ->where('order_number', (string)$order)
                    ->orWhere('order_number', $cleanNum)
                    ->orWhere('order_number', '#' . $cleanNum)
                    ->first();
            }
            $order = $orderRecord;
        } else {
            $order->loadMissing(['items', 'payment']);
        }

        if (!$order) {
            return [
                'success' => false,
                'error' => 'Order record could not be found.',
            ];
        }

        $targetStatus = strtolower(trim((string)($options['status'] ?? $order->status ?? 'delivered')));
        $force = !empty($options['force']);

        // Debounce lock to prevent redundant executions
        $lockKey = "shopify_fulfillment_sync_{$order->id}_{$targetStatus}";
        if (!$force && Cache::has($lockKey)) {
            return [
                'success' => true,
                'message' => "Shopify fulfillment sync already in progress or recently executed for order #{$order->order_number}.",
                'skipped' => true,
            ];
        }
        Cache::put($lockKey, true, 8);

        // 2. Resolve Shopify credentials
        $store = $this->resolveCredentials($options['shop'] ?? null, $options['access_token'] ?? null);
        if (!$store['success']) {
            $this->recordSyncLog([
                'event_type' => 'fulfillment_sync',
                'topic' => 'orders/fulfill',
                'order_number' => $order->order_number,
                'local_order_id' => $order->id,
                'status' => 'failed',
                'error_message' => 'No active Shopify store credentials available.',
                'duration_ms' => (int)((microtime(true) - $startTime) * 1000),
            ]);

            return [
                'success' => false,
                'error' => 'No active Shopify store credentials configured.',
            ];
        }

        $shopDomain = $store['shop'];
        $token = $store['access_token'];

        // 3. Locate Shopify Order ID
        $shopifyOrderId = $order->payment?->shopify_order_id;
        if (empty($shopifyOrderId)) {
            try {
                $searchUrl = "https://{$shopDomain}/admin/api/{$this->apiVersion}/orders.json";
                $searchRes = Http::withHeaders([
                    'X-Shopify-Access-Token' => $token,
                    'Accept' => 'application/json',
                ])->get($searchUrl, [
                    'name' => $order->order_number,
                    'status' => 'any',
                    'limit' => 5,
                ]);

                if ($searchRes->successful()) {
                    $foundOrders = $searchRes->json('orders', []);
                    if (!empty($foundOrders)) {
                        $shopifyOrderId = (string)$foundOrders[0]['id'];
                        // Save in OrderPayment for future lookups
                        if (\Illuminate\Support\Facades\Schema::hasTable('order_payments')) {
                            OrderPayment::updateOrCreate(
                                ['order_id' => $order->id],
                                ['shopify_order_id' => $shopifyOrderId]
                            );
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("Could not search Shopify order ID for order {$order->id}: " . $e->getMessage());
            }
        }

        if (empty($shopifyOrderId)) {
            $msg = "Order #{$order->order_number} is not linked to any Shopify order ID.";
            $this->recordSyncLog([
                'event_type' => 'fulfillment_sync',
                'topic' => 'orders/fulfill',
                'shop_domain' => $shopDomain,
                'order_number' => $order->order_number,
                'local_order_id' => $order->id,
                'status' => 'failed',
                'error_message' => $msg,
                'duration_ms' => (int)((microtime(true) - $startTime) * 1000),
            ]);

            return [
                'success' => false,
                'error' => $msg,
            ];
        }

        $driverName = $options['delivered_by'] ?? ($order->delivered_user_name ?: 'Delivery Driver');
        $deliveredAt = $order->delivered_at ? $order->delivered_at->toIso8601String() : now()->toIso8601String();
        $isCompleted = ($targetStatus === 'completed' || $targetStatus === 'installed');
        $isDelivered = ($targetStatus === 'delivered' || $targetStatus === 'partialy_deliverd' || $isCompleted);

        // Filter items to fulfill
        $order->loadMissing('items');
        $allItems = $order->items->reject(fn($i) => $i->status === 'cancelled');

        if (!empty($options['item_ids']) && is_array($options['item_ids'])) {
            $itemsToFulfill = $order->items->whereIn('id', $options['item_ids']);
        } elseif ($targetStatus === 'partialy_deliverd') {
            $itemsToFulfill = $allItems->filter(fn($i) => in_array(strtolower((string)$i->status), ['delivered', 'completed', 'installed']));
        } else {
            $itemsToFulfill = $allItems;
        }

        $results = [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'shopify_order_id' => $shopifyOrderId,
            'target_status' => $targetStatus,
            'items_fulfilled_count' => 0,
            'fulfillment_ids' => [],
            'delivery_events_created' => 0,
            'order_closed' => false,
            'permission_warning' => null,
        ];

        // 4. Fetch Fulfillment Orders from Shopify
        $foResult = $this->getFulfillmentOrders($shopifyOrderId, $shopDomain, $token);

        // Handle scope permission limitation if Fulfillment Orders API returns 403
        if (!$foResult['success'] && ($foResult['status_code'] ?? 0) === 403) {
            $warningMsg = "Shopify app access token requires 'write_merchant_managed_fulfillment_orders' to create item-level fulfillments via API. Updating tags, delivery note, and order status.";
            $results['permission_warning'] = $warningMsg;
            Log::info("Shopify Fulfillment Sync [Permission Warning]: {$warningMsg} (Order #{$order->order_number})");

            // Close order if completed
            if ($isCompleted) {
                $closeRes = $this->closeOrder($shopifyOrderId, $shopDomain, $token);
                if (!empty($closeRes['success'])) {
                    $results['order_closed'] = true;
                    $order->shopify_status = 'closed';
                    $order->save();
                }
            }

            // Tag order as Delivered and/or Completed
            $tags = ['Delivered'];
            if ($isCompleted) {
                $tags[] = 'Completed';
            }
            $this->updateOrderTagsAndNote($shopifyOrderId, $tags, [
                ['name' => 'Delivery Status', 'value' => ucfirst($targetStatus)],
                ['name' => 'Delivered By', 'value' => $driverName],
                ['name' => 'Delivered At', 'value' => $deliveredAt],
            ], $shopDomain, $token);

            $durationMs = (int)((microtime(true) - $startTime) * 1000);
            $this->recordSyncLog([
                'event_type' => 'fulfillment_sync',
                'topic' => 'orders/fulfill',
                'shop_domain' => $shopDomain,
                'shopify_order_id' => $shopifyOrderId,
                'order_number' => $order->order_number,
                'local_order_id' => $order->id,
                'status' => 'success',
                'error_message' => $warningMsg,
                'items_count' => $itemsToFulfill->count(),
                'duration_ms' => $durationMs,
                'payload' => $results,
            ]);

            return [
                'success' => true,
                'message' => "Order #{$order->order_number} delivery status and tags synchronized to Shopify. (Note: approve 'write_merchant_managed_fulfillment_orders' in Shopify Admin to enable automatic line item fulfillment).",
                'details' => $results,
            ];
        }

        // 5. Process fulfillment orders
        $createdFulfillmentIds = [];
        if (!empty($foResult['success'])) {
            $fulfillmentOrders = $foResult['fulfillment_orders'] ?? [];

            foreach ($fulfillmentOrders as $fo) {
                $foStatus = strtolower((string)($fo['status'] ?? ''));
                if (!in_array($foStatus, ['open', 'in_progress'])) {
                    continue;
                }

                $foId = $fo['id'];
                $foLineItemsToFulfill = [];

                foreach ($fo['line_items'] as $foLineItem) {
                    $foLineItemId = $foLineItem['id'];
                    $shopifyLineItemId = (string)($foLineItem['line_item_id'] ?? '');
                    $fulfillableQty = (int)($foLineItem['fulfillable_quantity'] ?? $foLineItem['remaining_quantity'] ?? 0);

                    if ($fulfillableQty <= 0) {
                        continue;
                    }

                    // Match item in $itemsToFulfill
                    $matched = $itemsToFulfill->first(function ($it) use ($shopifyLineItemId, $foLineItem) {
                        if (!empty($it->line_item_id) && (string)$it->line_item_id === $shopifyLineItemId) {
                            return true;
                        }
                        if (!empty($it->product_id) && !empty($foLineItem['product_id']) && (string)$it->product_id === (string)$foLineItem['product_id']) {
                            return true;
                        }
                        if (!empty($it->barcode) && !empty($foLineItem['sku']) && (string)$it->barcode === (string)$foLineItem['sku']) {
                            return true;
                        }
                        if (!empty($it->product_code) && !empty($foLineItem['sku']) && (string)$it->product_code === (string)$foLineItem['sku']) {
                            return true;
                        }
                        return false;
                    });

                    // If full order is delivered/completed, or item matched:
                    if ($matched || $targetStatus === 'delivered' || $targetStatus === 'completed') {
                        $qty = min($fulfillableQty, (int)($matched->quantity ?? $fulfillableQty));
                        $foLineItemsToFulfill[] = [
                            'id' => $foLineItemId,
                            'quantity' => $qty > 0 ? $qty : $fulfillableQty,
                        ];
                    }
                }

                if (!empty($foLineItemsToFulfill)) {
                    $fulfillmentPayload = [
                        'message' => "Items delivered by {$driverName}",
                        'notify_customer' => false,
                        'line_items_by_fulfillment_order' => [
                            [
                                'fulfillment_order_id' => $foId,
                                'fulfillment_order_line_items' => $foLineItemsToFulfill,
                            ],
                        ],
                    ];

                    $fulfillResult = $this->createFulfillment($fulfillmentPayload, $shopDomain, $token);
                    if (!empty($fulfillResult['success'])) {
                        $newFulfillment = $fulfillResult['fulfillment'];
                        $createdFulfillmentIds[] = $newFulfillment['id'];
                        $results['items_fulfilled_count'] += count($foLineItemsToFulfill);

                        // 6. Create Fulfillment Event (Shopify terms: 'delivered')
                        $eventRes = $this->createFulfillmentEvent(
                            $shopifyOrderId,
                            $newFulfillment['id'],
                            'delivered',
                            [
                                'message' => "Order items delivered by {$driverName}",
                            ],
                            $shopDomain,
                            $token
                        );
                        if (!empty($eventRes['success'])) {
                            $results['delivery_events_created']++;
                        }
                    } else {
                        Log::warning("Shopify createFulfillment failed for order {$order->id}: " . json_encode($fulfillResult));
                    }
                }
            }
        }

        // If items were already fulfilled prior to this call, verify existing fulfillments have 'delivered' event
        if (empty($createdFulfillmentIds)) {
            $existingFulfillments = $this->getOrderFulfillments($shopifyOrderId, $shopDomain, $token);
            if (!empty($existingFulfillments['success'])) {
                foreach ($existingFulfillments['fulfillments'] as $f) {
                    $fId = $f['id'];
                    $eventRes = $this->createFulfillmentEvent(
                        $shopifyOrderId,
                        $fId,
                        'delivered',
                        ['message' => "Order items delivered by {$driverName}"],
                        $shopDomain,
                        $token
                    );
                    if (!empty($eventRes['success'])) {
                        $results['delivery_events_created']++;
                    }
                }
            }
        }

        $results['fulfillment_ids'] = $createdFulfillmentIds;

        // 7. If order status is completed (or fully fulfilled and delivered), close the order in Shopify
        if ($isCompleted) {
            $closeRes = $this->closeOrder($shopifyOrderId, $shopDomain, $token);
            if (!empty($closeRes['success'])) {
                $results['order_closed'] = true;
                $order->shopify_status = 'closed';
            }
        } elseif ($isDelivered) {
            // Keep status open while delivered
            if ($order->shopify_status !== 'closed' && $order->shopify_status !== 'cancelled') {
                $order->shopify_status = 'open';
            }
        }

        // 8. Update Shopify Order Tags & Note Attributes
        $tagsToAdd = ['Delivered'];
        if ($isCompleted) {
            $tagsToAdd[] = 'Completed';
        }
        $this->updateOrderTagsAndNote($shopifyOrderId, $tagsToAdd, [
            ['name' => 'Delivery Status', 'value' => ucfirst($targetStatus)],
            ['name' => 'Delivered By', 'value' => $driverName],
            ['name' => 'Delivered At', 'value' => $deliveredAt],
        ], $shopDomain, $token);

        // 9. Save updated order in local DB
        $order->save();

        // 10. Create OrderStatusLog audit trail
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('order_status_logs')) {
                $logNote = $isCompleted
                    ? "Order marked as completed and closed in Shopify (Delivered by: {$driverName})."
                    : "Order fulfillment synced to Shopify. Items marked delivered in Shopify terms (Delivered by: {$driverName}).";

                OrderStatusLog::create([
                    'order_id' => $order->id,
                    'action' => 'shopify_fulfillment_synced',
                    'new_status' => $targetStatus,
                    'notes' => $logNote,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning("Could not create OrderStatusLog for order {$order->id}: " . $e->getMessage());
        }

        // 11. Record in ShopifySyncLog
        $durationMs = (int)((microtime(true) - $startTime) * 1000);
        $this->recordSyncLog([
            'event_type' => 'fulfillment_sync',
            'topic' => 'orders/fulfill',
            'shop_domain' => $shopDomain,
            'shopify_order_id' => $shopifyOrderId,
            'order_number' => $order->order_number,
            'local_order_id' => $order->id,
            'status' => 'success',
            'items_count' => $itemsToFulfill->count(),
            'duration_ms' => $durationMs,
            'payload' => $results,
        ]);

        return [
            'success' => true,
            'message' => "Order #{$order->order_number} fulfillment synced to Shopify successfully.",
            'details' => $results,
        ];
    }
}


