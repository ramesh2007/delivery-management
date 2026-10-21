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

class TechnicianScheduledListTest extends TestCase
{
    use RefreshDatabase;

    protected User $technician1;
    protected User $technician2;
    protected Order $order;
    protected OrderItem $item1;
    protected OrderItem $item2;
    protected OrderItem $item3;
    protected OrderInstallation $inst1;
    protected OrderInstallation $inst2;
    protected OrderInstallation $inst3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->technician1 = User::factory()->create([
            'name' => 'Carlos Technician',
            'email' => 'carlos@example.com',
            'role' => 'technician',
            'status' => 'active',
        ]);

        $this->technician2 = User::factory()->create([
            'name' => 'Ahmed Technician',
            'email' => 'ahmed@example.com',
            'role' => 'technician',
            'status' => 'active',
        ]);

        $this->order = Order::create([
            'order_number' => '#2001',
            'customer_name' => 'Khalid Al-Kuwari',
            'customer_phone' => '+974 55443322',
            'delivery_address' => 'Villa 55, Street 10, West Bay, Doha',
            'total_amount' => 500.00,
            'status' => 'pending',
        ]);

        // Item 1: Scheduled
        $this->item1 = OrderItem::create([
            'order_id' => $this->order->id,
            'line_item_id' => '1001',
            'product_name' => 'Convertible Baby Crib',
            'product_code' => 'CRIB-01',
            'quantity' => 1,
            'unit_price' => 300.00,
            'is_installable' => true,
            'status' => 'pending',
            'gift_wrap' => 'GW-G',
            'gift_message' => 'Happy Birthday!',
        ]);

        $this->inst1 = OrderInstallation::create([
            'order_item_id' => $this->item1->id,
            'installation_type' => 'Crib Assembly',
            'installation_level' => 'Standard',
            'is_scheduled_assigned' => true,
        ]);

        // Item 2: Rescheduled
        $this->item2 = OrderItem::create([
            'order_id' => $this->order->id,
            'line_item_id' => '1002',
            'product_name' => 'Wardrobe Installation',
            'product_code' => 'WARD-02',
            'quantity' => 1,
            'unit_price' => 200.00,
            'is_installable' => true,
            'status' => 'pending',
        ]);

        $this->inst2 = OrderInstallation::create([
            'order_item_id' => $this->item2->id,
            'installation_type' => 'Wardrobe Fitting',
            'installation_level' => 'Advanced',
            'is_scheduled_assigned' => true,
        ]);

        // Item 3: Completed (Should not show by default)
        $this->item3 = OrderItem::create([
            'order_id' => $this->order->id,
            'line_item_id' => '1003',
            'product_name' => 'Wall Shelf Unit',
            'product_code' => 'SHELF-03',
            'quantity' => 1,
            'unit_price' => 100.00,
            'is_installable' => true,
            'status' => 'delivered',
        ]);

        $this->inst3 = OrderInstallation::create([
            'order_item_id' => $this->item3->id,
            'installation_type' => 'Wall Mount',
            'installation_level' => 'Basic',
            'is_scheduled_assigned' => true,
        ]);

        // Schedule 1: Scheduled
        TechnicianScheduled::create([
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'order_item_id' => $this->item1->id,
            'order_installation_id' => $this->inst1->id,
            'technician_id' => $this->technician1->id,
            'technician_name' => $this->technician1->name,
            'scheduled_date' => '2026-10-16',
            'start_time' => '10:00:00',
            'end_time' => '11:30:00',
            'taken_time_in_mins' => 90,
            'zone' => 'Zone 66',
            'address' => 'Villa 55, Street 10, West Bay, Doha',
            'customer_name' => 'Khalid Al-Kuwari',
            'customer_phone' => '+974 55443322',
            'status' => 'scheduled',
            'notes' => 'Ring bell twice',
        ]);

        // Schedule 2: Rescheduled with history
        $sch2 = TechnicianScheduled::create([
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'order_item_id' => $this->item2->id,
            'order_installation_id' => $this->inst2->id,
            'technician_id' => $this->technician2->id,
            'technician_name' => $this->technician2->name,
            'scheduled_date' => '2026-10-18',
            'start_time' => '14:00:00',
            'end_time' => '15:30:00',
            'taken_time_in_mins' => 90,
            'zone' => 'Zone 66',
            'address' => 'Villa 55, Street 10, West Bay, Doha',
            'customer_name' => 'Khalid Al-Kuwari',
            'customer_phone' => '+974 55443322',
            'status' => 'rescheduled',
            'notes' => 'Rescheduled upon client request',
        ]);

