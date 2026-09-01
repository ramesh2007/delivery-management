<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderIdentityApiResponseTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_order_by_id_returns_user_identity_fields_for_picker_packer_and_driver()
    {
        $picker = User::factory()->create(['name' => 'Alice Picker', 'email' => 'alice@example.com']);
        $packer = User::factory()->create(['name' => 'Bob Packer', 'email' => 'bob@example.com']);
        $driver = User::factory()->create(['name' => 'Charlie Driver', 'email' => 'charlie@example.com']);

        $order = Order::create([
            'order_number' => '7058477416692',
            'customer_name' => 'Ramesh Rakonex',
            'status' => 'picking',
            'assigned_to' => $picker->id,
            'assigned_user_name' => $picker->name,
            'delivered_by' => $driver->id,
            'delivered_user_name' => $driver->name,
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => '16608622346484',
            'product_code' => 'FRP0046',
            'product_name' => 'Baby Brezza Formula Pro',
            'quantity' => 1,
            'unit_price' => 999.0,
            'status' => 'pending',
            'assigned_to' => $picker->id,
            'assigned_user_name' => $picker->name,
            'picked_by' => $picker->id,
            'picked_user_name' => $picker->name,
            'packed_by' => $packer->id,
            'packed_user_name' => $packer->name,
            'delivered_by' => $driver->id,
            'delivered_user_name' => $driver->name,
        ]);

        $response = $this->getJson("/api/orders/{$order->order_number}");

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'order_number' => '7058477416692',
                    'assigned_to' => $picker->id,
                    'assigned_user_name' => 'Alice Picker',
                    'picked_by' => $picker->id,
                    'picked_user_name' => 'Alice Picker',
                    'packed_by' => $packer->id,
                    'packed_user_name' => 'Bob Packer',
                    'delivered_by' => $driver->id,
                    'delivered_user_name' => 'Charlie Driver',
                    'line_items' => [
                        [
                            'line_item_id' => '16608622346484',
                            'assigned_to' => $picker->id,
                            'assigned_user_name' => 'Alice Picker',
                            'picked_by' => $picker->id,
                            'picked_user_name' => 'Alice Picker',
                            'packed_by' => $packer->id,
                            'packed_user_name' => 'Bob Packer',
                            'delivered_by' => $driver->id,
                            'delivered_user_name' => 'Charlie Driver',
                        ]
                    ]
                ]
            ]);
    }
}
