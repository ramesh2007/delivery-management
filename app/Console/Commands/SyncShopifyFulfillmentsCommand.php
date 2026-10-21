<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\ShopifyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncShopifyFulfillmentsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopify:sync-fulfillments
                            {order? : Order ID or Order Number to sync fulfillment for}
                            {--all-delivered : Sync all orders with status delivered or completed that are not closed in Shopify}
                            {--limit=50 : Limit number of orders to process}
                            {--force : Bypass cache debounce lock}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync fulfilled/delivered/completed orders and line items to Shopify in Shopify terms';

    protected ShopifyService $shopifyService;

    public function __construct(ShopifyService $shopifyService)
    {
        parent::__construct();
        $this->shopifyService = $shopifyService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $orderInput = $this->argument('order');
        $allDelivered = $this->option('all-delivered');
        $force = (bool) $this->option('force');
        $limit = (int) $this->option('limit');

        if ($orderInput) {
            $this->info("Syncing fulfillment for order: {$orderInput}...");

            $cleanNum = ltrim((string)$orderInput, '#');
            $order = Order::with(['items', 'payment'])
                ->where('id', $orderInput)
                ->orWhere('order_number', (string)$orderInput)
                ->orWhere('order_number', $cleanNum)
                ->orWhere('order_number', '#' . $cleanNum)
                ->first();

            if (!$order) {
                $this->error("Order '{$orderInput}' not found.");
                return 1;
            }

            $result = $this->shopifyService->syncOrderFulfillmentToShopify($order, ['force' => $force]);

            if ($result['success']) {
                $this->info("Successfully synced fulfillment for Order #{$order->order_number}: " . ($result['message'] ?? 'Done'));
                if (!empty($result['details'])) {
                    $this->line(json_encode($result['details'], JSON_PRETTY_PRINT));
                }
                return 0;
            } else {
                $this->error("Failed to sync fulfillment for Order #{$order->order_number}: " . ($result['error'] ?? 'Unknown error'));
                return 1;
            }
        }

        if ($allDelivered) {
            $this->info("Finding delivered and completed orders to sync with Shopify...");

            $orders = Order::with(['items', 'payment'])
                ->whereIn('status', ['delivered', 'completed', 'partialy_deliverd'])
                ->where(function ($q) {
                    $q->whereNull('shopify_status')
                      ->orWhere('shopify_status', '!=', 'closed');
                })
                ->orderBy('updated_at', 'desc')
                ->limit($limit)
                ->get();

            if ($orders->isEmpty()) {
                $this->info("No delivered/completed orders pending Shopify fulfillment sync.");
                return 0;
            }

            $this->info("Found {$orders->count()} orders to process.");

            $syncedCount = 0;
            $failedCount = 0;

            foreach ($orders as $order) {
                $this->line("Processing Order #{$order->order_number} (Status: {$order->status})...");
                $res = $this->shopifyService->syncOrderFulfillmentToShopify($order, ['force' => $force]);

                if (!empty($res['success'])) {
                    $syncedCount++;
                    $this->info("  -> Synced: " . ($res['message'] ?? 'OK'));
                } else {
                    $failedCount++;
                    $this->warn("  -> Failed: " . ($res['error'] ?? 'Unknown error'));
                }
            }

            $summary = "Fulfillment sync batch finished: {$syncedCount} succeeded, {$failedCount} failed.";
            $this->info($summary);
            Log::info($summary);

            return 0;
        }

        $this->warn("Please provide an order identifier or specify --all-delivered.");
        return 0;
    }
}
