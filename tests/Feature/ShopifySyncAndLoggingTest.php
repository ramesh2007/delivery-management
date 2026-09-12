<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\ShopifyStore;
use App\Models\ShopifySyncLog;
use App\Models\User;
use App\Services\ShopifyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifySyncAndLoggingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    /**
     * Test incoming orders/create webhook auto-syncs order to database and records success log
     */
    public function test_webhook_orders_create_auto_syncs_order_and_creates_log(): void
    {
        $payload = [
            'id' => 9566948786420,
            'order_number' => 1005,
            'name' => '#1005',
            'created_at' => '2026-09-09T10:00:00Z',
            'total_price' => '338.00',
            'total_outstanding' => '0.00',
            'currency' => 'QAR',
            'financial_status' => 'paid',
            'payment_gateway_names' => ['Shopify Payments'],
            'customer' => [
                'first_name' => 'Fatima',
                'last_name' => 'Al-Kuwari',
                'email' => 'fatima@example.com',
                'phone' => '+974 5511 2233',
            ],
            'shipping_address' => [
                'name' => 'Fatima Al-Kuwari',
                'address1' => 'Villa 12, Street 90',
                'city' => 'Doha',
                'country' => 'Qatar',
            ],
            'line_items' => [
                [
                    'id' => 16662970138868,
                    'product_id' => 9566948786420,
                    'variant_id' => 48933440454900,
                    'title' => '2 Pack Crib Fitted Sheets (White)',
                    'sku' => 'BD028BL',
                    'price' => '169.00',
                    'quantity' => 2,
                    'properties' => [
                        ['name' => 'installation_type', 'value' => 'express'],
                        ['name' => 'installation_level', 'value' => 'level-2'],
                    ],
                ]
            ],
        ];

        $response = $this->withHeaders([
            'X-Shopify-Topic' => 'orders/create',
            'X-Shopify-Shop-Domain' => 'halamama.myshopify.com',
            'X-Shopify-Webhook-Id' => 'wh-uuid-12345',
        ])->postJson('/api/shopify/webhooks/orders-create', $payload);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Order webhook processed successfully',
            'order_number' => '#1005',
            'items_count' => 1,
        ]);

        // Verify order saved in database
        $this->assertDatabaseHas('orders', [
            'order_number' => '#1005',
            'customer_name' => 'Fatima Al-Kuwari',
            'total_amount' => 338.00,
            'status' => 'pending',
        ]);

        // Verify order item saved
        $this->assertDatabaseHas('order_items', [
            'line_item_id' => '16662970138868',
            'product_name' => '2 Pack Crib Fitted Sheets (White)',
            'quantity' => 2,
            'unit_price' => 169.00,
            'is_installable' => true,
        ]);

        // Verify installation details saved
        $this->assertDatabaseHas('order_installations', [
            'installation_type' => 'express',
            'installation_level' => 'level-2',
        ]);

        // Verify payment record saved
        $this->assertDatabaseHas('order_payments', [
            'shopify_order_id' => '9566948786420',
            'payment_status' => 'paid',
            'paid_amount' => 338.00,
        ]);

        // Verify sync log created with success status
        $this->assertDatabaseHas('shopify_sync_logs', [
            'event_type' => 'webhook_orders_create',
            'topic' => 'orders/create',
            'shop_domain' => 'halamama.myshopify.com',
            'shopify_order_id' => '9566948786420',
            'order_number' => '#1005',
            'status' => 'success',
            'items_count' => 1,
        ]);
    }

    /**
     * Test web routes fallback endpoint (without /api prefix) works seamlessly
     */
    public function test_web_route_fallback_orders_create_webhook_succeeds(): void
    {
        $payload = [
            'id' => 9566948786421,
            'order_number' => 1006,
            'name' => '#1006',
            'total_price' => '150.00',
            'line_items' => [
                [
                    'id' => 16662970138869,
                    'title' => 'Baby Monitor',
                    'price' => '150.00',
                    'quantity' => 1,
                ]
            ],
        ];

        $response = $this->withHeaders([
            'X-Shopify-Topic' => 'orders/create',
            'X-Shopify-Shop-Domain' => 'halamama.myshopify.com',
        ])->postJson('/shopify/webhooks/orders-create', $payload);

        $response->assertStatus(200);
        $this->assertDatabaseHas('orders', ['order_number' => '#1006']);
    }

    /**
     * Test wrapped order payload e.g. { "order": { ... } } is parsed cleanly
     */
    public function test_webhook_unwraps_nested_order_payload(): void
    {
        $payload = [
            'order' => [
                'id' => 9566948786422,
                'order_number' => 1007,
                'name' => '#1007',
                'total_price' => '80.00',
                'line_items' => [
                    [
                        'id' => 16662970138870,
                        'title' => 'Baby Bottle',
                        'price' => '80.00',
                        'quantity' => 1,
                    ]
                ],
            ]
        ];

        $response = $this->postJson('/api/shopify/webhooks/orders-create', $payload);
        $response->assertStatus(200);
        $this->assertDatabaseHas('orders', ['order_number' => '#1007']);
    }

    /**
     * Test invalid or empty webhook payload creates a failed sync log
     */
    public function test_invalid_webhook_payload_records_failed_sync_log(): void
    {
        $response = $this->withHeaders([
            'X-Shopify-Topic' => 'orders/create',
            'X-Shopify-Shop-Domain' => 'test-shop.myshopify.com',
        ])->postJson('/api/shopify/webhooks/orders-create', []);

        $response->assertStatus(400);
        $response->assertJson([
            'success' => false,
            'message' => 'Invalid or empty Shopify order webhook payload',
        ]);

        $this->assertDatabaseHas('shopify_sync_logs', [
            'event_type' => 'webhook_orders_create',
            'status' => 'failed',
            'error_message' => 'Invalid or empty Shopify order webhook payload',
        ]);
    }

    /**
     * Test sync-logs API endpoint lists logs and respects status filters
     */
    public function test_api_sync_logs_lists_and_filters_logs(): void
    {
        ShopifySyncLog::create([
            'event_type' => 'webhook_orders_create',
            'topic' => 'orders/create',
            'order_number' => '#2001',
            'status' => 'success',
            'items_count' => 2,
        ]);

        ShopifySyncLog::create([
            'event_type' => 'webhook_orders_create',
            'topic' => 'orders/create',
            'order_number' => '#2002',
            'status' => 'failed',
            'error_message' => 'Simulated line item error',
            'payload' => ['id' => 2002, 'order_number' => 2002, 'name' => '#2002'],
        ]);

        // 1. List all logs
        $response = $this->getJson('/api/shopify/sync-logs');
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'total' => 2,
            'failed_count' => 1,
            'success_count' => 1,
        ]);

        // 2. Filter failed logs only
        $responseFailed = $this->getJson('/api/shopify/sync-logs?status=failed');
        $responseFailed->assertStatus(200);
        $this->assertCount(1, $responseFailed->json('logs'));
        $this->assertEquals('#2002', $responseFailed->json('logs.0.order_number'));
    }

    /**
     * Test single sync log detail endpoint
     */
    public function test_api_sync_log_detail(): void
    {
        $log = ShopifySyncLog::create([
            'event_type' => 'webhook_orders_create',
            'topic' => 'orders/create',
            'order_number' => '#2003',
            'status' => 'success',
            'items_count' => 3,
            'payload' => ['id' => 2003, 'name' => '#2003'],
        ]);

        $response = $this->getJson("/api/shopify/sync-logs/{$log->id}");
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'log' => [
                'id' => $log->id,
                'order_number' => '#2003',
            ],
        ]);
    }

    /**
     * Test retry failed sync log re-processes order and marks log as success
     */
    public function test_retry_sync_log_reprocesses_order_successfully(): void
    {
        $failedLog = ShopifySyncLog::create([
            'event_type' => 'webhook_orders_create',
            'topic' => 'orders/create',
            'order_number' => '#2004',
            'status' => 'failed',
            'error_message' => 'Network timeout during earlier sync attempt',
            'payload' => [
                'id' => 99992004,
                'order_number' => 2004,
                'name' => '#2004',
                'total_price' => '210.00',
                'customer' => ['first_name' => 'Sara', 'last_name' => 'Ahmed'],
                'line_items' => [
                    [
                        'id' => 555501,
                        'title' => 'Stroller Rain Cover',
                        'price' => '210.00',
                        'quantity' => 1,
                    ]
                ],
            ],
        ]);

        $response = $this->postJson("/api/shopify/sync-logs/{$failedLog->id}/retry");
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'order_number' => '#2004',
        ]);

        // Verify order created in database
        $this->assertDatabaseHas('orders', ['order_number' => '#2004']);
        $this->assertDatabaseHas('order_items', ['line_item_id' => '555501']);

        // Verify log updated to success
        $failedLog->refresh();
        $this->assertEquals('success', $failedLog->status);
        $this->assertNull($failedLog->error_message);
    }
}
