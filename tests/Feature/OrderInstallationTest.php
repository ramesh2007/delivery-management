<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderInstallation;
use App\Models\User;
use App\Services\ShopifyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrderInstallationTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_items_table_has_is_installable_column(): void
    {
        $this->assertTrue(Schema::hasColumn('order_items', 'is_installable'));
    }

    public function test_order_installations_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('order_installations'));
        $this->assertTrue(Schema::hasColumn('order_installations', 'id'));
        $this->assertTrue(Schema::hasColumn('order_installations', 'order_item_id'));
        $this->assertTrue(Schema::hasColumn('order_installations', 'installation_type'));
        $this->assertTrue(Schema::hasColumn('order_installations', 'installation_level'));
        $this->assertTrue(Schema::hasColumn('order_installations', 'created_at'));
        $this->assertTrue(Schema::hasColumn('order_installations', 'updated_at'));
    }

    public function test_order_item_is_installable_defaults_to_false(): void
    {
        $order = Order::create([
            'order_number' => '#TEST-100',
            'customer_name' => 'Alice Test',
            'total_amount' => 50.00,
            'status' => 'pending',
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_code' => 'PROD-001',
            'product_name' => 'Basic Item',
            'quantity' => 1,
            'unit_price' => 50.00,
            'status' => 'pending',
        ]);

        $this->assertFalse($item->is_installable);
        $this->assertDatabaseHas('order_items', [
            'id' => $item->id,
            'is_installable' => false,
        ]);
    }

    public function test_order_item_can_be_marked_as_installable(): void
    {
        $order = Order::create([
            'order_number' => '#TEST-101',
            'customer_name' => 'Bob Test',
            'total_amount' => 150.00,
            'status' => 'pending',
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_code' => 'APPLIANCE-001',
            'product_name' => 'Washing Machine',
            'quantity' => 1,
            'unit_price' => 150.00,
            'status' => 'pending',
            'is_installable' => true,
        ]);

        $this->assertTrue($item->is_installable);
        $this->assertDatabaseHas('order_items', [
            'id' => $item->id,
            'is_installable' => true,
        ]);
    }

    public function test_order_installation_can_be_created_and_associated_with_order_item(): void
    {
        $order = Order::create([
            'order_number' => '#TEST-102',
            'customer_name' => 'Charlie Test',
            'total_amount' => 200.00,
            'status' => 'pending',
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_code' => 'AC-001',
            'product_name' => 'Air Conditioner',
            'quantity' => 1,
            'unit_price' => 200.00,
            'status' => 'pending',
            'is_installable' => true,
        ]);

        $installation = OrderInstallation::create([
            'order_item_id' => $item->id,
            'installation_type' => 'wall_mount',
            'installation_level' => 'level-2',
        ]);

        $this->assertDatabaseHas('order_installations', [
            'id' => $installation->id,
            'order_item_id' => $item->id,
            'installation_type' => 'wall_mount',
            'installation_level' => 'level-2',
        ]);

        // Test relations
        $this->assertEquals($item->id, $installation->orderItem->id);
        $this->assertEquals($installation->id, $item->installation->id);
        $this->assertCount(1, $item->installations);
    }

    public function test_deleting_order_item_cascades_and_deletes_installation(): void
    {
        $order = Order::create([
            'order_number' => '#TEST-103',
            'customer_name' => 'Diana Test',
            'total_amount' => 90.00,
            'status' => 'pending',
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_code' => 'FAN-001',
            'product_name' => 'Ceiling Fan',
            'quantity' => 1,
            'unit_price' => 90.00,
            'status' => 'pending',
            'is_installable' => true,
        ]);

        $installation = OrderInstallation::create([
            'order_item_id' => $item->id,
            'installation_type' => 'ceiling_mount',
            'installation_level' => 'level-1',
        ]);

        $this->assertDatabaseHas('order_installations', [
            'id' => $installation->id,
        ]);

        // Delete order item
        $item->delete();

        $this->assertDatabaseMissing('order_installations', [
            'id' => $installation->id,
        ]);
    }

    public function test_order_sync_checks_metafields_and_stores_installation(): void
    {
        $shopifyService = app(ShopifyService::class);

        // Payload containing exact metafields structure provided by user
        $orderPayload = [
            'id' => 987654321,
            'order_number' => '1004',
            'total_price' => '169.00',
            'customer' => [
                'first_name' => 'Sarah',
                'last_name' => 'Smith',
            ],
            'line_items' => [
                [
                    'id' => 48933440454900,
                    'product_id' => 9566948786420,
                    'title' => '2 Pack Crib Fitted Sheets (White)',
                    'sku' => 'BD028BL',
                    'barcode' => '5060730240478',
                    'price' => '169.00',
                    'quantity' => 1,
                    'metafields' => [
                        [
                            'id' => 61744954835188,
                            'namespace' => 'custom',
                            'key' => 'installation_level',
                            'value' => 'level-1',
                            'type' => 'single_line_text_field',
                        ],
                        [
                            'id' => 61745161175284,
                            'namespace' => 'custom',
                            'key' => 'installation_type',
                            'value' => true,
                            'type' => 'boolean',
                        ],
                    ],
                ],
                [
                    'id' => 48933440454901,
                    'product_id' => 9566948786421,
                    'title' => 'Standard Pillow (No Installation)',
                    'sku' => 'PIL001',
                    'barcode' => '5060730240999',
                    'price' => '49.00',
                    'quantity' => 1,
                    'metafields' => [],
                ],
            ],
        ];

        $order = $shopifyService->processSingleOrderPayload($orderPayload);

        $this->assertNotNull($order);
        $this->assertEquals(2, $order->items->count());

        $installableItem = $order->items->where('sku', 'BD028BL')->first()
            ?? $order->items->where('product_code', 'BD028BL')->first();
        $regularItem = $order->items->where('sku', 'PIL001')->first()
            ?? $order->items->where('product_code', 'PIL001')->first();

        // Check installable item
        $this->assertNotNull($installableItem);
        $this->assertTrue($installableItem->is_installable);
        $this->assertNotNull($installableItem->installation);
        $this->assertEquals('level-1', $installableItem->installation->installation_type);
        $this->assertEquals('level-1', $installableItem->installation->installation_level);

        $this->assertDatabaseHas('order_installations', [
            'order_item_id' => $installableItem->id,
            'installation_type' => 'level-1',
            'installation_level' => 'level-1',
        ]);

        // Check non-installable item
        $this->assertNotNull($regularItem);
        $this->assertFalse($regularItem->is_installable);
        $this->assertNull($regularItem->installation);
        $this->assertDatabaseMissing('order_installations', [
            'order_item_id' => $regularItem->id,
        ]);
    }

    public function test_order_sync_fetches_metafields_via_api_when_not_in_payload(): void
    {
        Http::fake([
            '*/admin/api/*/products/9566948786420/metafields.json' => Http::response([
                'metafields' => [
                    [
                        'id' => 61744954835188,
                        'namespace' => 'custom',
                        'key' => 'installation_level',
                        'value' => 'level-1',
                        'type' => 'single_line_text_field',
                    ],
                    [
                        'id' => 61745161175284,
                        'namespace' => 'custom',
                        'key' => 'installation_type',
                        'value' => true,
                        'type' => 'boolean',
                    ],
                ],
            ], 200),
        ]);

        $shopifyService = app(ShopifyService::class);

        $orderPayload = [
            'id' => 987654322,
            'order_number' => '1005',
            'total_price' => '169.00',
            'line_items' => [
                [
                    'id' => 55555555,
                    'product_id' => 9566948786420,
                    'title' => '2 Pack Crib Fitted Sheets (White)',
                    'sku' => 'BD028BL-API',
                    'price' => '169.00',
                    'quantity' => 1,
                ],
            ],
        ];

        $order = $shopifyService->processSingleOrderPayload($orderPayload, 'test-shop.myshopify.com', 'shpat_test123');

        $this->assertNotNull($order);
        $item = $order->items->first();
        $this->assertTrue($item->is_installable);
        $this->assertNotNull($item->installation);
        $this->assertEquals('level-1', $item->installation->installation_type);
        $this->assertEquals('level-1', $item->installation->installation_level);
    }

    public function test_api_orders_includes_is_installable_and_installation_details(): void
    {
        $user = User::factory()->create();

        $order = Order::create([
            'order_number' => '#TEST-INSTALL-200',
            'customer_name' => 'Emma Customer',
            'total_amount' => 199.00,
            'status' => 'pending',
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_code' => 'CRIB-SHEET-01',
            'product_name' => 'Fitted Sheet Bedding',
            'quantity' => 1,
            'unit_price' => 199.00,
            'status' => 'pending',
            'is_installable' => true,
        ]);

        OrderInstallation::create([
            'order_item_id' => $item->id,
            'installation_type' => 'level-1',
            'installation_level' => 'level-1',
        ]);

        $response = $this->actingAs($user)->getJson('/api/orders');

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'is_installable' => true,
            'installation' => [
                'id' => $item->installation->id,
                'installation_type' => 'level-1',
                'installation_level' => 'level-1',
            ],
        ]);
    }

    public function test_sync_orders_to_database_handles_order_exceptions_gracefully(): void
    {
        Http::fake([
            'https://test-shop.myshopify.com/admin/api/*/orders.json*' => Http::response([
                'orders' => [
                    [
                        'id' => 888801,
                        'order_number' => 8801,
                        'name' => '#8801',
                        'total_price' => '100.00',
                        'line_items' => [
                            [
                                'id' => 999901,
                                'product_id' => 9566948786420,
                                'title' => 'Test Item 1',
                                'price' => '100.00',
                                'quantity' => 1,
                            ]
                        ]
                    ],
                ]
            ], 200),
            'https://test-shop.myshopify.com/admin/api/*/products/9566948786420/metafields.json*' => Http::response([
                'metafields' => [
                    ['key' => 'installation_type', 'value' => true],
                    ['key' => 'installation_level', 'value' => 'level-1'],
                ]
            ], 200),
        ]);

        $shopifyService = app(ShopifyService::class);
        $result = $shopifyService->syncOrdersToDatabase('test-shop.myshopify.com', 'shpat_test123');

        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['orders_count']);
        $this->assertDatabaseHas('orders', ['order_number' => '#8801']);
        $this->assertDatabaseHas('order_items', ['line_item_id' => '999901', 'is_installable' => true]);
    }

    public function test_get_orders_enriches_line_items_with_installation_metafields(): void
    {
        Http::fake([
            'https://test-shop.myshopify.com/admin/api/*/orders.json*' => Http::response([
                'orders' => [
                    [
                        'id' => 1234567,
                        'order_number' => '1006',
                        'name' => '#1006',
                        'line_items' => [
                            [
                                'id' => 16662970138868,
                                'admin_graphql_api_id' => 'gid://shopify/LineItem/16662970138868',
                                'product_id' => 9566948786420,
                                'variant_id' => 48933440454900,
                                'sku' => 'BD028BL',
                                'name' => '2 Pack Crib Fitted Sheets (White)',
                                'title' => '2 Pack Crib Fitted Sheets (White)',
                                'price' => '169.00',
                                'quantity' => 1,
                                'properties' => [],
                            ],
                            [
                                'id' => 16662970138869,
                                'product_id' => 9566948786999,
                                'variant_id' => 48933440459999,
                                'sku' => 'NOT-INSTALLABLE',
                                'name' => 'Simple Blanket',
                                'title' => 'Simple Blanket',
                                'price' => '50.00',
                                'quantity' => 1,
                                'properties' => [],
                            ],
                        ],
                    ],
                ],
            ], 200),
            'https://test-shop.myshopify.com/admin/api/*/products/9566948786420/metafields.json*' => Http::response([
                'metafields' => [
                    [
                        'id' => 61744954835188,
                        'namespace' => 'custom',
                        'key' => 'installation_level',
                        'value' => 'level-1',
                        'type' => 'single_line_text_field',
                    ],
                    [
                        'id' => 61745161175284,
                        'namespace' => 'custom',
                        'key' => 'installation_type',
                        'value' => true,
                        'type' => 'boolean',
                    ],
                ],
            ], 200),
            'https://test-shop.myshopify.com/admin/api/*/products/9566948786999/metafields.json*' => Http::response([
                'metafields' => [],
            ], 200),
            'https://test-shop.myshopify.com/admin/api/*/variants/48933440459999/metafields.json*' => Http::response([
                'metafields' => [],
            ], 200),
        ]);

        $shopifyService = app(ShopifyService::class);
        $result = $shopifyService->getOrders('test-shop.myshopify.com', 'shpat_test123');

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['orders']);

        $pulledOrder = $result['orders'][0];
        $this->assertCount(2, $pulledOrder['line_items']);

        // Check installable item (BD028BL)
        $installableItem = $pulledOrder['line_items'][0];
        $this->assertEquals(16662970138868, $installableItem['id']);
        $this->assertTrue($installableItem['is_installable']);
        $this->assertEquals('level-1', $installableItem['installation_type']);
        $this->assertEquals('level-1', $installableItem['installation_level']);
        $this->assertNotNull($installableItem['installation']);
        $this->assertEquals('level-1', $installableItem['installation']['installation_type']);
        $this->assertEquals('level-1', $installableItem['installation']['installation_level']);
        $this->assertNotEmpty($installableItem['metafields']);

        // Check non-installable item
        $nonInstallableItem = $pulledOrder['line_items'][1];
        $this->assertFalse($nonInstallableItem['is_installable']);
        $this->assertNull($nonInstallableItem['installation_type']);
        $this->assertNull($nonInstallableItem['installation_level']);
        $this->assertNull($nonInstallableItem['installation']);
    }

    public function test_get_single_order_enriches_line_items_with_metafields(): void
    {
        Http::fake([
            'https://test-shop.myshopify.com/admin/api/*/orders/1234567.json*' => Http::response([
                'order' => [
                    'id' => 1234567,
                    'order_number' => '1006',
                    'line_items' => [
                        [
                            'id' => 16662970138868,
                            'product_id' => 9566948786420,
                            'sku' => 'BD028BL',
                            'title' => '2 Pack Crib Fitted Sheets (White)',
                            'price' => '169.00',
                            'quantity' => 1,
                        ],
                    ],
                ],
            ], 200),
            'https://test-shop.myshopify.com/admin/api/*/products/9566948786420/metafields.json*' => Http::response([
                'metafields' => [
                    ['key' => 'installation_level', 'value' => 'level-2'],
                    ['key' => 'installation_type', 'value' => 'wall_mount'],
                ],
            ], 200),
        ]);

        $shopifyService = app(ShopifyService::class);
        $result = $shopifyService->getOrder('1234567', 'test-shop.myshopify.com', 'shpat_test123');

        $this->assertTrue($result['success']);
        $order = $result['order'];
        $item = $order['line_items'][0];

        $this->assertTrue($item['is_installable']);
        $this->assertEquals('wall_mount', $item['installation_type']);
        $this->assertEquals('level-2', $item['installation_level']);
    }

    public function test_api_shopify_orders_returns_enriched_metafields(): void
    {
        Http::fake([
            'https://test-shop.myshopify.com/admin/api/*/orders.json*' => Http::response([
                'orders' => [
                    [
                        'id' => 999123,
                        'order_number' => '2001',
                        'line_items' => [
                            [
                                'id' => 112233,
                                'product_id' => 9566948786420,
                                'title' => 'Fitted Crib Sheet',
                                'price' => '169.00',
                                'quantity' => 1,
                            ],
                        ],
                    ],
                ],
            ], 200),
            'https://test-shop.myshopify.com/admin/api/*/products/9566948786420/metafields.json*' => Http::response([
                'metafields' => [
                    ['key' => 'installation_type', 'value' => 'level-1'],
                    ['key' => 'installation_level', 'value' => 'level-1'],
                ],
            ], 200),
        ]);

        $response = $this->getJson('/api/shopify/orders?shop=test-shop.myshopify.com&access_token=shpat_test123');

        $response->assertStatus(200);
        $response->assertJsonPath('orders.0.line_items.0.is_installable', true);
        $response->assertJsonPath('orders.0.line_items.0.installation_type', 'level-1');
        $response->assertJsonPath('orders.0.line_items.0.installation_level', 'level-1');
    }

    public function test_order_management_show_includes_installation_details(): void
    {
        $order = Order::create([
            'order_number' => '#TEST-SHOW-300',
            'customer_name' => 'John Doe',
            'total_amount' => 169.00,
            'status' => 'pending',
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_code' => 'BD028BL',
            'product_name' => '2 Pack Crib Fitted Sheets (White)',
            'quantity' => 1,
            'unit_price' => 169.00,
            'status' => 'pending',
            'is_installable' => true,
        ]);

        OrderInstallation::create([
            'order_item_id' => $item->id,
            'installation_type' => 'level-1',
            'installation_level' => 'level-1',
        ]);

        $response = $this->getJson("/api/order-management/orders/{$order->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.items.0.is_installable', true);
        $response->assertJsonPath('data.items.0.installation_type', 'level-1');
        $response->assertJsonPath('data.items.0.installation_level', 'level-1');
        $response->assertJsonPath('data.items.0.installation.installation_type', 'level-1');
    }

    public function test_resource_controller_sales_order_returns_installation_metafields(): void
    {
        \App\Models\ShopifyStore::create([
            'shop' => 'halamama.myshopify.com',
            'access_token' => 'shpat_test123',
            'is_active' => true,
        ]);

        Http::fake([
            'https://halamama.myshopify.com/admin/api/*/orders.json*' => Http::response([
                'orders' => [
                    [
                        'id' => 777701,
                        'order_number' => '5001',
                        'name' => '#5001',
                        'total_price' => '169.00',
                        'line_items' => [
                            [
                                'id' => 888801,
                                'product_id' => 9566948786420,
                                'sku' => 'BD028BL',
                                'title' => '2 Pack Crib Fitted Sheets (White)',
                                'price' => '169.00',
                                'quantity' => 1,
                            ]
                        ]
                    ]
                ]
            ], 200),
            'https://halamama.myshopify.com/admin/api/*/products/9566948786420/metafields.json*' => Http::response([
                'metafields' => [
                    ['key' => 'installation_type', 'value' => true],
                    ['key' => 'installation_level', 'value' => 'level-1'],
                ]
            ], 200),
        ]);

        $user = User::factory()->create();
        $response = $this->actingAs($user)->getJson('/api/resource/sales-order');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.line_items.0.is_installable', true);
        $response->assertJsonPath('data.0.line_items.0.installation_type', 'level-1');
        $response->assertJsonPath('data.0.line_items.0.installation_level', 'level-1');
    }

    public function test_orders_status_installation_endpoint_returns_only_installation_orders(): void
    {
        // 1. Regular order without installation
        $regularOrder = Order::create([
            'order_number' => '#REG-1001',
            'customer_name' => 'Regular Customer',
            'total_amount' => 100.00,
            'status' => 'pending',
        ]);
        OrderItem::create([
            'order_id' => $regularOrder->id,
            'product_code' => 'REG-ITEM',
            'product_name' => 'Regular Cotton Shirt',
            'quantity' => 1,
            'unit_price' => 100.00,
            'status' => 'pending',
            'is_installable' => false,
        ]);

        // 2. Order with installation item and OrderInstallation record
        $installOrder = Order::create([
            'order_number' => '#INST-2002',
            'customer_name' => 'Install Customer',
            'total_amount' => 500.00,
            'status' => 'pending',
        ]);
        $installItem = OrderItem::create([
            'order_id' => $installOrder->id,
            'product_code' => 'BD028BL',
            'product_name' => '2 Pack Crib Fitted Sheets (White)',
            'quantity' => 1,
            'unit_price' => 500.00,
            'status' => 'pending',
            'is_installable' => true,
        ]);
        OrderInstallation::create([
            'order_item_id' => $installItem->id,
            'installation_type' => 'level-1',
            'installation_level' => 'level-1',
        ]);

        // 3. Order with status = in_installation
        $statusInstallOrder = Order::create([
            'order_number' => '#INST-3003',
            'customer_name' => 'Status Install Customer',
            'total_amount' => 300.00,
            'status' => 'in_installation',
        ]);
        OrderItem::create([
            'order_id' => $statusInstallOrder->id,
            'product_code' => 'MISC-01',
            'product_name' => 'Hardware Part',
            'quantity' => 1,
            'unit_price' => 300.00,
            'status' => 'pending',
            'is_installable' => false,
        ]);

        // Test GET /api/orders/status/installation
        $response = $this->getJson('/api/orders/status/installation');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('status_filter', 'installation');
        $response->assertJsonPath('pagination.total', 2);

        $orderNumbers = collect($response->json('data'))->pluck('order_number')->all();
        $this->assertContains('#INST-2002', $orderNumbers);
        $this->assertContains('#INST-3003', $orderNumbers);
        $this->assertNotContains('#REG-1001', $orderNumbers);

        // Verify detailed structure of the installation order
        $installOrderData = collect($response->json('data'))->firstWhere('order_number', '#INST-2002');
        $this->assertNotNull($installOrderData);
        $this->assertTrue($installOrderData['summary']['has_installation']);
        $this->assertEquals(1, $installOrderData['summary']['installation_items']);
        $this->assertTrue($installOrderData['items'][0]['is_installable']);
        $this->assertEquals('level-1', $installOrderData['items'][0]['installation_type']);
        $this->assertEquals('level-1', $installOrderData['items'][0]['installation_level']);
        $this->assertEquals('level-1', $installOrderData['items'][0]['installation']['installation_type']);

        // Test alias GET /api/orders/installation
        $aliasResponse = $this->getJson('/api/orders/installation');
        $aliasResponse->assertStatus(200);
        $aliasResponse->assertJsonPath('pagination.total', 2);

        // Test POST /api/orders/status/installation
        $postResponse = $this->postJson('/api/orders/status/installation');
        $postResponse->assertStatus(200);
        $postResponse->assertJsonPath('pagination.total', 2);

        // Test status filtering: ?status=pending
        $filteredResponse = $this->getJson('/api/orders/status/installation?status=pending');
        $filteredResponse->assertStatus(200);
        $filteredResponse->assertJsonPath('pagination.total', 1);
        $filteredResponse->assertJsonPath('data.0.order_number', '#INST-2002');
    }
}


