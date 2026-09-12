<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shopify Integration Dashboard</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- FontAwesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary: #95bf47;
            --primary-dark: #5e8e3e;
            --shopify-black: #002e25;
            --shopify-green: #008060;
            --bg-slate: #0f172a;
            --card-bg: #1e293b;
            --border-color: #334155;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --accent-blue: #38bdf8;
            --accent-emerald: #10b981;
            --accent-amber: #f59e0b;
            --accent-rose: #f43f5e;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--bg-slate);
            color: var(--text-main);
            min-height: 100vh;
            padding: 24px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Glassmorphism Header */
        .app-header {
            background: rgba(30, 41, 59, 0.7);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            padding: 24px 32px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }

        .brand-section {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .brand-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #95bf47 0%, #008060 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
            box-shadow: 0 4px 12px rgba(149, 191, 71, 0.4);
        }

        .brand-title h1 {
            font-size: 22px;
            font-weight: 700;
            color: #ffffff;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .brand-title p {
            font-size: 13px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-badge.connected {
            background: rgba(16, 185, 129, 0.15);
            color: #34d399;
            border: 1px solid rgba(52, 211, 153, 0.3);
        }

        .status-badge.disconnected {
            background: rgba(244, 63, 94, 0.15);
            color: #fb7185;
            border: 1px solid rgba(251, 113, 133, 0.3);
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, #008060 0%, #004d3a 100%);
            color: white;
            box-shadow: 0 4px 14px rgba(0, 128, 96, 0.4);
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(0, 128, 96, 0.6);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.08);
            color: var(--text-main);
            border: 1px solid rgba(255, 255, 255, 0.15);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.15);
        }

        /* Alerts */
        .alert {
            padding: 14px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #34d399;
        }

        .alert-error {
            background: rgba(244, 63, 94, 0.15);
            border: 1px solid rgba(244, 63, 94, 0.3);
            color: #fb7185;
        }

        /* Store Connection Card */
        .connection-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
        }

        .connection-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .form-group label {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-muted);
        }

        .input-group {
            display: flex;
            gap: 8px;
        }

        .form-control {
            width: 100%;
            padding: 10px 14px;
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: white;
            font-size: 14px;
            font-family: inherit;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--accent-blue);
            box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.2);
        }

        /* Navigation Tabs */
        .nav-tabs {
            display: flex;
            gap: 8px;
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 24px;
        }

        .tab-btn {
            padding: 12px 24px;
            background: transparent;
            border: none;
            color: var(--text-muted);
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
        }

        .tab-btn:hover {
            color: white;
        }

        .tab-btn.active {
            color: #38bdf8;
            border-bottom-color: #38bdf8;
        }

        .count-pill {
            background: rgba(255, 255, 255, 0.1);
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Products & Orders Grids */
        .cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
        }

        .data-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 20px;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .data-card:hover {
            transform: translateY(-2px);
            border-color: rgba(255, 255, 255, 0.2);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
        }

        .card-header-flex {
            display: flex;
            gap: 16px;
            align-items: flex-start;
            margin-bottom: 14px;
        }

        .thumb-img {
            width: 64px;
            height: 64px;
            border-radius: 10px;
            object-fit: cover;
            background: rgba(15, 23, 42, 0.8);
            border: 1px solid var(--border-color);
            flex-shrink: 0;
        }

        .thumb-placeholder {
            width: 64px;
            height: 64px;
            border-radius: 10px;
            background: rgba(15, 23, 42, 0.8);
            border: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: var(--text-muted);
            flex-shrink: 0;
        }

        .card-title-meta h3 {
            font-size: 16px;
            font-weight: 700;
            color: white;
            line-height: 1.3;
            margin-bottom: 4px;
        }

        .meta-tag {
            font-size: 12px;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .card-body-metrics {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin: 16px 0;
            padding: 12px;
            background: rgba(15, 23, 42, 0.5);
            border-radius: 8px;
        }

        .metric-item span {
            display: block;
            font-size: 11px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .metric-item strong {
            font-size: 15px;
            color: #ffffff;
            font-weight: 700;
        }

        .tag-pill {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-transform: capitalize;
        }

        .tag-active { background: rgba(16, 185, 129, 0.2); color: #34d399; }
        .tag-draft { background: rgba(245, 158, 11, 0.2); color: #fbbf24; }
        .tag-paid { background: rgba(16, 185, 129, 0.2); color: #34d399; }
        .tag-pending { background: rgba(245, 158, 11, 0.2); color: #fbbf24; }
        .tag-fulfilled { background: rgba(56, 189, 248, 0.2); color: #38bdf8; }
        .tag-unfulfilled { background: rgba(244, 63, 94, 0.2); color: #fb7185; }

        .card-footer-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-top: 1px solid var(--border-color);
            padding-top: 14px;
            margin-top: 8px;
        }

        /* Raw JSON Viewer Codeblock */
        .json-viewer {
            background: #090d16;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 16px;
            font-family: 'Courier New', Courier, monospace;
            font-size: 13px;
            color: #38bdf8;
            max-height: 400px;
            overflow-y: auto;
            white-space: pre-wrap;
            word-break: break-all;
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: var(--card-bg);
            border: 1px dashed var(--border-color);
            border-radius: 16px;
        }

        .empty-state i {
            font-size: 48px;
            color: var(--text-muted);
            margin-bottom: 16px;
        }

        .empty-state h2 {
            font-size: 20px;
            color: white;
            margin-bottom: 8px;
        }

        .empty-state p {
            color: var(--text-muted);
            max-width: 480px;
            margin: 0 auto 20px;
        }
    </style>
</head>
<body>

<div class="container">
    <!-- Header -->
    <header class="app-header">
        <div class="brand-section">
            <div class="brand-icon">
                <i class="fa-brands fa-shopify"></i>
            </div>
            <div class="brand-title">
                <h1>
                    Shopify Live Sync
                    @if($activeStore && $activeStore->access_token)
                        <span class="status-badge connected"><i class="fa-solid fa-circle-check"></i> Connected</span>
                    @else
                        <span class="status-badge disconnected"><i class="fa-solid fa-circle-exclamation"></i> Not Connected</span>
                    @endif
                </h1>
                <p>Live REST API fetch for Shopify Products & Orders (No DB Data Caching)</p>
            </div>
        </div>

        <div class="header-actions">
            <button class="btn btn-secondary" onclick="toggleConfigModal()">
                <i class="fa-solid fa-gear"></i> Settings / Auth
            </button>
            <a href="{{ route('shopify.index') }}" class="btn btn-primary">
                <i class="fa-solid fa-arrows-rotate"></i> Sync Live Data
            </a>
        </div>
    </header>

    <!-- Success & Error Flash Messages -->
    @if(session('success'))
        <div class="alert alert-success">
            <i class="fa-solid fa-circle-check font-size-18"></i>
            <span>{{ session('success') }}</span>
        </div>
    @endif

    @if(session('error'))
        <div class="alert alert-error">
            <i class="fa-solid fa-triangle-exclamation font-size-18"></i>
            <span>{{ session('error') }}</span>
        </div>
    @endif

    <!-- Connection & Auth Form Box (Collapsible / Toggleable) -->
    <div id="config-box" class="connection-card" style="{{ ($activeStore && $activeStore->access_token) ? 'display: none;' : '' }}">
        <h2 style="font-size: 18px; margin-bottom: 16px; color: white; display: flex; align-items: center; gap: 8px;">
            <i class="fa-solid fa-plug"></i> Shopify Store Authorization
        </h2>
        <div class="connection-grid">
            <!-- OAuth Connect Form -->
            <form action="{{ route('shopify.connect') }}" method="GET">
                <div class="form-group">
                    <label><i class="fa-solid fa-store"></i> Connect Store Domain via OAuth</label>
                    <div class="input-group">
                        <input type="text" name="shop" class="form-control" placeholder="e.g. your-store.myshopify.com" value="{{ $activeStore->shop ?? '' }}" required>
                        <button type="submit" class="btn btn-primary" style="white-space: nowrap;">
                            Connect OAuth <i class="fa-solid fa-arrow-right"></i>
                        </button>
                    </div>
                    <small style="color: var(--text-muted); font-size: 12px; margin-top: 4px;">Redirects to Shopify for permissions authorization.</small>
                </div>
            </form>

            <!-- Direct Token Form (Custom App) -->
            <form action="{{ route('shopify.save-settings') }}" method="POST">
                @csrf
                <div class="form-group">
                    <label><i class="fa-solid fa-key"></i> Direct Admin Access Token (Custom App)</label>
                    <div class="input-group" style="margin-bottom: 8px;">
                        <input type="text" name="shop" class="form-control" placeholder="your-store.myshopify.com" value="{{ $activeStore->shop ?? '' }}" required>
                        <input type="password" name="access_token" class="form-control" placeholder="shpat_xxxx... or shpss_xxxx..." required>
                    </div>
                    <button type="submit" class="btn btn-secondary" style="width: 100%; justify-content: center;">
                        <i class="fa-solid fa-floppy-disk"></i> Save Access Token Directly
                    </button>
                </div>
            </form>
        </div>

        <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border-color); display: flex; gap: 20px; font-size: 12px; color: var(--text-muted); flex-wrap: wrap;">
            <div><strong>Client ID:</strong> {{ $config['api_key'] ?? 'Not set' }}</div>
            <div><strong>Scopes:</strong> {{ $config['scopes'] ?? 'Not set' }}</div>
            <div><strong>Redirect URI:</strong> {{ $config['redirect_uri'] ?? 'Not set' }}</div>
        </div>
    </div>

    <!-- Main Navigation Tabs -->
    <div class="nav-tabs">
        <button class="tab-btn active" onclick="switchTab('products')">
            <i class="fa-solid fa-box-archive"></i> Products
            <span class="count-pill">{{ count($productsResult['products'] ?? []) }}</span>
        </button>
        <button class="tab-btn" onclick="switchTab('orders')">
            <i class="fa-solid fa-receipt"></i> Orders
            <span class="count-pill">{{ count($ordersResult['orders'] ?? []) }}</span>
        </button>
        <button class="tab-btn" onclick="switchTab('api-debug')">
            <i class="fa-solid fa-code"></i> API JSON Inspector
        </button>
        <button class="tab-btn" onclick="switchTab('sync-logs')">
            <i class="fa-solid fa-clock-rotate-left"></i> Sync & Webhook Logs
            @if(!empty($syncStats['failed']) && $syncStats['failed'] > 0)
                <span class="count-pill" style="background: rgba(244, 63, 94, 0.2); color: #fb7185; border: 1px solid rgba(244, 63, 94, 0.4);">{{ $syncStats['failed'] }} Failed</span>
            @else
                <span class="count-pill">{{ count($syncLogs ?? []) }}</span>
            @endif
        </button>
    </div>

    <!-- TAB 1: PRODUCTS LIST -->
    <div id="tab-products" class="tab-content active">
        @if(!$productsResult['success'])
            <div class="empty-state">
                <i class="fa-solid fa-circle-exclamation" style="color: #fb7185;"></i>
                <h2>Unable to fetch Shopify Products</h2>
                <p>{{ $productsResult['error'] ?? 'Please ensure your store domain and access token are correctly configured.' }}</p>
                <button class="btn btn-primary" onclick="toggleConfigModal()">
                    <i class="fa-solid fa-plug"></i> Configure Store Credentials
                </button>
            </div>
        @elseif(empty($productsResult['products']))
            <div class="empty-state">
                <i class="fa-solid fa-box-open"></i>
                <h2>No Products Found</h2>
                <p>Connected to <strong>{{ $productsResult['shop'] }}</strong> but no products were returned from Shopify.</p>
            </div>
        @else
            <div style="margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center;">
                <p style="color: var(--text-muted); font-size: 14px;">Showing {{ count($productsResult['products']) }} products live from <strong>{{ $productsResult['shop'] }}</strong></p>
            </div>

            <div class="cards-grid">
                @foreach($productsResult['products'] as $product)
                    @php
                        $imageSrc = $product['image']['src'] ?? ($product['images'][0]['src'] ?? null);
                        $minPrice = $product['variants'][0]['price'] ?? '0.00';
                        $totalInventory = array_sum(array_column($product['variants'] ?? [], 'inventory_quantity'));
                    @endphp
                    <div class="data-card">
                        <div>
                            <div class="card-header-flex">
                                @if($imageSrc)
                                    <img src="{{ $imageSrc }}" alt="{{ $product['title'] }}" class="thumb-img">
                                @else
                                    <div class="thumb-placeholder">
                                        <i class="fa-solid fa-image"></i>
                                    </div>
                                @endif
                                <div class="card-title-meta">
                                    <h3>{{ $product['title'] }}</h3>
                                    <div class="meta-tag">
                                        <i class="fa-solid fa-tag"></i> {{ $product['vendor'] ?? 'Shopify' }} &bull; {{ $product['product_type'] ?: 'Standard Product' }}
                                    </div>
                                </div>
                            </div>

                            <div class="card-body-metrics">
                                <div class="metric-item">
                                    <span>Price</span>
                                    <strong>${{ number_format((float)$minPrice, 2) }}</strong>
                                </div>
                                <div class="metric-item">
                                    <span>Stock Inventory</span>
                                    <strong>{{ $totalInventory }} units</strong>
                                </div>
                                <div class="metric-item">
                                    <span>Variants</span>
                                    <strong>{{ count($product['variants'] ?? []) }} variant(s)</strong>
                                </div>
                                <div class="metric-item">
                                    <span>Status</span>
                                    <span class="tag-pill tag-{{ $product['status'] ?? 'active' }}">{{ $product['status'] ?? 'active' }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="card-footer-actions">
                            <span style="font-size: 12px; color: var(--text-muted);">ID: {{ $product['id'] }}</span>
                            <button class="btn btn-secondary" style="padding: 4px 10px; font-size: 12px;" onclick="viewJsonModal('Product Payload', {{ json_encode($product) }})">
                                <i class="fa-solid fa-code"></i> Inspect JSON
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <!-- TAB 2: ORDERS LIST -->
    <div id="tab-orders" class="tab-content">
        @if(!$ordersResult['success'])
            <div class="empty-state">
                <i class="fa-solid fa-circle-exclamation" style="color: #fb7185;"></i>
                <h2>Unable to fetch Shopify Orders</h2>
                <p>{{ $ordersResult['error'] ?? 'Please check your scopes and Shopify API access token.' }}</p>
                <button class="btn btn-primary" onclick="toggleConfigModal()">
                    <i class="fa-solid fa-plug"></i> Configure Store Credentials
                </button>
            </div>
        @elseif(empty($ordersResult['orders']))
            <div class="empty-state">
                <i class="fa-solid fa-receipt"></i>
                <h2>No Orders Found</h2>
                <p>Connected to <strong>{{ $ordersResult['shop'] }}</strong> but no orders were found in Shopify.</p>
            </div>
        @else
            <div style="margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center;">
                <p style="color: var(--text-muted); font-size: 14px;">Showing {{ count($ordersResult['orders']) }} orders live from <strong>{{ $ordersResult['shop'] }}</strong></p>
            </div>

            <div class="cards-grid">
                @foreach($ordersResult['orders'] as $order)
                    @php
                        $customerName = trim(($order['customer']['first_name'] ?? '') . ' ' . ($order['customer']['last_name'] ?? ''));
                        if(!$customerName) { $customerName = $order['email'] ?? 'Guest Customer'; }
                        $itemCount = count($order['line_items'] ?? []);
                    @endphp
                    <div class="data-card">
                        <div>
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                                <h3 style="font-size: 18px; font-weight: 800; color: #38bdf8;">
                                    {{ $order['name'] ?? ('#'.$order['order_number']) }}
                                </h3>
                                <span style="font-size: 12px; color: var(--text-muted);">
                                    {{ date('M d, Y H:i', strtotime($order['created_at'])) }}
                                </span>
                            </div>

                            <div style="margin-bottom: 14px;">
                                <div style="font-size: 14px; font-weight: 600; color: white;">
                                    <i class="fa-solid fa-user" style="color: var(--text-muted); margin-right: 6px;"></i> {{ $customerName }}
                                </div>
                                <div style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">
                                    <i class="fa-solid fa-envelope" style="margin-right: 6px;"></i> {{ $order['email'] ?: 'No email provided' }}
                                </div>
                            </div>

                            <div class="card-body-metrics">
                                <div class="metric-item">
                                    <span>Total Amount</span>
                                    <strong style="color: #34d399;">{{ $order['currency'] ?? '$' }} {{ number_format((float)($order['total_price'] ?? 0), 2) }}</strong>
                                </div>
                                <div class="metric-item">
                                    <span>Items Ordered</span>
                                    <strong>{{ $itemCount }} item(s)</strong>
                                </div>
                                <div class="metric-item">
                                    <span>Payment</span>
                                    <span class="tag-pill tag-{{ $order['financial_status'] ?? 'pending' }}">{{ $order['financial_status'] ?? 'pending' }}</span>
                                </div>
                                <div class="metric-item">
                                    <span>Fulfillment</span>
                                    <span class="tag-pill tag-{{ $order['fulfillment_status'] ?: 'unfulfilled' }}">{{ $order['fulfillment_status'] ?: 'unfulfilled' }}</span>
                                </div>
                            </div>

                            <!-- Line items preview -->
                            <div style="font-size: 12px; background: rgba(15, 23, 42, 0.4); padding: 10px; border-radius: 8px; margin-top: 10px;">
                                <div style="color: var(--text-muted); font-weight: 600; margin-bottom: 6px;">Line Items:</div>
                                @foreach(array_slice($order['line_items'] ?? [], 0, 3) as $item)
                                    <div style="display: flex; justify-content: space-between; align-items: center; color: #cbd5e1; margin-bottom: 4px;">
                                        <div>
                                            <span>&bull; {{ $item['name'] }}</span>
                                            @if(!empty($item['is_installable']))
                                                <span style="display: inline-block; background: rgba(56, 189, 248, 0.2); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.4); border-radius: 4px; padding: 1px 6px; font-size: 10px; margin-left: 6px;">
                                                    <i class="fa-solid fa-wrench"></i> {{ $item['installation_level'] ?: ($item['installation_type'] ?: 'Installable') }}
                                                </span>
                                            @endif
                                        </div>
                                        <strong>x{{ $item['quantity'] }}</strong>
                                    </div>
                                @endforeach
                                @if(count($order['line_items'] ?? []) > 3)
                                    <div style="color: var(--text-muted); font-style: italic; margin-top: 4px;">+ {{ count($order['line_items']) - 3 }} more item(s)...</div>
                                @endif
                            </div>
                        </div>

                        <div class="card-footer-actions">
                            <span style="font-size: 12px; color: var(--text-muted);">ID: {{ $order['id'] }}</span>
                            <button class="btn btn-secondary" style="padding: 4px 10px; font-size: 12px;" onclick="viewJsonModal('Order Payload', {{ json_encode($order) }})">
                                <i class="fa-solid fa-code"></i> Inspect JSON
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <!-- TAB 3: API JSON INSPECTOR -->
    <div id="tab-api-debug" class="tab-content">
        <div class="connection-card">
            <h2 style="font-size: 18px; color: white; margin-bottom: 14px;">
                <i class="fa-solid fa-code-compare"></i> Live REST API Endpoint Responses
            </h2>
            <p style="color: var(--text-muted); font-size: 14px; margin-bottom: 20px;">
                These API routes can be called by your frontend application to fetch Shopify data directly.
            </p>

            <div style="display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap;">
                <a href="{{ url('/api/shopify/status') }}" target="_blank" class="btn btn-secondary">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i> GET /api/shopify/status
                </a>
                <a href="{{ url('/api/shopify/products') }}" target="_blank" class="btn btn-secondary">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i> GET /api/shopify/products
                </a>
                <a href="{{ url('/api/shopify/orders') }}" target="_blank" class="btn btn-secondary">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i> GET /api/shopify/orders
                </a>
            </div>

            <h3 style="font-size: 15px; color: white; margin-bottom: 10px;">Raw Products API JSON Payload</h3>
            <pre class="json-viewer">{{ json_encode($productsResult, JSON_PRETTY_PRINT) }}</pre>

            <h3 style="font-size: 15px; color: white; margin-top: 24px; margin-bottom: 10px;">Raw Orders API JSON Payload</h3>
            <pre class="json-viewer">{{ json_encode($ordersResult, JSON_PRETTY_PRINT) }}</pre>
        </div>
    </div>

    <!-- TAB 4: SYNC & WEBHOOK LOGS / ERROR TRACKER -->
    <div id="tab-sync-logs" class="tab-content">
        <!-- Webhook URLs Configuration Guide -->
        <div class="card" style="margin-bottom: 20px; background: rgba(30, 41, 59, 0.5); border-left: 4px solid var(--accent-blue);">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 12px; margin-bottom: 14px;">
                <div>
                    <h3 style="font-size: 16px; color: white; display: flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-satellite-dish" style="color: var(--accent-blue);"></i> Shopify Webhook Endpoints
                    </h3>
                    <p style="font-size: 13px; color: var(--text-muted); margin-top: 4px;">
                        Configure these URLs in Shopify Admin &rarr; <strong>Settings &rarr; Notifications &rarr; Webhooks</strong> to enable instant real-time auto-sync when orders are placed.
                    </p>
                </div>
                <button class="btn btn-secondary" onclick="registerWebhooksDirectly(this)" style="font-size: 12px; padding: 6px 14px;">
                    <i class="fa-solid fa-arrows-rotate"></i> Auto-Register Webhooks via API
                </button>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 12px;">
                <div style="background: rgba(15, 23, 42, 0.6); padding: 12px 16px; border-radius: 8px; border: 1px solid var(--border-color);">
                    <div style="font-size: 11px; color: var(--accent-emerald); font-weight: 700; text-transform: uppercase;">Topic: Order Creation (orders/create)</div>
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-top: 6px; gap: 8px;">
                        <code style="font-size: 12px; color: #e2e8f0; word-break: break-all;">{{ $webhookUrls['orders_create'] ?? url('/api/shopify/webhooks/orders-create') }}</code>
                        <button class="btn btn-secondary" style="padding: 4px 8px; font-size: 11px;" onclick="copyToClipboard('{{ $webhookUrls['orders_create'] ?? url('/api/shopify/webhooks/orders-create') }}', this)">
                            <i class="fa-regular fa-copy"></i>
                        </button>
                    </div>
                </div>

                <div style="background: rgba(15, 23, 42, 0.6); padding: 12px 16px; border-radius: 8px; border: 1px solid var(--border-color);">
                    <div style="font-size: 11px; color: var(--accent-blue); font-weight: 700; text-transform: uppercase;">Topic: Order Updated (orders/updated)</div>
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-top: 6px; gap: 8px;">
                        <code style="font-size: 12px; color: #e2e8f0; word-break: break-all;">{{ $webhookUrls['orders_update'] ?? url('/api/shopify/webhooks/orders-update') }}</code>
                        <button class="btn btn-secondary" style="padding: 4px 8px; font-size: 11px;" onclick="copyToClipboard('{{ $webhookUrls['orders_update'] ?? url('/api/shopify/webhooks/orders-update') }}', this)">
                            <i class="fa-regular fa-copy"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sync Logs Summary Bar -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 12px;">
            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <button class="btn btn-secondary log-filter-btn active" onclick="filterLogStatus('all', this)">
                    All Logs ({{ $syncStats['total'] ?? count($syncLogs ?? []) }})
                </button>
                <button class="btn btn-secondary log-filter-btn" onclick="filterLogStatus('failed', this)" style="border-color: rgba(244, 63, 94, 0.4); color: #fb7185;">
                    <i class="fa-solid fa-triangle-exclamation"></i> Failed ({{ $syncStats['failed'] ?? 0 }})
                </button>
                <button class="btn btn-secondary log-filter-btn" onclick="filterLogStatus('success', this)" style="border-color: rgba(16, 185, 129, 0.4); color: #34d399;">
                    <i class="fa-solid fa-circle-check"></i> Success ({{ $syncStats['success'] ?? 0 }})
                </button>
            </div>

            <div style="display: flex; gap: 10px;">
                <button class="btn btn-secondary" onclick="refreshSyncLogs()" style="font-size: 13px;">
                    <i class="fa-solid fa-rotate-right"></i> Refresh
                </button>
            </div>
        </div>

        <!-- Logs Table Card -->
        <div class="card" style="padding: 0; overflow: hidden;">
            @if(empty($syncLogs) || count($syncLogs) === 0)
                <div class="empty-state" style="padding: 40px 20px;">
                    <i class="fa-solid fa-clock-rotate-left" style="color: var(--accent-blue);"></i>
                    <h2>No sync logs recorded yet</h2>
                    <p>When Shopify sends webhooks upon placing orders or when cron sync runs, full diagnostic logs and error traces will be tracked here.</p>
                </div>
            @else
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 13px;">
                        <thead>
                            <tr style="border-bottom: 1px solid var(--border-color); background: rgba(15, 23, 42, 0.4); color: var(--text-muted);">
                                <th style="padding: 14px 18px; font-weight: 600;">Status</th>
                                <th style="padding: 14px 18px; font-weight: 600;">Event / Topic</th>
                                <th style="padding: 14px 18px; font-weight: 600;">Order Identifier</th>
                                <th style="padding: 14px 18px; font-weight: 600;">Items / Duration</th>
                                <th style="padding: 14px 18px; font-weight: 600;">Error / Info</th>
                                <th style="padding: 14px 18px; font-weight: 600;">Timestamp</th>
                                <th style="padding: 14px 18px; font-weight: 600; text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="syncLogsTableBody">
                            @foreach($syncLogs as $log)
                                <tr class="sync-log-row status-{{ $log->status }}" style="border-bottom: 1px solid rgba(51, 65, 85, 0.4); transition: background 0.15s;" onmouseover="this.style.background='rgba(51, 65, 85, 0.2)'" onmouseout="this.style.background='transparent'">
                                    <td style="padding: 12px 18px;">
                                        @if($log->status === 'success')
                                            <span style="display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 9999px; font-size: 11px; font-weight: 700; background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3);">
                                                <i class="fa-solid fa-check"></i> SUCCESS
                                            </span>
                                        @elseif($log->status === 'failed')
                                            <span style="display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 9999px; font-size: 11px; font-weight: 700; background: rgba(244, 63, 94, 0.15); color: #fb7185; border: 1px solid rgba(244, 63, 94, 0.3);">
                                                <i class="fa-solid fa-triangle-exclamation"></i> FAILED
                                            </span>
                                        @else
                                            <span style="display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 9999px; font-size: 11px; font-weight: 700; background: rgba(148, 163, 184, 0.15); color: #94a3b8; border: 1px solid rgba(148, 163, 184, 0.3);">
                                                {{ strtoupper($log->status) }}
                                            </span>
                                        @endif
                                    </td>
                                    <td style="padding: 12px 18px;">
                                        <div style="font-weight: 600; color: white;">{{ $log->topic ?? $log->event_type }}</div>
                                        <div style="font-size: 11px; color: var(--text-muted); font-family: monospace;">{{ $log->event_type }}</div>
                                    </td>
                                    <td style="padding: 12px 18px;">
                                        <div style="font-weight: 700; color: var(--accent-emerald);">
                                            {{ $log->order_number ?? ($log->shopify_order_id ? '#' . $log->shopify_order_id : 'N/A') }}
                                        </div>
                                        @if($log->local_order_id)
                                            <div style="font-size: 11px; color: var(--text-muted);">Local DB ID: #{{ $log->local_order_id }}</div>
                                        @endif
                                    </td>
                                    <td style="padding: 12px 18px; color: var(--text-muted); font-size: 12px;">
                                        <div>{{ $log->items_count }} items</div>
                                        <div>{{ $log->duration_ms }} ms</div>
                                    </td>
                                    <td style="padding: 12px 18px; max-width: 320px;">
                                        @if($log->error_message)
                                            <div style="color: #fb7185; font-size: 12px; font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="{{ $log->error_message }}">
                                                {{ $log->error_message }}
                                            </div>
                                        @else
                                            <div style="color: #34d399; font-size: 12px;">Synced without errors</div>
                                        @endif
                                    </td>
                                    <td style="padding: 12px 18px; color: var(--text-muted); font-size: 12px; white-space: nowrap;">
                                        {{ $log->created_at ? $log->created_at->format('Y-m-d H:i:s') : 'N/A' }}
                                    </td>
                                    <td style="padding: 12px 18px; text-align: right; white-space: nowrap;">
                                        <button class="btn btn-secondary" style="padding: 5px 10px; font-size: 11px;" onclick='viewJsonModal("Sync Log #{{ $log->id }} ({{ $log->order_number ?? "Detail" }})", @json($log))'>
                                            <i class="fa-solid fa-magnifying-glass"></i> Payload
                                        </button>
                                        @if($log->status === 'failed' && !empty($log->payload))
                                            <button class="btn btn-primary" style="padding: 5px 10px; font-size: 11px; margin-left: 6px;" onclick="retrySyncLog({{ $log->id }}, this)">
                                                <i class="fa-solid fa-rotate"></i> Retry
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>

<!-- Modal for Inspecting Specific Item JSON -->
<div id="jsonModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.8); backdrop-filter: blur(8px); z-index: 999; align-items: center; justify-content: center; padding: 20px;">
    <div style="background: var(--card-bg); border: 1px solid var(--border-color); width: 100%; max-width: 800px; border-radius: 16px; padding: 24px; max-height: 90vh; display: flex; flex-direction: column;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 id="modalTitle" style="font-size: 18px; color: white;">JSON Inspector</h3>
            <button onclick="closeJsonModal()" style="background: none; border: none; color: var(--text-muted); font-size: 20px; cursor: pointer;">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <pre id="modalJsonContent" class="json-viewer" style="flex: 1;"></pre>
    </div>
</div>

<script>
    function switchTab(tabName) {
        document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));

        event.currentTarget.classList.add('active');
        document.getElementById('tab-' + tabName).classList.add('active');
    }

    function toggleConfigModal() {
        const box = document.getElementById('config-box');
        if (box.style.display === 'none') {
            box.style.display = 'block';
        } else {
            box.style.display = 'none';
        }
    }

    function viewJsonModal(title, jsonObject) {
        document.getElementById('modalTitle').innerText = title;
        document.getElementById('modalJsonContent').innerText = JSON.stringify(jsonObject, null, 2);
        document.getElementById('jsonModal').style.display = 'flex';
    }

    function closeJsonModal() {
        document.getElementById('jsonModal').style.display = 'none';
    }

    function copyToClipboard(text, btn) {
        navigator.clipboard.writeText(text).then(() => {
            const original = btn.innerHTML;
            btn.innerHTML = '<i class="fa-solid fa-check" style="color: #34d399;"></i> Copied';
            setTimeout(() => {
                btn.innerHTML = original;
            }, 2000);
        });
    }

    function filterLogStatus(status, btn) {
        document.querySelectorAll('.log-filter-btn').forEach(b => b.classList.remove('active'));
        if (btn) btn.classList.add('active');

        const rows = document.querySelectorAll('.sync-log-row');
        rows.forEach(row => {
            if (status === 'all') {
                row.style.display = '';
            } else if (row.classList.contains('status-' + status)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }

    function refreshSyncLogs() {
        location.reload();
    }

    function retrySyncLog(id, btn) {
        if (!confirm('Re-process this order webhook payload now?')) return;
        
        const originalHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';

        fetch(`/api/shopify/sync-logs/${id}/retry`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            }
        })
        .then(res => res.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
            if (data.success) {
                alert(data.message || 'Order re-synced successfully!');
                location.reload();
            } else {
                alert('Retry failed: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
            alert('Request error: ' + err.message);
        });
    }

    function registerWebhooksDirectly(btn) {
        if (!confirm('Auto-register Shopify Webhooks for Order Creation, Updates, and Payments with your Shopify Store?')) return;
        
        const originalHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Registering...';

        fetch('/api/shopify/sync-orders', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            }
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
            alert('Webhook endpoints are active and listening. Ensure your store domain and token are authorized.');
        });
    }
</script>

</body>
</html>
