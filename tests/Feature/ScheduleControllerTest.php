<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderInstallation;
use App\Models\OrderStatusLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ScheduleControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_installations_table_has_is_scheduled_assigned_column(): void
    {
        $this->assertTrue(Schema::hasTable('order_installations'));
        $this->assertTrue(Schema::hasColumn('order_installations', 'is_scheduled_assigned'));
    }

    public function test_assign_schedule_via_route_parameter(): void
    {
        $order = Order::create([
            'order_number' => '#TEST-SCHED-1',
            'customer_name' => 'John Doe',
            'total_amount' => 120.00,
            'status' => 'pending',
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_code' => 'AC-SPLIT',
            'product_name' => 'Split AC 1.5 Ton',
            'quantity' => 1,
            'unit_price' => 120.00,
            'status' => 'pending',
            'is_installable' => true,
        ]);

        $response = $this->postJson("/api/admin/schedule/assign/{$item->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('data.order_item_id', $item->id);
        $response->assertJsonPath('data.is_scheduled_assigned', true);
        $response->assertJsonPath('data.installation.is_scheduled_assigned', true);

        $this->assertDatabaseHas('order_installations', [
            'order_item_id' => $item->id,
            'is_scheduled_assigned' => true,
        ]);
    }

    public function test_assign_schedule_via_request_body(): void
    {
        $order = Order::create([
            'order_number' => '#TEST-SCHED-2',
            'customer_name' => 'Jane Smith',
            'total_amount' => 250.00,
            'status' => 'pending',
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_code' => 'WASH-01',
            'product_name' => 'Front Load Washing Machine',
            'quantity' => 1,
            'unit_price' => 250.00,
            'status' => 'pending',
            'is_installable' => true,
        ]);

        $response = $this->postJson('/api/admin/schedule/assign', [
            'order_item_id' => $item->id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.order_item_id', $item->id);
        $response->assertJsonPath('data.is_scheduled_assigned', true);

        $this->assertDatabaseHas('order_installations', [
            'order_item_id' => $item->id,
            'is_scheduled_assigned' => true,
        ]);
    }

    public function test_unassign_schedule_sets_boolean_false(): void
    {
        $order = Order::create([
            'order_number' => '#TEST-SCHED-3',
            'customer_name' => 'Mark Wilson',
            'total_amount' => 90.00,
            'status' => 'pending',
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_code' => 'DISH-01',
            'product_name' => 'Dishwasher',
            'quantity' => 1,
            'unit_price' => 90.00,
            'status' => 'pending',
            'is_installable' => true,
        ]);

        OrderInstallation::create([
            'order_item_id' => $item->id,
            'installation_type' => 'standard',
            'is_scheduled_assigned' => true,
        ]);

        $response = $this->postJson("/api/admin/schedule/assign/{$item->id}", [
            'is_scheduled_assigned' => false,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.is_scheduled_assigned', false);

        $this->assertDatabaseHas('order_installations', [
            'order_item_id' => $item->id,
            'is_scheduled_assigned' => false,
        ]);
    }

    public function test_assign_multiple_order_items_batch(): void
    {
        $order = Order::create([
            'order_number' => '#TEST-SCHED-4',
            'customer_name' => 'Sarah Connor',
            'total_amount' => 500.00,
            'status' => 'pending',
        ]);

        $item1 = OrderItem::create([
            'order_id' => $order->id,
            'product_code' => 'AC-01',
            'product_name' => 'AC Unit 1',
            'quantity' => 1,
            'unit_price' => 250.00,
            'status' => 'pending',
            'is_installable' => true,
        ]);

        $item2 = OrderItem::create([
            'order_id' => $order->id,
            'product_code' => 'AC-02',
            'product_name' => 'AC Unit 2',
            'quantity' => 1,
            'unit_price' => 250.00,
            'status' => 'pending',
            'is_installable' => true,
        ]);

        $response = $this->postJson('/api/admin/schedule/assign', [
            'order_item_ids' => [$item1->id, $item2->id],
            'is_scheduled_assigned' => true,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.updated_count', 2);

        $this->assertDatabaseHas('order_installations', [
            'order_item_id' => $item1->id,
            'is_scheduled_assigned' => true,
        ]);

        $this->assertDatabaseHas('order_installations', [
            'order_item_id' => $item2->id,
            'is_scheduled_assigned' => true,
        ]);
    }

    public function test_assign_schedule_returns_404_when_item_not_found(): void
    {
        $response = $this->postJson('/api/admin/schedule/assign/99999');

        $response->assertStatus(404);
        $response->assertJsonPath('status', 'error');
        $response->assertJsonPath('success', false);
    }

    public function test_assign_schedule_returns_422_when_order_item_id_missing(): void
    {
        $response = $this->postJson('/api/admin/schedule/assign');

        $response->assertStatus(422);
        $response->assertJsonPath('status', 'error');
        $response->assertJsonPath('success', false);
    }

    public function test_scheduled_items_endpoint_returns_only_items_with_is_scheduled_assigned_true(): void
    {
        $order1 = Order::create([
            'order_number' => '#ORD-SCHED-101',
            'customer_name' => 'Alice Customer',
            'customer_phone' => '+974 5555 1111',
            'delivery_address' => 'Doha, Qatar',
            'total_amount' => 450.00,
            'status' => 'pending',
        ]);

        $itemScheduled = OrderItem::create([
            'order_id' => $order1->id,
            'product_code' => 'FRIDGE-01',
            'product_name' => 'Double Door Refrigerator',
            'quantity' => 1,
            'unit_price' => 350.00,
            'status' => 'pending',
            'is_installable' => true,
        ]);

        OrderInstallation::create([
            'order_item_id' => $itemScheduled->id,
            'installation_type' => 'appliances',
            'installation_level' => 'level-2',
            'is_scheduled_assigned' => true,
        ]);

        $itemNotScheduled = OrderItem::create([
            'order_id' => $order1->id,
            'product_code' => 'FILTER-01',
            'product_name' => 'Water Filter',
            'quantity' => 1,
            'unit_price' => 100.00,
            'status' => 'pending',
            'is_installable' => true,
        ]);

        OrderInstallation::create([
            'order_item_id' => $itemNotScheduled->id,
            'installation_type' => 'plumbing',
            'installation_level' => 'level-1',
            'is_scheduled_assigned' => false,
        ]);

        // Second order with regular item (no installation)
        $order2 = Order::create([
            'order_number' => '#ORD-REG-202',
            'customer_name' => 'Bob Regular',
            'total_amount' => 50.00,
            'status' => 'pending',
        ]);

        OrderItem::create([
            'order_id' => $order2->id,
            'product_code' => 'MUG-01',
            'product_name' => 'Coffee Mug',
            'quantity' => 1,
            'unit_price' => 50.00,
            'status' => 'pending',
            'is_installable' => false,
        ]);

        // 1. Test GET /api/admin/orders/status/scheduled-items
        $response = $this->getJson('/api/admin/orders/status/scheduled-items');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('status_filter', 'scheduled_assigned');
        $response->assertJsonPath('pagination.total', 1);

        // Verify item-wise fields along with order details
        $itemData = $response->json('data.0');
        $this->assertEquals($itemScheduled->id, $itemData['order_item_id']);
        $this->assertEquals('#ORD-SCHED-101', $itemData['order_number']);
        $this->assertEquals('Alice Customer', $itemData['customer']['name']);
        $this->assertEquals('Double Door Refrigerator', $itemData['product_name']);
        $this->assertTrue($itemData['is_scheduled_assigned']);
        $this->assertTrue($itemData['installation']['is_scheduled_assigned']);
        $this->assertEquals('appliances', $itemData['installation_type']);
        $this->assertEquals('level-2', $itemData['installation_level']);

        // 2. Test alias GET /api/admin/schedule/items
        $aliasResponse = $this->getJson('/api/admin/schedule/items');
        $aliasResponse->assertStatus(200);
        $aliasResponse->assertJsonPath('pagination.total', 1);

        // 3. Test filter is_scheduled_assigned=false
        $unassignedResponse = $this->getJson('/api/admin/orders/status/scheduled-items?is_scheduled_assigned=false');
        $unassignedResponse->assertStatus(200);
        $unassignedResponse->assertJsonPath('pagination.total', 1);
        $this->assertEquals($itemNotScheduled->id, $unassignedResponse->json('data.0.order_item_id'));
        $this->assertFalse($unassignedResponse->json('data.0.is_scheduled_assigned'));

        // 4. Test search filter
        $searchResponse = $this->getJson('/api/admin/orders/status/scheduled-items?search=ORD-SCHED-101');
        $searchResponse->assertStatus(200);
        $searchResponse->assertJsonPath('pagination.total', 1);

        $noMatchSearch = $this->getJson('/api/admin/orders/status/scheduled-items?search=NONEXISTENT');
        $noMatchSearch->assertStatus(200);
        $noMatchSearch->assertJsonPath('pagination.total', 0);
    }

    public function test_assign_schedule_via_line_items_array_payload(): void
    {
        $order = Order::create([
            'order_number' => '#TEST-LINE-ITEM-1',
            'customer_name' => 'Shopify User',
            'total_amount' => 300.00,
            'status' => 'pending',
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => '16658974671092',
            'product_code' => 'SHOPIFY-PROD-1',
            'product_name' => 'Shopify TV Stand',
            'quantity' => 1,
            'unit_price' => 300.00,
            'status' => 'pending',
            'is_installable' => true,
        ]);

        // Payload with line_items array containing line item id
        $response = $this->postJson('/api/admin/schedule/assign', [
            'line_items' => [
                [
                    'id' => 16658974671092,
                ]
            ],
            'is_scheduled_assigned' => true,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.order_item_id', $item->id);
        $response->assertJsonPath('data.is_scheduled_assigned', true);

        $this->assertDatabaseHas('order_installations', [
            'order_item_id' => $item->id,
            'is_scheduled_assigned' => true,
        ]);
    }

    public function test_assign_schedule_via_line_item_id_in_url(): void
    {
        $order = Order::create([
            'order_number' => '#TEST-LINE-ITEM-2',
            'customer_name' => 'Shopify User 2',
            'total_amount' => 150.00,
            'status' => 'pending',
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => '16658974671099',
            'product_code' => 'SHOPIFY-PROD-2',
            'product_name' => 'Shopify Coffee Table',
            'quantity' => 1,
            'unit_price' => 150.00,
            'status' => 'pending',
            'is_installable' => true,
        ]);

        $response = $this->postJson('/api/admin/schedule/assign/16658974671099');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.order_item_id', $item->id);
        $response->assertJsonPath('data.is_scheduled_assigned', true);

        $this->assertDatabaseHas('order_installations', [
            'order_item_id' => $item->id,
            'is_scheduled_assigned' => true,
        ]);
    }
}
