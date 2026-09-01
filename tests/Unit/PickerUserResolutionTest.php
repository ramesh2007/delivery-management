<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PickerUserResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_assign_order_with_items_resolves_user_by_email_when_user_id_missing(): void
    {
        $user = User::factory()->create([
            'name' => 'Alice Picker',
            'email' => 'alice.picker@example.com',
        ]);

        $order = Order::create([
            'order_number' => '10001',
            'status' => 'pending',
            'total_amount' => 100.00,
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => '50001',
            'product_code' => 'ITEM-1',
            'product_name' => 'Test Item',
            'quantity' => 1,
            'unit_price' => 100.00,
            'status' => 'pending',
        ]);

        // Send request with email instead of numeric user_id
        $response = $this->postJson('/api/orders/assign-with-items', [
            'order_id' => $order->id,
            'order_items' => [$item->id],
            'user_email' => 'alice.picker@example.com',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $order->refresh();
        $item->refresh();

        $this->assertEquals($user->id, $order->assigned_to);
        $this->assertEquals('Alice Picker', $order->assigned_user_name);
        $this->assertEquals($user->id, $item->assigned_to);
        $this->assertEquals('Alice Picker', $item->assigned_user_name);
    }

    public function test_assign_order_with_items_resolves_user_by_name_when_user_id_missing(): void
    {
        $user = User::factory()->create([
            'name' => 'Bob Picker',
            'email' => 'bob.picker@example.com',
        ]);

        $order = Order::create([
            'order_number' => '10002',
            'status' => 'pending',
            'total_amount' => 50.00,
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => '50002',
            'product_code' => 'ITEM-2',
            'product_name' => 'Test Item 2',
            'quantity' => 1,
            'unit_price' => 50.00,
            'status' => 'pending',
        ]);

        // Send request with name string as assigned_to
        $response = $this->postJson('/api/orders/assign-with-items', [
            'order_id' => $order->id,
            'order_items' => [$item->id],
            'assigned_to' => 'Bob Picker',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $order->refresh();
        $item->refresh();

        $this->assertEquals($user->id, $order->assigned_to);
        $this->assertEquals('Bob Picker', $order->assigned_user_name);
        $this->assertEquals($user->id, $item->assigned_to);
        $this->assertEquals('Bob Picker', $item->assigned_user_name);
    }
}
