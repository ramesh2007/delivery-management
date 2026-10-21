<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\ShopifyStore;
use App\Models\OrderStatusLog;
use App\Models\ShopifySyncLog;
use App\Services\ShopifyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ShopifyFulfillmentSyncTest extends TestCase
{
    use RefreshDatabase;

    protected ShopifyService $shopifyService;
    protected ShopifyStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shopifyService = new ShopifyService();

        $this->store = ShopifyStore::create([
            'shop' => 'test-shop.myshopify.com',
            'access_token' => 'shpat_test_token_123',
            'scopes' => 'read_orders,write_orders,write_fulfillments',
            'is_active' => true,
        ]);
    }

    public function test_sync_order_fulfillment_when_order_delivered(): void
    {
        // 1. Create Order with items and payment link
        $order = Order::create([
            'order_number' => '#1001',
            'customer_name' => 'John Doe',
            'total_amount' => 150.00,
            'status' => 'delivered',
            'shopify_status' => 'open',
            'delivered_user_name' => 'Driver Mike',
            'delivered_at' => now(),
        ]);

        $item1 = OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => '8801',
            'product_id' => '7701',
            'product_code' => 'CODE-8801',
            'product_name' => 'Shirt',
            'quantity' => 1,
            'status' => 'delivered',
        ]);

        $item2 = OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => '8802',
            'product_id' => '7702',
            'product_code' => 'CODE-8802',
            'product_name' => 'Jeans',
            'quantity' => 2,
            'status' => 'delivered',
        ]);

        OrderPayment::create([
            'order_id' => $order->id,
            'shopify_order_id' => '555001',
            'payment_status' => 'paid',
        ]);

        // 2. Mock Shopify API endpoints
        Http::fake([
            'https://test-shop.myshopify.com/admin/api/*/orders/555001/fulfillment_orders.json' => Http::response([
                'fulfillment_orders' => [
                    [
                        'id' => 101,
                        'status' => 'open',
                        'supported_actions' => ['create_fulfillment'],
                        'line_items' => [
                            [
                                'id' => 201,
                                'line_item_id' => 8801,
                                'fulfillable_quantity' => 1,
                            ],
                            [
                                'id' => 202,
                                'line_item_id' => 8802,
                                'fulfillable_quantity' => 2,
                            ],
                        ],
                    ],
                ],
            ], 200),

            'https://test-shop.myshopify.com/admin/api/*/fulfillments.json' => Http::response([
                'fulfillment' => [
                    'id' => 901,
                    'order_id' => 555001,
                    'status' => 'success',
                ],
            ], 201),

            'https://test-shop.myshopify.com/admin/api/*/orders/555001/fulfillments/901/events.json' => Http::response([
                'fulfillment_event' => [
                    'id' => 301,
                    'status' => 'delivered',
                ],
            ], 200),

            'https://test-shop.myshopify.com/admin/api/*/orders/555001.json?fields=id,tags,note_attributes' => Http::response([
                'order' => [
                    'id' => 555001,
                    'tags' => 'VIP',
                    'note_attributes' => [],
                ],
            ], 200),

            'https://test-shop.myshopify.com/admin/api/*/orders/555001.json' => Http::response([
                'order' => [
                    'id' => 555001,
                    'tags' => 'VIP, Delivered',
                ],
            ], 200),
        ]);

        // 3. Execute sync
        $result = $this->shopifyService->syncOrderFulfillmentToShopify($order, [
            'status' => 'delivered',
            'delivered_by' => 'Driver Mike',
            'force' => true,
        ]);

        // 4. Assertions
        $this->assertTrue($result['success']);
        $this->assertEquals(2, $result['details']['items_fulfilled_count']);
        $this->assertEquals([901], $result['details']['fulfillment_ids']);
        $this->assertEquals(1, $result['details']['delivery_events_created']);

        // Assert audit log created
        $this->assertDatabaseHas('order_status_logs', [
            'order_id' => $order->id,
            'action' => 'shopify_fulfillment_synced',
        ]);

        // Assert shopify_sync_logs created
        $this->assertDatabaseHas('shopify_sync_logs', [
            'local_order_id' => $order->id,
            'shopify_order_id' => '555001',
            'event_type' => 'fulfillment_sync',
            'status' => 'success',
        ]);

        // Assert HTTP requests
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/fulfillments.json')
                && $request['fulfillment']['line_items_by_fulfillment_order'][0]['fulfillment_order_id'] === 101;
        });

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/events.json')
                && $request['event']['status'] === 'delivered';
        });

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && str_contains($request->url(), '/orders/555001.json')
                && str_contains($request['order']['tags'] ?? '', 'Delivered');
        });
    }

    public function test_sync_order_fulfillment_when_order_completed_closes_order_in_shopify(): void
    {
        $order = Order::create([
            'order_number' => '#1002',
            'customer_name' => 'Alice Smith',
            'total_amount' => 200.00,
            'status' => 'completed',
            'shopify_status' => 'open',
            'delivered_user_name' => 'Technician Bob',
            'delivered_at' => now(),
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => '8803',
            'product_code' => 'CODE-8803',
            'product_name' => 'Table',
            'quantity' => 1,
            'status' => 'completed',
        ]);

        OrderPayment::create([
            'order_id' => $order->id,
            'shopify_order_id' => '555002',
            'payment_status' => 'paid',
        ]);

        Http::fake([
            'https://test-shop.myshopify.com/admin/api/*/orders/555002/fulfillment_orders.json' => Http::response([
                'fulfillment_orders' => [
                    [
                        'id' => 102,
                        'status' => 'open',
                        'line_items' => [
                            [
                                'id' => 203,
                                'line_item_id' => 8803,
                                'fulfillable_quantity' => 1,
                            ],
                        ],
                    ],
                ],
            ], 200),

            'https://test-shop.myshopify.com/admin/api/*/fulfillments.json' => Http::response([
                'fulfillment' => [
                    'id' => 902,
                    'order_id' => 555002,
                    'status' => 'success',
                ],
            ], 201),

            'https://test-shop.myshopify.com/admin/api/*/orders/555002/fulfillments/902/events.json' => Http::response([
                'fulfillment_event' => [
                    'id' => 302,
                    'status' => 'delivered',
                ],
            ], 200),

            'https://test-shop.myshopify.com/admin/api/*/orders/555002/close.json' => Http::response([
                'order' => [
                    'id' => 555002,
                    'closed_at' => now()->toIso8601String(),
                ],
            ], 200),

            'https://test-shop.myshopify.com/admin/api/*/orders/555002.json?fields=id,tags,note_attributes' => Http::response([
                'order' => [
                    'id' => 555002,
                    'tags' => '',
                    'note_attributes' => [],
                ],
            ], 200),

            'https://test-shop.myshopify.com/admin/api/*/orders/555002.json' => Http::response([
                'order' => [
                    'id' => 555002,
                    'tags' => 'Delivered, Completed',
                ],
            ], 200),
        ]);

        $result = $this->shopifyService->syncOrderFulfillmentToShopify($order, [
            'status' => 'completed',
            'delivered_by' => 'Technician Bob',
            'force' => true,
        ]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['details']['order_closed']);
        $this->assertEquals('closed', $order->fresh()->shopify_status);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/orders/555002/close.json');
        });

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && str_contains($request->url(), '/orders/555002.json')
                && str_contains($request['order']['tags'] ?? '', 'Completed');
        });
    }

    public function test_partial_delivery_fulfills_only_delivered_items(): void
    {
        $order = Order::create([
            'order_number' => '#1003',
            'customer_name' => 'Charlie Brown',
            'total_amount' => 300.00,
            'status' => 'partialy_deliverd',
            'shopify_status' => 'open',
            'delivered_user_name' => 'Driver Dave',
        ]);

        $deliveredItem = OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => '8804',
            'product_code' => 'CODE-8804',
            'product_name' => 'Curtains',
            'quantity' => 1,
            'status' => 'delivered',
        ]);

        $pendingItem = OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => '8805',
            'product_code' => 'CODE-8805',
            'product_name' => 'Curtain Rod (Needs installation)',
            'quantity' => 1,
            'status' => 'pending',
            'is_installable' => true,
        ]);

        OrderPayment::create([
            'order_id' => $order->id,
            'shopify_order_id' => '555003',
            'payment_status' => 'paid',
        ]);

        Http::fake([
            'https://test-shop.myshopify.com/admin/api/*/orders/555003/fulfillment_orders.json' => Http::response([
                'fulfillment_orders' => [
                    [
                        'id' => 103,
                        'status' => 'open',
                        'line_items' => [
                            [
                                'id' => 204,
                                'line_item_id' => 8804,
                                'fulfillable_quantity' => 1,
                            ],
                            [
                                'id' => 205,
                                'line_item_id' => 8805,
                                'fulfillable_quantity' => 1,
                            ],
                        ],
                    ],
                ],
            ], 200),

            'https://test-shop.myshopify.com/admin/api/*/fulfillments.json' => Http::response([
                'fulfillment' => [
                    'id' => 903,
                    'order_id' => 555003,
                    'status' => 'success',
                ],
            ], 201),

            'https://test-shop.myshopify.com/admin/api/*/orders/555003/fulfillments/903/events.json' => Http::response([
                'fulfillment_event' => ['id' => 303, 'status' => 'delivered'],
            ], 200),

            'https://test-shop.myshopify.com/admin/api/*/orders/555003.json?fields=id,tags,note_attributes' => Http::response([
                'order' => ['id' => 555003, 'tags' => '', 'note_attributes' => []],
            ], 200),

            'https://test-shop.myshopify.com/admin/api/*/orders/555003.json' => Http::response([
                'order' => ['id' => 555003],
            ], 200),
        ]);

        $result = $this->shopifyService->syncOrderFulfillmentToShopify($order, [
            'status' => 'partialy_deliverd',
            'item_ids' => [$deliveredItem->id],
            'force' => true,
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['details']['items_fulfilled_count']);
        // Verify order is NOT closed because it's partial
        $this->assertFalse($result['details']['order_closed']);
        $this->assertEquals('open', $order->fresh()->shopify_status);

        // Verify only item 204 was sent to be fulfilled
        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), '/fulfillments.json')) {
                return false;
            }
            $lineItems = $request['fulfillment']['line_items_by_fulfillment_order'][0]['fulfillment_order_line_items'];
            return count($lineItems) === 1 && $lineItems[0]['id'] === 204;
        });
    }

    public function test_artisan_sync_fulfillments_command(): void
    {
        $order = Order::create([
            'order_number' => '#1004',
            'customer_name' => 'Dana White',
            'total_amount' => 50.00,
            'status' => 'delivered',
            'shopify_status' => 'open',
        ]);

        OrderPayment::create([
            'order_id' => $order->id,
            'shopify_order_id' => '555004',
        ]);

        Http::fake([
            'https://test-shop.myshopify.com/admin/api/*/orders/555004/fulfillment_orders.json' => Http::response([
                'fulfillment_orders' => [],
            ], 200),
            'https://test-shop.myshopify.com/admin/api/*/orders/555004/fulfillments.json' => Http::response([
                'fulfillments' => [],
            ], 200),
            'https://test-shop.myshopify.com/admin/api/*/orders/555004.json?fields=id,tags,note_attributes' => Http::response([
                'order' => ['id' => 555004, 'tags' => ''],
            ], 200),
            'https://test-shop.myshopify.com/admin/api/*/orders/555004.json' => Http::response([
                'order' => ['id' => 555004],
            ], 200),
        ]);

        $exitCode = Artisan::call('shopify:sync-fulfillments', [
            'order' => $order->id,
            '--force' => true,
        ]);

        $this->assertEquals(0, $exitCode);
    }
}
