<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPackerAssigned;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PackerOrdersByIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_packer_orders_by_id_returns_picked_orders_assigned_to_packer()
    {
        $packer = User::factory()->create(['name' => 'John Packer']);

        $picker = User::factory()->create(['name' => 'Picker Person']);
        $otherPacker = User::factory()->create(['name' => 'Other Packer']);

        // Order 1: Status = 'picked', packed_by = $packer->id -> should be returned
        $order1 = Order::create([
            'order_number' => '#5001',
            'status' => 'picked',
            'packed_by' => $packer->id,
            'packed_user_name' => $packer->name,
            'customer_name' => 'Alice',
            'total_amount' => 120.00,
        ]);

        OrderItem::create([
            'order_id' => $order1->id,
            'line_item_id' => 'LI-101',
            'product_code' => 'PROD-001',
            'product_name' => 'Baby Stroller',
            'quantity' => 1,
            'unit_price' => 120.00,
            'status' => 'picked',
            'picked_by' => $picker->id,
        ]);

        // Order 2: Status = 'picked', but assigned via OrderPackerAssigned -> should be returned
        $order2 = Order::create([
            'order_number' => '#5002',
            'status' => 'picked',
            'customer_name' => 'Bob',
            'total_amount' => 80.00,
        ]);

        OrderPackerAssigned::create([
            'order_id' => $order2->id,
            'packer_assigned_user_id' => $packer->id,
            'packer_assigned_user_name' => $packer->name,
            'assigned_at' => now(),
        ]);

        OrderItem::create([
            'order_id' => $order2->id,
            'line_item_id' => 'LI-102',
            'product_code' => 'PROD-002',
            'product_name' => 'Baby Bottle',
            'quantity' => 2,
            'unit_price' => 40.00,
            'status' => 'picked',
            'picked_by' => $picker->id,
        ]);

        // Order 3: Status = 'packed' (already packed), packed_by = $packer->id -> should NOT be returned
        $order3 = Order::create([
            'order_number' => '#5003',
            'status' => 'packed',
            'packed_by' => $packer->id,
            'packed_user_name' => $packer->name,
            'customer_name' => 'Charlie',
            'total_amount' => 50.00,
        ]);

        // Order 4: Status = 'picked', but assigned to a DIFFERENT packer -> should NOT be returned
        $order4 = Order::create([
            'order_number' => '#5004',
            'status' => 'picked',
            'packed_by' => $otherPacker->id,
            'customer_name' => 'David',
            'total_amount' => 70.00,
        ]);

        // Request: GET /api/mobile/orders/packer/{id}
        $response = $this->getJson("/api/mobile/orders/packer/{$packer->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'count' => 2,
        ]);

        $data = $response->json('data');
        $this->assertCount(2, $data);

        $orderNumbers = collect($data)->pluck('order_number')->all();
        $this->assertContains('#5001', $orderNumbers);
        $this->assertContains('#5002', $orderNumbers);
        $this->assertNotContains('#5003', $orderNumbers);
        $this->assertNotContains('#5004', $orderNumbers);

        // Verify line items are populated from order_items table
        $firstOrder = collect($data)->firstWhere('order_number', '#5001');
        $this->assertCount(1, $firstOrder['items']);
        $this->assertEquals('Baby Stroller', $firstOrder['items'][0]['product_name']);
        $this->assertEquals('PROD-001', $firstOrder['items'][0]['sku']);
    }
}
