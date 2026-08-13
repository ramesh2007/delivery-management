<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\ShopifyService;
use Illuminate\Http\JsonResponse;

class ResourceController extends Controller
{
    protected ShopifyService $shopifyService;

    public function __construct(ShopifyService $shopifyService)
    {
        $this->shopifyService = $shopifyService;
    }

    /**
     * Return Sales Order list populated dynamically from Shopify API.
     * Checks with local `orders` table matching `order_number` to merge local status and line items status.
     *
     * @return JsonResponse
     */
    public function salesOrder(): JsonResponse
    {
        $ordersResult = $this->shopifyService->getOrders();

        if (!empty($ordersResult['success']) && !empty($ordersResult['orders'])) {
            $candidateKeys = [];
            foreach ($ordersResult['orders'] as $order) {
                $candidateKeys = array_merge($candidateKeys, $this->getCandidateKeysForShopifyOrder($order));
            }
            $candidateKeys = array_values(array_unique(array_filter($candidateKeys)));

            $localOrders = Order::with([
                'items.assignedUser',
                'items.pickedUser',
                'items.packedUser',
                'items.deliveredUser',
                'assignedUser',
                'deliveredUser'
            ])->whereIn('order_number', $candidateKeys)->get();

            $localOrderMap = [];
            foreach ($localOrders as $localOrder) {
                $num = (string) $localOrder->order_number;
                $localOrderMap[$num] = $localOrder;
                $localOrderMap['#' . ltrim($num, '#')] = $localOrder;
                $localOrderMap[ltrim($num, '#')] = $localOrder;
            }

            $data = array_map(function ($order) use ($localOrderMap) {
                $dbOrder = $this->findMatchingLocalOrderFromMap($order, $localOrderMap);
                return $this->transformShopifyOrder($order, $dbOrder);
            }, $ordersResult['orders']);

            return response()->json([
                'data' => $data,
                'source' => 'shopify',
                'count' => count($data),
            ]);
        }

        // Fallback sample data if Shopify store is not connected or returns 0 orders
        $fallbackOrders = [
            [
                'name' => 'SO-00001',
                'customer' => 'CUST-00001',
                'customer_name' => 'Sara Al Sulaiti',
                'contact_email' => 'sara@example.com',
                'contact_phone' => '+974 5512 3456',
                'transaction_date' => '2026-05-25 14:10:00',
                'delivery_date' => '2026-05-26',
                'grand_total' => 450.00,
                'status' => 'Picking',
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
                'custom_picking_status' => 'Completed',
                'custom_packing_status' => 'Pending',
                'custom_shopify_status' => 'Unfulfilled',
                'custom_return_count' => 0,
                'custom_tags' => 'Express, Fragile',
            ],
            [
                'name' => 'SO-00002',
                'customer' => 'CUST-00002',
                'customer_name' => 'Mohamed Al Naimi',
                'contact_email' => 'mohamed@example.com',
                'contact_phone' => '+974 5566 7788',
                'transaction_date' => '2026-05-26 09:35:00',
                'delivery_date' => '2026-05-27',
                'grand_total' => 380.50,
                'status' => 'Packing',
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
                'custom_picking_status' => 'Completed',
                'custom_packing_status' => 'In Progress',
                'custom_shopify_status' => 'Unfulfilled',
                'custom_return_count' => 0,
                'custom_tags' => 'Standard',
            ],
            [
                'name' => 'SO-00003',
                'customer' => 'CUST-00003',
                'customer_name' => 'Fatima Al-Kuwari',
                'contact_email' => 'fatima@example.com',
                'contact_phone' => '+974 5599 1122',
                'transaction_date' => '2026-05-26 11:20:00',
                'delivery_date' => '2026-05-27',
                'grand_total' => 520.75,
                'status' => 'Pending',
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
                'custom_picking_status' => 'Pending',
                'custom_packing_status' => 'Pending',
                'custom_shopify_status' => 'Unfulfilled',
                'custom_return_count' => 1,
                'custom_tags' => 'High Value, Fragile',
            ],
        ];

        $fallbackData = array_map(function ($mockOrder) {
            $candidateKeys = [
                $mockOrder['name'] ?? '',
                ltrim($mockOrder['name'] ?? '', '#'),
                '#' . ltrim($mockOrder['name'] ?? '', '#'),
            ];
            $dbOrder = $this->findLocalOrder($candidateKeys);
            if ($dbOrder) {
                return $this->enrichOrderDataWithLocalDb($mockOrder, $dbOrder);
            }
            return $mockOrder;
        }, $fallbackOrders);

        return response()->json([
            'data' => $fallbackData,
            'source' => 'mock_fallback',
            'error' => $ordersResult['error'] ?? null,
        ]);
    }

