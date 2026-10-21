<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderDriverAssigned;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileDriverAssignedInstallationFilterTest extends TestCase
{
    use RefreshDatabase;

    protected User $driver;
    protected User $otherDriver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = User::factory()->create([
            'name' => 'Driver One',
            'role' => 'driver',
        ]);

        $this->otherDriver = User::factory()->create([
            'name' => 'Driver Two',
            'role' => 'driver',
        ]);
    }

    /**
     * Test: If an item has installation and driver is not yet assigned (delivered_by != driver_id),
     * do not give response in that item to driver. Instead give partial delivery and installation counts.
     */
    public function test_unassigned_installation_item_is_hidden_from_driver_response_with_partial_counts(): void
    {
        $order = Order::create([
            'order_number' => 'ORD-FILTER-001',
            'customer_name' => 'Alice Customer',
            'total_amount' => 450.00,
            'status' => 'partial_assigned_driver',
        ]);

        $regularItem = OrderItem::create([
            'order_id' => $order->id,
            'product_code' => 'REG-01',
            'product_name' => 'Baby Pillow',
            'quantity' => 1,
            'unit_price' => 50.00,
            'status' => 'picked',
            'delivered_by' => $this->driver->id,
            'delivered_user_name' => $this->driver->name,
            'is_installable' => false,
        ]);

        $installItem = OrderItem::create([
            'order_id' => $order->id,
            'product_code' => 'INST-01',
            'product_name' => 'Wardrobe Installation Service',
            'quantity' => 1,
            'unit_price' => 400.00,
            'status' => 'pending',
            'delivered_by' => null, // Not yet assigned to driver
            'delivered_user_name' => null,
            'is_installable' => true,
        ]);

        OrderDriverAssigned::create([
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'assigned_driver_user_id' => $this->driver->id,
            'driver_name' => $this->driver->name,
            'driver_status' => 'assigned',
            'order_status' => 'partial_assigned_driver',
            'zone' => 'Doha Central',
        ]);

        $response = $this->postJson('/api/mobile/orders/driver/assigned', [
            'assigned_driver_user_id' => $this->driver->id,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'count' => 1,
        ]);

        $data = $response->json('data.0');

        // Check partial delivery and installation metadata
        $this->assertTrue($data['partial_delivery']);
        $this->assertTrue($data['has_installation']);
        $this->assertEquals(1, $data['installation_items_count']);
        $this->assertEquals(1, $data['unassigned_installation_items_count']);
        $this->assertEquals(1, $data['items_count']);
        $this->assertEquals(2, $data['total_order_items']);

        // Check order payload contains customer details and items without repeating root metrics
        $orderData = $data['order'];
        $this->assertEquals('Alice Customer', $orderData['customer_name']);
        $this->assertArrayNotHasKey('partial_delivery', $orderData);
        $this->assertArrayNotHasKey('total_amount', $orderData);

        // Only the regular item should be returned in items list
        $items = $orderData['items'];
        $this->assertCount(1, $items);
        $this->assertEquals($regularItem->id, $items[0]['item_id']);
        $this->assertEquals('Baby Pillow', $items[0]['product_name']);
    }

    /**
     * Test: If the same driver is assigned in delivered_by for the installation item, then show it.
     */
    public function test_installation_item_is_shown_if_same_driver_assigned_in_delivered_by(): void
    {
        $order = Order::create([
            'order_number' => 'ORD-FILTER-002',
            'customer_name' => 'Bob Customer',
            'total_amount' => 500.00,
            'status' => 'assigned_to_driver',
        ]);

        $regularItem = OrderItem::create([
            'order_id' => $order->id,
            'product_code' => 'REG-02',
            'product_name' => 'Stroller',
            'quantity' => 1,
            'unit_price' => 200.00,
            'status' => 'picked',
            'delivered_by' => $this->driver->id,
            'delivered_user_name' => $this->driver->name,
            'is_installable' => false,
        ]);

        $installItem = OrderItem::create([
            'order_id' => $order->id,
            'product_code' => 'INST-02',
            'product_name' => 'Crib with Full Installation',
            'quantity' => 1,
            'unit_price' => 300.00,
            'status' => 'picked',
            'delivered_by' => $this->driver->id, // Same driver assigned!
            'delivered_user_name' => $this->driver->name,
            'is_installable' => true,
        ]);

        OrderDriverAssigned::create([
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'assigned_driver_user_id' => $this->driver->id,
            'driver_name' => $this->driver->name,
            'driver_status' => 'assigned',
            'order_status' => 'assigned_to_driver',
            'zone' => 'Al Rayyan',
        ]);

        $response = $this->postJson('/api/mobile/orders/driver/assigned', [
            'assigned_driver_user_id' => $this->driver->id,
        ]);

        $response->assertStatus(200);

        $data = $response->json('data.0');
        $this->assertFalse($data['partial_delivery']);
        $this->assertTrue($data['has_installation']);
        $this->assertEquals(1, $data['installation_items_count']);
        $this->assertEquals(0, $data['unassigned_installation_items_count']);
        $this->assertEquals(2, $data['items_count']);
        $this->assertEquals(2, $data['total_order_items']);

        // Both items should be returned to the driver
        $items = $data['order']['items'];
        $this->assertCount(2, $items);

        $itemIds = collect($items)->pluck('item_id')->all();
        $this->assertContains($regularItem->id, $itemIds);
        $this->assertContains($installItem->id, $itemIds);

        // Verify installation item has is_installable true
        $returnedInstallItem = collect($items)->firstWhere('item_id', $installItem->id);
        $this->assertTrue($returnedInstallItem['is_installable']);
        $this->assertArrayNotHasKey('has_installation', $returnedInstallItem);
    }

    /**
     * Test: If installation item is assigned to a DIFFERENT driver, do not show to this driver.
     */
    public function test_installation_item_assigned_to_another_driver_is_not_shown_to_current_driver(): void
    {
        $order = Order::create([
            'order_number' => 'ORD-FILTER-003',
            'customer_name' => 'Charlie Customer',
            'total_amount' => 600.00,
            'status' => 'partial_assigned_driver',
        ]);

        $regularItem = OrderItem::create([
            'order_id' => $order->id,
            'product_code' => 'REG-03',
            'product_name' => 'Baby Monitor',
            'quantity' => 1,
            'unit_price' => 100.00,
            'status' => 'picked',
            'delivered_by' => $this->driver->id,
            'delivered_user_name' => $this->driver->name,
            'is_installable' => false,
        ]);

        $installItem = OrderItem::create([
            'order_id' => $order->id,
            'product_code' => 'INST-03',
            'product_name' => 'Playpen with Assembly Installation',
            'quantity' => 1,
            'unit_price' => 500.00,
            'status' => 'picked',
            'delivered_by' => $this->otherDriver->id, // Assigned to different driver!
            'delivered_user_name' => $this->otherDriver->name,
            'is_installable' => true,
        ]);

        OrderDriverAssigned::create([
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'assigned_driver_user_id' => $this->driver->id,
            'driver_name' => $this->driver->name,
            'driver_status' => 'assigned',
            'order_status' => 'partial_assigned_driver',
            'zone' => 'Al Wakrah',
        ]);

        $response = $this->postJson('/api/mobile/orders/driver/assigned', [
            'assigned_driver_user_id' => $this->driver->id,
        ]);

        $response->assertStatus(200);

        $data = $response->json('data.0');
        $this->assertTrue($data['partial_delivery']);
        $this->assertEquals(1, $data['installation_items_count']);
        $this->assertEquals(1, $data['unassigned_installation_items_count']);
        $this->assertEquals(1, $data['items_count']);

        $items = $data['order']['items'];
        $this->assertCount(1, $items);
        $this->assertEquals($regularItem->id, $items[0]['item_id']);
    }

    /**
     * Test: Flutter driver endpoint GET /api/mobile/driver/assigned-orders/{id} applies the same installation filter.
     */
    public function test_get_assigned_orders_endpoint_applies_same_installation_filter(): void
    {
        $order = Order::create([
            'order_number' => 'ORD-FILTER-004',
            'customer_name' => 'David Customer',
            'total_amount' => 350.00,
            'status' => 'partial_assigned_driver',
        ]);

        $regularItem = OrderItem::create([
            'order_id' => $order->id,
            'product_code' => 'REG-04',
            'product_name' => 'Bed Sheet',
            'quantity' => 1,
            'unit_price' => 50.00,
            'status' => 'picked',
            'delivered_by' => $this->driver->id,
            'delivered_user_name' => $this->driver->name,
            'is_installable' => false,
        ]);

        $installItem = OrderItem::create([
            'order_id' => $order->id,
            'product_code' => 'INST-04',
            'product_name' => 'Toddler Bed Installation',
            'quantity' => 1,
            'unit_price' => 300.00,
            'status' => 'pending',
            'delivered_by' => null,
            'is_installable' => true,
        ]);

        OrderDriverAssigned::create([
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'assigned_driver_user_id' => $this->driver->id,
            'driver_name' => $this->driver->name,
            'driver_status' => 'assigned',
            'order_status' => 'partial_assigned_driver',
            'zone' => 'Lusail',
        ]);

        $response = $this->getJson("/api/mobile/driver/assigned-orders/{$this->driver->id}");

        $response->assertStatus(200);
        $data = $response->json('data.0');

        $this->assertTrue($data['partial_delivery']);
        $this->assertEquals(1, $data['installation_items_count']);
        $this->assertEquals(1, $data['unassigned_installation_items_count']);
        $this->assertEquals(1, $data['items_count']);
        $this->assertCount(1, $data['order']['items']);
        $this->assertEquals($regularItem->id, $data['order']['items'][0]['item_id']);
    }

    /**
     * Test: POST /api/mobile/orders/driver/assigned supports query params for mobile apps.
     */
    public function test_post_assigned_driver_with_query_params(): void
    {
        $order = Order::create([
            'order_number' => 'ORD-FILTER-005',
            'customer_name' => 'Eve Customer',
            'total_amount' => 200.00,
            'status' => 'assigned_to_driver',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_code' => 'REG-05',
            'product_name' => 'Baby Carrier',
            'quantity' => 1,
            'unit_price' => 200.00,
            'status' => 'picked',
            'delivered_by' => $this->driver->id,
            'delivered_user_name' => $this->driver->name,
            'is_installable' => false,
        ]);

        OrderDriverAssigned::create([
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'assigned_driver_user_id' => $this->driver->id,
            'driver_name' => $this->driver->name,
            'driver_status' => 'assigned',
            'order_status' => 'assigned_to_driver',
            'zone' => 'The Pearl',
        ]);

        $response = $this->postJson("/api/mobile/orders/driver/assigned?assigned_driver_user_id={$this->driver->id}");

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('count'));
        $this->assertFalse($response->json('data.0.partial_delivery'));
        $this->assertFalse($response->json('data.0.has_installation'));
        $this->assertEquals(0, $response->json('data.0.installation_items_count'));
    }
}
