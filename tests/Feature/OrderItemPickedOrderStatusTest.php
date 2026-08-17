<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderItemPickedOrderStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_status_remains_picking_when_partially_picked_and_changes_to_picked_when_all_items_picked()
    {
        $user = User::factory()->create(['name' => 'Picker User']);

        $order = Order::create([
            'order_number' => '#3001',
            'status' => 'picking',
        ]);

        $item1 = OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => '101',
            'product_code' => 'P101',
            'product_name' => 'Item 1',
            'status' => 'pending',
        ]);

        $item2 = OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => '102',
            'product_code' => 'P102',
            'product_name' => 'Item 2',
            'status' => 'pending',
        ]);

        $item3 = OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => '103',
            'product_code' => 'P103',
            'product_name' => 'Item 3',
            'status' => 'pending',
        ]);

        $item4 = OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => '104',
            'product_code' => 'P104',
            'product_name' => 'Item 4',
            'status' => 'pending',
        ]);

        // Step 1: Pick 3 out of 4 items
        $this->postJson('/api/orders/items/update-status', [
            'order_item_id' => $item1->id,
            'status' => 'picked',
            'user_id' => $user->id,
        ]);
        $this->postJson('/api/orders/items/update-status', [
            'order_item_id' => $item2->id,
            'status' => 'picked',
            'user_id' => $user->id,
        ]);
        $this->postJson('/api/orders/items/update-status', [
            'order_item_id' => $item3->id,
            'status' => 'picked',
            'user_id' => $user->id,
        ]);

        // Order status should still be 'picking' because item 4 is not picked yet
        $order->refresh();
        $this->assertEquals('picking', $order->status);

        // Step 2: Pick the 4th item (all 4 items picked)
        $response = $this->postJson('/api/orders/items/update-status', [
            'order_item_id' => $item4->id,
            'status' => 'picked',
            'user_id' => $user->id,
        ]);

        $response->assertStatus(200);

        // Order status MUST now be updated to 'picked'
        $order->refresh();
        $this->assertEquals('picked', $order->status);
    }
}
