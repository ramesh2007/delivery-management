<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\FlagImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class FlagDiscrepancyImagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_flag_item_discrepancy_with_uploaded_photo_saves_to_flag_images_table()
    {
        $order = Order::create([
            'order_number' => '#1001',
            'customer_name' => 'Alice Doe',
            'status' => 'pending',
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => 'LI-998877',
            'product_code' => 'SKU-TEST-01',
            'product_name' => 'Ceramic Mug',
            'quantity' => 2,
            'unit_price' => 15.00,
            'status' => 'pending',
        ]);

        $user = User::factory()->create([
            'name' => 'Warehouse Jane',
        ]);

        $photo = UploadedFile::fake()->image('damaged_item.jpg', 600, 600);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/mobile/orders/items/flag-discrepancy', [
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'issue_type' => 'damaged',
            'comment' => 'Broken handle on delivery',
            'photo' => $photo,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'order_id' => $order->id,
                    'order_item_id' => $item->id,
                    'line_item_id' => 'LI-998877',
                    'issue_type' => 'damaged',
                    'comment' => 'Broken handle on delivery',
                ],
            ]);

        // Assert record exists in flag_images against order_id and line_item_id
        $this->assertDatabaseHas('flag_images', [
            'order_id' => $order->id,
            'line_item_id' => 'LI-998877',
            'order_item_id' => $item->id,
            'issue_type' => 'damaged',
            'comment' => 'Broken handle on delivery',
        ]);

        $flagImage = FlagImage::where('order_id', $order->id)
            ->where('line_item_id', 'LI-998877')
            ->first();

        $this->assertNotNull($flagImage);
        $this->assertNotEmpty($flagImage->image_url);
    }

    public function test_flag_item_discrepancy_with_photo_url_saves_to_flag_images_table()
    {
        $order = Order::create([
            'order_number' => '#1002',
            'customer_name' => 'Bob Smith',
            'status' => 'pending',
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => 'LI-112233',
            'product_code' => 'SKU-TEST-02',
            'product_name' => 'Floor Lamp',
            'quantity' => 1,
            'unit_price' => 89.99,
            'status' => 'pending',
        ]);

        $photoUrl = 'https://example.com/uploads/lamp_scratch.jpg';

        $response = $this->postJson('/api/mobile/orders/items/flag-discrepancy', [
            'line_item_id' => 'LI-112233',
            'issue_type' => 'defective',
            'comment' => 'Deep scratch on the lamp base',
            'photo_url' => $photoUrl,
            'user_name' => 'Inspector John',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'order_id' => $order->id,
                    'line_item_id' => 'LI-112233',
                    'photo_url' => $photoUrl,
                ],
            ]);

        $this->assertDatabaseHas('flag_images', [
            'order_id' => $order->id,
            'line_item_id' => 'LI-112233',
            'order_item_id' => $item->id,
            'image_url' => $photoUrl,
            'issue_type' => 'defective',
            'user_name' => 'Inspector John',
        ]);
    }

    public function test_flag_item_discrepancy_without_photo_does_not_add_to_flag_images()
    {
        $order = Order::create([
            'order_number' => '#1003',
            'customer_name' => 'Carol Danvers',
            'status' => 'pending',
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => 'LI-556677',
            'product_code' => 'SKU-TEST-03',
            'product_name' => 'Dining Chair',
            'quantity' => 1,
            'unit_price' => 120.00,
            'status' => 'pending',
        ]);

        $response = $this->postJson('/api/mobile/orders/items/flag-discrepancy', [
            'order_item_id' => $item->id,
            'issue_type' => 'missing',
            'comment' => 'Missing item from box',
        ]);

        $response->assertStatus(200);

        // No image in flag_images
        $this->assertEquals(0, FlagImage::where('order_id', $order->id)->count());
    }
}
