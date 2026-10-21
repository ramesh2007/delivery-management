<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderDriverAssigned;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileDriverVsDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected User $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = User::factory()->create([
            'name' => 'Tariq Driver',
            'role' => 'driver',
        ]);
    }

    /**
     * Test 1: Driver can verify Virtual Stock (VS) items via POST /api/mobile/orders/driver/verify-vs-item
     */
    public function test_driver_can_verify_vs_item_by_barcode(): void
    {
        $order = Order::create([
            'order_number' => 'ORD-VS-101',
            'customer_name' => 'John Customer',
            'total_amount' => 300.00,
            'status' => 'assigned_to_driver',
        ]);

        $vsItem = OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => 1001,
            'product_code' => 'VS-CHAIR-01',
            'barcode' => 'BAR-VS-999',
            'product_name' => 'Virtual Stock Office Chair',
            'quantity' => 1,
            'unit_price' => 150.00,
            'status' => 'pending',
            'product_tags' => 'VS, furniture',
            'is_vs_verified' => false,
            'is_installable' => false,
        ]);

        $response = $this->postJson('/api/mobile/orders/driver/verify-vs-item', [
            'order_number' => $order->order_number,
            'barcode' => 'BAR-VS-999',
            'driver_user_id' => $this->driver->id,
            'driver_name' => $this->driver->name,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'message' => 'Virtual stock item verified successfully.',
            'data' => [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'vs_items_count' => 1,
                'verified_vs_items_count' => 1,
                'remaining_unverified_count' => 0,
                'all_vs_verified' => true,
            ],
        ]);

        $vsItem->refresh();
        $this->assertTrue($vsItem->is_vs_verified);
        $this->assertEquals($this->driver->id, $vsItem->vs_verified_by);
        $this->assertEquals($this->driver->name, $vsItem->vs_verified_user_name);
        $this->assertNotNull($vsItem->vs_verified_at);
        $this->assertEquals('picked', $vsItem->status); // Picked from virtual store
    }

    /**
     * Test 2: Alias route POST /api/mobile/orders/driver/verify-virtual-stock also works
     */
    public function test_driver_verify_virtual_stock_alias_route(): void
    {
        $order = Order::create([
            'order_number' => 'ORD-VS-ALIAS',
            'customer_name' => 'Sara Customer',
            'total_amount' => 100.00,
            'status' => 'assigned_to_driver',
        ]);

        $vsItem = OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => 1002,
            'product_code' => 'VS-LAMP',
            'barcode' => 'BAR-VS-888',
            'product_name' => 'Virtual Stock Lamp',
            'quantity' => 1,
            'unit_price' => 100.00,
            'status' => 'picked',
            'product_tags' => 'virtual_stock',
            'is_vs_verified' => false,
            'is_installable' => false,
        ]);

        $response = $this->postJson('/api/mobile/orders/driver/verify-virtual-stock', [
            'order_number' => $order->order_number,
            'driver_user_id' => $this->driver->id,
        ]);

        $response->assertStatus(200);
        $vsItem->refresh();
        $this->assertTrue($vsItem->is_vs_verified);
    }

    /**
     * Test 3: Verifying VS item fails if order has no VS items
     */
    public function test_verify_vs_item_fails_when_order_has_no_vs_items(): void
    {
        $order = Order::create([
            'order_number' => 'ORD-NO-VS',
            'customer_name' => 'Regular Customer',
            'total_amount' => 50.00,
            'status' => 'assigned_to_driver',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_code' => 'REG-01',
            'product_name' => 'Regular Pillow',
            'quantity' => 1,
            'unit_price' => 50.00,
            'status' => 'packed',
            'is_installable' => false,
        ]);

        $response = $this->postJson('/api/mobile/orders/driver/verify-vs-item', [
            'order_number' => $order->order_number,
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'status' => 'error',
        ]);
    }

    /**
     * Test 4: Delivery completion FAILS when order has unverified VS item
     */
    public function test_delivery_fails_if_virtual_stock_item_is_not_verified(): void
    {
        $order = Order::create([
            'order_number' => 'ORD-UNVERIFIED-VS',
            'customer_name' => 'David Customer',
            'total_amount' => 250.00,
            'status' => 'out_for_delivery',
        ]);

        // Packed regular item
        OrderItem::create([
            'order_id' => $order->id,
            'product_code' => 'REG-10',
            'product_name' => 'Baby Blanket',
            'quantity' => 1,
            'unit_price' => 50.00,
            'status' => 'packed',
            'is_packer_verified' => true,
            'is_installable' => false,
        ]);

        // Unverified VS item
        OrderItem::create([
            'order_id' => $order->id,
            'product_code' => 'VS-TOY-01',
            'product_name' => 'Virtual Stock Wooden Toy',
            'quantity' => 1,
            'unit_price' => 200.00,
            'status' => 'picked',
            'product_tags' => 'VS',
            'is_vs_verified' => false, // NOT VERIFIED
            'is_installable' => false,
        ]);

        OrderDriverAssigned::create([
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'assigned_driver_user_id' => $this->driver->id,
            'driver_name' => $this->driver->name,
            'driver_status' => 'started',
            'order_status' => 'out_for_delivery',
        ]);

        // Driver attempts delivery
        $response = $this->postJson('/api/mobile/orders/driver/delivered', [
            'order_number' => $order->order_number,
            'driver_user_id' => $this->driver->id,
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'status' => 'error',
            'message' => "Order {$order->order_number} cannot be delivered. All virtual stock items must be verified by the driver after being picked from the virtual store.",
        ]);

        $order->refresh();
        $this->assertNotEquals('delivered', $order->status);
        $this->assertNotEquals('partialy_deliverd', $order->status);
    }

    /**
     * Test 5: Delivery completion FAILS when regular items are NOT picked and packed
     */
    public function test_delivery_fails_if_regular_item_is_not_picked_and_packed(): void
    {
        $order = Order::create([
            'order_number' => 'ORD-UNPACKED-REG',
            'customer_name' => 'David Customer',
            'total_amount' => 250.00,
            'status' => 'out_for_delivery',
        ]);

        // Pending regular item (not picked & not packed)
        OrderItem::create([
            'order_id' => $order->id,
            'product_code' => 'REG-UNPACKED',
            'product_name' => 'Pending Blanket',
            'quantity' => 1,
            'unit_price' => 50.00,
            'status' => 'pending', // Unpacked!
            'is_packer_verified' => false,
            'is_installable' => false,
        ]);

        // Verified VS item
        OrderItem::create([
            'order_id' => $order->id,
            'product_code' => 'VS-TOY-02',
            'product_name' => 'Virtual Stock Toy',
            'quantity' => 1,
            'unit_price' => 200.00,
            'status' => 'picked',
            'product_tags' => 'VS',
            'is_vs_verified' => true,
            'is_installable' => false,
        ]);

        OrderDriverAssigned::create([
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'assigned_driver_user_id' => $this->driver->id,
            'driver_name' => $this->driver->name,
            'driver_status' => 'started',
            'order_status' => 'out_for_delivery',
        ]);

        $response = $this->postJson('/api/mobile/orders/driver/delivered', [
            'order_number' => $order->order_number,
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'status' => 'error',
            'message' => "Order {$order->order_number} cannot be delivered. All regular items must be picked and packed first.",
        ]);
    }

    /**
     * Test 6: Order with installation item remaining + normal item picked/packed + VS item verified
     * is marked as 'partialy_deliverd' on delivery completion.
     */
    public function test_order_with_installation_remaining_marked_as_partialy_deliverd(): void
    {
        $order = Order::create([
            'order_number' => 'ORD-PARTIAL-INSTALL-01',
            'customer_name' => 'Fatima Al-Thani',
            'total_amount' => 850.00,
            'status' => 'out_for_delivery',
        ]);

        // Regular item (packed)
        $regItem = OrderItem::create([
            'order_id' => $order->id,
            'product_code' => 'REG-BEDSHEET',
            'product_name' => 'Luxury Bedsheet',
            'quantity' => 1,
            'unit_price' => 150.00,
            'status' => 'packed',
            'is_packer_verified' => true,
            'is_installable' => false,
        ]);

        // Virtual Stock item (verified by driver)
        $vsItem = OrderItem::create([
            'order_id' => $order->id,
            'product_code' => 'VS-CUSHION',
            'barcode' => 'BAR-CUSH-01',
            'product_name' => 'VS Designer Cushion',
            'quantity' => 1,
            'unit_price' => 200.00,
            'status' => 'picked',
            'product_tags' => 'VS',
            'is_vs_verified' => true, // Driver verified after picking
            'vs_verified_by' => $this->driver->id,
            'vs_verified_user_name' => $this->driver->name,
            'is_installable' => false,
        ]);

        // Installation item (handled by installation team, NOT delivered by driver)
        $installItem = OrderItem::create([
            'order_id' => $order->id,
            'product_code' => 'INST-BED',
            'product_name' => 'King Bed Assembly Installation',
            'quantity' => 1,
            'unit_price' => 500.00,
            'status' => 'pending',
            'is_installable' => true,
            'delivered_by' => null,
            'delivered_user_name' => null,
        ]);

        $assignment = OrderDriverAssigned::create([
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'assigned_driver_user_id' => $this->driver->id,
            'driver_name' => $this->driver->name,
            'driver_status' => 'started',
            'order_status' => 'partial_assigned_driver',
        ]);

        // Driver delivers via markAsDelivered
        $response = $this->postJson('/api/mobile/orders/driver/mark-as-delivered', [
            'order_number' => $order->order_number,
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'collected_amount' => 850.00,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'message' => "Order {$order->order_number} marked as delivered successfully.",
            'data' => [
                'status' => 'partialy_deliverd',
                'order_status' => 'partialy_deliverd',
                'driver_status' => 'delivered',
                'partial_delivery' => true,
                'is_partial_delivery' => true,
                'has_installation' => true,
                'installation_items_count' => 1,
                'delivered_items_count' => 2, // 1 normal + 1 VS
            ],
        ]);

        // Verify order status in DB
        $order->refresh();
        $this->assertEquals('partialy_deliverd', $order->status);

        // Verify assignment status in DB
        $assignment->refresh();
        $this->assertEquals('partialy_deliverd', $assignment->order_status);
        $this->assertEquals('delivered', $assignment->driver_status);
        $this->assertNotNull($assignment->delivered_at);

        // Verify regular item delivered
        $regItem->refresh();
        $this->assertEquals('delivered', $regItem->status);
        $this->assertEquals($this->driver->id, $regItem->delivered_by);

        // Verify VS item delivered
        $vsItem->refresh();
        $this->assertEquals('delivered', $vsItem->status);
        $this->assertEquals($this->driver->id, $vsItem->delivered_by);

        // Verify installation item remains NOT delivered
        $installItem->refresh();
        $this->assertEquals('pending', $installItem->status);
        $this->assertNull($installItem->delivered_by);
        $this->assertNull($installItem->delivered_at);
    }

    /**
     * Test 7: Order without installation items is marked as 'delivered' when driver delivers
     */
    public function test_order_without_installation_marked_as_delivered(): void
    {
        $order = Order::create([
            'order_number' => 'ORD-FULL-DELIVERY-01',
            'customer_name' => 'Khalid Customer',
            'total_amount' => 350.00,
            'status' => 'out_for_delivery',
        ]);

        // Regular item (packed)
        $regItem = OrderItem::create([
            'order_id' => $order->id,
            'product_code' => 'REG-RUG',
            'product_name' => 'Floor Rug',
            'quantity' => 1,
            'unit_price' => 150.00,
            'status' => 'packed',
            'is_packer_verified' => true,
            'is_installable' => false,
        ]);

        // VS item (verified by driver)
        $vsItem = OrderItem::create([
            'order_id' => $order->id,
            'product_code' => 'VS-VASE',
            'product_name' => 'VS Flower Vase',
            'quantity' => 1,
            'unit_price' => 200.00,
            'status' => 'picked',
            'product_tags' => 'VS',
            'is_vs_verified' => true,
            'is_installable' => false,
        ]);

        $assignment = OrderDriverAssigned::create([
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'assigned_driver_user_id' => $this->driver->id,
            'driver_name' => $this->driver->name,
            'driver_status' => 'started',
            'order_status' => 'out_for_delivery',
        ]);

        // Complete delivery via /orders/driver/delivered
        $response = $this->postJson('/api/mobile/orders/driver/delivered', [
            'order_number' => $order->order_number,
        ]);

        $response->assertStatus(200);

        $order->refresh();
        $this->assertEquals('delivered', $order->status);

        $assignment->refresh();
        $this->assertEquals('delivered', $assignment->order_status);
        $this->assertEquals('delivered', $assignment->driver_status);

        $regItem->refresh();
        $this->assertEquals('delivered', $regItem->status);

        $vsItem->refresh();
        $this->assertEquals('delivered', $vsItem->status);
    }

    /**
     * Test 8: Start delivery uses local database maximum and does NOT call Shopify when payment exists locally.
     */
    public function test_start_delivery_uses_local_database_without_calling_shopify_when_payment_exists(): void
    {
        $mockShopify = $this->createMock(\App\Services\ShopifyService::class);
        $mockShopify->expects($this->never())->method('getOrder');
        $mockShopify->expects($this->never())->method('getOrders');
        $this->app->instance(\App\Services\ShopifyService::class, $mockShopify);

        $order = Order::create([
            'order_number' => 'ORD-LOCAL-DB-01',
            'customer_name' => 'Local DB Customer',
            'total_amount' => 100.00,
            'status' => 'assigned_to_driver',
            'payment_status' => 'paid',
            'payment_method' => 'credit_card',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_code' => 'LOCAL-01',
            'product_name' => 'Local Product',
            'quantity' => 1,
            'unit_price' => 100.00,
            'status' => 'packed',
            'is_packer_verified' => true,
            'is_installable' => false,
        ]);

        OrderDriverAssigned::create([
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'assigned_driver_user_id' => $this->driver->id,
            'driver_name' => $this->driver->name,
            'driver_status' => 'assigned',
            'order_status' => 'assigned_to_driver',
        ]);

        $response = $this->postJson('/api/mobile/orders/driver/start-delivery', [
            'order_number' => $order->order_number,
            'driver_user_id' => $this->driver->id,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
        ]);

        $order->refresh();
        $this->assertEquals('out_for_delivery', $order->status);
    }
}

