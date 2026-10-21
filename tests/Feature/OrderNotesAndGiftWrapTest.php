<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\ShopifyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderNotesAndGiftWrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_shopify_sync_extracts_notes_gift_wrap_and_gift_message_properties()
    {
        $shopifyService = app(ShopifyService::class);

        $payload = [
            'id' => 8881234567,
            'name' => '#1099',
            'order_number' => 1099,
            'note' => 'Please call recipient before delivery',
            'financial_status' => 'paid',
            'total_price' => '150.00',
            'customer' => [
                'first_name' => 'Sara',
                'last_name' => 'Al-Kuwari',
                'phone' => '97433445566',
            ],
            'shipping_address' => [
                'address1' => 'Street 10, Zone 20',
                'city' => 'Doha',
                'country' => 'Qatar',
            ],
            'line_items' => [
                [
                    'id' => 99112233,
                    'product_id' => 771100,
                    'title' => 'Luxury Baby Blanket',
                    'sku' => 'BLANKET-01',
                    'quantity' => 1,
                    'price' => '100.00',
                    'properties' => [
                        [
                            'name' => 'Giftwrap',
                            'value' => 'GW-G, R-G, GC-G',
                        ],
                        [
                            'name' => 'Gift Message',
                            'value' => 'Great Things  start now!',
                        ],
                    ],
                ],
                [
                    'id' => 99112234,
                    'product_id' => 771101,
                    'title' => 'Plush Toy',
                    'sku' => 'TOY-02',
                    'quantity' => 1,
                    'price' => '50.00',
                    'properties' => [],
                ],
            ],
        ];

        $order = $shopifyService->processSingleOrderPayload($payload);

        $this->assertNotNull($order);
        $this->assertEquals('#1099', $order->order_number);
        $this->assertEquals('Please call recipient before delivery', $order->notes);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'order_number' => '#1099',
            'notes' => 'Please call recipient before delivery',
        ]);

        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'line_item_id' => '99112233',
            'gift_wrap' => 'GW-G, R-G, GC-G',
            'gift_message' => 'Great Things  start now!',
        ]);

        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'line_item_id' => '99112234',
            'gift_wrap' => null,
            'gift_message' => null,
        ]);
    }

    public function test_new_orders_endpoint_includes_notes_gift_wrap_and_gift_message()
    {
        $order = Order::create([
            'order_number' => '#1100',
            'customer_name' => 'Fatima Al-Sulaiti',
            'customer_phone' => '55667788',
            'total_amount' => 85.00,
            'status' => 'pending',
            'notes' => 'Happy Birthday Baby John',
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => '99887711',
            'product_code' => 'GIFT-SET',
            'product_name' => 'Baby Bath Set',
            'quantity' => 1,
            'unit_price' => 85.00,
            'gift_wrap' => 'GW-P',
            'gift_message' => 'Enjoy your special day!',
            'status' => 'pending',
        ]);

        $response = $this->getJson('/api/admin/orders/status/new');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                '*' => [
                    'order_id',
                    'order_number',
                    'notes',
                    'items' => [
                        '*' => [
                            'item_id',
                            'line_item_id',
                            'gift_wrap',
                            'gift_message',
                        ],
                    ],
                ],
            ],
        ]);

        $orders = $response->json('data');
        $matched = collect($orders)->firstWhere('order_number', '#1100');
        $this->assertNotNull($matched);
        $this->assertEquals('Happy Birthday Baby John', $matched['notes']);
        $this->assertEquals('GW-P', $matched['items'][0]['gift_wrap']);
        $this->assertEquals('Enjoy your special day!', $matched['items'][0]['gift_message']);
    }

    public function test_order_details_endpoint_includes_notes_gift_wrap_and_gift_message()
    {
        $order = Order::create([
            'order_number' => '#1101',
            'customer_name' => 'Nasser Al-Khelaifi',
            'customer_phone' => '66778899',
            'total_amount' => 200.00,
            'status' => 'pending',
            'notes' => 'Leave at reception desk',
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'line_item_id' => '88776655',
            'product_code' => 'STROLLER-01',
            'product_name' => 'Compact Stroller',
            'quantity' => 1,
            'unit_price' => 200.00,
            'gift_wrap' => 'GW-G, R-G, GC-G',
            'gift_message' => 'Great Things  start now!',
            'status' => 'pending',
        ]);

        $response = $this->getJson("/api/admin/order-details/{$order->id}");

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertEquals('Leave at reception desk', $data['notes']);
        $this->assertEquals('Leave at reception desk', $data['custom_notes']);

        $lineItems = $data['line_items'] ?? $data['items'] ?? [];
        $this->assertNotEmpty($lineItems);
        $this->assertEquals('GW-G, R-G, GC-G', $lineItems[0]['gift_wrap']);
        $this->assertEquals('Great Things  start now!', $lineItems[0]['gift_message']);
    }
}
