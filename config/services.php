<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'shopify' => [
        'api_key' => env('SHOPIFY_API_KEY'),
        'api_secret' => env('SHOPIFY_API_SECRET'),
        'shop_domain' => env('SHOPIFY_SHOP_DOMAIN'),
        'access_token' => env('SHOPIFY_ACCESS_TOKEN'),
        'scopes' => env('SHOPIFY_SCOPES', 'read_products,read_orders,write_orders,read_customers,read_inventory,read_locations,read_collections,read_fulfillments,write_fulfillments,read_assigned_fulfillment_orders,write_assigned_fulfillment_orders,read_merchant_managed_fulfillment_orders,write_merchant_managed_fulfillment_orders,read_third_party_fulfillment_orders,write_third_party_fulfillment_orders'),
        'redirect_uri' => env('SHOPIFY_REDIRECT_URI', 'https://delivery-management.hmws.qatar123.com/public/shopify/callback'),
    ],

    'erpnext' => [
        'base_url' => env('ERPNEXT_BASE_URL', 'https://halamama.rakonex.cc'),
        'login_endpoint' => env('ERPNEXT_LOGIN_ENDPOINT', '/api/method/warehouse_management.api.login.mobile_login'),
    ],
    'whatsapp' => [
        'api_version' => env('WHATSAPP_API_VERSION', 'v26.0'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'business_account_id' => env('WHATSAPP_WABA_ID'),
        'access_token' => env('WHATSAPP_TOKEN'),
        'app_secret' => env('META_APP_SECRET'),
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
    ],

    'facebook' => [
        'api_version' => env('FACEBOOK_API_VERSION', 'v21.0'),
        'app_id' => env('FACEBOOK_APP_ID'),
        'app_secret' => env('FACEBOOK_APP_SECRET', env('META_APP_SECRET')),
        'page_id' => env('FACEBOOK_PAGE_ID'),
        'page_access_token' => env('FACEBOOK_PAGE_ACCESS_TOKEN'),
        'verify_token' => env('FACEBOOK_MESSENGER_VERIFY_TOKEN', env('WHATSAPP_VERIFY_TOKEN')),
    ],

    'instagram' => [
        'api_version' => env('INSTAGRAM_API_VERSION', 'v21.0'),
        'account_id' => env('INSTAGRAM_ACCOUNT_ID'),
        'access_token' => env('INSTAGRAM_ACCESS_TOKEN', env('FACEBOOK_PAGE_ACCESS_TOKEN')),
        'app_secret' => env('INSTAGRAM_APP_SECRET', env('META_APP_SECRET')),
        'verify_token' => env('INSTAGRAM_VERIFY_TOKEN', env('WHATSAPP_VERIFY_TOKEN')),
    ],

];
