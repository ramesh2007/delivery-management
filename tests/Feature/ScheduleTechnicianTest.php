<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderInstallation;
use App\Models\TechnicianScheduled;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleTechnicianTest extends TestCase
{
    use RefreshDatabase;

    protected User $technician;
    protected Order $order;
    protected OrderItem $orderItem;
    protected OrderInstallation $orderInstallation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->technician = User::factory()->create([
            'name' => 'Test Technician',
            'role' => 'technician',
            'status' => 'active',
        ]);

        $this->order = Order::create([
            'order_number' => 'ORD-TECH-' . uniqid(),
            'customer_name' => 'John Doe',
            'customer_phone' => '123456789',
            'delivery_address' => 'Building 14, Unit 202, Doha',
            'total_amount' => 150.00,
            'status' => 'pending',
        ]);

        $this->orderItem = OrderItem::create([
            'order_id' => $this->order->id,
            'product_name' => 'AC Installation Unit',
            'product_code' => 'AC-001',
            'quantity' => 1,
            'unit_price' => 150.00,
            'is_installable' => true,
            'status' => 'pending',
        ]);

        $this->orderInstallation = OrderInstallation::create([
            'order_item_id' => $this->orderItem->id,
            'installation_type' => 'Split AC',
            'is_scheduled_assigned' => false,
        ]);
    }

    public function test_check_technician_available_when_no_schedules_exist(): void
    {
        $response = $this->json('POST', '/api/check-technician-avaliablity', [
            'technician_id' => $this->technician->id,
            'scheduled_date' => '2026-10-15',
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'available' => true,
            'response' => 'avaliable',
            'status' => 'available',
        ]);
    }

    public function test_create_schedule_fails_when_required_fields_missing(): void
    {
        $response = $this->json('POST', '/api/admin/technician-schedule/create', [
            'order_id' => $this->order->id,
            // Missing order_item_id, order_installation_id, technician_id, scheduled_date, start_time
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'status' => 'error',
        ]);
    }

    public function test_create_schedule_success_and_verifies_engaged_afterwards(): void
    {
        $payload = [
            'order_id' => $this->order->id,
            'order_item_id' => $this->orderItem->id,
            'order_installation_id' => $this->orderInstallation->id,
            'technician_id' => $this->technician->id,
            'scheduled_date' => '2026-10-15',
            'start_time' => '10:00:00',
            'end_time' => '11:30:00',
            'taken_time_in_mins' => 90,
            'zone' => 'Zone A',
            'building' => 'Building 14',
            'unit' => 'Unit 202',
            'customer_name' => 'John Doe',
        ];

        $createResponse = $this->json('POST', '/api/admin/technician-schedule/create', $payload);
        $createResponse->assertStatus(201);
        $createResponse->assertJson([
            'success' => true,
            'status' => 'success',
            'data' => [
                'order_id' => $this->order->id,
                'order_item_id' => $this->orderItem->id,
                'technician_id' => $this->technician->id,
                'start_time' => '10:00:00',
                'end_time' => '11:30:00',
                'taken_time_in_mins' => 90,
                'building' => 'Building 14',
                'unit' => 'Unit 202',
            ],
        ]);

        $this->assertDatabaseHas('technician_scheduled', [
            'order_id' => $this->order->id,
            'order_item_id' => $this->orderItem->id,
            'technician_id' => $this->technician->id,
            'start_time' => '10:00:00',
            'end_time' => '11:30:00',
            'taken_time_in_mins' => 90,
        ]);

        // Now checking availability during that window (10:30) must return not avaliable / engaged
        $checkConflictResponse = $this->json('POST', '/api/check-technician-avaliablity', [
            'technician_id' => $this->technician->id,
            'scheduled_date' => '2026-10-15',
            'start_time' => '10:30:00',
            'end_time' => '11:00:00',
        ]);

        $checkConflictResponse->assertStatus(200);
        $checkConflictResponse->assertJson([
            'success' => true,
            'available' => false,
            'response' => 'not avaliable',
            'status' => 'engaged',
        ]);

        // A non-overlapping time slot (12:00:00) should be available
        $checkFreeResponse = $this->json('POST', '/api/check-technician-avaliablity', [
            'technician_id' => $this->technician->id,
            'scheduled_date' => '2026-10-15',
            'start_time' => '12:00:00',
            'end_time' => '13:00:00',
        ]);

        $checkFreeResponse->assertStatus(200);
        $checkFreeResponse->assertJson([
            'success' => true,
            'available' => true,
            'response' => 'avaliable',
            'status' => 'available',
        ]);
    }
}
