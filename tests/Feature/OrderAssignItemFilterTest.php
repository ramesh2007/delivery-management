<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderAssignItemFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigns_only_single_line_item_id_when_provided()
    {
        $user = User::factory()->create(['name' => 'Single Line Picker']);

        $order = Order::create([
            'order_number' => '#2001',
            'status' => 'pending',
        ]);

        $item1 = OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => '10001',
            'product_code' => 'P1',
            'product_name' => 'Product 1',
            'quantity' => 1,
            'unit_price' => 10.0,
            'status' => 'pending',
        ]);

        $item2 = OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => '10002',
            'product_code' => 'P2',
            'product_name' => 'Product 2',
            'quantity' => 1,
            'unit_price' => 20.0,
            'status' => 'pending',
        ]);

        // Request assignment with single line_item_id
        $response = $this->postJson('/api/orders/assign-me', [
            'order_id' => $order->id,
            'line_item_id' => '10001',
            'user_id' => $user->id,
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        // Item 1 should be assigned
        $this->assertEquals($user->id, $item1->fresh()->assigned_to);

        // Item 2 should remain unassigned
        $this->assertNull($item2->fresh()->assigned_to);
    }

    public function test_assigns_array_of_line_item_ids_when_provided()
    {
        $user = User::factory()->create(['name' => 'Array Line Picker']);

        $order = Order::create([
            'order_number' => '#2002',
            'status' => 'pending',
        ]);

        $item1 = OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => '20001',
            'product_code' => 'PA',
            'product_name' => 'Product A',
            'quantity' => 1,
            'unit_price' => 10.0,
            'status' => 'pending',
        ]);

        $item2 = OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => '20002',
            'product_code' => 'PB',
            'product_name' => 'Product B',
            'quantity' => 1,
            'unit_price' => 20.0,
            'status' => 'pending',
        ]);

        $item3 = OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => '20003',
            'product_code' => 'PC',
            'product_name' => 'Product C',
            'quantity' => 1,
            'unit_price' => 30.0,
            'status' => 'pending',
        ]);

        // Request assignment with array of line_item_ids
        $response = $this->postJson('/api/orders/assign-me', [
            'order_id' => $order->id,
            'line_item_ids' => ['20001', '20003'],
            'user_id' => $user->id,
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        // Item 1 and Item 3 should be assigned
        $this->assertEquals($user->id, $item1->fresh()->assigned_to);
        $this->assertEquals($user->id, $item3->fresh()->assigned_to);

        // Item 2 should remain unassigned
        $this->assertNull($item2->fresh()->assigned_to);
    }

    public function test_assigns_all_items_when_no_item_ids_specified()
    {
        $user = User::factory()->create(['name' => 'Full Order Picker']);

        $order = Order::create([
            'order_number' => '#2003',
            'status' => 'pending',
        ]);

        $item1 = OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => '30001',
            'product_code' => 'PX',
            'product_name' => 'Product X',
            'status' => 'pending',
        ]);

        $item2 = OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => '30002',
            'product_code' => 'PY',
            'product_name' => 'Product Y',
            'status' => 'pending',
        ]);

        // Request assignment for whole order without specifying line_item_id
        $response = $this->postJson('/api/orders/assign-me', [
            'order_id' => $order->id,
            'user_id' => $user->id,
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        // Both items should be assigned
        $this->assertEquals($user->id, $item1->fresh()->assigned_to);
        $this->assertEquals($user->id, $item2->fresh()->assigned_to);
    }
}
