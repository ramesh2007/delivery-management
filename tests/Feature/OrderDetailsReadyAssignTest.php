<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\OrderDetailsService;
use App\Services\ShopifyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderDetailsReadyAssignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \Cache::flush();
    }

    public function test_order_with_all_items_having_vs_tag_returns_ready_assign_true()
    {
        $this->mock(ShopifyService::class, function ($mock) {
            $mock->shouldReceive('getOrder')->andReturn(['success' => false]);
            $mock->shouldReceive('getOrders')->andReturn(['success' => true, 'orders' => []]);
        });

        $order = Order::create([
            'order_number' => '#1071',
            'customer_name' => 'Nandu Test',
            'customer_phone' => '77532802',
            'delivery_address' => 'Doha, Qatar',
            'total_amount' => 150.00,
            'status' => 'pending',
            'bag_count' => 1,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => '101',
            'product_code' => 'SKU-VS-1',
            'product_name' => 'Virtual Stock Item 1',
            'product_tags' => 'VS, Fragile',
            'barcode' => '890111111111',
            'quantity' => 1,
            'unit_price' => 75.00,
            'status' => 'pending',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => '102',
            'product_code' => 'SKU-VS-2',
            'product_name' => 'Virtual Stock Item 2',
            'product_tags' => 'Virtual Stock, Baby',
            'barcode' => '890222222222',
            'quantity' => 1,
            'unit_price' => 75.00,
            'status' => 'pending',
        ]);

        $response = $this->getJson("/api/admin/order-details/{$order->id}");

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertTrue($data['ready_assign'], 'Expected ready_assign to be true when all items are VS');
        $this->assertTrue($data['ready_to_assign'], 'Expected ready_to_assign to be true');
        $this->assertTrue($data['all_items_vs'], 'Expected all_items_vs to be true');
        $this->assertEquals(2, $data['vs_items_count']);

        // Check summary
        $this->assertTrue($data['summary']['ready_assign']);
        $this->assertEquals(2, $data['summary']['vs_items_count']);

        // Check line items
        $this->assertCount(2, $data['line_items']);
        $this->assertTrue($data['line_items'][0]['is_vs']);
        $this->assertTrue($data['line_items'][1]['is_vs']);
    }

    public function test_order_with_partially_vs_items_returns_ready_assign_false()
    {
        $this->mock(ShopifyService::class, function ($mock) {
            $mock->shouldReceive('getOrder')->andReturn(['success' => false]);
            $mock->shouldReceive('getOrders')->andReturn(['success' => true, 'orders' => []]);
        });

        $order = Order::create([
            'order_number' => '#1072',
            'customer_name' => 'Ali Test',
            'customer_phone' => '77532803',
            'delivery_address' => 'Doha, Qatar',
            'total_amount' => 200.00,
            'status' => 'pending',
            'bag_count' => 2,
        ]);

        // Item 1 is VS
        OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => '201',
            'product_code' => 'SKU-VS-1',
            'product_name' => 'Virtual Stock Item',
            'product_tags' => 'vs',
            'barcode' => '890333333333',
            'quantity' => 1,
            'unit_price' => 100.00,
            'status' => 'pending',
        ]);

        // Item 2 is regular warehouse stock (non-VS)
        OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => '202',
            'product_code' => 'SKU-REG-2',
            'product_name' => 'Regular Physical Item',
            'product_tags' => 'Gear, Travel, FC',
            'barcode' => '890444444444',
            'quantity' => 1,
            'unit_price' => 100.00,
            'status' => 'pending',
        ]);

        $response = $this->getJson("/api/admin/order-details/{$order->id}");

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertFalse($data['ready_assign'], 'Expected ready_assign to be false for partial VS order');
        $this->assertFalse($data['ready_to_assign']);
        $this->assertFalse($data['all_items_vs']);
        $this->assertEquals(1, $data['vs_items_count']);

        // Check summary
        $this->assertFalse($data['summary']['ready_assign']);

        // Check line items
        $this->assertTrue($data['line_items'][0]['is_vs']);
        $this->assertFalse($data['line_items'][1]['is_vs']);
    }

    public function test_order_with_no_vs_items_returns_ready_assign_false()
    {
        $this->mock(ShopifyService::class, function ($mock) {
            $mock->shouldReceive('getOrder')->andReturn(['success' => false]);
            $mock->shouldReceive('getOrders')->andReturn(['success' => true, 'orders' => []]);
        });

        $order = Order::create([
            'order_number' => '#1073',
            'customer_name' => 'Sara Test',
            'customer_phone' => '77532804',
            'delivery_address' => 'Doha, Qatar',
            'total_amount' => 100.00,
            'status' => 'pending',
            'bag_count' => 1,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => '301',
            'product_code' => 'SKU-REG-1',
            'product_name' => 'Regular Item',
            'product_tags' => 'Standard, Baby Clothes',
            'barcode' => '890555555555',
            'quantity' => 1,
            'unit_price' => 100.00,
            'status' => 'pending',
        ]);

        $response = $this->getJson("/api/admin/order-details/{$order->id}");

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertFalse($data['ready_assign']);
        $this->assertFalse($data['all_items_vs']);
        $this->assertEquals(0, $data['vs_items_count']);
        $this->assertFalse($data['line_items'][0]['is_vs']);
    }

    public function test_is_virtual_stock_tag_helper_variations()
    {
        /** @var OrderDetailsService $service */
        $service = app(OrderDetailsService::class);

        $this->assertTrue($service->isVirtualStockTag('VS'));
        $this->assertTrue($service->isVirtualStockTag('vs'));
        $this->assertTrue($service->isVirtualStockTag('Vs'));
        $this->assertTrue($service->isVirtualStockTag('VS, Fragile'));
        $this->assertTrue($service->isVirtualStockTag('Baby, vs, Toys'));
        $this->assertTrue($service->isVirtualStockTag('Virtual Stock'));
        $this->assertTrue($service->isVirtualStockTag('virtual_stock'));
        $this->assertTrue($service->isVirtualStockTag('VS - Direct'));
        $this->assertTrue($service->isVirtualStockTag('VS: Supplier'));
        $this->assertTrue($service->isVirtualStockTag('(VS)'));
        $this->assertTrue($service->isVirtualStockTag(['Accessories', 'VS']));

        $this->assertFalse($service->isVirtualStockTag('Vase'));
        $this->assertFalse($service->isVirtualStockTag('Versus'));
        $this->assertFalse($service->isVirtualStockTag('Baby clothes'));
        $this->assertFalse($service->isVirtualStockTag(''));
        $this->assertFalse($service->isVirtualStockTag(null));
    }
}
