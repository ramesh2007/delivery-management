<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PackerVerificationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_packer_can_verify_item_barcode_and_record_verification()
    {
        $packer = User::factory()->create(['name' => 'Sam Packer']);

        $order = Order::create([
            'order_number' => '#4001',
            'status' => 'picked',
        ]);

        $item1 = OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => '9001',
            'product_code' => 'PCODE-9001',
            'barcode' => '8901234567890',
            'product_name' => 'Verified Product',
            'status' => 'picked',
        ]);

        // Packer scans and verifies barcode
        $response = $this->postJson('/api/orders/packer/verify-item', [
            'order_id' => $order->id,
            'scanned_barcode' => '8901234567890',
            'user_id' => $packer->id,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'is_verified' => true,
                    'packer' => [
                        'id' => $packer->id,
                        'name' => $packer->name,
                    ],
                    'packing_summary' => [
                        'total_items' => 1,
                        'verified_items' => 1,
                        'all_items_verified' => true,
                    ],
                ],
            ]);

        $this->assertDatabaseHas('packer_verifications', [
            'order_id' => $order->id,
            'order_item_id' => $item1->id,
            'packer_id' => $packer->id,
            'scanned_barcode' => '8901234567890',
            'is_verified' => 1,
        ]);

        $this->assertTrue((bool) $item1->fresh()->is_packer_verified);
        $this->assertEquals($packer->id, $item1->fresh()->packer_verified_by);
    }

    public function test_unmatched_barcode_returns_error()
    {
        $packer = User::factory()->create(['name' => 'Sam Packer']);

        $order = Order::create([
            'order_number' => '#4002',
            'status' => 'picked',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => '9002',
            'product_code' => 'PCODE-9002',
            'barcode' => '1111111111111',
            'product_name' => 'Valid Product',
            'status' => 'picked',
        ]);

        // Packer scans incorrect barcode
        $response = $this->postJson('/api/orders/packer/verify-item', [
            'order_id' => $order->id,
            'scanned_barcode' => '9999999999999',
            'user_id' => $packer->id,
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'is_verified' => false,
            ]);
    }

    public function test_packer_can_complete_packing_and_enter_bag_count()
    {
        $packer = User::factory()->create(['name' => 'Bob Packer']);

        $order = Order::create([
            'order_number' => '#4003',
            'status' => 'picked',
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => '9003',
            'product_code' => 'PCODE-9003',
            'barcode' => '2222222222222',
            'product_name' => 'Item to pack',
            'status' => 'picked',
        ]);

        // Complete packing with 3 bags
        $response = $this->postJson('/api/orders/packer/complete-packing', [
            'order_id' => $order->id,
            'bag_count' => 3,
            'user_id' => $packer->id,
            'notes' => 'Packed safely in 3 bags',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'bag_count' => 3,
                    'packed_by' => [
                        'id' => $packer->id,
                        'name' => $packer->name,
                    ],
                ],
            ]);

        $order->refresh();
        $this->assertEquals('packed', $order->status);
        $this->assertEquals(3, $order->bag_count);
        $this->assertEquals($packer->id, $order->packed_by);

        $item->refresh();
        $this->assertEquals('packed', $item->status);
        $this->assertEquals($packer->id, $item->packed_by);
    }
}
