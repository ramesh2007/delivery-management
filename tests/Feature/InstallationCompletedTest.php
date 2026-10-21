<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderInstallation;
use App\Models\InstallationImage;
use App\Models\TechnicianScheduled;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallationCompletedTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $technician;
    protected Order $order;
    protected OrderItem $itemCompleted;
    protected OrderItem $itemInstalled;
    protected OrderItem $itemPending;
    protected OrderInstallation $instCompleted;
    protected OrderInstallation $instInstalled;
    protected OrderInstallation $instPending;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'role' => 'admin',
        ]);

        $this->technician = User::factory()->create([
            'name' => 'Carlos Specialist',
            'email' => 'carlos@technician.com',
            'role' => 'technician',
            'phone' => '+974 55112233',
        ]);

        $this->order = Order::create([
            'order_number' => '#3001',
            'customer_name' => 'Fatima Al-Thani',
            'customer_phone' => '+974 66778899',
            'delivery_address' => 'Pearl Qatar, Tower 12, Apt 405',
            'total_amount' => 750.00,
            'status' => 'completed',
            'notes' => 'Customer requested morning delivery',
        ]);

        // 1. Item 1: Installation Completed
        $this->itemCompleted = OrderItem::create([
            'order_id' => $this->order->id,
            'line_item_id' => '3001-1',
            'product_name' => 'Montessori Toddler Bed',
            'product_code' => 'BED-MON-01',
            'barcode' => '8899001122',
            'image' => 'https://example.com/bed.jpg',
            'quantity' => 1,
            'unit_price' => 450.00,
            'is_installable' => true,
            'status' => 'packed',
            'gift_wrap' => 'GW-G',
            'gift_message' => 'Happy Birthday Little One!',
            'assigned_to' => $this->technician->id,
            'assigned_user_name' => $this->technician->name,
        ]);

        $this->instCompleted = OrderInstallation::create([
            'order_item_id' => $this->itemCompleted->id,
            'installation_type' => 'White Glove',
            'installation_level' => 'level-2',
            'status' => 'completed',
            'is_scheduled_assigned' => true,
            'notes' => 'Bed installed successfully in the nursery room.',
            'completed_at' => now()->subHours(2),
            'images' => [
                'https://example.com/proof1.jpg',
            ],
        ]);

        // Proof image in installation_images table
        InstallationImage::create([
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'order_item_id' => $this->itemCompleted->id,
            'order_installation_id' => $this->instCompleted->id,
            'before_install_image' => 'https://example.com/before.jpg',
            'after_install_image' => 'https://example.com/after.jpg',
            'notes' => 'Completed proof photo',
        ]);

        // Associated technician schedule
        TechnicianScheduled::create([
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'order_item_id' => $this->itemCompleted->id,
            'order_installation_id' => $this->instCompleted->id,
            'technician_id' => $this->technician->id,
            'technician_name' => $this->technician->name,
            'scheduled_date' => now()->toDateString(),
            'start_time' => '09:00:00',
            'end_time' => '10:30:00',
            'taken_time_in_mins' => 90,
            'status' => 'completed',
            'completed_at' => now()->subHours(2),
        ]);

        // 2. Item 2: Installation Installed (alternative completed status)
        $this->itemInstalled = OrderItem::create([
            'order_id' => $this->order->id,
            'line_item_id' => '3001-2',
            'product_name' => 'Wall Safety Gate',
            'product_code' => 'GATE-01',
            'quantity' => 1,
            'unit_price' => 150.00,
            'is_installable' => true,
            'status' => 'packed',
        ]);

        $this->instInstalled = OrderInstallation::create([
            'order_item_id' => $this->itemInstalled->id,
            'installation_type' => 'Standard',
            'installation_level' => 'level-1',
            'status' => 'installed',
            'is_scheduled_assigned' => true,
            'notes' => 'Safety gate mounted at stairway.',
            'completed_at' => now()->subHour(),
        ]);

        // 3. Item 3: Pending Installation (should NOT appear in completed list)
        $this->itemPending = OrderItem::create([
            'order_id' => $this->order->id,
            'line_item_id' => '3001-3',
            'product_name' => 'Changing Table',
            'product_code' => 'TABLE-01',
            'quantity' => 1,
            'unit_price' => 150.00,
            'is_installable' => true,
            'status' => 'pending',
        ]);

        $this->instPending = OrderInstallation::create([
            'order_item_id' => $this->itemPending->id,
            'installation_type' => 'Standard',
            'installation_level' => 'level-1',
            'status' => 'pending',
            'is_scheduled_assigned' => false,
        ]);
    }

    public function test_installation_completed_endpoint_returns_only_completed_installations(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/admin/installation-completed');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'status' => 'success',
            ]);

        $data = $response->json('data');
        $this->assertCount(2, $data); // instCompleted and instInstalled

        $returnedIds = collect($data)->pluck('id')->all();
        $this->assertContains($this->instCompleted->id, $returnedIds);
        $this->assertContains($this->instInstalled->id, $returnedIds);
        $this->assertNotContains($this->instPending->id, $returnedIds);
    }

    public function test_installation_completed_strict_status_filter(): void
    {
        // When status=completed is explicitly passed, only status=completed items return
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/admin/installation-completed?status=completed');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($this->instCompleted->id, $data[0]['id']);
        $this->assertEquals('completed', $data[0]['status']);
    }

    public function test_installation_completed_returns_rich_payload_fields(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/admin/installation-completed?order_item_id=' . $this->itemCompleted->id);

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);

        $item = $data[0];
        $this->assertEquals($this->instCompleted->id, $item['id']);
        $this->assertEquals('#3001', $item['order_number']);
        $this->assertEquals('Fatima Al-Thani', $item['customer_name']);
        $this->assertEquals('Montessori Toddler Bed', $item['product_name']);
        $this->assertEquals('BED-MON-01', $item['product_code']);
        $this->assertEquals('GW-G', $item['gift_wrap']);
        $this->assertEquals('Happy Birthday Little One!', $item['gift_message']);
        $this->assertEquals('Carlos Specialist', $item['technician_name']);
        $this->assertEquals('White Glove', $item['installation_type']);
        $this->assertEquals('level-2', $item['installation_level']);
        $this->assertEquals('completed', $item['status']);

        // Check customer structure
        $this->assertIsArray($item['customer']);
        $this->assertEquals('Fatima Al-Thani', $item['customer']['name']);

        // Check order_item structure
        $this->assertIsArray($item['order_item']);
        $this->assertEquals($this->itemCompleted->id, $item['order_item']['id']);

        // Check order structure
        $this->assertIsArray($item['order']);
        $this->assertEquals('#3001', $item['order']['order_number']);

        // Check technician structure
        $this->assertIsArray($item['technician']);
        $this->assertEquals('Carlos Specialist', $item['technician']['name']);

        // Check proof images collected from both images json and installation_images table
        $this->assertIsArray($item['images']);
        $this->assertContains('https://example.com/proof1.jpg', $item['images']);
        $this->assertContains('https://example.com/before.jpg', $item['images']);
        $this->assertContains('https://example.com/after.jpg', $item['images']);

        // Check proof_images records
        $this->assertNotEmpty($item['proof_images']);
    }

    public function test_installation_completed_search_filter(): void
    {
        // Search by product name
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/admin/installation-completed?search=Toddler');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($this->instCompleted->id, $data[0]['id']);

        // Search by order number without hash
        $responseOrder = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/admin/installation-completed?search=3001');

        $responseOrder->assertStatus(200);
        $this->assertCount(2, $responseOrder->json('data'));
    }

    public function test_installation_completed_via_post_and_aliases(): void
    {
        // POST to /api/admin/installation-completed
        $postRes = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/admin/installation-completed', ['status' => 'completed']);
        $postRes->assertStatus(200);
        $this->assertCount(1, $postRes->json('data'));

        // Alias: /api/admin/orders/status/installation-completed
        $aliasRes = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/admin/orders/status/installation-completed');
        $aliasRes->assertStatus(200);
        $this->assertCount(2, $aliasRes->json('data'));

        // Top-level alias: /api/installation-completed
        $topRes = $this->getJson('/api/installation-completed');
        $topRes->assertStatus(200);
        $this->assertCount(2, $topRes->json('data'));
    }
}
