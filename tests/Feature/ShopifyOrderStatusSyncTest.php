<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusLog;
use App\Services\ShopifyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopifyOrderStatusSyncTest extends TestCase
{
    use RefreshDatabase;

    protected ShopifyService $shopifyService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shopifyService = app(ShopifyService::class);
    }

    /**
     * Test creating a new active order from Shopify payload with only lean columns on orders table
     */
    public function test_new_shopify_order_is_stored_with_pending_status_and_lean_columns()
    {
        $payload = [
            'id' => 7234949415100,
            'order_number' => 1086,
            'name' => '#1086',
            'created_at' => '2026-10-18T16:00:00+03:00',
            'updated_at' => '2026-10-18T16:05:00+03:00',
            'currency' => 'QAR',
            'total_price' => '150.00',
            'financial_status' => 'paid',
            'fulfillment_status' => null,
            'cancelled_at' => null,
            'cancel_reason' => null,
            'closed_at' => null,
            'customer' => [
                'first_name' => 'Sara',
                'last_name' => 'Ali',
                'phone' => '+97455001122',
            ],
            'shipping_address' => [
                'address1' => 'Villa 12',
                'city' => 'Doha',
                'country' => 'Qatar',
            ],
            'line_items' => [
                [
                    'id' => 17051830321001,
                    'sku' => 'SKU-001',
                    'title' => 'Baby Carrier',
                    'quantity' => 1,
                    'price' => '150.00',
                ]
            ],
        ];

        $order = $this->shopifyService->processSingleOrderPayload($payload);

        $this->assertNotNull($order);
        $this->assertEquals('#1086', $order->order_number);
        $this->assertEquals('pending', $order->status);
        $this->assertEquals('open', $order->shopify_status);
        $this->assertNull($order->cancelled_at);
        $this->assertFalse($order->isCancelled());
        $this->assertEquals(150.00, (float)$order->total_amount);

        // Financial status is stored separately in order_payments table
        $order->load('payment');
        $this->assertNotNull($order->payment);
        $this->assertEquals('7234949415100', $order->payment->shopify_order_id);
        $this->assertEquals('paid', $order->payment->payment_status);
        $this->assertEquals('QAR', $order->payment->currency);
    }

    /**
     * Test storing a cancelled Shopify order (matching user provided payload)
     */
    public function test_cancelled_shopify_order_stores_cancelled_status_and_timestamps()
    {
        $payload = [
            'id' => 7234949415156,
            'order_number' => 1087,
            'name' => '#1087',
            'cancel_reason' => 'customer',
            'cancelled_at' => '2026-10-18T17:02:00+03:00',
            'closed_at' => '2026-10-18T17:01:59+03:00',
            'created_at' => '2026-10-18T16:59:39+03:00',
            'updated_at' => '2026-10-18T17:02:00+03:00',
            'currency' => 'QAR',
            'financial_status' => 'refunded',
            'fulfillment_status' => null,
            'total_price' => '262.00',
            'customer' => [
                'first_name' => 'Ramesh',
                'last_name' => 'Rakonex',
                'email' => 'ramesh@rakonex.net',
            ],
            'shipping_address' => [
                'name' => 'Ramesh Rakonex',
                'phone' => '+919790010244',
                'address1' => '90',
                'address2' => 'Agraharam street',
                'city' => 'Trichy',
                'country' => 'Qatar',
            ],
            'line_items' => [
                [
                    'id' => 17051830321396,
                    'sku' => 'MNSKWMT6',
                    'title' => 'Moon Organic Toddler Half Moon Bed Bumper (White)',
                    'quantity' => 1,
                    'price' => '262.00',
                ]
            ],
        ];

        $order = $this->shopifyService->processSingleOrderPayload($payload);

        $this->assertNotNull($order);
        $this->assertEquals('#1087', $order->order_number);
        $this->assertEquals('cancelled', $order->status);
        $this->assertEquals('cancelled', $order->shopify_status);
        $this->assertNotNull($order->cancelled_at);
        $this->assertTrue($order->isCancelled());

        // Line item should also be marked cancelled
        $item = $order->items->first();
        $this->assertNotNull($item);
        $this->assertEquals('cancelled', $item->status);

        // Payment status stored in separate order_payments table
        $order->load('payment');
        $this->assertNotNull($order->payment);
        $this->assertEquals('7234949415156', $order->payment->shopify_order_id);
        $this->assertEquals('refunded', $order->payment->payment_status);
    }

    /**
     * Test updating existing pending order to cancelled dynamically during cron update
     */
    public function test_existing_order_updates_to_cancelled_on_cron_sync()
    {
        // 1. Initial order placed as pending
        $initialPayload = [
            'id' => 7234949415999,
            'order_number' => 1088,
            'name' => '#1088',
            'created_at' => '2026-10-18T10:00:00+03:00',
            'updated_at' => '2026-10-18T10:00:00+03:00',
            'currency' => 'QAR',
            'total_price' => '300.00',
            'financial_status' => 'pending',
            'fulfillment_status' => null,
            'cancelled_at' => null,
            'cancel_reason' => null,
            'line_items' => [
                [
                    'id' => 999111,
                    'sku' => 'TOY-1',
                    'title' => 'Stroller Toy',
                    'quantity' => 1,
                    'price' => '300.00',
                ]
            ],
        ];

        $order = $this->shopifyService->processSingleOrderPayload($initialPayload);
        $this->assertEquals('pending', $order->status);
        $this->assertEquals('open', $order->shopify_status);
        $this->assertNull($order->cancelled_at);

        // 2. Cron job runs later and order has been cancelled on Shopify
        $updatedPayload = [
            'id' => 7234949415999,
            'order_number' => 1088,
            'name' => '#1088',
            'created_at' => '2026-10-18T10:00:00+03:00',
            'updated_at' => '2026-10-18T10:30:00+03:00',
            'currency' => 'QAR',
            'total_price' => '300.00',
            'financial_status' => 'refunded',
            'fulfillment_status' => null,
            'cancelled_at' => '2026-10-18T10:30:00+03:00',
            'cancel_reason' => 'inventory',
            'closed_at' => '2026-10-18T10:30:00+03:00',
            'line_items' => [
                [
                    'id' => 999111,
                    'sku' => 'TOY-1',
                    'title' => 'Stroller Toy',
                    'quantity' => 1,
                    'price' => '300.00',
                ]
            ],
        ];

        $updatedOrder = $this->shopifyService->processSingleOrderPayload($updatedPayload);

        $this->assertEquals($order->id, $updatedOrder->id);
        $this->assertEquals('cancelled', $updatedOrder->status);
        $this->assertEquals('cancelled', $updatedOrder->shopify_status);
        $this->assertNotNull($updatedOrder->cancelled_at);
        $this->assertTrue($updatedOrder->isCancelled());

        // Verify status log was written
        $log = OrderStatusLog::where('order_id', $order->id)
            ->where('action', 'shopify_order_cancelled')
            ->first();
        $this->assertNotNull($log);
        $this->assertEquals('cancelled', $log->new_status);
        $this->assertEquals('pending', $log->old_status);

        // Verify separate order_payments table updated
        $updatedOrder->load('payment');
        $this->assertNotNull($updatedOrder->payment);
        $this->assertEquals('refunded', $updatedOrder->payment->payment_status);
    }

    /**
     * Test closed order without cancellation sets shopify_status = closed
     */
    public function test_closed_shopify_order_without_cancellation_stores_closed_shopify_status()
    {
        $payload = [
            'id' => 7234949415555,
            'order_number' => 1089,
            'name' => '#1089',
            'created_at' => '2026-10-18T10:00:00+03:00',
            'updated_at' => '2026-10-18T12:00:00+03:00',
            'cancelled_at' => null,
            'closed_at' => '2026-10-18T12:00:00+03:00',
            'financial_status' => 'paid',
            'total_price' => '200.00',
            'line_items' => [],
        ];

        $order = $this->shopifyService->processSingleOrderPayload($payload);

        $this->assertEquals('closed', $order->shopify_status);
        $this->assertNull($order->cancelled_at);
        $this->assertFalse($order->isCancelled());
    }
}

