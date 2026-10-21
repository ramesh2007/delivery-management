<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderDriverAssigned;
use App\Models\OrderStatusLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DriverAssignmentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected User $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->driver = User::factory()->create([
            'name' => 'John Driver',
            'role' => 'driver',
        ]);
    }

    /**
     * Rule 1: Normal items + VS items: assigns delivered_by and delivered_user_name in order_items,
     * and sets the order status to assigned_to_driver.
     */
    public function test_assigns_normal_and_vs_items_and_sets_order_status_to_assigned_to_driver()
    {
        $order = Order::create([
            'order_number' => 'ORD-101',
            'customer_name' => 'Alice Test',
            'total_amount' => 150.00,
            'status' => 'pending',
        ]);

        $normalItem = OrderItem::create([
            'order_id' => $order->id,
            'product_code' => 'PROD-NORMAL',
            'product_name' => 'Normal Baby Bed',
            'quantity' => 1,
            'unit_price' => 100.00,
            'status' => 'picked',
            'is_installable' => false,
        ]);

        $vsItem = OrderItem::create([
            'order_id' => $order->id,
            'product_code' => 'PROD-VS',
            'product_name' => 'Virtual Stock Item',
            'quantity' => 1,
            'unit_price' => 50.00,
            'status' => 'pending',
            'product_tags' => 'VS, virtual_stock',
            'is_installable' => false,
        ]);

        $response = $this->postJson('/api/admin/orders/assign-driver', [
            'order_number' => 'ORD-101',
            'assigned_driver_user_id' => $this->driver->id,
            'zone' => 'Zone A',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'status' => 'success',
            'data' => [
                'order_number' => 'ORD-101',
                'order_status' => 'assigned_to_driver',
                'driver_status' => 'Assigned',
                'zone' => 'Zone A',
            ],
        ]);

        // Check order table
        $order->refresh();
        $this->assertEquals('assigned_to_driver', $order->status);
        $this->assertEquals($this->driver->id, $order->delivered_by);
        $this->assertEquals('John Driver', $order->delivered_user_name);

        // Check normal and VS items are assigned delivered_by and delivered_user_name
        $normalItem->refresh();
        $this->assertEquals($this->driver->id, $normalItem->delivered_by);
        $this->assertEquals('John Driver', $normalItem->delivered_user_name);

        $vsItem->refresh();
        $this->assertEquals($this->driver->id, $vsItem->delivered_by);
        $this->assertEquals('John Driver', $vsItem->delivered_user_name);

        // Check orders_driver_assigned table
        $assignment = OrderDriverAssigned::where('order_id', $order->id)->first();
        $this->assertNotNull($assignment);
        $this->assertEquals($this->driver->id, $assignment->assigned_driver_user_id);
        $this->assertEquals('assigned_to_driver', $assignment->order_status);
        $this->assertEquals('Zone A', $assignment->zone);
    }

    /**
     * Rule 2 & 3: Installation items are NOT assigned delivered_by or delivered_user_name,
     * and if all regular items are picked, order status is set to partial_assigned_driver.
     */
    public function test_installation_items_remain_unassigned_and_order_status_is_partial_assigned_driver()
    {
        $order = Order::create([
            'order_number' => 'ORD-102',
            'customer_name' => 'Bob Test',
            'total_amount' => 300.00,
            'status' => 'pending',
        ]);

        $regularItem = OrderItem::create([
            'order_id' => $order->id,
            'product_code' => 'PROD-REGULAR',
            'product_name' => 'Regular Pillow',
            'quantity' => 1,
            'unit_price' => 50.00,
            'status' => 'picked',
            'is_installable' => false,
        ]);

        $installationItem = OrderItem::create([
            'order_id' => $order->id,
            'product_code' => 'PROD-INSTALL',
            'product_name' => 'Heavy Wooden Crib with Installation',
            'quantity' => 1,
            'unit_price' => 250.00,
            'status' => 'pending',
            'is_installable' => true,
        ]);

        $response = $this->postJson('/api/admin/orders/assign-driver', [
            'order_number' => 'ORD-102',
            'assigned_driver_user_id' => $this->driver->id,
            'zone' => 'Zone B',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'status' => 'success',
            'data' => [
                'order_number' => 'ORD-102',
                'order_status' => 'partial_assigned_driver',
                'driver_status' => 'Assigned',
            ],
        ]);

        $order->refresh();
        $this->assertEquals('partial_assigned_driver', $order->status);
        $this->assertEquals($this->driver->id, $order->delivered_by);
        $this->assertEquals('John Driver', $order->delivered_user_name);

        // Regular item was assigned
        $regularItem->refresh();
        $this->assertEquals($this->driver->id, $regularItem->delivered_by);
        $this->assertEquals('John Driver', $regularItem->delivered_user_name);

        // Installation item remains UNASSIGNED to driver
        $installationItem->refresh();
        $this->assertNull($installationItem->delivered_by);
        $this->assertNull($installationItem->delivered_user_name);

        // Check orders_driver_assigned
        $assignment = OrderDriverAssigned::where('order_id', $order->id)->first();
        $this->assertNotNull($assignment);
        $this->assertEquals('partial_assigned_driver', $assignment->order_status);
    }

    /**
     * Test regular item not picked returns HTTP 400.
     */
    public function test_rejects_assignment_if_regular_item_not_picked()
    {
        $order = Order::create([
            'order_number' => 'ORD-103',
            'customer_name' => 'Charlie Test',
            'total_amount' => 80.00,
            'status' => 'pending',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_code' => 'PROD-UNPICKED',
            'product_name' => 'Unpicked Item',
            'quantity' => 1,
            'unit_price' => 80.00,
            'status' => 'pending',
            'is_installable' => false,
        ]);

        $response = $this->postJson('/api/admin/orders/assign-driver', [
            'order_number' => 'ORD-103',
            'assigned_driver_user_id' => $this->driver->id,
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'success' => false,
            'status' => 'error',
            'message' => 'Order ORD-103 cannot be assigned to a driver in its current status. All regular items must be picked first.',
        ]);
    }

    /**
     * Test mixed order containing Normal, VS, and Installation items:
     * Normal + VS items are assigned to the driver, installation items remain unassigned,
     * and order status becomes partial_assigned_driver.
     */
    public function test_mixed_order_with_normal_vs_and_installation_items()
    {
        $order = Order::create([
            'order_number' => 'ORD-104',
            'customer_name' => 'Dana Test',
            'total_amount' => 500.00,
            'status' => 'pending',
        ]);

        $normalItem = OrderItem::create([
            'order_id' => $order->id,
            'product_code' => 'NORM-1',
            'product_name' => 'Normal Diapers',
            'quantity' => 2,
            'unit_price' => 50.00,
            'status' => 'picked',
            'is_installable' => false,
        ]);

        $vsItem = OrderItem::create([
            'order_id' => $order->id,
            'product_code' => 'VS-1',
            'product_name' => 'Virtual Stock Rocker',
            'quantity' => 1,
            'unit_price' => 150.00,
            'status' => 'pending',
            'product_tags' => 'VS',
            'is_installable' => false,
        ]);

        $installItem = OrderItem::create([
            'order_id' => $order->id,
            'product_code' => 'INST-1',
            'product_name' => 'Wardrobe Installation',
            'quantity' => 1,
            'unit_price' => 300.00,
            'status' => 'pending',
            'is_installable' => true,
        ]);

        $response = $this->postJson('/api/admin/orders/assign-driver', [
            'order_number' => 'ORD-104',
            'assigned_driver_user_id' => $this->driver->id,
            'zone' => 'Zone North',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'status' => 'success',
            'data' => [
                'order_number' => 'ORD-104',
                'order_status' => 'partial_assigned_driver',
                'driver_status' => 'Assigned',
            ],
        ]);

        $order->refresh();
        $this->assertEquals('partial_assigned_driver', $order->status);
        $this->assertEquals($this->driver->id, $order->delivered_by);

        // Normal item has driver assigned
        $normalItem->refresh();
        $this->assertEquals($this->driver->id, $normalItem->delivered_by);
        $this->assertEquals('John Driver', $normalItem->delivered_user_name);

        // VS item has driver assigned
        $vsItem->refresh();
        $this->assertEquals($this->driver->id, $vsItem->delivered_by);
        $this->assertEquals('John Driver', $vsItem->delivered_user_name);

        // Installation item is NOT assigned
        $installItem->refresh();
        $this->assertNull($installItem->delivered_by);
        $this->assertNull($installItem->delivered_user_name);
    }
}

