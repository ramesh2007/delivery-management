<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderInstallation;
use App\Models\TechnicianScheduled;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallationOrdersPickerTest extends TestCase
{
    use RefreshDatabase;

    protected User $picker;
    protected User $technician;
    protected Order $todayOrder;
    protected Order $tomorrowOrder;
    protected OrderItem $todayItem1;
    protected OrderItem $todayItem2;
    protected OrderInstallation $todayInstallation;
    protected TechnicianScheduled $todaySchedule;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Create Picker user
        $this->picker = User::factory()->create([
            'name' => 'Test Picker',
            'email' => 'picker@example.com',
            'role' => 'picker',
        ]);

        // 2. Create Technician user
        $this->technician = User::factory()->create([
            'name' => 'Fahad Technician',
            'email' => 'fahad@example.com',
            'role' => 'technician',
        ]);

        $today = Carbon::today()->toDateString();
        $tomorrow = Carbon::tomorrow()->toDateString();

        // 3. Create Order scheduled for TODAY
        $this->todayOrder = Order::create([
            'order_number' => '#1001',
            'customer_name' => 'Ahmad Al-Thani',
            'customer_phone' => '+974 55112233',
            'delivery_address' => 'Building 12, West Bay, Doha',
            'total_amount' => 450.00,
            'status' => 'pending',
            'assigned_to' => $this->picker->id,
            'assigned_user_name' => $this->picker->name,
        ]);

        // Item 1: Installable item
        $this->todayItem1 = OrderItem::create([
            'order_id' => $this->todayOrder->id,
            'line_item_id' => 'LI-101',
            'product_name' => 'Baby Crib Luxury',
            'product_code' => 'CRIB-LUX',
            'barcode' => 'BC101',
            'quantity' => 1,
            'unit_price' => 350.00,
            'is_installable' => true,
            'status' => 'pending',
            'assigned_to' => $this->picker->id,
            'assigned_user_name' => $this->picker->name,
        ]);

        $this->todayInstallation = OrderInstallation::create([
            'order_item_id' => $this->todayItem1->id,
            'installation_type' => 'Crib Assembly',
            'installation_level' => 'Standard',
            'is_scheduled_assigned' => true,
        ]);

        $this->todaySchedule = TechnicianScheduled::create([
            'order_id' => $this->todayOrder->id,
            'order_number' => $this->todayOrder->order_number,
            'order_item_id' => $this->todayItem1->id,
            'order_installation_id' => $this->todayInstallation->id,
            'technician_id' => $this->technician->id,
            'technician_name' => $this->technician->name,
            'scheduled_date' => $today,
            'start_time' => '10:00 AM',
            'end_time' => '12:00 PM',
            'status' => 'scheduled',
            'customer_name' => $this->todayOrder->customer_name,
            'customer_phone' => $this->todayOrder->customer_phone,
            'address' => $this->todayOrder->delivery_address,
        ]);

        // Item 2: Regular item in same order
        $this->todayItem2 = OrderItem::create([
            'order_id' => $this->todayOrder->id,
            'line_item_id' => 'LI-102',
            'product_name' => 'Baby Pillow',
            'product_code' => 'PIL-01',
            'barcode' => 'BC102',
            'quantity' => 2,
            'unit_price' => 50.00,
            'is_installable' => false,
            'status' => 'pending',
            'assigned_to' => $this->picker->id,
        ]);

        // 4. Create Order scheduled for TOMORROW (should NOT show in today list)
        $this->tomorrowOrder = Order::create([
            'order_number' => '#1002',
            'customer_name' => 'Sara Al-Kuwari',
            'customer_phone' => '+974 66223344',
            'delivery_address' => 'The Pearl, Porto Arabia, Tower 5',
            'total_amount' => 600.00,
            'status' => 'pending',
        ]);

        $tomorrowItem = OrderItem::create([
            'order_id' => $this->tomorrowOrder->id,
            'line_item_id' => 'LI-201',
            'product_name' => 'Wardrobe 3-Door',
            'product_code' => 'WARD-03',
            'barcode' => 'BC201',
            'quantity' => 1,
            'unit_price' => 600.00,
            'is_installable' => true,
            'status' => 'pending',
        ]);

        $tomorrowInst = OrderInstallation::create([
            'order_item_id' => $tomorrowItem->id,
            'installation_type' => 'Wardrobe Assembly',
            'installation_level' => 'Advanced',
            'is_scheduled_assigned' => true,
        ]);

        TechnicianScheduled::create([
            'order_id' => $this->tomorrowOrder->id,
            'order_number' => $this->tomorrowOrder->order_number,
            'order_item_id' => $tomorrowItem->id,
            'order_installation_id' => $tomorrowInst->id,
            'technician_id' => $this->technician->id,
            'technician_name' => $this->technician->name,
            'scheduled_date' => $tomorrow,
            'start_time' => '02:00 PM',
            'end_time' => '04:00 PM',
            'status' => 'scheduled',
        ]);
    }

    /**
     * Test endpoint returns scheduled installation orders for today with today list key.
     */
    public function test_installation_orders_picker_returns_today_list(): void
    {
        $response = $this->getJson('/api/mobile/installation-orders/picker');

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'status' => 'success',
            'date' => Carbon::today()->toDateString(),
        ]);

        // Must have today key as requested by user
        $response->assertJsonStructure([
            'success',
            'status',
            'message',
            'date',
            'today' => [
                '*' => [
                    'id',
                    'order_id',
                    'order_number',
                    'status',
                    'customer_name',
                    'customer_phone',
                    'delivery_address',
                    'scheduled_date',
                    'scheduled_time',
                    'technician_name',
                    'is_installable',
                    'items' => [
                        '*' => [
                            'id',
                            'line_item_id',
                            'product_name',
                            'is_installable',
                            'installation_type',
                            'installation_level',
                        ]
                    ],
                    'installation_items',
                ]
            ],
            'data',
            'orders',
            'count',
        ]);

        $todayList = $response->json('today');
        $this->assertCount(1, $todayList);
        $this->assertEquals('#1001', $todayList[0]['order_number']);
        $this->assertEquals(Carbon::today()->toDateString(), $todayList[0]['scheduled_date']);
        $this->assertEquals('Fahad Technician', $todayList[0]['technician_name']);
        $this->assertEquals('10:00 AM - 12:00 PM', $todayList[0]['scheduled_time']);

        // Data and orders aliases should also contain the orders
        $this->assertCount(1, $response->json('data'));
        $this->assertCount(1, $response->json('orders'));
    }

    /**
     * Test POST method on /api/mobile/installation-orders/picker (Route::any).
     */
    public function test_installation_orders_picker_supports_post_method(): void
    {
        $response = $this->postJson('/api/mobile/installation-orders/picker', [
            'search' => 'Ahmad',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'count' => 1,
        ]);
        $this->assertEquals('#1001', $response->json('today.0.order_number'));
    }

    /**
     * Test search filter by order number and product name.
     */
    public function test_installation_orders_picker_search_filter(): void
    {
        // Search matching today order
        $response = $this->getJson('/api/mobile/installation-orders/picker?search=CRIB-LUX');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('today'));

        // Search not matching today order
        $response2 = $this->getJson('/api/mobile/installation-orders/picker?search=NONEXISTENT');
        $response2->assertStatus(200);
        $this->assertCount(0, $response2->json('today'));
    }

    /**
     * Test filter by assigned picker.
     */
    public function test_installation_orders_picker_assigned_filter(): void
    {
        $response = $this->getJson('/api/mobile/installation-orders/picker?assigned_to=' . $this->picker->id);
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('today'));

        $otherUser = User::factory()->create();
        $responseOther = $this->getJson('/api/mobile/installation-orders/picker?assigned_to=' . $otherUser->id);
        $responseOther->assertStatus(200);
        $this->assertCount(0, $responseOther->json('today'));
    }

    /**
     * Test /api/mobile/installation-orders/picker/assigned route.
     */
    public function test_installation_orders_picker_assigned_endpoint(): void
    {
        $response = $this->actingAs($this->picker)
            ->getJson('/api/mobile/installation-orders/picker/assigned');

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
        $this->assertCount(1, $response->json('today'));
    }

    /**
     * Test cancelled orders are excluded from today list.
     */
    public function test_cancelled_orders_are_excluded(): void
    {
        $this->todayOrder->update(['status' => 'cancelled']);

        $response = $this->getJson('/api/mobile/installation-orders/picker');
        $response->assertStatus(200);
        $this->assertCount(0, $response->json('today'));
    }

    /**
     * Test pagination structure when paginate=15 is used.
     */
    public function test_pagination_structure_is_returned(): void
    {
        $response = $this->getJson('/api/mobile/installation-orders/picker?per_page=10');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'pagination' => [
                'current_page',
                'per_page',
                'total',
                'last_page',
                'from',
                'to',
                'has_more_pages',
                'next_page_url',
                'previous_page_url',
            ]
        ]);
        $this->assertEquals(1, $response->json('pagination.total'));
    }

    /**
     * Test flag image is included if an item is flagged.
     */
    public function test_flag_image_is_returned_when_item_is_flagged(): void
    {
        $this->todayItem1->update([
            'is_flagged' => true,
            'flag_reason' => 'Damaged during unloading',
        ]);

        \App\Models\FlagImage::create([
            'order_id' => $this->todayOrder->id,
            'line_item_id' => $this->todayItem1->line_item_id,
            'order_item_id' => $this->todayItem1->id,
            'image_url' => 'http://localhost/storage/discrepancies/sample_flag.jpg',
            'issue_type' => 'damaged',
            'comment' => 'Broken leg',
        ]);

        $response = $this->getJson('/api/mobile/installation-orders/picker');
        $response->assertStatus(200);

        $items = $response->json('today.0.items');
        $flaggedItem = collect($items)->firstWhere('line_item_id', 'LI-101');

        $this->assertTrue($flaggedItem['is_flagged']);
        $this->assertEquals('Damaged during unloading', $flaggedItem['flag_reason']);
        $this->assertNotNull($flaggedItem['flag_image']);
        $this->assertStringContainsString('sample_flag.jpg', $flaggedItem['flag_image']);
    }
}
