<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\FlagImage;
use App\Services\ShopifyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderDetailsFlagImagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(ShopifyService::class, function ($mock) {
            $mock->shouldReceive('getOrder')->andReturn(['success' => false]);
            $mock->shouldReceive('getOrders')->andReturn(['success' => true, 'orders' => []]);
        });
    }

    public function test_when_no_line_items_are_flagged_zero_queries_to_flag_images_table()
    {
        $order = Order::create([
            'order_number' => '#1001',
            'status' => 'pending',
            'total_amount' => 100.00,
        ]);

        $item1 = OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => '111001',
            'product_code' => 'PROD-001',
            'product_name' => 'Item One',
            'is_flagged' => false,
            'quantity' => 1,
            'unit_price' => 50.00,
        ]);

        $item2 = OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => '111002',
            'product_code' => 'PROD-002',
            'product_name' => 'Item Two',
            'is_flagged' => false,
            'quantity' => 1,
            'unit_price' => 50.00,
        ]);

        // Insert a dummy flag_image for another order just to ensure table exists
        FlagImage::create([
            'order_id' => 9999,
            'line_item_id' => '99999',
            'image_url' => 'https://example.com/unrelated.jpg',
            'comment' => 'Unrelated',
        ]);

        DB::enableQueryLog();

        $response = $this->getJson("/api/admin/order-details/{$order->id}?nocache=1");

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertNotEmpty($data['line_items']);
        $this->assertArrayNotHasKey('flag_images', $data);

        foreach ($data['line_items'] as $item) {
            $this->assertNull($item['flag_image']);
            $this->assertArrayNotHasKey('flag_images', $item);
            $this->assertArrayNotHasKey('flag_photos', $item);
        }

        // Verify that NO query touched flag_images
        $queries = DB::getQueryLog();
        $flagImageQueries = array_filter($queries, function ($q) {
            return str_contains(strtolower($q['query']), 'flag_images');
        });

        $this->assertCount(0, $flagImageQueries, 'Expected zero queries to flag_images table when no items are flagged');
    }

    public function test_when_line_item_is_flagged_converts_localhost_url_to_production_domain()
    {
        $order = Order::create([
            'order_number' => '#2001',
            'status' => 'pending',
            'total_amount' => 120.00,
        ]);

        $unflaggedItem = OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => '222001',
            'product_code' => 'PROD-201',
            'product_name' => 'Good Condition Item',
            'is_flagged' => false,
            'quantity' => 1,
            'unit_price' => 60.00,
        ]);

        $flaggedItem = OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => '222002',
            'product_code' => 'PROD-202',
            'product_name' => 'Damaged Item',
            'is_flagged' => true,
            'flag_reason' => 'Damaged Box',
            'quantity' => 1,
            'unit_price' => 60.00,
        ]);

        // Create flag image with localhost URL like user reported
        FlagImage::create([
            'order_id' => $order->id,
            'order_number' => '#2001',
            'order_item_id' => $flaggedItem->id,
            'line_item_id' => '222002',
            'image_url' => 'http://localhost/storage/discrepancies/JorjjtzD8BTcln1p5XASjazNzdDywcFopGWDY1Xn.jpg',
            'issue_type' => 'damaged',
            'comment' => 'test flagging',
            'user_name' => 'Aravind',
        ]);

        DB::enableQueryLog();

        $response = $this->getJson("/api/admin/order-details/{$order->id}?nocache=1");

        $response->assertStatus(200);
        $data = $response->json('data');

        // Top level flag_images must NOT exist
        $this->assertArrayNotHasKey('flag_images', $data);

        // Check line items
        $returnedItems = collect($data['line_items'])->keyBy('line_item_id');

        $goodItem = $returnedItems->get('222001');
        $this->assertNotNull($goodItem);
        $this->assertNull($goodItem['flag_image']);
        $this->assertArrayNotHasKey('flag_images', $goodItem);

        $badItem = $returnedItems->get('222002');
        $this->assertNotNull($badItem);
        $this->assertTrue((bool)$badItem['is_flagged']);
        $this->assertEquals(
            'https://delivery-management.hmws.qatar123.com/public/storage/discrepancies/JorjjtzD8BTcln1p5XASjazNzdDywcFopGWDY1Xn.jpg',
            $badItem['flag_image']
        );
        $this->assertArrayNotHasKey('flag_images', $badItem);
        $this->assertArrayNotHasKey('flag_photos', $badItem);

        // Verify query optimization: EXACTLY 1 query was executed to flag_images
        $queries = DB::getQueryLog();
        $flagImageQueries = array_filter($queries, function ($q) {
            return str_contains(strtolower($q['query']), 'flag_images');
        });

        $this->assertCount(1, $flagImageQueries, 'Expected exactly one optimized query to flag_images table');
    }

    public function test_multiple_flagged_line_items_use_a_single_optimized_query()
    {
        $order = Order::create([
            'order_number' => '#3001',
            'status' => 'pending',
            'total_amount' => 200.00,
        ]);

        $flaggedItem1 = OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => '333001',
            'product_code' => 'PROD-301',
            'product_name' => 'Damaged Item 1',
            'is_flagged' => true,
            'quantity' => 1,
            'unit_price' => 100.00,
        ]);

        $flaggedItem2 = OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => '333002',
            'product_code' => 'PROD-302',
            'product_name' => 'Damaged Item 2',
            'is_flagged' => true,
            'quantity' => 1,
            'unit_price' => 100.00,
        ]);

        FlagImage::create([
            'order_id' => $order->id,
            'order_item_id' => $flaggedItem1->id,
            'line_item_id' => '333001',
            'image_url' => 'http://localhost/storage/discrepancies/item1_photo.jpg',
        ]);

        FlagImage::create([
            'order_id' => $order->id,
            'order_item_id' => $flaggedItem2->id,
            'line_item_id' => '333002',
            'image_url' => 'http://127.0.0.1/storage/discrepancies/item2_photo.jpg',
        ]);

        DB::enableQueryLog();

        $response = $this->postJson("/api/admin/order-details/{$order->id}?nocache=1");

        $response->assertStatus(200);
        $data = $response->json('data');

        $returnedItems = collect($data['line_items'])->keyBy('line_item_id');
        $this->assertEquals(
            'https://delivery-management.hmws.qatar123.com/public/storage/discrepancies/item1_photo.jpg',
            $returnedItems->get('333001')['flag_image']
        );
        $this->assertEquals(
            'https://delivery-management.hmws.qatar123.com/public/storage/discrepancies/item2_photo.jpg',
            $returnedItems->get('333002')['flag_image']
        );

        // Exactly ONE query for all flagged items
        $queries = DB::getQueryLog();
        $flagImageQueries = array_filter($queries, function ($q) {
            return str_contains(strtolower($q['query']), 'flag_images');
        });

        $this->assertCount(1, $flagImageQueries, 'Expected exactly one query for multiple flagged line items (no N+1)');
    }
}