    /**
     * Transform raw Shopify order array into Sales Order frontend schema.
     * Enriches status and order items status from local DB matching order if available.
     */
    protected function transformShopifyOrder(array $order, ?Order $dbOrder = null): array
    {
        $customerName = trim(($order['customer']['first_name'] ?? '') . ' ' . ($order['customer']['last_name'] ?? ''));
        if (!$customerName) {
            $customerName = $order['shipping_address']['name'] ?? $order['billing_address']['name'] ?? $order['customer']['email'] ?? $order['email'] ?? 'Guest Customer';
        }

        $email = $order['customer']['email'] ?? $order['contact_email'] ?? $order['email'] ?? '';
        $phone = $order['customer']['phone'] ?? $order['shipping_address']['phone'] ?? $order['billing_address']['phone'] ?? $order['phone'] ?? '';

        $city = $order['shipping_address']['city'] ?? $order['customer']['default_address']['city'] ?? 'Doha';
        $zone = $order['shipping_address']['province'] ?? $order['shipping_address']['city'] ?? 'Zone A';

        $fulfillmentStatus = $order['fulfillment_status'] ?: 'unfulfilled';
        $shopifyStatus = ucfirst($fulfillmentStatus); // Unfulfilled, Fulfilled, Partial

        // Default Shopify status calculation
        $status = 'Pending';
        if ($fulfillmentStatus === 'fulfilled') {
            $status = 'Delivered';
        } elseif ($fulfillmentStatus === 'partial') {
            $status = 'Picking';
        }

        $lineItems = $order['line_items'] ?? [];
        $totalQty = 0;
        foreach ($lineItems as $li) {
            $totalQty += (int)($li['quantity'] ?? 1);
        }
        if ($totalQty === 0) {
            $totalQty = count($lineItems);
        }

        $pickingStatus = $fulfillmentStatus === 'fulfilled' ? "{$totalQty}/{$totalQty} Picked" : "0/{$totalQty} Picked";
        $packingStatus = $fulfillmentStatus === 'fulfilled' ? "{$totalQty}/{$totalQty} Packed" : "0/{$totalQty} Packed";

        $createdAt = isset($order['created_at']) ? strtotime($order['created_at']) : time();
        $transactionDate = date('Y-m-d H:i:s', $createdAt);
        $deliveryDate = date('Y-m-d', strtotime('+1 day', $createdAt));

        $channel = isset($order['source_name']) ? ucfirst(str_replace('_', ' ', $order['source_name'])) : 'Web';

        $shippingAddress = $order['shipping_address'] ?? $order['billing_address'] ?? [];
        $addressLine1 = $shippingAddress['address1'] ?? ($shippingAddress['address2'] ?? 'Building 12, Street 340');
        $addressLine2 = $shippingAddress['address2'] ?? '';
        $shippingCity = $shippingAddress['city'] ?? $city ?: 'Doha';
        $shippingCountry = $shippingAddress['country'] ?? 'Qatar';

        $lat = (float)($shippingAddress['latitude'] ?? 25.276987);
        $lng = (float)($shippingAddress['longitude'] ?? 51.520008);

        $placeholders = [
            'https://images.unsplash.com/photo-1519689680058-324335c77eba?w=150',
            'https://images.unsplash.com/photo-1584308666744-24d5c474f2ae?w=150',
            'https://images.unsplash.com/photo-1515488042361-ee00e0ddd4e4?w=150',
            'https://images.unsplash.com/photo-1522771739844-6a9f6d5f14af?w=150',
        ];

        $transformedLineItems = array_map(function ($item, $index) use ($fulfillmentStatus, $placeholders) {
            $qty = (int)($item['quantity'] ?? 1);
            $price = (float)($item['price'] ?? 0);
            $amount = $qty * $price;

            $barcode = !empty($item['barcode']) ? $item['barcode'] : ('890' . sprintf('%09d', abs(crc32(($item['id'] ?? $index) . ($item['sku'] ?? '')))));

            $binLetter = chr(65 + ($index % 4));
            $binNum = sprintf('%02d', ($index % 15) + 1);
            $bin = "BIN-{$binLetter}-{$binNum}";

            $image = $item['image']['src'] ?? $item['featured_image']['src'] ?? null;
            if (!$image) {
                $image = $placeholders[$index % count($placeholders)];
            }

            $itemStatus = $fulfillmentStatus === 'fulfilled' ? 'Picked' : 'Pending';

            return [
                'id' => $item['id'] ?? ($index + 1),
                'name' => $item['name'] ?? $item['title'] ?? ('Product Item ' . ($index + 1)),
                'sku' => !empty($item['sku']) ? $item['sku'] : ('SKU-' . rand(1000, 9999)),
                'custom_barcode' => (string)$barcode,
                'quantity' => $qty,
                'price' => $price,
                'amount' => $amount,
                'warehouse' => 'Fulfillment Center Hilal (F01)',
                'custom_bin' => $bin,
                'custom_status' => $itemStatus,
                'image' => $image,
                'product_id' => $item['product_id'] ?? null,
                'variant_id' => $item['variant_id'] ?? null,
            ];
        }, $lineItems, array_keys($lineItems));

        $orderData = [
            'name' => $order['name'] ?? ('SO-' . ($order['order_number'] ?? $order['id'])),
            'customer' => 'CUST-' . ($order['customer']['id'] ?? $order['id']),
            'customer_name' => $customerName,
            'contact_email' => $email,
            'contact_phone' => $phone,
            'transaction_date' => $transactionDate,
            'delivery_date' => $deliveryDate,
            'grand_total' => (float)($order['total_price'] ?? 0),
            'status' => $status,
            'custom_city' => $city ?: 'Doha',
            'custom_zone' => $zone ?: 'Zone A',
            'custom_coordinator' => 'Unassigned',
            'custom_driver' => 'Unassigned',
            'custom_driver_status' => 'Pending',
            'custom_picker' => 'Unassigned',
            'custom_packer' => 'Unassigned',
            'custom_channel' => $channel,
            'custom_tat' => '2h 00m',
            'custom_bags' => count($lineItems),
            'custom_picking_status' => $pickingStatus,
            'custom_packing_status' => $packingStatus,
            'custom_shopify_status' => $shopifyStatus,
            'custom_notes' => $order['note'] ?? 'Handle with care. Call upon arrival.',
            'custom_shipping_address_line1' => $addressLine1,
            'custom_shipping_address_line2' => $addressLine2,
            'custom_shipping_city' => $shippingCity,
            'custom_shipping_country' => $shippingCountry,
            'custom_latitude' => $lat,
            'custom_longitude' => $lng,
            'custom_return_count' => count($order['refunds'] ?? []),
            'custom_tags' => $order['tags'] ?: 'Standard',
            'line_items' => $transformedLineItems,
        ];

        if ($dbOrder) {
            return $this->enrichOrderDataWithLocalDb($orderData, $dbOrder);
        }

        return $orderData;
    }

