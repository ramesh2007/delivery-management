<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\ShopifyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopifyServiceSyncTest extends TestCase
{
    use RefreshDatabase;

    protected ShopifyService $shopifyService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shopifyService = new ShopifyService();
    }

    public function test_process_single_order_payload_creates_order_and_items_without_duplicates(): void
    {
        $payload = [
            'id' => 584938493843,
            'order_number' => 1001,
            'name' => '#1001',
            'total_price' => '150.00',
            'customer' => [
                'first_name' => 'John',
                'last_name' => 'Doe',
            ],
            'shipping_address' => [
                'address1' => '123 Main St',
                'city' => 'Dubai',
                'country' => 'UAE',
            ],
            'line_items' => [
                [
                    'id' => 9001,
                    'product_id' => 7001,
                    'sku' => 'TSHIRT-RED',
                    'title' => 'Red T-Shirt',
                    'quantity' => 1,
                    'price' => '50.00',
                ],
                [
                    'id' => 9002,
                    'product_id' => 7002,
                    'sku' => 'JEANS-BLUE',
                    'title' => 'Blue Jeans',
                    'quantity' => 1,
                    'price' => '100.00',
                ],
            ],
        ];

        // First run
        $order1 = $this->shopifyService->processSingleOrderPayload($payload);
        $this->assertEquals(1, Order::count());
        $this->assertEquals(2, OrderItem::count());

        // Second run with same payload
        $order2 = $this->shopifyService->processSingleOrderPayload($payload);
        $this->assertEquals(1, Order::count());
        $this->assertEquals(2, OrderItem::count());
        $this->assertEquals($order1->id, $order2->id);
    }

    public function test_process_single_order_payload_handles_same_sku_items_correctly(): void
    {
        $payload = [
            'id' => 584938493844,
            'order_number' => 1002,
            'name' => '#1002',
            'total_price' => '100.00',
            'line_items' => [
                [
                    'id' => 9003,
                    'product_id' => 7001,
                    'sku' => 'TSHIRT-RED',
                    'title' => 'Red T-Shirt Size M',
                    'quantity' => 1,
                    'price' => '50.00',
                ],
                [
                    'id' => 9004,
                    'product_id' => 7001,
                    'sku' => 'TSHIRT-RED',
                    'title' => 'Red T-Shirt Size L',
                    'quantity' => 1,
                    'price' => '50.00',
                ],
            ],
        ];

        $order = $this->shopifyService->processSingleOrderPayload($payload);
        $this->assertEquals(1, Order::count());
        $this->assertEquals(2, OrderItem::count());

        // Sync again
        $this->shopifyService->processSingleOrderPayload($payload);
        $this->assertEquals(1, Order::count());
        $this->assertEquals(2, OrderItem::count());
    }

    public function test_process_single_order_payload_cleans_up_existing_duplicates(): void
    {
        $order = Order::create([
            'order_number' => '584938493845',
            'total_amount' => 100.00,
            'status' => 'pending',
        ]);

        // Manually insert duplicate items
        OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => '9005',
            'product_code' => 'TEST-SKU',
            'product_name' => 'Test Item Duplicate 1',
            'quantity' => 1,
            'unit_price' => 50.00,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => '9005',
            'product_code' => 'TEST-SKU',
            'product_name' => 'Test Item Duplicate 2',
            'quantity' => 1,
            'unit_price' => 50.00,
        ]);

        $this->assertEquals(2, OrderItem::count());

        $payload = [
            'id' => 584938493845,
            'order_number' => 1003,
            'name' => '#1003',
            'line_items' => [
                [
                    'id' => 9005,
                    'product_id' => 7005,
                    'sku' => 'TEST-SKU',
                    'title' => 'Test Item Cleaned',
                    'quantity' => 1,
                    'price' => '50.00',
                ],
            ],
        ];

        $this->shopifyService->processSingleOrderPayload($payload);

        $this->assertEquals(1, Order::count());
        $this->assertEquals(1, OrderItem::count());
        $this->assertEquals('Test Item Cleaned', OrderItem::first()->product_name);
    }
}
