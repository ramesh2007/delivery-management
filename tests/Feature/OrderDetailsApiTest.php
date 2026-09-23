<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\OrderDriverAssigned;
use App\Models\User;
use App\Services\ShopifyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderDetailsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_details_returns_stored_database_information_with_sales_order_schema()
    {
        $picker = User::factory()->create(['name' => 'John Picker', 'email' => 'picker@example.com']);
        $packer = User::factory()->create(['name' => 'Jane Packer', 'email' => 'packer@example.com']);
        $driver = User::factory()->create(['name' => 'Dave Driver', 'email' => 'driver@example.com']);

        $order = Order::create([
            'order_number' => '#9901',
            'customer_name' => 'Ahmed Al-Thani',
            'customer_phone' => '+974 5511 2233',
            'delivery_address' => 'Villa 10, West Bay Lagoon, Doha',
            'total_amount' => 350.00,
            'status' => 'picking',
            'bag_count' => 4,
            'payment_method' => 'Cash on Delivery (COD)',
            'payment_status' => 'pending',
            'collected_amount' => 0.00,
            'assigned_to' => $picker->id,
            'assigned_user_name' => $picker->name,
            'packed_by' => $packer->id,
            'packed_user_name' => $packer->name,
            'delivered_by' => $driver->id,
            'delivered_user_name' => $driver->name,
        ]);

        $item1 = OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => '10001',
            'product_code' => 'CRIB-SILVER',
            'product_name' => 'Next2Me Crib Silver',
            'barcode' => '890111222333',
            'quantity' => 1,
            'unit_price' => 250.00,
            'status' => 'picked',
            'assigned_to' => $picker->id,
            'assigned_user_name' => $picker->name,
            'picked_by' => $picker->id,
            'picked_user_name' => $picker->name,
        ]);

        $item2 = OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => '10002',
            'product_code' => 'BABY-WIPES',
            'product_name' => 'Organic Baby Wipes',
            'barcode' => '890444555666',
            'quantity' => 2,
            'unit_price' => 50.00,
            'status' => 'pending',
            'assigned_to' => $picker->id,
            'assigned_user_name' => $picker->name,
        ]);

        OrderPayment::create([
            'order_id' => $order->id,
            'shopify_order_id' => '9901888222',
            'payment_method' => 'Credit Card',
            'payment_status' => 'paid',
            'paid_amount' => 350.00,
            'total_price' => 350.00,
            'total_outstanding' => 0.00,
            'currency' => 'QAR',
        ]);

        OrderDriverAssigned::create([
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'assigned_driver_user_id' => $driver->id,
            'driver_name' => $driver->name,
            'zone' => 'Zone B',
            'driver_status' => 'assigned',
        ]);

        // Mock Shopify service to return remaining fields like customer email, notes, tags
        $this->mock(ShopifyService::class, function ($mock) use ($order) {
            $mock->shouldReceive('getOrder')
                ->andReturn([
                    'success' => true,
                    'order' => [
                        'id' => 9901888222,
                        'name' => '#9901',
                        'order_number' => '9901',
                        'contact_email' => 'ahmed@example.com',
                        'customer' => [
                            'id' => 771122,
                            'first_name' => 'Ahmed',
                            'last_name' => 'Al-Thani',
                            'email' => 'ahmed@example.com',
                        ],
                        'note' => 'Leave near the front gate',
                        'tags' => 'VIP, Fragile',
                        'source_name' => 'web',
                        'fulfillment_status' => 'unfulfilled',
                        'shipping_address' => [
                            'address1' => 'Villa 10, West Bay Lagoon',
                            'address2' => 'Street 240',
                            'city' => 'Doha',
                            'province' => 'Zone B',
                            'country' => 'Qatar',
                            'latitude' => 25.350000,
                            'longitude' => 51.530000,
                        ],
                        'line_items' => [
                            [
                                'id' => 10001,
                                'variant_id' => 45678,
                                'metafields' => ['installation_guide' => 'PDF link'],
                            ],
                            [
                                'id' => 10002,
                                'variant_id' => 45679,
                                'metafields' => [],
                            ],
                        ],
                    ],
                ]);
            $mock->shouldReceive('getOrders')->andReturn(['success' => true, 'orders' => []]);
        });

        // Test GET /api/admin/order-details/{order_id}
        $response = $this->getJson("/api/admin/order-details/{$order->id}");

        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
                'message' => 'Order details',
                'data' => [
                    'order_number' => '#9901',
                    'name' => '#9901',
                    'customer_name' => 'Ahmed Al-Thani',
                    'contact_phone' => '+974 5511 2233',
                    // Remaining fields from Shopify:
                    'contact_email' => 'ahmed@example.com',
                    'custom_notes' => 'Leave near the front gate',
                    'custom_tags' => 'VIP, Fragile',
                    'custom_channel' => 'Web',
                    'custom_shipping_address_line2' => 'Street 240',
                    // Database precedence:
                    'status' => 'Picking',
                    'custom_bags' => 4,
                    'grand_total' => 350.0,
                    'custom_picker' => 'John Picker',
                    'custom_packer' => 'Jane Packer',
                    'custom_driver' => 'Dave Driver',
                    'custom_driver_status' => 'Assigned',
                    'custom_picking_status' => '1/2 Picked',
                    'custom_packing_status' => '0/2 Packed',
                    'payment' => [
                        'payment_method' => 'Credit Card',
                        'payment_status' => 'paid',
                        'paid_amount' => 350.0,
                        'total_price' => 350.0,
                        'total_outstanding' => 0.0,
                    ],
                ],
            ]);

        // Check line items in data
        $data = $response->json('data');
        $this->assertCount(2, $data['line_items']);
        $this->assertEquals('Next2Me Crib Silver', $data['line_items'][0]['name']);
        $this->assertEquals('Picked', $data['line_items'][0]['custom_status']);
        $this->assertEquals('890111222333', $data['line_items'][0]['barcode']);
        $this->assertEquals(45678, $data['line_items'][0]['variant_id']); // enriched from Shopify
        $this->assertEquals('Pending', $data['line_items'][1]['custom_status']);

        // Test that both line_items and items exist
        $this->assertArrayHasKey('line_items', $data);
        $this->assertArrayHasKey('items', $data);
        $this->assertEquals($data['line_items'], $data['items']);
    }

    public function test_order_details_returns_404_when_order_not_found()
    {
        $this->mock(ShopifyService::class, function ($mock) {
            $mock->shouldReceive('getOrder')->andReturn(['success' => false]);
            $mock->shouldReceive('getOrders')->andReturn(['success' => true, 'orders' => []]);
        });

        $response = $this->getJson('/api/admin/order-details/NONEXISTENT999');

        $response->assertStatus(404)
            ->assertJson([
                'status' => false,
                'message' => 'Order not found',
            ]);
    }

    public function test_resource_sales_order_detail_uses_same_unified_structure()
    {
        $order = Order::create([
            'order_number' => '#8801',
            'customer_name' => 'Fatima Al-Kuwari',
            'customer_phone' => '+974 5599 0011',
            'delivery_address' => 'Pearl Qatar Tower 4',
            'total_amount' => 120.00,
            'status' => 'packed',
            'bag_count' => 1,
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => '20001',
            'product_code' => 'BIB-01',
            'product_name' => 'Silicone Bib',
            'barcode' => '890777888999',
            'quantity' => 1,
            'unit_price' => 120.00,
            'status' => 'packed',
        ]);

        $this->mock(ShopifyService::class, function ($mock) {
            $mock->shouldReceive('getOrder')->andReturn(['success' => false]);
            $mock->shouldReceive('getOrders')->andReturn(['success' => true, 'orders' => []]);
        });

        $user = User::factory()->create();

        $encodedNum = urlencode($order->order_number);
        $response = $this->actingAs($user)->getJson("/api/resource/sales-order/{$encodedNum}");

        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
                'data' => [
                    'order_number' => '#8801',
                    'customer_name' => 'Fatima Al-Kuwari',
                    'status' => 'Packing',
                    'custom_bags' => 1,
                    'grand_total' => 120.0,
                    'custom_picking_status' => '1/1 Picked',
                    'custom_packing_status' => '1/1 Packed',
                ],
            ]);

        // Also verify the public /api/order-details/{order} endpoint
        $publicResponse = $this->getJson("/api/order-details/{$encodedNum}");
        $publicResponse->assertStatus(200)
            ->assertJson([
                'status' => true,
                'data' => [
                    'order_number' => '#8801',
                    'customer_name' => 'Fatima Al-Kuwari',
                ],
            ]);
    }

    public function test_order_details_returns_shopify_only_order_when_not_in_db()
    {
        $this->mock(ShopifyService::class, function ($mock) {
            $mock->shouldReceive('getOrder')->with('775533')->andReturn([
                'success' => true,
                'order' => [
                    'id' => 775533,
                    'name' => '#7755',
                    'order_number' => '7755',
                    'total_price' => '210.00',
                    'financial_status' => 'paid',
                    'fulfillment_status' => 'fulfilled',
                    'customer' => [
                        'id' => 1234,
                        'first_name' => 'Noor',
                        'last_name' => 'Al-Malki',
                        'email' => 'noor@example.com',
                        'phone' => '+974 3311 4455',
                    ],
                    'shipping_address' => [
                        'address1' => 'Street 10',
                        'city' => 'Doha',
                        'country' => 'Qatar',
                    ],
                    'line_items' => [
                        [
                            'id' => 5511,
                            'title' => 'Baby Stroller Black',
                            'sku' => 'STR-BLK-01',
                            'quantity' => 1,
                            'price' => '210.00',
                        ],
                    ],
                ],
            ]);
            $mock->shouldReceive('getOrders')->andReturn(['success' => true, 'orders' => []]);
        });

        $response = $this->getJson('/api/admin/order-details/775533');

        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
                'source' => 'shopify',
                'data' => [
                    'name' => '#7755',
                    'customer_name' => 'Noor Al-Malki',
                    'contact_email' => 'noor@example.com',
                    'contact_phone' => '+974 3311 4455',
                    'status' => 'Delivered',
                    'grand_total' => 210.0,
                    'line_items' => [
                        [
                            'id' => 5511,
                            'name' => 'Baby Stroller Black',
                            'sku' => 'STR-BLK-01',
                            'price' => 210.0,
                        ],
                    ],
                ],
            ]);
    }

    public function test_order_details_supports_post_and_lookup_without_hash()
    {
        $order = Order::create([
            'order_number' => '#6543',
            'customer_name' => 'Salem Al-Marri',
            'customer_phone' => '+974 5500 9988',
            'status' => 'picking',
            'total_amount' => 199.00,
        ]);

        $this->mock(ShopifyService::class, function ($mock) {
            $mock->shouldReceive('getOrder')->andReturn(['success' => false]);
            $mock->shouldReceive('getOrders')->andReturn(['success' => true, 'orders' => []]);
        });

        // Request using POST with order number without hash ('6543')
        $response = $this->postJson('/api/admin/order-details/6543');

        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
                'data' => [
                    'order_number' => '#6543',
                    'customer_name' => 'Salem Al-Marri',
                    'status' => 'Picking',
                    'grand_total' => 199.0,
                ],
            ]);
    }
}
