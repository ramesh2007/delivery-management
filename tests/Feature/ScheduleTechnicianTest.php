<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderInstallation;
use App\Models\TechnicianScheduled;
use App\Models\TechnicianScheduleHistory;
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

    public function test_reschedule_preserves_existing_schedule_in_history_table_and_updates_technician_scheduled(): void
    {
        // 1. Initial Schedule Creation
        $initialPayload = [
            'order_id' => $this->order->id,
            'order_item_id' => $this->orderItem->id,
            'order_installation_id' => $this->orderInstallation->id,
            'technician_id' => $this->technician->id,
            'scheduled_date' => '2026-10-15',
            'start_time' => '10:00:00',
            'end_time' => '11:30:00',
            'taken_time_in_mins' => 90,
            'zone' => 'Zone North',
            'notes' => 'Original schedule note',
        ];

        $initialResponse = $this->json('POST', '/api/technician-schedule/create', $initialPayload);
        $initialResponse->assertStatus(201);
        $initialResponse->assertJson([
            'success' => true,
            'is_rescheduled' => false,
        ]);

        $this->assertDatabaseCount('technician_scheduled', 1);
        $this->assertDatabaseCount('technician_scheduled_history', 0);

        $initialSchedule = TechnicianScheduled::first();
        $this->assertEquals('2026-10-15', $initialSchedule->scheduled_date->toDateString());
        $this->assertEquals('10:00:00', $initialSchedule->start_time);
        $this->assertEquals(0, $initialSchedule->reschedule_count);

        // 2. Reschedule to a new date and time
        $reschedulePayload = [
            'order_id' => $this->order->id,
            'order_item_id' => $this->orderItem->id,
            'order_installation_id' => $this->orderInstallation->id,
            'technician_id' => $this->technician->id,
            'scheduled_date' => '2026-10-18',
            'start_time' => '14:00:00',
            'end_time' => '15:30:00',
            'taken_time_in_mins' => 90,
            'reschedule_reason' => 'Customer requested afternoon slot',
            'notes' => 'Updated afternoon appointment',
        ];

        $rescheduleResponse = $this->json('POST', '/api/technician-schedule/create', $reschedulePayload);
        $rescheduleResponse->assertStatus(200);
        $rescheduleResponse->assertJson([
            'success' => true,
            'status' => 'success',
            'is_rescheduled' => true,
        ]);

        // Verify: Existing schedule table still has only 1 record (same ID), NOT duplicate records
        $this->assertDatabaseCount('technician_scheduled', 1);
        $updatedSchedule = TechnicianScheduled::first();
        $this->assertEquals($initialSchedule->id, $updatedSchedule->id);
        $this->assertEquals('2026-10-18', $updatedSchedule->scheduled_date->toDateString());
        $this->assertEquals('14:00:00', $updatedSchedule->start_time);
        $this->assertEquals('15:30:00', $updatedSchedule->end_time);
        $this->assertEquals(1, $updatedSchedule->reschedule_count);
        $this->assertEquals('rescheduled', $updatedSchedule->status);
        $this->assertEquals('Updated afternoon appointment', $updatedSchedule->notes);

        // Verify: History table now contains the PREVIOUS schedule data (optimized minimal log)
        $this->assertDatabaseCount('technician_scheduled_history', 1);
        $history = TechnicianScheduleHistory::first();
        $this->assertEquals($initialSchedule->id, $history->technician_scheduled_id);
        $this->assertEquals('2026-10-15', $history->scheduled_date->toDateString());
        $this->assertEquals('10:00:00', $history->start_time);
        $this->assertEquals('11:30:00', $history->end_time);
        $this->assertEquals('Original schedule note', $history->notes);
        $this->assertEquals('Customer requested afternoon slot', $history->reschedule_reason);

        // Verify: Order & customer data are resolved via relationship without repeating columns
        $this->assertEquals('John Doe', $history->technicianSchedule->customer_name);
        $this->assertEquals($this->order->id, $history->technicianSchedule->order_id);

        // Verify: Previous slot (2026-10-15 10:00:00) is now AVAILABLE again
        $checkOldSlot = $this->json('POST', '/api/check-technician-avaliablity', [
            'technician_id' => $this->technician->id,
            'scheduled_date' => '2026-10-15',
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
        ]);
        $checkOldSlot->assertStatus(200);
        $checkOldSlot->assertJson(['available' => true, 'status' => 'available']);

        // Verify: New slot (2026-10-18 14:00:00) is now ENGAGED
        $checkNewSlot = $this->json('POST', '/api/check-technician-avaliablity', [
            'technician_id' => $this->technician->id,
            'scheduled_date' => '2026-10-18',
            'start_time' => '14:30:00',
            'end_time' => '15:00:00',
        ]);
        $checkNewSlot->assertStatus(200);
        $checkNewSlot->assertJson(['available' => false, 'status' => 'engaged']);

        // 3. Second reschedule to test multiple history audit snapshots
        $secondReschedulePayload = [
            'order_id' => $this->order->id,
            'order_item_id' => $this->orderItem->id,
            'order_installation_id' => $this->orderInstallation->id,
            'technician_id' => $this->technician->id,
            'scheduled_date' => '2026-10-20',
            'start_time' => '09:00:00',
            'end_time' => '10:30:00',
            'reschedule_reason' => 'Technician illness emergency',
        ];

        $secondReschedule = $this->json('POST', '/api/technician-schedule/reschedule', $secondReschedulePayload);
        $secondReschedule->assertStatus(200);
        $secondReschedule->assertJson(['is_rescheduled' => true]);

        // Master table still 1 record with latest schedule and count = 2
        $this->assertDatabaseCount('technician_scheduled', 1);
        $latestSchedule = TechnicianScheduled::first();
        $this->assertEquals('2026-10-20', $latestSchedule->scheduled_date->toDateString());
        $this->assertEquals('09:00:00', $latestSchedule->start_time);
        $this->assertEquals(2, $latestSchedule->reschedule_count);

        // History table now has 2 historical snapshots preserved in chronological order
        $this->assertDatabaseCount('technician_scheduled_history', 2);
    }

    public function test_history_api_retrieves_records(): void
    {
        // Create initial
        $this->json('POST', '/api/technician-schedule/create', [
            'order_id' => $this->order->id,
            'order_item_id' => $this->orderItem->id,
            'order_installation_id' => $this->orderInstallation->id,
            'technician_id' => $this->technician->id,
            'scheduled_date' => '2026-10-15',
            'start_time' => '10:00:00',
        ]);

        // Reschedule
        $this->json('POST', '/api/technician-schedule/create', [
            'order_id' => $this->order->id,
            'order_item_id' => $this->orderItem->id,
            'order_installation_id' => $this->orderInstallation->id,
            'technician_id' => $this->technician->id,
            'scheduled_date' => '2026-10-16',
            'start_time' => '11:00:00',
            'reschedule_reason' => 'Client request',
        ]);

        // Retrieve history
        $response = $this->json('GET', '/api/technician-schedule/history', [
            'order_id' => $this->order->id,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'status' => 'success',
        ]);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('Client request', $response->json('data.0.reschedule_reason'));
    }
}