    public function salesOrderDetail(string $orderId): JsonResponse
    {
        $orderId = trim(urldecode($orderId));
        $numericId = ltrim($orderId, '#');

        $candidateKeys = [
            $orderId,
            $numericId,
            "#{$numericId}",
            "SO-" . str_pad($numericId, 5, '0', STR_PAD_LEFT),
            "SO-" . $numericId,
        ];
        $dbOrder = $this->findLocalOrder($candidateKeys);

        // 1. Attempt to find order in Shopify live data if available
        $ordersResult = $this->shopifyService->getOrders();
        if (!empty($ordersResult['success']) && !empty($ordersResult['orders'])) {
            foreach ($ordersResult['orders'] as $shopifyOrder) {
                $sId = (string)($shopifyOrder['id'] ?? '');
                $sName = (string)($shopifyOrder['name'] ?? '');
                $sNum = (string)($shopifyOrder['order_number'] ?? '');

                if (
                    $orderId === $sId ||
                    $orderId === $sName ||
                    $orderId === "#{$sNum}" ||
                    $numericId === $sNum ||
                    $numericId === ltrim($sName, '#')
                ) {
                    $orderDbMatch = $dbOrder ?: $this->findLocalOrder($this->getCandidateKeysForShopifyOrder($shopifyOrder));
                    return response()->json([
                        'data' => $this->transformShopifyOrder($shopifyOrder, $orderDbMatch),
                        'source' => 'shopify',
                    ]);
                }
            }
        }

        // 2. Predefined mock orders repository
        $mockOrders = [
            '1002' => [
                'name' => '#1002',
                'customer' => 'CUST-9501213884660',
                'customer_name' => 'Ansil A',
                'contact_email' => 'ansil@gmail.com',
                'contact_phone' => '+97432131234',
                'transaction_date' => '2026-07-30 06:28:13',
                'delivery_date' => '2026-07-31',
                'grand_total' => 178.00,
                'status' => 'Pending',
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
                'customer' => 'CUST-00001',
                'customer_name' => 'Sara Al Sulaiti',
                'contact_email' => 'sara@example.com',
                'contact_phone' => '+974 5512 3456',
                'transaction_date' => '2026-05-25 14:10:00',
                'delivery_date' => '2026-05-26',
                'grand_total' => 450.00,
                'status' => 'Picking',
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
                'customer' => 'CUST-00002',
                'customer_name' => 'Mohamed Al Naimi',
                'contact_email' => 'mohamed@example.com',
                'contact_phone' => '+974 5566 7788',
                'transaction_date' => '2026-05-26 09:35:00',
                'delivery_date' => '2026-05-27',
                'grand_total' => 380.50,
                'status' => 'Packing',
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
                'customer' => 'CUST-00003',
                'customer_name' => 'Fatima Al-Kuwari',
                'contact_email' => 'fatima@example.com',
                'contact_phone' => '+974 5599 1122',
                'transaction_date' => '2026-05-26 11:20:00',
                'delivery_date' => '2026-05-27',
                'grand_total' => 520.75,
                'status' => 'Pending',
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

        // Check exact or key alias match in mock data
        $lookupKeys = [$orderId, $numericId, "#{$numericId}", "SO-" . str_pad($numericId, 5, '0', STR_PAD_LEFT)];
        foreach ($lookupKeys as $key) {
            if (isset($mockOrders[$key])) {
                $mockData = $mockOrders[$key];
                if ($dbOrder) {
                    $mockData = $this->enrichOrderDataWithLocalDb($mockData, $dbOrder);
                }
                return response()->json([
                    'data' => $mockData,
                    'source' => 'mock',
                ]);
            }
        }

        // 3. Dynamic generic fallback for any arbitrary order identifier requested by front-end
        $formattedName = str_starts_with($orderId, 'SO-') || str_starts_with($orderId, '#')
            ? $orderId
            : '#' . $orderId;

        $genericData = [
            'name' => $formattedName,
            'customer' => 'CUST-' . abs(crc32($orderId)),
            'customer_name' => 'Customer (' . $formattedName . ')',
            'contact_email' => 'customer' . abs(crc32($orderId)) . '@example.com',
            'contact_phone' => '+974 5500 ' . sprintf('%04d', abs(crc32($orderId)) % 10000),
            'transaction_date' => date('Y-m-d H:i:s'),
            'delivery_date' => date('Y-m-d', strtotime('+1 day')),
            'grand_total' => 250.00,
            'status' => 'Pending',
            'custom_city' => 'Doha',
            'custom_zone' => 'Zone A',
            'custom_coordinator' => 'Unassigned',
            'custom_driver' => 'Unassigned',
            'custom_driver_status' => 'Pending',
            'custom_picker' => 'Unassigned',
            'custom_packer' => 'Unassigned',
            'custom_channel' => 'Web',
            'custom_tat' => '2h 00m',
            'custom_bags' => 2,
            'custom_picking_status' => '0/2 Picked',
            'custom_packing_status' => '0/2 Packed',
            'custom_shopify_status' => 'Unfulfilled',
            'custom_notes' => 'Standard order delivery.',
            'custom_shipping_address_line1' => 'Building 10, Street 200',
            'custom_shipping_address_line2' => 'West Bay',
            'custom_shipping_city' => 'Doha',
            'custom_shipping_country' => 'Qatar',
            'custom_latitude' => 25.276987,
            'custom_longitude' => 51.520008,
            'custom_tags' => 'Standard',
            'line_items' => [
                [
                    'id' => 901,
                    'name' => 'Standard Order Product Item 1',
                    'sku' => 'SKU-' . rand(1000, 9999),
                    'custom_barcode' => '890' . rand(100000000, 999999999),
                    'quantity' => 1,
                    'price' => 150.00,
                    'amount' => 150.00,
                    'warehouse' => 'Fulfillment Center Hilal (F01)',
                    'custom_bin' => 'BIN-A-01',
                    'custom_status' => 'Pending',
                    'image' => 'https://images.unsplash.com/photo-1519689680058-324335c77eba?w=150',
                ],
                [
                    'id' => 902,
                    'name' => 'Standard Order Product Item 2',
                    'sku' => 'SKU-' . rand(1000, 9999),
                    'custom_barcode' => '890' . rand(100000000, 999999999),
                    'quantity' => 1,
                    'price' => 100.00,
                    'amount' => 100.00,
                    'warehouse' => 'Fulfillment Center Hilal (F01)',
                    'custom_bin' => 'BIN-B-02',
                    'custom_status' => 'Pending',
                    'image' => 'https://images.unsplash.com/photo-1584308666744-24d5c474f2ae?w=150',
                ],
            ],
        ];

        if ($dbOrder) {
            $genericData = $this->enrichOrderDataWithLocalDb($genericData, $dbOrder);
        }

        return response()->json([
            'data' => $genericData,
            'source' => 'fallback',
        ]);
    }

    /**
     * Generate list of candidate order_number strings for database lookup.
     */
    protected function getCandidateKeysForShopifyOrder(array $order): array
    {
        $keys = [];
        if (!empty($order['id'])) {
            $keys[] = (string)$order['id'];
        }
        if (!empty($order['name'])) {
            $keys[] = (string)$order['name'];
            $keys[] = ltrim((string)$order['name'], '#');
            $keys[] = '#' . ltrim((string)$order['name'], '#');
        }
        if (!empty($order['order_number'])) {
            $num = (string)$order['order_number'];
            $keys[] = $num;
            $keys[] = '#' . $num;
            $keys[] = 'SO-' . $num;
            if (is_numeric($num)) {
                $keys[] = 'SO-' . sprintf('%05d', (int)$num);
            }
        }
        return array_values(array_unique(array_filter($keys)));
    }

    /**
     * Match a Shopify order array to a local database Order model from preloaded map.
     */
    protected function findMatchingLocalOrderFromMap(array $order, array $localOrderMap): ?Order
    {
        $keys = $this->getCandidateKeysForShopifyOrder($order);
        foreach ($keys as $key) {
            if (isset($localOrderMap[$key])) {
                return $localOrderMap[$key];
            }
        }
        return null;
    }

    /**
     * Find local Order model from database using array of candidate order numbers.
     */
    protected function findLocalOrder(array $candidateKeys): ?Order
    {
        $keys = array_values(array_unique(array_filter($candidateKeys)));
        if (empty($keys)) {
            return null;
        }

        return Order::with([
            'items.assignedUser',
            'items.pickedUser',
            'items.packedUser',
            'items.deliveredUser',
            'assignedUser',
            'deliveredUser'
        ])->where(function ($q) use ($keys) {
            $q->whereIn('order_number', $keys);
            foreach ($keys as $k) {
                $q->orWhere('order_number', 'like', "%{$k}%");
            }
        })->first();
    }

    /**
     * Merge local database order status and order items status into the frontend order response array.
     */
    protected function enrichOrderDataWithLocalDb(array $orderData, Order $dbOrder): array
    {
        $dbStatusRaw = strtolower($dbOrder->status);
        $statusMap = [
            'pending' => 'Pending',
            'picking' => 'Picking',
            'packed' => 'Packing',
            'out_for_delivery' => 'Out for Delivery',
            'delivered' => 'Delivered',
            'cancelled' => 'Cancelled',
        ];
        $orderData['status'] = $statusMap[$dbStatusRaw] ?? ucfirst(str_replace('_', ' ', $dbStatusRaw));

        $dbItems = $dbOrder->items;
        $totalItemsCount = max(count($orderData['line_items'] ?? []), $dbItems->count(), 1);

        $pickedCount = $dbItems->filter(function ($i) {
            return in_array(strtolower($i->status), ['picked', 'packed', 'delivered']);
        })->count();

        $packedCount = $dbItems->filter(function ($i) {
            return in_array(strtolower($i->status), ['packed', 'delivered']);
        })->count();

        $orderData['custom_picking_status'] = "{$pickedCount}/{$totalItemsCount} Picked";
        $orderData['custom_packing_status'] = "{$packedCount}/{$totalItemsCount} Packed";

        // Picker name
        $pickerName = $dbOrder->assigned_user_name ?: ($dbOrder->assignedUser->name ?? null);
        if (!$pickerName) {
            $pickers = $dbItems->map(fn($i) => $i->picked_user_name ?: ($i->pickedUser->name ?? ($i->assigned_user_name ?: null)))->filter()->unique()->values();
            $pickerName = $pickers->isNotEmpty() ? implode(', ', $pickers->all()) : 'Unassigned';
        }
        $orderData['custom_picker'] = $pickerName;

        // Packer name
        $packers = $dbItems->map(fn($i) => $i->packed_user_name ?: ($i->packedUser->name ?? null))->filter()->unique()->values();
        $orderData['custom_packer'] = $packers->isNotEmpty() ? implode(', ', $packers->all()) : 'Unassigned';

        // Driver name & status
        $driverName = $dbOrder->delivered_user_name ?: ($dbOrder->deliveredUser->name ?? null);
        if (!$driverName) {
            $drivers = $dbItems->map(fn($i) => $i->delivered_user_name ?: ($i->deliveredUser->name ?? null))->filter()->unique()->values();
            $driverName = $drivers->isNotEmpty() ? implode(', ', $drivers->all()) : 'Unassigned';
        }
        $orderData['custom_driver'] = $driverName;

        if ($dbStatusRaw === 'delivered') {
            $orderData['custom_driver_status'] = 'Delivered';
        } elseif ($dbStatusRaw === 'out_for_delivery') {
            $orderData['custom_driver_status'] = 'In Transit';
        } elseif ($dbOrder->delivered_by || $dbOrder->delivered_user_name) {
            $orderData['custom_driver_status'] = 'Assigned';
        } else {
            $orderData['custom_driver_status'] = 'Pending';
        }

        if (!empty($orderData['line_items']) && is_array($orderData['line_items'])) {
            $orderData['line_items'] = array_map(function ($item, $index) use ($dbOrder) {
                $sLineItemId = (string)($item['id'] ?? '');
                $sSku = (string)($item['sku'] ?? '');
                $sBarcode = (string)($item['custom_barcode'] ?? ($item['barcode'] ?? ''));

                $dbItem = $dbOrder->items->first(function ($dbI) use ($sLineItemId, $sSku, $sBarcode) {
                    if (!empty($sLineItemId) && (string)$dbI->line_item_id === $sLineItemId) {
                        return true;
                    }
                    if (!empty($sSku) && (string)$dbI->product_code === $sSku) {
                        return true;
                    }
                    if (!empty($sBarcode) && (string)$dbI->barcode === $sBarcode) {
                        return true;
                    }
                    return false;
                });

                if (!$dbItem && isset($dbOrder->items[$index])) {
                    $dbItem = $dbOrder->items[$index];
                }

                if ($dbItem) {
                    $item['custom_status'] = ucfirst(strtolower($dbItem->status));
                    if (!empty($dbItem->barcode)) {
                        $item['custom_barcode'] = $dbItem->barcode;
                    }
                    if (!empty($dbItem->product_code)) {
                        $item['sku'] = $dbItem->product_code;
                    }
                    $item['db_item_id'] = $dbItem->id;
                    $item['picked_by'] = $dbItem->picked_user_name ?: ($dbItem->pickedUser->name ?? null);
                    $item['packed_by'] = $dbItem->packed_user_name ?: ($dbItem->packedUser->name ?? null);
                    $item['delivered_by'] = $dbItem->delivered_user_name ?: ($dbItem->deliveredUser->name ?? null);
                }

                return $item;
            }, $orderData['line_items'], array_keys($orderData['line_items']));
        }

        return $orderData;
    }

    public function ping(): JsonResponse
    {
        return response()->json(['message' => 'ok']);
    }
}

