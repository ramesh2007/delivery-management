<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderUnassignApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_assign_and_unassign_order()
    {
        $user = User::factory()->create(['name' => 'John Picker']);

        $order = Order::create([
            'order_number' => '#1001',
            'customer_name' => 'Jane Doe',
            'total_amount' => 100.00,
            'status' => 'pending',
        ]);

        $item1 = OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => '111',
            'product_code' => 'WIDGET-A',
            'product_name' => 'Widget A',
            'quantity' => 2,
            'unit_price' => 25.00,
            'status' => 'pending',
        ]);

        $item2 = OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => '222',
            'product_code' => 'WIDGET-B',
            'product_name' => 'Widget B',
            'quantity' => 1,
            'unit_price' => 50.00,
            'status' => 'pending',
        ]);

        // Assign order to user
        $assignResponse = $this->postJson('/api/orders/assign-me', [
            'order_id' => $order->id,
            'user_id' => $user->id,
            'user_name' => $user->name,
        ]);

        $assignResponse->assertStatus(200)
            ->assertJson(['success' => true]);

        $order->refresh();
        $this->assertEquals($user->id, $order->assigned_to);
        $this->assertEquals('picking', $order->status);
        $this->assertEquals($user->id, $item1->fresh()->assigned_to);

        // Unassign order
        $unassignResponse = $this->postJson('/api/orders/unassign', [
            'order_id' => $order->id,
            'user_id' => $user->id,
            'user_name' => $user->name,
        ]);

        $unassignResponse->assertStatus(200)
            ->assertJson(['success' => true]);

        $order->refresh();
        $this->assertNull($order->assigned_to);
        $this->assertEquals('pending', $order->status);
        $this->assertNull($item1->fresh()->assigned_to);
        $this->assertNull($item2->fresh()->assigned_to);
    }

    public function test_can_unassign_specific_unpicked_item()
    {
        $user = User::factory()->create(['name' => 'Jane Picker']);

        $order = Order::create([
            'order_number' => '#1002',
            'status' => 'picking',
            'assigned_to' => $user->id,
            'assigned_user_name' => $user->name,
        ]);

        $item1 = OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => '333',
            'product_code' => 'ITEM-1',
            'product_name' => 'Item 1',
            'status' => 'picking',
            'assigned_to' => $user->id,
            'assigned_user_name' => $user->name,
        ]);

        $item2 = OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => '444',
            'product_code' => 'ITEM-2',
            'product_name' => 'Item 2',
            'status' => 'picking',
            'assigned_to' => $user->id,
            'assigned_user_name' => $user->name,
        ]);

        // Unassign only item 1
        $response = $this->postJson('/api/orders/items/unassign', [
            'order_id' => $order->id,
            'line_item_id' => '333',
            'user_id' => $user->id,
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertNull($item1->fresh()->assigned_to);
        $this->assertEquals('pending', $item1->fresh()->status);

        // Item 2 should remain assigned
        $this->assertEquals($user->id, $item2->fresh()->assigned_to);
    }

    public function test_cannot_unassign_item_if_already_picked()
    {
        $user = User::factory()->create(['name' => 'Alice Picker']);

        $order = Order::create([
            'order_number' => '#1003',
            'status' => 'picking',
            'assigned_to' => $user->id,
            'assigned_user_name' => $user->name,
        ]);

        $itemPicked = OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => '555',
            'product_code' => 'ITEM-PICKED',
            'product_name' => 'Picked Item',
            'status' => 'picked',
            'assigned_to' => $user->id,
            'assigned_user_name' => $user->name,
            'picked_by' => $user->id,
            'picked_at' => now(),
        ]);

        // Attempting to unassign picked item should fail with HTTP 400
        $response = $this->postJson('/api/orders/items/unassign', [
            'order_id' => $order->id,
            'line_item_id' => '555',
            'user_id' => $user->id,
        ]);

        $response->assertStatus(400)
            ->assertJson(['success' => false]);

        // The picked item assignment must not be cleared
        $this->assertEquals($user->id, $itemPicked->fresh()->assigned_to);
        $this->assertEquals('picked', $itemPicked->fresh()->status);
    }
}
