<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Services\ShopifyService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class OrderDetailsService
{
    protected ShopifyService $shopifyService;

    /**
     * Predefined mock orders repository for fallback/demo consistency.
     */
    protected array $mockOrders = [
        '1002' => [
            'name' => '#1002',
            'order_number' => '#1002',
            'customer' => 'CUST-9501213884660',
            'customer_name' => 'Ansil A',
            'contact_email' => 'ansil@gmail.com',
            'contact_phone' => '+97432131234',
            'transaction_date' => '2026-07-30 06:28:13',
            'delivery_date' => '2026-07-31',
            'grand_total' => 178.00,
            'status' => 'Pending',
            'payment_method' => 'Cash on Delivery (COD)',
            'payment_status' => 'pending',
            'custom_payment_method' => 'Cash on Delivery (COD)',
            'custom_payment_status' => 'Pending',
            'financial_status' => 'pending',
            'paid_amount' => 0.00,
            'total_outstanding' => 178.00,
            'currency' => 'QAR',
            'custom_city' => 'Doha',
            'custom_zone' => 'Zone A',
            'custom_coordinator' => 'Unassigned',
            'custom_driver' => 'Unassigned',
            'custom_driver_status' => 'Pending',
            'custom_picker' => 'Unassigned',
            'custom_packer' => 'Unassigned',
            'custom_channel' => 'Shopify draft order',
            'custom_tat' => '2h 00m',
            'custom_bags' => 3,
            'custom_picking_status' => '0/3 Picked',
            'custom_packing_status' => '0/3 Packed',
            'custom_shopify_status' => 'Unfulfilled',
            'custom_notes' => 'Handle with care. Call upon arrival.',
            'custom_shipping_address_line1' => 'Building 12, Street 340',
            'custom_shipping_address_line2' => 'Apartment 4B',
            'custom_shipping_city' => 'Doha',
            'custom_shipping_country' => 'Qatar',
            'custom_latitude' => 25.276987,
            'custom_longitude' => 51.520008,
            'custom_tags' => 'Express, Fragile',
            'line_items' => [
                [
                    'id' => 14592039485,
                    'name' => 'Chicco Next2Me Sleeping Crib - Silver',
                    'sku' => 'CHK-CRIB-01',
                    'custom_barcode' => '890123456789',
                    'quantity' => 1,
                    'price' => 120.00,
                    'amount' => 120.00,
                    'warehouse' => 'Fulfillment Center Hilal (F01)',
                    'custom_bin' => 'BIN-A-12',
                    'custom_status' => 'Pending',
                    'image' => 'https://images.unsplash.com/photo-1519689680058-324335c77eba?w=150',
                ],
                [
                    'id' => 14592039486,
                    'name' => 'Baby Bottle Sterilizer & Dryer',
                    'sku' => 'BB-STER-02',
                    'custom_barcode' => '890987654321',
                    'quantity' => 2,
                    'price' => 29.00,
                    'amount' => 58.00,
                    'warehouse' => 'Fulfillment Center Hilal (F01)',
                    'custom_bin' => 'BIN-B-05',
                    'custom_status' => 'Pending',
                    'image' => 'https://images.unsplash.com/photo-1584308666744-24d5c474f2ae?w=150',
                ],
            ],
        ],
        'SO-00001' => [
            'name' => 'SO-00001',
            'order_number' => 'SO-00001',
            'customer' => 'CUST-00001',
            'customer_name' => 'Sara Al Sulaiti',
            'contact_email' => 'sara@example.com',
            'contact_phone' => '+974 5512 3456',
            'transaction_date' => '2026-05-25 14:10:00',
            'delivery_date' => '2026-05-26',
            'grand_total' => 450.00,
            'status' => 'Picking',
            'payment_method' => 'Cash on Delivery (COD)',
            'payment_status' => 'pending',
            'custom_payment_method' => 'Cash on Delivery (COD)',
            'custom_payment_status' => 'Pending',
            'financial_status' => 'pending',
            'paid_amount' => 0.00,
            'total_outstanding' => 450.00,
            'currency' => 'QAR',
            'custom_city' => 'Doha',
            'custom_zone' => 'Zone A',
            'custom_coordinator' => 'Ahmed Hassan',
            'custom_driver' => 'Omar Farooq',
            'custom_driver_status' => 'Assigned',
            'custom_picker' => 'Ahmed Khalil',
            'custom_packer' => 'Sara Al-Thani',
            'custom_channel' => 'Web',
            'custom_tat' => '2h 15m',
            'custom_bags' => 2,
            'custom_picking_status' => '1/2 Picked',
            'custom_packing_status' => '0/2 Packed',
            'custom_shopify_status' => 'Unfulfilled',
            'custom_notes' => 'Please deliver before 5 PM.',
            'custom_shipping_address_line1' => 'Villa 45, Al Waab Street',
            'custom_shipping_address_line2' => 'Near Aspire Park',
            'custom_shipping_city' => 'Doha',
            'custom_shipping_country' => 'Qatar',
            'custom_latitude' => 25.261987,
            'custom_longitude' => 51.440008,
            'custom_tags' => 'Express, Fragile',
            'line_items' => [
                [
                    'id' => 101,
                    'name' => 'Chicco Next2Me Sleeping Crib - Silver',
                    'sku' => 'CHK-CRIB-01',
                    'custom_barcode' => '890123456789',
                    'quantity' => 1,
                    'price' => 350.00,
                    'amount' => 350.00,
                    'warehouse' => 'Fulfillment Center Hilal (F01)',
                    'custom_bin' => 'BIN-A-12',
                    'custom_status' => 'Picked',
                    'image' => 'https://images.unsplash.com/photo-1519689680058-324335c77eba?w=150',
                ],
                [
                    'id' => 102,
                    'name' => 'Organic Baby Wipes 80s Pack',
                    'sku' => 'OB-WIPES-03',
                    'custom_barcode' => '890123456790',
                    'quantity' => 4,
                    'price' => 25.00,
                    'amount' => 100.00,
                    'warehouse' => 'Fulfillment Center Hilal (F01)',
                    'custom_bin' => 'BIN-C-01',
                    'custom_status' => 'Pending',
                    'image' => 'https://images.unsplash.com/photo-1584308666744-24d5c474f2ae?w=150',
                ],
            ],
        ],
        'SO-00002' => [
            'name' => 'SO-00002',
            'order_number' => 'SO-00002',
            'customer' => 'CUST-00002',
            'customer_name' => 'Mohamed Al Naimi',
            'contact_email' => 'mohamed@example.com',
            'contact_phone' => '+974 5566 7788',
            'transaction_date' => '2026-05-26 09:35:00',
            'delivery_date' => '2026-05-27',
            'grand_total' => 380.50,
            'status' => 'Packing',
            'payment_method' => 'Cash on Delivery (COD)',
            'payment_status' => 'pending',
            'custom_payment_method' => 'Cash on Delivery (COD)',
            'custom_payment_status' => 'Pending',
            'financial_status' => 'pending',
            'paid_amount' => 0.00,
            'total_outstanding' => 380.50,
            'currency' => 'QAR',
            'custom_city' => 'Doha',
            'custom_zone' => 'Zone B',
            'custom_coordinator' => 'Layla Al-Mannai',
            'custom_driver' => 'Faisal Al-Kuwari',
            'custom_driver_status' => 'Assigned',
            'custom_picker' => 'Noora Hassan',
            'custom_packer' => 'Salem Abdulla',
            'custom_channel' => 'Mobile',
            'custom_tat' => '1h 50m',
            'custom_bags' => 1,
            'custom_picking_status' => '1/1 Picked',
            'custom_packing_status' => '0/1 Packed',
            'custom_shopify_status' => 'Unfulfilled',
            'custom_notes' => 'Ring door bell twice.',
            'custom_shipping_address_line1' => 'Tower 3, Apt 1402, Pearl Qatar',
            'custom_shipping_address_line2' => 'Porto Arabia',
            'custom_shipping_city' => 'Doha',
            'custom_shipping_country' => 'Qatar',
            'custom_latitude' => 25.371987,
            'custom_longitude' => 51.550008,
            'custom_tags' => 'Standard',
            'line_items' => [
                [
                    'id' => 201,
                    'name' => 'Ergonomic Baby Carrier - Midnight Blue',
                    'sku' => 'EBC-001',
                    'custom_barcode' => '890987654111',
                    'quantity' => 1,
                    'price' => 280.50,
                    'amount' => 280.50,
                    'warehouse' => 'Fulfillment Center Hilal (F01)',
                    'custom_bin' => 'BIN-A-04',
                    'custom_status' => 'Picked',
                    'image' => 'https://images.unsplash.com/photo-1519689680058-324335c77eba?w=150',
                ],
                [
                    'id' => 202,
                    'name' => 'Silicone Feeding Bib Set (2-Pack)',
                    'sku' => 'SFBS-02',
                    'custom_barcode' => '890987654222',
                    'quantity' => 2,
                    'price' => 50.00,
                    'amount' => 100.00,
                    'warehouse' => 'Fulfillment Center Hilal (F01)',
                    'custom_bin' => 'BIN-B-02',
                    'custom_status' => 'Picked',
                    'image' => 'https://images.unsplash.com/photo-1584308666744-24d5c474f2ae?w=150',
                ],
            ],
        ],
        'SO-00003' => [
            'name' => 'SO-00003',
            'order_number' => 'SO-00003',
            'customer' => 'CUST-00003',
            'customer_name' => 'Fatima Al-Kuwari',
            'contact_email' => 'fatima@example.com',
            'contact_phone' => '+974 5599 1122',
            'transaction_date' => '2026-05-26 11:20:00',
            'delivery_date' => '2026-05-27',
            'grand_total' => 520.75,
            'status' => 'Pending',
            'payment_method' => 'Cash on Delivery (COD)',
            'payment_status' => 'pending',
            'custom_payment_method' => 'Cash on Delivery (COD)',
            'custom_payment_status' => 'Pending',
            'financial_status' => 'pending',
            'paid_amount' => 0.00,
            'total_outstanding' => 520.75,
            'currency' => 'QAR',
            'custom_city' => 'Al Rayyan',
            'custom_zone' => 'Zone C',
            'custom_coordinator' => 'Issa Al Thani',
            'custom_driver' => 'Hassan Al-Saadi',
            'custom_driver_status' => 'Pending',
            'custom_picker' => 'Reem Al Ansari',
            'custom_packer' => 'Maha Al Kuwari',
            'custom_channel' => 'Store',
            'custom_tat' => '3h 05m',
            'custom_bags' => 3,
            'custom_picking_status' => '0/3 Picked',
            'custom_packing_status' => '0/3 Packed',
            'custom_shopify_status' => 'Unfulfilled',
            'custom_notes' => 'Fragile item, handle with care.',
            'custom_shipping_address_line1' => 'Building 8, Street 910',
            'custom_shipping_address_line2' => 'Al Rayyan Compound',
            'custom_shipping_city' => 'Al Rayyan',
            'custom_shipping_country' => 'Qatar',
            'custom_latitude' => 25.291987,
            'custom_longitude' => 51.420008,
            'custom_tags' => 'High Value, Fragile',
            'line_items' => [
                [
                    'id' => 301,
                    'name' => 'Convertible Baby High Chair',
                    'sku' => 'CHC-99',
                    'custom_barcode' => '890987654333',
                    'quantity' => 1,
                    'price' => 450.00,
                    'amount' => 450.00,
                    'warehouse' => 'Fulfillment Center Hilal (F01)',
                    'custom_bin' => 'BIN-D-01',
                    'custom_status' => 'Pending',
                    'image' => 'https://images.unsplash.com/photo-1519689680058-324335c77eba?w=150',
                ],
                [
                    'id' => 302,
                    'name' => 'Soft Plush Teether Toy',
                    'sku' => 'SPTT-05',
                    'custom_barcode' => '890987654444',
                    'quantity' => 2,
                    'price' => 35.375,
                    'amount' => 70.75,
                    'warehouse' => 'Fulfillment Center Hilal (F01)',
                    'custom_bin' => 'BIN-A-01',
                    'custom_status' => 'Pending',
                    'image' => 'https://images.unsplash.com/photo-1584308666744-24d5c474f2ae?w=150',
                ],
            ],
        ],
    ];

    public function __construct(ShopifyService $shopifyService)
    {
        $this->shopifyService = $shopifyService;
    }

    /**
     * Retrieve unified order details.
     * All stored database information takes precedence, and remaining info is enriched from Shopify.
     *
     * @param string|int $orderId
     * @return array{success: bool, data: ?array, source: string, message?: string}
     */
    public function getOrderDetails(string|int $orderId): array
    {
        $orderId = trim(urldecode((string)$orderId));
        $numericId = ltrim($orderId, '#');

        $candidateKeys = array_values(array_unique(array_filter([
            $orderId,
            $numericId,
            "#{$numericId}",
            "SO-" . str_pad($numericId, 5, '0', STR_PAD_LEFT),
            "SO-" . $numericId,
        ])));

        // 1. Look in local database
        $dbOrder = $this->findLocalOrder($candidateKeys, $numericId);

        // 2. Attempt to fetch corresponding Shopify order
        $shopifyOrder = $this->findShopifyOrder($dbOrder, $candidateKeys, $orderId, $numericId);

        // 3. If found in database
        if ($dbOrder) {
            $data = $this->buildMergedOrderData($dbOrder, $shopifyOrder, $orderId);
            return [
                'success' => true,
                'data' => $data,
                'source' => $shopifyOrder ? 'database_shopify' : 'database',
            ];
        }

        // 4. If not in DB, but found in Shopify live data
        if ($shopifyOrder) {
            $data = $this->buildMergedOrderData(null, $shopifyOrder, $orderId);
            return [
                'success' => true,
                'data' => $data,
                'source' => 'shopify',
            ];
        }

        // 5. Predefined mock orders repository check
        $lookupKeys = [$orderId, $numericId, "#{$numericId}", "SO-" . str_pad($numericId, 5, '0', STR_PAD_LEFT)];
        foreach ($lookupKeys as $key) {
            if (isset($this->mockOrders[$key])) {
                $mockData = $this->mockOrders[$key];
                return [
                    'success' => true,
                    'data' => $mockData,
                    'source' => 'mock',
                ];
            }
        }

        return [
            'success' => false,
            'message' => 'Order not found',
            'data' => null,
            'source' => 'none',
        ];
    }

    /**
     * Find local Order model from database using candidate keys.
     */
    public function findLocalOrder(array $candidateKeys, ?string $numericId = null): ?Order
    {
        if (empty($candidateKeys)) {
            return null;
        }

        $relations = [
            'items.assignedUser',
            'items.pickedUser',
            'items.packedUser',
            'items.deliveredUser',
            'items.packerVerifiedUser',
            'assignedUser',
            'pickedUser',
            'packedUser',
            'deliveredUser',
            'packerAssignment',
            'driverAssignment',
            'payment',
        ];

        static $tableCache = [];
        $checkTable = function (string $table) use (&$tableCache): bool {
            if (!isset($tableCache[$table])) {
                try {
                    $tableCache[$table] = Schema::hasTable($table);
                } catch (\Throwable $e) {
                    $tableCache[$table] = false;
                }
            }
            return $tableCache[$table];
        };

        if ($checkTable('order_installations')) {
            $relations[] = 'items.installation';
        }
        if ($checkTable('order_item_discrepancies')) {
            $relations[] = 'discrepancies.user';
        }
        if ($checkTable('order_status_logs')) {
            $relations[] = 'logs.user';
        }
        if ($checkTable('order_return_replacements')) {
            $relations[] = 'returnReplacements';
        }

        try {
            return Order::with($relations)->where(function ($q) use ($candidateKeys, $numericId) {
                if ($numericId !== null && is_numeric($numericId)) {
                    $q->where('id', (int) $numericId);
                }
                $q->orWhereIn('order_number', $candidateKeys);
                foreach ($candidateKeys as $k) {
                    $q->orWhere('order_number', 'like', "%{$k}%");
                }
                $q->orWhereHas('payment', function ($pq) use ($candidateKeys) {
                    $pq->whereIn('shopify_order_id', $candidateKeys);
                });
            })->first();
        } catch (\Throwable $e) {
            Log::error("OrderDetailsService::findLocalOrder Exception: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Attempt to find Shopify order data via ShopifyService.
     */
    protected function findShopifyOrder(?Order $dbOrder, array $candidateKeys, string $orderId, string $numericId): ?array
    {
        // 1. If DB order has a Shopify order ID in payment tracking
        if ($dbOrder && $dbOrder->payment && !empty($dbOrder->payment->shopify_order_id)) {
            try {
                $result = $this->shopifyService->getOrder($dbOrder->payment->shopify_order_id);
                if (!empty($result['success']) && !empty($result['order'])) {
                    return $result['order'];
                }
            } catch (\Throwable $e) {
                Log::warning("ShopifyService getOrder error by payment shopify_order_id: " . $e->getMessage());
            }
        }

        // 2. If ID looks like numeric Shopify internal ID (> 5 digits)
        if (is_numeric($numericId) && strlen($numericId) >= 6) {
            try {
                $result = $this->shopifyService->getOrder($numericId);
                if (!empty($result['success']) && !empty($result['order'])) {
                    return $result['order'];
                }
            } catch (\Throwable $e) {
                // Continue to name search
            }
        }

        // 3. Search Shopify API by order name/number parameter
        $searchNames = array_unique(array_filter([
            $dbOrder?->order_number,
            $orderId,
            "#{$numericId}",
            $numericId,
        ]));

        foreach ($searchNames as $name) {
            try {
                $result = $this->shopifyService->getOrders(params: ['name' => $name]);
                if (!empty($result['success']) && !empty($result['orders'])) {
                    foreach ($result['orders'] as $sOrder) {
                        $sName = (string)($sOrder['name'] ?? '');
                        $sNum = (string)($sOrder['order_number'] ?? '');
                        if ($sName === $name || $sNum === $name || "#{$sNum}" === $name || $sName === "#{$name}") {
                            return $sOrder;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Continue
            }
        }

        // 4. Scan recent orders from getOrders()
        try {
            $ordersResult = $this->shopifyService->getOrders();
            if (!empty($ordersResult['success']) && !empty($ordersResult['orders'])) {
                foreach ($ordersResult['orders'] as $sOrder) {
                    $sId = (string)($sOrder['id'] ?? '');
                    $sName = (string)($sOrder['name'] ?? '');
                    $sNum = (string)($sOrder['order_number'] ?? '');

                    if (
                        in_array($sId, $candidateKeys, true) ||
                        in_array($sName, $candidateKeys, true) ||
                        in_array($sNum, $candidateKeys, true) ||
                        in_array("#{$sNum}", $candidateKeys, true) ||
                        ($dbOrder && $sName === $dbOrder->order_number)
                    ) {
                        return $sOrder;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning("ShopifyService getOrders list scan error in OrderDetailsService: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Build unified Sales Order data array.
     * All stored database values take precedence; remaining values are extracted from Shopify.
     */
    public function buildMergedOrderData(?Order $dbOrder, ?array $shopifyOrder = null, string $fallbackIdentifier = ''): array
    {
        $placeholders = [
            'https://images.unsplash.com/photo-1519689680058-324335c77eba?w=150',
            'https://images.unsplash.com/photo-1584308666744-24d5c474f2ae?w=150',
            'https://images.unsplash.com/photo-1515488042361-ee00e0ddd4e4?w=150',
            'https://images.unsplash.com/photo-1522771739844-6a9f6d5f14af?w=150',
        ];

        // 1. Order Number & Name
        $orderNumber = $dbOrder?->order_number
            ?: ($shopifyOrder['name'] ?? ('SO-' . ($shopifyOrder['order_number'] ?? $shopifyOrder['id'] ?? $fallbackIdentifier)));

        // 2. Customer details
        $customerName = $dbOrder?->customer_name;
        if (empty($customerName) && $shopifyOrder) {
            $customerName = trim(($shopifyOrder['customer']['first_name'] ?? '') . ' ' . ($shopifyOrder['customer']['last_name'] ?? ''));
            if (!$customerName) {
                $customerName = $shopifyOrder['shipping_address']['name'] ?? $shopifyOrder['billing_address']['name'] ?? $shopifyOrder['customer']['email'] ?? $shopifyOrder['email'] ?? null;
            }
        }
        if (empty($customerName)) {
            $customerName = 'Customer (' . $orderNumber . ')';
        }

        $customerId = $shopifyOrder ? ('CUST-' . ($shopifyOrder['customer']['id'] ?? $shopifyOrder['id'] ?? '')) : null;
        if (!$customerId && $dbOrder) {
            $customerId = 'CUST-' . $dbOrder->id;
        }
        if (!$customerId) {
            $customerId = 'CUST-' . abs(crc32($fallbackIdentifier ?: $orderNumber));
        }

        // Contact info: Phone from DB, Email from Shopify (since DB has phone, not email)
        $phone = $dbOrder?->customer_phone;
        if (empty($phone) && $shopifyOrder) {
            $phone = $shopifyOrder['customer']['phone'] ?? $shopifyOrder['shipping_address']['phone'] ?? $shopifyOrder['billing_address']['phone'] ?? $shopifyOrder['phone'] ?? null;
        }
        $email = $shopifyOrder['customer']['email'] ?? $shopifyOrder['contact_email'] ?? $shopifyOrder['email'] ?? '';

        // Dates
        $createdAtTime = isset($shopifyOrder['created_at'])
            ? strtotime($shopifyOrder['created_at'])
            : ($dbOrder?->created_at ? $dbOrder->created_at->timestamp : time());

        $transactionDate = date('Y-m-d H:i:s', $createdAtTime);

        $deliveredAtDate = $dbOrder?->driverAssignment?->delivered_at
            ?: ($dbOrder?->delivered_at ?: null);
        $deliveryDate = $deliveredAtDate
            ? $deliveredAtDate->format('Y-m-d')
            : date('Y-m-d', strtotime('+1 day', $createdAtTime));

        // Status
        if ($dbOrder) {
            $status = $this->mapDbStatusToFrontend($dbOrder->status);
        } elseif ($shopifyOrder) {
            $fulfillmentStatus = strtolower($shopifyOrder['fulfillment_status'] ?? 'unfulfilled');
            if ($fulfillmentStatus === 'fulfilled') {
                $status = 'Delivered';
            } elseif ($fulfillmentStatus === 'partial') {
                $status = 'Picking';
            } else {
                $status = 'Pending';
            }
        } else {
            $status = 'Pending';
        }

        // Grand Total
        if ($dbOrder && isset($dbOrder->total_amount) && (float)$dbOrder->total_amount > 0) {
            $grandTotal = (float)$dbOrder->total_amount;
        } elseif ($shopifyOrder && isset($shopifyOrder['total_price'])) {
            $grandTotal = (float)$shopifyOrder['total_price'];
        } else {
            $grandTotal = 0.00;
        }

        // Payment
        $dbOrder?->loadMissing('payment');
        $paymentRecord = $dbOrder?->payment;

        if ($paymentRecord) {
            $paymentMethod = $paymentRecord->payment_method ?: ($dbOrder->payment_method ?: 'Cash on Delivery (COD)');
            $financialStatus = $paymentRecord->payment_status ?: ($dbOrder->payment_status ?: 'pending');
            $paidAmount = (float)$paymentRecord->paid_amount;
            $totalPrice = (float)$paymentRecord->total_price ?: $grandTotal;
            $totalOutstanding = (float)$paymentRecord->total_outstanding;
            $currency = $paymentRecord->currency ?: 'QAR';
            $processedAt = $paymentRecord->processed_at ? $paymentRecord->processed_at->toIso8601String() : null;
            $shopifyCreatedAt = $paymentRecord->shopify_created_at ? $paymentRecord->shopify_created_at->toIso8601String() : null;
            $shopifyUpdatedAt = $paymentRecord->shopify_updated_at ? $paymentRecord->shopify_updated_at->toIso8601String() : null;
            $paymentId = $paymentRecord->id;
            $shopifyPaymentOrderId = $paymentRecord->shopify_order_id;
        } elseif ($dbOrder && (!empty($dbOrder->payment_method) || !empty($dbOrder->payment_status))) {
            $paymentMethod = $dbOrder->payment_method ?: 'Cash on Delivery (COD)';
            $financialStatus = $dbOrder->payment_status ?: 'pending';
            $paidAmount = isset($dbOrder->collected_amount) ? (float)$dbOrder->collected_amount : 0.00;
            $totalPrice = $grandTotal;
            $totalOutstanding = max(0.00, $totalPrice - $paidAmount);
            $currency = 'QAR';
            $processedAt = null;
            $shopifyCreatedAt = null;
            $shopifyUpdatedAt = null;
            $paymentId = null;
            $shopifyPaymentOrderId = $shopifyOrder['id'] ?? null;
        } elseif ($shopifyOrder) {
            $gateways = $shopifyOrder['payment_gateway_names'] ?? [];
            $paymentMethod = is_array($gateways) && !empty($gateways) ? implode(', ', array_filter($gateways)) : ($shopifyOrder['gateway'] ?? 'Cash on Delivery (COD)');
            $financialStatus = $shopifyOrder['financial_status'] ?? 'pending';
            $totalPrice = (float)($shopifyOrder['total_price'] ?? $grandTotal);
            $totalOutstanding = (float)($shopifyOrder['total_outstanding'] ?? ($financialStatus === 'paid' ? 0.00 : $totalPrice));
            $paidAmount = max(0.00, $totalPrice - $totalOutstanding);
            $currency = $shopifyOrder['currency'] ?? 'QAR';
            $processedAt = $shopifyOrder['processed_at'] ?? null;
            $shopifyCreatedAt = $shopifyOrder['created_at'] ?? null;
            $shopifyUpdatedAt = $shopifyOrder['updated_at'] ?? null;
            $paymentId = null;
            $shopifyPaymentOrderId = $shopifyOrder['id'] ?? null;
        } else {
            $paymentMethod = 'Cash on Delivery (COD)';
            $financialStatus = 'pending';
            $paidAmount = 0.00;
            $totalPrice = $grandTotal;
            $totalOutstanding = $grandTotal;
            $currency = 'QAR';
            $processedAt = null;
            $shopifyCreatedAt = null;
            $shopifyUpdatedAt = null;
            $paymentId = null;
            $shopifyPaymentOrderId = null;
        }

        $paymentStatusStr = ucfirst(str_replace('_', ' ', (string)$financialStatus));

        // Shipping address: Delivery address from DB, additional fields from Shopify
        $shippingAddress = $shopifyOrder['shipping_address'] ?? $shopifyOrder['billing_address'] ?? [];
        $addressLine1 = $dbOrder?->delivery_address
            ?: ($shippingAddress['address1'] ?? ($shippingAddress['address2'] ?? 'Building 12, Street 340'));
        $addressLine2 = $shippingAddress['address2'] ?? '';
        $shippingCity = $shippingAddress['city'] ?? 'Doha';
        $shippingCountry = $shippingAddress['country'] ?? 'Qatar';
        $lat = (float)($shippingAddress['latitude'] ?? 25.276987);
        $lng = (float)($shippingAddress['longitude'] ?? 51.520008);
        $city = $shippingCity ?: 'Doha';
        $zone = $shippingAddress['province'] ?? $shippingCity ?: 'Zone A';

        // Additional Shopify attributes
        $channel = isset($shopifyOrder['source_name']) ? ucfirst(str_replace('_', ' ', $shopifyOrder['source_name'])) : 'Web';
        $shopifyStatus = ucfirst($shopifyOrder['fulfillment_status'] ?? ($dbOrder?->status === 'delivered' ? 'fulfilled' : 'unfulfilled'));
        $notes = $shopifyOrder['note'] ?? 'Handle with care. Call upon arrival.';
        $tags = !empty($shopifyOrder['tags']) ? $shopifyOrder['tags'] : 'Standard';
        $returnCount = count($shopifyOrder['refunds'] ?? []);
        if ($returnCount === 0 && $dbOrder) {
            try {
                if (Schema::hasTable('order_return_replacements')) {
                    $returnCount = $dbOrder->returnReplacements()->count();
                }
            } catch (\Throwable $e) {
                $returnCount = 0;
            }
        }

        // 3. Line Items Merging
        $mergedLineItems = $this->mergeLineItems($dbOrder, $shopifyOrder, $placeholders);

        $totalItemsCount = max(count($mergedLineItems), 1);
        $pickedItemsCount = count(array_filter($mergedLineItems, fn($i) => in_array(strtolower($i['custom_status'] ?? ''), ['picked', 'packed', 'delivered'])));
        $packedItemsCount = count(array_filter($mergedLineItems, fn($i) => in_array(strtolower($i['custom_status'] ?? ''), ['packed', 'delivered'])));
        $deliveredItemsCount = count(array_filter($mergedLineItems, fn($i) => strtolower($i['custom_status'] ?? '') === 'delivered'));
        $installationItemsCount = count(array_filter($mergedLineItems, fn($i) => !empty($i['is_installable']) || !empty($i['installation'])));

        // Operational assignments from DB
        $pickerName = $dbOrder?->assigned_user_name ?: ($dbOrder?->assignedUser?->name ?? null);
        if (!$pickerName && $dbOrder) {
            $pickers = $dbOrder->items->map(fn($i) => $i->picked_user_name ?: ($i->pickedUser->name ?? ($i->assigned_user_name ?: null)))->filter()->unique()->values();
            $pickerName = $pickers->isNotEmpty() ? implode(', ', $pickers->all()) : null;
        }
        $assignedTo = $dbOrder?->assigned_to ? (int)$dbOrder->assigned_to : null;
        $assignedUserName = $dbOrder?->assigned_user_name ?: ($dbOrder?->assignedUser?->name ?? null);
        $pickedByUserId = $dbOrder?->items?->whereNotNull('picked_by')->pluck('picked_by')->first() ?: $dbOrder?->assigned_to;

        $packerName = $dbOrder?->packed_user_name ?: ($dbOrder?->packedUser?->name ?? null);
        if (!$packerName && $dbOrder) {
            $packers = $dbOrder->items->map(fn($i) => $i->packed_user_name ?: ($i->packedUser->name ?? null))->filter()->unique()->values();
            $packerName = $packers->isNotEmpty() ? implode(', ', $packers->all()) : null;
        }
        $packedByUserId = $dbOrder?->packed_by
            ?: ($dbOrder?->items?->whereNotNull('packed_by')->pluck('packed_by')->first()
                ?: $dbOrder?->items?->whereNotNull('packer_verified_by')->pluck('packer_verified_by')->first());

        $driverName = $dbOrder?->driverAssignment?->driver_name ?: ($dbOrder?->delivered_user_name ?: ($dbOrder?->deliveredUser?->name ?? null));
        if (!$driverName && $dbOrder) {
            $drivers = $dbOrder->items->map(fn($i) => $i->delivered_user_name ?: ($i->deliveredUser->name ?? null))->filter()->unique()->values();
            $driverName = $drivers->isNotEmpty() ? implode(', ', $drivers->all()) : null;
        }
        $deliveredByUserId = $dbOrder?->driverAssignment?->assigned_driver_user_id
            ?: ($dbOrder?->delivered_by ?: $dbOrder?->items?->whereNotNull('delivered_by')->pluck('delivered_by')->first());

        if ($dbOrder?->driverAssignment) {
            $driverStatus = ucfirst(strtolower($dbOrder->driverAssignment->driver_status));
        } elseif ($dbOrder && strtolower($dbOrder->status) === 'delivered') {
            $driverStatus = 'Delivered';
        } elseif ($dbOrder && strtolower($dbOrder->status) === 'out_for_delivery') {
            $driverStatus = 'In Transit';
        } elseif ($dbOrder && ($dbOrder->delivered_by || $dbOrder->delivered_user_name)) {
            $driverStatus = 'Assigned';
        } else {
            $driverStatus = 'Pending';
        }

        $bagCount = ($dbOrder && isset($dbOrder->bag_count) && (int)$dbOrder->bag_count > 0)
            ? (int)$dbOrder->bag_count
            : max(count($mergedLineItems), 1);

        // Build unique pickers and packers arrays
        $pickersList = [];
        $packersList = [];
        if ($dbOrder) {
            $pickersList = $dbOrder->items->map(function ($item) {
                if ($item->picked_by || $item->picked_user_name) {
                    return [
                        'id' => $item->picked_by ? (int)$item->picked_by : null,
                        'name' => $item->pickedUser ? $item->pickedUser->name : ($item->picked_user_name ?? 'Picker User'),
                        'picked_at' => $item->picked_at ? $item->picked_at->toIso8601String() : null,
                    ];
                }
                return null;
            })->filter()->unique('name')->values()->all();

            $packersList = $dbOrder->items->map(function ($item) {
                if ($item->packed_by || $item->packed_user_name) {
                    return [
                        'id' => $item->packed_by ? (int)$item->packed_by : null,
                        'name' => $item->packedUser ? $item->packedUser->name : ($item->packed_user_name ?? 'Packer User'),
                        'packed_at' => $item->packed_at ? $item->packed_at->toIso8601String() : null,
                    ];
                }
                return null;
            })->filter()->unique('name')->values()->all();
        }

        return [
            // Standard Sales Order frontend schema
            'order_id' => $dbOrder?->id,
            'order_number' => $orderNumber,
            'name' => $orderNumber,
            'customer' => $customerId,
            'customer_name' => $customerName,
            'contact_email' => $email,
            'contact_phone' => $phone,
            'transaction_date' => $transactionDate,
            'delivery_date' => $deliveryDate,
            'grand_total' => $grandTotal,
            'status' => $status,
            'payment_method' => $paymentMethod,
            'payment_status' => $financialStatus,
            'custom_payment_method' => $paymentMethod,
            'custom_payment_status' => $paymentStatusStr,
            'financial_status' => $financialStatus,
            'paid_amount' => $paidAmount,
            'total_outstanding' => $totalOutstanding,
            'currency' => $currency,
            'payment' => [
                'id' => $paymentId,
                'shopify_order_id' => $shopifyPaymentOrderId,
                'payment_method' => $paymentMethod,
                'payment_status' => $financialStatus,
                'paid_amount' => $paidAmount,
                'total_price' => $totalPrice,
                'total_outstanding' => $totalOutstanding,
                'currency' => $currency,
                'processed_at' => $processedAt,
                'shopify_created_at' => $shopifyCreatedAt,
                'shopify_updated_at' => $shopifyUpdatedAt,
            ],
            'custom_city' => $city,
            'custom_zone' => $zone,
            'custom_coordinator' => 'Unassigned',
            'custom_driver' => $driverName ?: 'Unassigned',
            'custom_driver_status' => $driverStatus,
            'custom_picker' => $pickerName ?: 'Unassigned',
            'custom_packer' => $packerName ?: 'Unassigned',
            'custom_channel' => $channel,
            'custom_tat' => '2h 00m',
            'custom_bags' => $bagCount,
            'custom_picking_status' => "{$pickedItemsCount}/{$totalItemsCount} Picked",
            'custom_packing_status' => "{$packedItemsCount}/{$totalItemsCount} Packed",
            'custom_shopify_status' => $shopifyStatus,
            'custom_notes' => $notes,
            'custom_shipping_address_line1' => $addressLine1,
            'custom_shipping_address_line2' => $addressLine2,
            'custom_shipping_city' => $shippingCity,
            'custom_shipping_country' => $shippingCountry,
            'custom_latitude' => $lat,
            'custom_longitude' => $lng,
            'custom_return_count' => $returnCount,
            'custom_tags' => $tags,

            // Identity & Assignment helper fields
            'assigned_to' => $assignedTo,
            'assigned_user_name' => $assignedUserName,
            'picked_by' => $pickedByUserId ? (int)$pickedByUserId : null,
            'picked_user_name' => $pickerName,
            'packed_by' => $packedByUserId ? (int)$packedByUserId : null,
            'packed_user_name' => $packerName,
            'delivered_by' => $deliveredByUserId ? (int)$deliveredByUserId : null,
            'delivered_user_name' => $driverName,
            'driver_assignment' => $dbOrder?->driverAssignment ? [
                'id' => $dbOrder->driverAssignment->id,
                'assigned_driver_user_id' => (int)$dbOrder->driverAssignment->assigned_driver_user_id,
                'driver_name' => $dbOrder->driverAssignment->driver_name,
                'zone' => $dbOrder->driverAssignment->zone,
                'driver_status' => $dbOrder->driverAssignment->driver_status,
                'assigned_at' => $dbOrder->driverAssignment->assigned_at ? $dbOrder->driverAssignment->assigned_at->toIso8601String() : null,
                'accepted_at' => $dbOrder->driverAssignment->accepted_at ? $dbOrder->driverAssignment->accepted_at->toIso8601String() : null,
                'started_at' => $dbOrder->driverAssignment->started_at ? $dbOrder->driverAssignment->started_at->toIso8601String() : null,
                'delivered_at' => $dbOrder->driverAssignment->delivered_at ? $dbOrder->driverAssignment->delivered_at->toIso8601String() : null,
            ] : null,
            'driver_user' => $dbOrder?->driverAssignment ? [
                'id' => (int)$dbOrder->driverAssignment->assigned_driver_user_id,
                'name' => $dbOrder->driverAssignment->driver_name,
                'assigned_at' => $dbOrder->driverAssignment->assigned_at ? $dbOrder->driverAssignment->assigned_at->toIso8601String() : null,
            ] : null,
            'pickers' => $pickersList,
            'packers' => $packersList,
            'summary' => [
                'total_amount' => (float)$grandTotal,
                'total_items' => $totalItemsCount,
                'picked_items' => $pickedItemsCount,
                'packed_items' => $packedItemsCount,
                'delivered_items' => $deliveredItemsCount,
                'installation_items' => $installationItemsCount,
                'has_installation' => $installationItemsCount > 0,
            ],
            // Both line_items and items for universal consumer compatibility
            'line_items' => $mergedLineItems,
            'items' => $mergedLineItems,
            'created_at' => $dbOrder?->created_at ? $dbOrder->created_at->toIso8601String() : date('c', $createdAtTime),
            'updated_at' => $dbOrder?->updated_at ? $dbOrder->updated_at->toIso8601String() : date('c', $createdAtTime),
        ];
    }

    /**
     * Merge line items from local DB order and Shopify order.
     */
    protected function mergeLineItems(?Order $dbOrder, ?array $shopifyOrder, array $placeholders): array
    {
        $shopifyLineItems = $shopifyOrder['line_items'] ?? [];
        $merged = [];
        $matchedShopifyIndices = [];

        // If local DB has items, each DB item is primary
        if ($dbOrder && $dbOrder->items->isNotEmpty()) {
            foreach ($dbOrder->items as $index => $dbItem) {
                $sLineId = (string)($dbItem->line_item_id ?? '');
                $sSku = (string)($dbItem->product_code ?? '');
                $sBarcode = (string)($dbItem->barcode ?? '');

                // Match in Shopify line items
                $matchingShopifyIndex = null;
                $matchingShopifyItem = null;

                foreach ($shopifyLineItems as $sIdx => $sItem) {
                    if (in_array($sIdx, $matchedShopifyIndices, true)) {
                        continue;
                    }
                    if (!empty($sLineId) && (string)($sItem['id'] ?? '') === $sLineId) {
                        $matchingShopifyIndex = $sIdx;
                        $matchingShopifyItem = $sItem;
                        break;
                    }
                    if (!empty($sSku) && (string)($sItem['sku'] ?? '') === $sSku) {
                        $matchingShopifyIndex = $sIdx;
                        $matchingShopifyItem = $sItem;
                        break;
                    }
                    if (!empty($sBarcode) && (string)($sItem['barcode'] ?? '') === $sBarcode) {
                        $matchingShopifyIndex = $sIdx;
                        $matchingShopifyItem = $sItem;
                        break;
                    }
                }

                // If not matched by ID/SKU/Barcode, attempt positional match
                if ($matchingShopifyIndex === null && isset($shopifyLineItems[$index]) && !in_array($index, $matchedShopifyIndices, true)) {
                    $matchingShopifyIndex = $index;
                    $matchingShopifyItem = $shopifyLineItems[$index];
                }

                if ($matchingShopifyIndex !== null) {
                    $matchedShopifyIndices[] = $matchingShopifyIndex;
                }

                $qty = (int)$dbItem->quantity;
                $price = (float)$dbItem->unit_price;
                $amount = (float)($qty * $price);

                $image = $dbItem->image
                    ?: ($matchingShopifyItem['image']['src'] ?? $matchingShopifyItem['featured_image']['src'] ?? ($matchingShopifyItem['image'] ?? null));
                if (!$image) {
                    $image = $placeholders[$index % count($placeholders)];
                }

                $binLetter = chr(65 + ($index % 4));
                $binNum = sprintf('%02d', ($index % 15) + 1);
                $bin = $dbItem->bin ?? "BIN-{$binLetter}-{$binNum}";

                // Installation details
                $installationObj = null;
                $instType = null;
                $instLevel = null;
                $isScheduledAssigned = false;

                if ($dbItem->relationLoaded('installation') && $dbItem->installation) {
                    $instType = $dbItem->installation->installation_type;
                    $instLevel = $dbItem->installation->installation_level;
                    $isScheduledAssigned = (bool)($dbItem->installation->is_scheduled_assigned ?? false);
                    $installationObj = [
                        'id' => $dbItem->installation->id,
                        'installation_type' => $instType,
                        'installation_level' => $instLevel,
                        'is_scheduled_assigned' => $isScheduledAssigned,
                    ];
                } elseif (!empty($matchingShopifyItem['installation_type']) || !empty($matchingShopifyItem['installation_level'])) {
                    $instType = $matchingShopifyItem['installation_type'] ?? null;
                    $instLevel = $matchingShopifyItem['installation_level'] ?? null;
                    $installationObj = [
                        'id' => null,
                        'installation_type' => $instType,
                        'installation_level' => $instLevel,
                        'is_scheduled_assigned' => false,
                    ];
                }

                $itemStatus = ucfirst(strtolower($dbItem->status ?: 'pending'));

                $merged[] = [
                    'id' => $dbItem->line_item_id ? (is_numeric($dbItem->line_item_id) ? (int)$dbItem->line_item_id : $dbItem->line_item_id) : ($matchingShopifyItem['id'] ?? $dbItem->id),
                    'db_item_id' => $dbItem->id,
                    'item_id' => $dbItem->id,
                    'line_item_id' => $dbItem->line_item_id ?: ($matchingShopifyItem['id'] ?? null),
                    'product_id' => $dbItem->product_id ?: ($matchingShopifyItem['product_id'] ?? null),
                    'variant_id' => $matchingShopifyItem['variant_id'] ?? null,
                    'name' => $dbItem->product_name ?: ($matchingShopifyItem['name'] ?? $matchingShopifyItem['title'] ?? ('Product Item ' . ($index + 1))),
                    'title' => $dbItem->product_name ?: ($matchingShopifyItem['name'] ?? $matchingShopifyItem['title'] ?? ('Product Item ' . ($index + 1))),
                    'product_name' => $dbItem->product_name ?: ($matchingShopifyItem['name'] ?? $matchingShopifyItem['title'] ?? ('Product Item ' . ($index + 1))),
                    'sku' => $dbItem->product_code ?: ($matchingShopifyItem['sku'] ?? ('SKU-' . rand(1000, 9999))),
                    'product_code' => $dbItem->product_code ?: ($matchingShopifyItem['sku'] ?? ('SKU-' . rand(1000, 9999))),
                    'barcode' => $dbItem->barcode ?: ($matchingShopifyItem['barcode'] ?? ('890' . sprintf('%09d', abs(crc32($dbItem->id))))),
                    'custom_barcode' => $dbItem->barcode ?: ($matchingShopifyItem['barcode'] ?? ('890' . sprintf('%09d', abs(crc32($dbItem->id))))),
                    'quantity' => $qty,
                    'price' => $price,
                    'unit_price' => $price,
                    'amount' => $amount,
                    'warehouse' => 'Fulfillment Center Hilal (F01)',
                    'custom_bin' => $bin,
                    'status' => $dbItem->status,
                    'custom_status' => $itemStatus,
                    'image' => $image,
                    'image_url' => $image,
                    'product_image' => $image,
                    'product_image_url' => $image,
                    'is_installable' => (bool)$dbItem->is_installable,
                    'installation_type' => $instType,
                    'installation_level' => $instLevel,
                    'installation' => $installationObj,
                    'is_scheduled_assigned' => $isScheduledAssigned,
                    'is_flagged' => (bool)$dbItem->is_flagged,
                    'flag_reason' => $dbItem->flag_reason,
                    'is_packer_verified' => (bool)$dbItem->is_packer_verified,
                    'packer_verified_by' => $dbItem->packer_verified_by ? (int)$dbItem->packer_verified_by : null,
                    'packer_verified_user_name' => $dbItem->packer_verified_user_name ?: ($dbItem->packerVerifiedUser?->name ?? null),
                    'packer_verified_at' => $dbItem->packer_verified_at ? $dbItem->packer_verified_at->toIso8601String() : null,
                    'assigned_to' => $dbItem->assigned_to ? (int)$dbItem->assigned_to : null,
                    'assigned_user_name' => $dbItem->assigned_user_name ?: ($dbItem->assignedUser?->name ?? null),
                    'assigned_at' => $dbItem->assigned_at ? $dbItem->assigned_at->toIso8601String() : null,
                    'picked_by' => $dbItem->picked_by ? (int)$dbItem->picked_by : null,
                    'picked_user_name' => $dbItem->picked_user_name ?: ($dbItem->pickedUser?->name ?? null),
                    'picked_at' => $dbItem->picked_at ? $dbItem->picked_at->toIso8601String() : null,
                    'packed_by' => $dbItem->packed_by ? (int)$dbItem->packed_by : null,
                    'packed_user_name' => $dbItem->packed_user_name ?: ($dbItem->packedUser?->name ?? null),
                    'packed_at' => $dbItem->packed_at ? $dbItem->packed_at->toIso8601String() : null,
                    'delivered_by' => $dbItem->delivered_by ? (int)$dbItem->delivered_by : null,
                    'delivered_user_name' => $dbItem->delivered_user_name ?: ($dbItem->deliveredUser?->name ?? null),
                    'delivered_at' => $dbItem->delivered_at ? $dbItem->delivered_at->toIso8601String() : null,
                    'metafields' => $matchingShopifyItem['metafields'] ?? [],
                ];
            }
        }

        // Add any remaining unmatched line items from Shopify
        foreach ($shopifyLineItems as $sIdx => $sItem) {
            if (in_array($sIdx, $matchedShopifyIndices, true)) {
                continue;
            }

            $qty = (int)($sItem['quantity'] ?? 1);
            $price = (float)($sItem['price'] ?? 0);
            $amount = $qty * $price;
            $index = count($merged);

            $binLetter = chr(65 + ($index % 4));
            $binNum = sprintf('%02d', ($index % 15) + 1);
            $bin = "BIN-{$binLetter}-{$binNum}";

            $image = $sItem['image']['src'] ?? $sItem['featured_image']['src'] ?? ($sItem['image'] ?? null);
            if (!$image) {
                $image = $placeholders[$index % count($placeholders)];
            }

            $barcode = !empty($sItem['barcode']) ? $sItem['barcode'] : ('890' . sprintf('%09d', abs(crc32(($sItem['id'] ?? $index) . ($sItem['sku'] ?? '')))));

            $instType = $sItem['installation_type'] ?? null;
            $instLevel = $sItem['installation_level'] ?? null;
            $installationObj = (!empty($instType) || !empty($instLevel)) ? [
                'id' => null,
                'installation_type' => $instType,
                'installation_level' => $instLevel,
                'is_scheduled_assigned' => false,
            ] : null;

            $merged[] = [
                'id' => $sItem['id'] ?? ($index + 1),
                'db_item_id' => null,
                'item_id' => $sItem['id'] ?? ($index + 1),
                'line_item_id' => $sItem['id'] ?? null,
                'product_id' => $sItem['product_id'] ?? null,
                'variant_id' => $sItem['variant_id'] ?? null,
                'name' => $sItem['name'] ?? $sItem['title'] ?? ('Product Item ' . ($index + 1)),
                'title' => $sItem['name'] ?? $sItem['title'] ?? ('Product Item ' . ($index + 1)),
                'product_name' => $sItem['name'] ?? $sItem['title'] ?? ('Product Item ' . ($index + 1)),
                'sku' => !empty($sItem['sku']) ? $sItem['sku'] : ('SKU-' . rand(1000, 9999)),
                'product_code' => !empty($sItem['sku']) ? $sItem['sku'] : ('SKU-' . rand(1000, 9999)),
                'barcode' => (string)$barcode,
                'custom_barcode' => (string)$barcode,
                'quantity' => $qty,
                'price' => $price,
                'unit_price' => $price,
                'amount' => $amount,
                'warehouse' => 'Fulfillment Center Hilal (F01)',
                'custom_bin' => $bin,
                'status' => 'pending',
                'custom_status' => 'Pending',
                'image' => $image,
                'image_url' => $image,
                'product_image' => $image,
                'product_image_url' => $image,
                'is_installable' => (bool)($sItem['is_installable'] ?? false),
                'installation_type' => $instType,
                'installation_level' => $instLevel,
                'installation' => $installationObj,
                'is_scheduled_assigned' => false,
                'is_flagged' => false,
                'flag_reason' => null,
                'is_packer_verified' => false,
                'packer_verified_by' => null,
                'packer_verified_user_name' => null,
                'packer_verified_at' => null,
                'assigned_to' => null,
                'assigned_user_name' => null,
                'assigned_at' => null,
                'picked_by' => null,
                'picked_user_name' => null,
                'picked_at' => null,
                'packed_by' => null,
                'packed_user_name' => null,
                'packed_at' => null,
                'delivered_by' => null,
                'delivered_user_name' => null,
                'delivered_at' => null,
                'metafields' => $sItem['metafields'] ?? [],
            ];
        }

        return $merged;
    }

    /**
     * Helper to map raw database status string to frontend status string.
     */
    protected function mapDbStatusToFrontend(?string $dbStatus): string
    {
        $dbStatusRaw = strtolower((string)$dbStatus);
        $statusMap = [
            'pending' => 'Pending',
            'picking' => 'Picking',
            'picked' => 'Picked',
            'packing' => 'Packing',
            'packed' => 'Packing',
            'out_for_delivery' => 'Out for Delivery',
            'in_delivery' => 'Out for Delivery',
            'delivered' => 'Delivered',
            'cancelled' => 'Cancelled',
            'delivery_cancelled' => 'Cancelled',
            'cancelled_delivery' => 'Cancelled',
            'delivery_failed' => 'Delivery Failed',
            'failed' => 'Failed',
        ];

        return $statusMap[$dbStatusRaw] ?? ucfirst(str_replace('_', ' ', $dbStatusRaw ?: 'pending'));
    }
}
