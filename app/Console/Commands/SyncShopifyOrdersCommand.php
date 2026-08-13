<?php

namespace App\Console\Commands;

use App\Services\ShopifyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncShopifyOrdersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopify:sync-orders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync orders and line items from Shopify API into local database';

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
        $this->info('Starting Shopify orders sync...');

        try {
            $result = $this->shopifyService->syncOrdersToDatabase();

            if ($result['success']) {
                $msg = "Shopify orders sync completed: {$result['orders_count']} orders, {$result['items_count']} items synced.";
                $this->info($msg);
                Log::info($msg);
            } else {
                $errorMsg = "Shopify orders sync failed: " . ($result['error'] ?? 'Unknown error');
                $this->error($errorMsg);
                Log::error($errorMsg);
            }
        } catch (\Exception $e) {
            $this->error("Shopify Sync Command Exception: " . $e->getMessage());
            Log::error("Shopify Sync Command Exception: " . $e->getMessage());
        }
    }
}