        TechnicianScheduleHistory::create([
            'technician_scheduled_id' => $sch2->id,
            'technician_id' => $this->technician1->id,
            'technician_name' => $this->technician1->name,
            'scheduled_date' => '2026-10-15',
            'start_time' => '09:00:00',
            'end_time' => '10:30:00',
            'taken_time_in_mins' => 90,
            'status' => 'rescheduled',
            'reschedule_reason' => 'Customer was unavailable',
        ]);

        // Schedule 3: Completed
        TechnicianScheduled::create([
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'order_item_id' => $this->item3->id,
            'order_installation_id' => $this->inst3->id,
            'technician_id' => $this->technician1->id,
            'technician_name' => $this->technician1->name,
            'scheduled_date' => '2026-10-14',
            'start_time' => '08:00:00',
            'end_time' => '09:00:00',
            'taken_time_in_mins' => 60,
            'zone' => 'Zone 66',
            'address' => 'Villa 55, Street 10, West Bay, Doha',
            'customer_name' => 'Khalid Al-Kuwari',
            'customer_phone' => '+974 55443322',
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    public function test_lists_scheduled_and_rescheduled_items_by_default()
    {
        $response = $this->getJson('/api/admin/technician-schedule/list');

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'status' => 'success',
        ]);

        $data = $response->json('data');
        $this->assertCount(2, $data);

        $statuses = collect($data)->pluck('status')->all();
        $this->assertContains('scheduled', $statuses);
        $this->assertContains('rescheduled', $statuses);
        $this->assertNotContains('completed', $statuses);

        // Verify detailed fields for scheduled item 1
        $item1Data = collect($data)->firstWhere('product_name', 'Convertible Baby Crib');
        $this->assertNotNull($item1Data);
        $this->assertEquals('#2001', $item1Data['order_number']);
        $this->assertEquals('Carlos Technician', $item1Data['technician_name']);
        $this->assertEquals('Crib Assembly', $item1Data['installation_type']);
        $this->assertEquals('GW-G', $item1Data['gift_wrap']);
        $this->assertEquals('Happy Birthday!', $item1Data['gift_message']);
        $this->assertFalse($item1Data['is_rescheduled']);

        // Verify detailed fields for rescheduled item 2
        $item2Data = collect($data)->firstWhere('product_name', 'Wardrobe Installation');
        $this->assertNotNull($item2Data);
        $this->assertEquals('#2001', $item2Data['order_number']);
        $this->assertEquals('Ahmed Technician', $item2Data['technician_name']);
        $this->assertEquals('Wardrobe Fitting', $item2Data['installation_type']);
        $this->assertTrue($item2Data['is_rescheduled']);
        $this->assertEquals(1, $item2Data['reschedule_count']);
        $this->assertEquals('Customer was unavailable', $item2Data['latest_reschedule_reason']);
        $this->assertCount(1, $item2Data['reschedule_history']);
    }

    public function test_filter_by_specific_status()
    {
        $response = $this->getJson('/api/admin/technician-schedule/list?status=scheduled');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('scheduled', $data[0]['status']);
        $this->assertEquals('Convertible Baby Crib', $data[0]['product_name']);
    }

    public function test_filter_by_technician_id()
    {
        $response = $this->getJson('/api/admin/technician-schedule/list?technician_id=' . $this->technician2->id);

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('Ahmed Technician', $data[0]['technician_name']);
    }

    public function test_search_by_product_name_and_order_number()
    {
        // Search by product name
        $response1 = $this->getJson('/api/admin/technician-schedule/list?search=Wardrobe');
        $response1->assertStatus(200);
        $this->assertCount(1, $response1->json('data'));
        $this->assertEquals('Wardrobe Installation', $response1->json('data.0.product_name'));

        // Search by order number
        $response2 = $this->getJson('/api/admin/technician-schedule/list?search=2001');
        $response2->assertStatus(200);
        $this->assertCount(2, $response2->json('data'));
    }

    public function test_filter_by_scheduled_date()
    {
        $response = $this->getJson('/api/admin/technician-schedule/list?scheduled_date=2026-10-16');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('2026-10-16', $data[0]['scheduled_date']);
    }
}
