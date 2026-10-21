<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule Shopify orders sync to run automatically every minute
Schedule::command('shopify:sync-orders')->everyMinute();

// Schedule Shopify fulfillments sync for delivered/completed orders every 5 minutes
Schedule::command('shopify:sync-fulfillments --all-delivered')->everyFiveMinutes();

