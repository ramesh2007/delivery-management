<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusLog;
use App\Services\ShopifyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class OrderManagementController extends Controller
{
    protected ShopifyService $shopifyService;

    public function __construct(ShopifyService $shopifyService)
    {
        $this->shopifyService = $shopifyService;
    }

    /**
     * Get list of all orders with customer details and line items for mobile app.
     * Auto-syncs with Shopify silently so mobile users don't need a manual sync endpoint.
     */
    public function index(Request $request)
    {
        // Auto-sync orders from Shopify if requested or by default
        if ($request->boolean('auto_sync', true)) {
            try {
                $this->shopifyService->syncOrdersToDatabase();
            } catch (\Exception $e) {
                // Continue using local DB if sync fails
            }
        }

        $query = Order::with([
            'items.assignedUser',
            'items.pickedUser',
            'items.packedUser',
            'items.deliveredUser',
            'assignedUser',
            'pickedUser',
            'packedUser',
            'deliveredUser'
        ]);

        // Optional status filter
        if ($request->has('status') && !empty($request->query('status'))) {
            $query->where('status', $request->query('status'));
        }

        // Optional assigned picker filter
        if ($request->has('assigned_to') && !empty($request->query('assigned_to'))) {
            $query->where('assigned_to', $request->query('assigned_to'));
        } elseif ($request->boolean('unassigned')) {
            $query->whereNull('assigned_to');
        }

        if ($request->has('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%")
                  ->orWhere('customer_phone', 'like', "%{$search}%")
                  ->orWhere('assigned_user_name', 'like', "%{$search}%");
            });
        }

        $orders = $query->orderBy('created_at', 'desc')->get();

        // Format response structure optimized for mobile list view
        $formattedOrders = $orders->map(function ($order) {
            $totalItems = $order->items->count();
            $pickedItems = $order->items->where('status', 'picked')->count();
            $packedItems = $order->items->where('status', 'packed')->count();
            $deliveredItems = $order->items->where('status', 'delivered')->count();

            // Collect unique pickers and packers involved across all line items
            $pickers = $order->items->map(function ($item) {
                if ($item->picked_by || $item->picked_user_name) {
                    return [
                        'id' => $item->picked_by ? (int) $item->picked_by : null,
                        'name' => $item->pickedUser ? $item->pickedUser->name : ($item->picked_user_name ?? 'Picker User'),
                        'picked_at' => $item->picked_at ? $item->picked_at->toIso8601String() : null,
                    ];
                }
                return null;
            })->filter()->unique('name')->values();

            $packers = $order->items->map(function ($item) {
                if ($item->packed_by || $item->packed_user_name) {
                    return [
                        'id' => $item->packed_by ? (int) $item->packed_by : null,
                        'name' => $item->packedUser ? $item->packedUser->name : ($item->packed_user_name ?? 'Packer User'),
                        'packed_at' => $item->packed_at ? $item->packed_at->toIso8601String() : null,
                    ];
                }
                return null;
            })->filter()->unique('name')->values();

            return [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'assigned_user' => $order->assigned_to ? [
                    'id' => (int) $order->assigned_to,
                    'name' => $order->assignedUser ? $order->assignedUser->name : ($order->assigned_user_name ?? 'Picker User'),
                    'assigned_at' => $order->assigned_at ? $order->assigned_at->toIso8601String() : null,
                ] : null,
                'driver_user' => ($order->delivered_by || $order->delivered_user_name) ? [
                    'id' => $order->delivered_by ? (int) $order->delivered_by : null,
                    'name' => $order->deliveredUser ? $order->deliveredUser->name : ($order->delivered_user_name ?? 'Driver User'),
                    'delivered_at' => $order->delivered_at ? $order->delivered_at->toIso8601String() : null,
                ] : null,
                'pickers' => $pickers,
                'packers' => $packers,
                'customer' => [
                    'name' => $order->customer_name ?? 'N/A',
                    'phone' => $order->customer_phone ?? 'N/A',
                    'delivery_address' => $order->delivery_address ?? 'N/A',
                ],
                'summary' => [
                    'total_amount' => (float) $order->total_amount,
                    'total_items' => $totalItems,
                    'picked_items' => $pickedItems,
                    'packed_items' => $packedItems,
                    'delivered_items' => $deliveredItems,
                ],
                'items' => $order->items->map(function ($item) {
                    return [
                        'item_id' => $item->id,
                        'line_item_id' => $item->line_item_id,
                        'product_id' => $item->product_id,
                        'product_code' => $item->product_code,
                        'barcode' => $item->barcode,
                        'product_name' => $item->product_name,
                        'quantity' => $item->quantity,
                        'unit_price' => (float) $item->unit_price,
                        'status' => $item->status,
                        'assigned_user' => $item->assigned_to ? [
                            'id' => (int) $item->assigned_to,
                            'name' => $item->assignedUser ? $item->assignedUser->name : ($item->assigned_user_name ?? 'Picker User'),
                            'assigned_at' => $item->assigned_at ? $item->assigned_at->toIso8601String() : null,
                        ] : null,
                        'picked_user' => ($item->picked_by || $item->picked_user_name) ? [
                            'id' => $item->picked_by ? (int) $item->picked_by : null,
                            'name' => $item->pickedUser ? $item->pickedUser->name : ($item->picked_user_name ?? 'Picker User'),
                            'picked_at' => $item->picked_at ? $item->picked_at->toIso8601String() : null,
                        ] : null,
                        'packed_user' => ($item->packed_by || $item->packed_user_name) ? [
                            'id' => $item->packed_by ? (int) $item->packed_by : null,
                            'name' => $item->packedUser ? $item->packedUser->name : ($item->packed_user_name ?? 'Packer User'),
                            'packed_at' => $item->packed_at ? $item->packed_at->toIso8601String() : null,
                        ] : null,
                        'delivered_user' => ($item->delivered_by || $item->delivered_user_name) ? [
                            'id' => $item->delivered_by ? (int) $item->delivered_by : null,
                            'name' => $item->deliveredUser ? $item->deliveredUser->name : ($item->delivered_user_name ?? 'Driver User'),
                            'delivered_at' => $item->delivered_at ? $item->delivered_at->toIso8601String() : null,
                        ] : null,
                        'picked_at' => $item->picked_at ? $item->picked_at->toIso8601String() : null,
                        'packed_at' => $item->packed_at ? $item->packed_at->toIso8601String() : null,
                        'delivered_at' => $item->delivered_at ? $item->delivered_at->toIso8601String() : null,
                    ];
                }),
                'created_at' => $order->created_at->toIso8601String(),
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Orders retrieved successfully.',
            'count' => $formattedOrders->count(),
            'data' => $formattedOrders,
        ]);
    }

    /**
     * Get single order details with customer details and line items for mobile item selection
     */
    public function show($id)
    {
        $order = Order::with([
            'items.assignedUser',
            'items.pickedUser',
            'items.packedUser',
            'items.deliveredUser',
            'assignedUser',
            'deliveredUser',
            'logs.user'
        ])->where('id', $id)->orWhere('order_number', $id)->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found.',
            ], 404);
        }

        $totalItems = $order->items->count();
        $pickedItems = $order->items->where('status', 'picked')->count();
        $packedItems = $order->items->where('status', 'packed')->count();
        $deliveredItems = $order->items->where('status', 'delivered')->count();

        // Collect unique pickers and packers involved across all line items
        $pickers = $order->items->map(function ($item) {
            if ($item->picked_by || $item->picked_user_name) {
                return [
                    'id' => $item->picked_by ? (int) $item->picked_by : null,
                    'name' => $item->pickedUser ? $item->pickedUser->name : ($item->picked_user_name ?? 'Picker User'),
                    'picked_at' => $item->picked_at ? $item->picked_at->toIso8601String() : null,
                ];
            }
            return null;
        })->filter()->unique('name')->values();

        $packers = $order->items->map(function ($item) {
            if ($item->packed_by || $item->packed_user_name) {
                return [
                    'id' => $item->packed_by ? (int) $item->packed_by : null,
                    'name' => $item->packedUser ? $item->packedUser->name : ($item->packed_user_name ?? 'Packer User'),
                    'packed_at' => $item->packed_at ? $item->packed_at->toIso8601String() : null,
                ];
            }
            return null;
        })->filter()->unique('name')->values();

        return response()->json([
            'success' => true,
            'data' => [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'assigned_user' => $order->assigned_to ? [
                    'id' => (int) $order->assigned_to,
                    'name' => $order->assignedUser ? $order->assignedUser->name : ($order->assigned_user_name ?? 'Picker User'),
                    'assigned_at' => $order->assigned_at ? $order->assigned_at->toIso8601String() : null,
                ] : null,
                'driver_user' => ($order->delivered_by || $order->delivered_user_name) ? [
                    'id' => $order->delivered_by ? (int) $order->delivered_by : null,
                    'name' => $order->deliveredUser ? $order->deliveredUser->name : ($order->delivered_user_name ?? 'Driver User'),
                    'delivered_at' => $order->delivered_at ? $order->delivered_at->toIso8601String() : null,
                ] : null,
                'pickers' => $pickers,
                'packers' => $packers,
                'customer' => [
                    'name' => $order->customer_name ?? 'N/A',
                    'phone' => $order->customer_phone ?? 'N/A',
                    'delivery_address' => $order->delivery_address ?? 'N/A',
                ],
                'summary' => [
                    'total_amount' => (float) $order->total_amount,
                    'total_items' => $totalItems,
                    'picked_items' => $pickedItems,
                    'packed_items' => $packedItems,
                    'delivered_items' => $deliveredItems,
                ],
                'items' => $order->items->map(function ($item) {
                    return [
                        'item_id' => $item->id,
                        'line_item_id' => $item->line_item_id,
                        'product_id' => $item->product_id,
                        'product_code' => $item->product_code,
                        'barcode' => $item->barcode,
                        'product_name' => $item->product_name,
                        'quantity' => $item->quantity,
                        'unit_price' => (float) $item->unit_price,
                        'status' => $item->status,
                        'assigned_user' => $item->assigned_to ? [
                            'id' => (int) $item->assigned_to,
                            'name' => $item->assignedUser ? $item->assignedUser->name : ($item->assigned_user_name ?? 'Picker User'),
                            'assigned_at' => $item->assigned_at ? $item->assigned_at->toIso8601String() : null,
                        ] : null,
                        'picked_user' => ($item->picked_by || $item->picked_user_name) ? [
                            'id' => $item->picked_by ? (int) $item->picked_by : null,
                            'name' => $item->pickedUser ? $item->pickedUser->name : ($item->picked_user_name ?? 'Picker User'),
                            'picked_at' => $item->picked_at ? $item->picked_at->toIso8601String() : null,
                        ] : null,
                        'packed_user' => ($item->packed_by || $item->packed_user_name) ? [
                            'id' => $item->packed_by ? (int) $item->packed_by : null,
                            'name' => $item->packedUser ? $item->packedUser->name : ($item->packed_user_name ?? 'Packer User'),
                            'packed_at' => $item->packed_at ? $item->packed_at->toIso8601String() : null,
                        ] : null,
                        'delivered_user' => ($item->delivered_by || $item->delivered_user_name) ? [
                            'id' => $item->delivered_by ? (int) $item->delivered_by : null,
                            'name' => $item->deliveredUser ? $item->deliveredUser->name : ($item->delivered_user_name ?? 'Driver User'),
                            'delivered_at' => $item->delivered_at ? $item->delivered_at->toIso8601String() : null,
                        ] : null,
                        'picked_at' => $item->picked_at ? $item->picked_at->toIso8601String() : null,
                        'packed_at' => $item->packed_at ? $item->packed_at->toIso8601String() : null,
                        'delivered_at' => $item->delivered_at ? $item->delivered_at->toIso8601String() : null,
                    ];
                }),
                'logs' => $order->logs,
                'created_at' => $order->created_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * Sync orders from Shopify into database (orders & order_items) with 'pending' status
     */
    public function syncShopify(Request $request)
    {
        $shop = $request->input('shop');
        $accessToken = $request->input('access_token');

        $result = $this->shopifyService->syncOrdersToDatabase($shop, $accessToken);

        if (!$result['success']) {
            return response()->json($result, 400);
        }

        return response()->json($result);
    }

    /**
     * Assign entire order and its items to picker ("Assign Me Order")
     * Route: POST /api/orders/assign-me
     */
    public function assignOrder(Request $request)
    {
        if (!$request->filled('order_id') && ($request->filled('order_item_id') || $request->filled('order_item_ids') || $request->filled('line_item_id') || $request->filled('line_item_ids'))) {
            return $this->assignItems($request);
        }

        $validator = Validator::make($request->all(), [
            'order_id' => 'required',
            'user_id' => 'nullable',
            'user_name' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        // Determine logged in or specified user
        $user = Auth::user();
        $userId = $user ? $user->id : $request->input('user_id');

        $dbUser = null;
        if ($userId) {
            $dbUser = \App\Models\User::find($userId);
        }

        $userName = $user ? $user->name : ($dbUser ? $dbUser->name : ($request->input('user_name') ?? 'Picker User'));

        if (!$userId && !$userName) {
            return response()->json([
                'success' => false,
                'message' => 'User identification required. Pass user_id, user_name, or authenticate with Sanctum.',
            ], 422);
        }

        return DB::transaction(function () use ($request, $userId, $userName) {
            $orderIdStr = (string) $request->input('order_id');

            // Find order by ID, order_number (e.g. 7016676425972), or #1001
            $order = Order::with('items')
                ->where('id', $orderIdStr)
                ->orWhere('order_number', $orderIdStr)
                ->orWhere('order_number', ltrim($orderIdStr, '#'))
                ->orWhere('order_number', '#' . ltrim($orderIdStr, '#'))
                ->first();

            // Auto-sync from Shopify if order is not yet in local database
            if (!$order) {
                try {
                    $this->shopifyService->syncOrdersToDatabase();
                    $order = Order::with('items')
                        ->where('id', $orderIdStr)
                        ->orWhere('order_number', $orderIdStr)
                        ->orWhere('order_number', ltrim($orderIdStr, '#'))
                        ->orWhere('order_number', '#' . ltrim($orderIdStr, '#'))
                        ->first();
                } catch (\Exception $e) {
                    // Ignore sync exception if offline
                }
            }

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => "Order '{$orderIdStr}' not found in database or Shopify.",
                ], 404);
            }

            $order->update([
                'assigned_to' => $userId,
                'assigned_user_name' => $userName,
                'assigned_at' => now(),
            ]);

            if ($order->status === 'pending') {
                $order->update(['status' => 'picking']);
            }

            foreach ($order->items as $item) {
                $item->update([
                    'assigned_to' => $userId,
                    'assigned_user_name' => $userName,
                    'assigned_at' => now(),
                ]);
            }

            $log = OrderStatusLog::create([
                'order_id' => $order->id,
                'user_id' => $userId,
                'user_name' => $userName,
                'action' => 'order_assigned_to_picker',
                'old_status' => $order->status,
                'new_status' => $order->status,
                'notes' => $request->input('notes', "Assigned entire order {$order->order_number} to picker {$userName}"),
            ]);

            return response()->json([
                'success' => true,
                'message' => "Order {$order->order_number} successfully assigned to picker {$userName}.",
                'data' => [
                    'assigned_user' => [
                        'id' => $userId,
                        'name' => $userName,
                    ],
                    'order' => $order->fresh(['items']),
                    'log' => $log,
                ],
            ]);
        });
    }

    /**
     * Assign specific order item(s) to picker ("Assign Me Items")
     * Route: POST /api/orders/items/assign-me
     */
    public function assignItems(Request $request)
    {
        /*
        |--------------------------------------------------------------------------
        | Validate Request
        |--------------------------------------------------------------------------
        |
        | Expected request:
        |
        | {
        |     "order_id": "7013524242676",
        |     "line_item_id": "123456789",
        |     "user_id": 1,
        |     "notes": "Assigning_item_to_myself"
        | }
        |
        | Multiple items can also be sent:
        |
        | "line_item_ids": ["123456789", "987654321"]
        |
        */
    
        $validator = Validator::make($request->all(), [
            'order_id' => 'required',
            'line_item_id' => 'nullable|string',
            'line_item_ids' => 'nullable|array',
            'line_item_ids.*' => 'nullable|string',
            'order_item_id' => 'nullable',
            'order_item_ids' => 'nullable|array',
            'order_item_ids.*' => 'nullable',
            'user_id' => 'nullable',
            'user_name' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }
    
        /*
        |--------------------------------------------------------------------------
        | Determine User
        |--------------------------------------------------------------------------
        */
    
        $authUser = Auth::user();
    
        $userId = $authUser
            ? $authUser->id
            : $request->input('user_id');
    
        $dbUser = null;
    
        if ($userId) {
            $dbUser = \App\Models\User::find($userId);
        }
    
        $userName = $authUser
            ? $authUser->name
            : (
                $dbUser
                    ? $dbUser->name
                    : ($request->input('user_name') ?? 'Picker User')
            );
    
        if (!$userId && !$userName) {
            return response()->json([
                'success' => false,
                'message' => 'User identification required. Pass user_id, user_name, or authenticate with Sanctum.',
            ], 422);
        }
    
        /*
        |--------------------------------------------------------------------------
        | Transaction
        |--------------------------------------------------------------------------
        */
    
        return DB::transaction(function () use ($request, $userId, $userName) {
    
            /*
            |--------------------------------------------------------------------------
            | Get Order ID
            |--------------------------------------------------------------------------
            */
    
            $orderIdStr = trim((string) $request->input('order_id'));
    
            $cleanOrderId = ltrim($orderIdStr, '#');
           
            /*
            |--------------------------------------------------------------------------
            | Get Line Item IDs
            |--------------------------------------------------------------------------
            */
    
            $lineItemIds = [];
    
            // Single line_item_id
            if ($request->filled('line_item_id')) {
                $lineItemIds[] = (string) $request->input('line_item_id');
            }
    
            // Multiple line_item_ids
            if ($request->filled('line_item_ids')) {
                foreach ((array) $request->input('line_item_ids') as $lineItemId) {
                    if ($lineItemId !== null && $lineItemId !== '') {
                        $lineItemIds[] = (string) $lineItemId;
                    }
                }
            }
    
            /*
            |--------------------------------------------------------------------------
            | Support Local Order Item IDs Too
            |--------------------------------------------------------------------------
            */
    
            $orderItemIds = [];
    
            if ($request->filled('order_item_id')) {
                $orderItemIds[] = $request->input('order_item_id');
            }
    
            if ($request->filled('order_item_ids')) {
                foreach ((array) $request->input('order_item_ids') as $orderItemId) {
                    if ($orderItemId !== null && $orderItemId !== '') {
                        $orderItemIds[] = $orderItemId;
                    }
                }
            }
    
            /*
            |--------------------------------------------------------------------------
            | Require Item Identification
            |--------------------------------------------------------------------------
            */
    
            if (empty($lineItemIds) && empty($orderItemIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please provide line_item_id, line_item_ids, order_item_id, or order_item_ids.',
                ], 422);
            }
    
            /*
            |--------------------------------------------------------------------------
            | Find Order
            |--------------------------------------------------------------------------
            */
    
            $order = Order::with('items')
                ->where(function ($query) use ($orderIdStr, $cleanOrderId) {
    
                    $query->where('id', $orderIdStr)
                        ->orWhere('order_number', $orderIdStr)
                        ->orWhere('order_number', $cleanOrderId)
                        ->orWhere('order_number', '#' . $cleanOrderId);
    
                })
                ->first();
           
            /*
            |--------------------------------------------------------------------------
            | If Order Not Found, Sync From Shopify
            |--------------------------------------------------------------------------
            */
    
            if (!$order) {
    
                try {
    
                    $this->shopifyService->syncOrdersToDatabase();
    
                    $order = Order::with('items')
                        ->where(function ($query) use ($orderIdStr, $cleanOrderId) {
    
                            $query->where('id', $orderIdStr)
                                ->orWhere('order_number', $orderIdStr)
                                ->orWhere('order_number', $cleanOrderId)
                                ->orWhere('order_number', '#' . $cleanOrderId);
    
                        })
                        ->first();
    
                } catch (\Exception $e) {
    
                    \Log::error('Shopify order sync failed during item assignment', [
                        'order_id' => $orderIdStr,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
    
            /*
            |--------------------------------------------------------------------------
            | Order Not Found
            |--------------------------------------------------------------------------
            */
    
            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => "Order '{$orderIdStr}' not found.",
                ], 404);
            }
    
            /*
            |--------------------------------------------------------------------------
            | Find Items BELONGING TO THIS ORDER
            |--------------------------------------------------------------------------
            */
    
            $itemsQuery = OrderItem::where('order_id', $order->id);
            
        
            $items = $itemsQuery->get();
        
            /*
            |--------------------------------------------------------------------------
            | Items Not Found
            |--------------------------------------------------------------------------
            */
    
            if ($items->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No matching order items found for this order.',
                    'data' => [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'requested_line_item_ids' => $lineItemIds,
                        'requested_order_item_ids' => $orderItemIds,
                    ],
                ], 404);
            }
    
            /*
            |--------------------------------------------------------------------------
            | Save Old Order Status
            |--------------------------------------------------------------------------
            */
    
            $oldOrderStatus = $order->status;
    
            /*
            |--------------------------------------------------------------------------
            | Assign Items
            |--------------------------------------------------------------------------
            */
    
            $assignedItems = collect();
    
            foreach ($items as $item) {
    
                $oldItemStatus = $item->status;
    
                $item->update([
                    'assigned_to' => $userId,
                    'assigned_user_name' => $userName,
                    'assigned_at' => now(),
                ]);
    
                $assignedItems->push($item->fresh());
    
                /*
                |--------------------------------------------------------------------------
                | Create Item Assignment Log
                |--------------------------------------------------------------------------
                */
    
                OrderStatusLog::create([
                    'order_id' => $order->id,
                    'order_item_id' => $item->id,
                    'user_id' => $userId,
                    'user_name' => $userName,
                    'action' => 'item_assigned_to_picker',
                    'old_status' => $oldItemStatus,
                    'new_status' => $oldItemStatus,
                    'notes' => $request->input(
                        'notes',
                        "Assigned item {$item->product_name} to picker {$userName}"
                    ),
                ]);
            }
    
            /*
            |--------------------------------------------------------------------------
            | Assign Order If It Is Not Already Assigned
            |--------------------------------------------------------------------------
            */
    
            if (!$order->assigned_to) {
    
                $order->update([
                    'assigned_to' => $userId,
                    'assigned_user_name' => $userName,
                    'assigned_at' => now(),
                ]);
            }
    
            /*
            |--------------------------------------------------------------------------
            | Pending -> Picking
            |--------------------------------------------------------------------------
            */
    
            if ($order->status === 'pending') {
    
                $order->update([
                    'status' => 'picking',
                ]);
            }
    
            /*
            |--------------------------------------------------------------------------
            | Return Response
            |--------------------------------------------------------------------------
            */
    
            return response()->json([
                'success' => true,
                'message' => "Successfully assigned {$assignedItems->count()} item(s) to picker {$userName}.",
    
                'data' => [
    
                    'assigned_user' => [
                        'id' => $userId,
                        'name' => $userName,
                    ],
    
                    'order' => [
                        'id' => $order->id,
                        'order_number' => $order->order_number,
                        'old_status' => $oldOrderStatus,
                        'new_status' => $order->status,
                        'assigned_to' => $order->assigned_to,
                        'assigned_user_name' => $order->assigned_user_name,
                        'assigned_at' => $order->assigned_at,
                    ],
    
                    'items' => $assignedItems,
    
                ],
            ]);
        });
    }

    /**
     * Assign order or order items to current picker (Unified endpoint)
     */
    public function assignMe(Request $request)
    {
        if ($request->has('order_id') && !$request->has('order_item_id') && !$request->has('order_item_ids') && !$request->has('line_item_id') && !$request->has('line_item_ids')) {
            return $this->assignOrder($request);
        }
        return $this->assignItems($request);
    }

    /**
     * Update individual item status (picked, packed, delivered) with user log
     */
    public function updateItemStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_item_id' => 'required_without_all:line_item_id,barcode,product_code|nullable',
            'line_item_id' => 'nullable|string',
            'order_id' => 'nullable',
            'barcode' => 'nullable|string',
            'product_code' => 'nullable|string',
            'status' => 'required|in:pending,picked,packed,delivered,cancelled',
            'notes' => 'nullable|string',
            'user_name' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        return DB::transaction(function () use ($request) {
            // Find order item by ID, line_item_id, or by barcode/product_code within order_id
            $query = OrderItem::query();

            if ($request->filled('order_item_id')) {
                $query->where('id', $request->input('order_item_id'));
            } elseif ($request->filled('line_item_id')) {
                $query->where('line_item_id', $request->input('line_item_id'));
            } elseif ($request->filled('order_id')) {
                $query->where('order_id', $request->input('order_id'));
                if ($request->filled('barcode')) {
                    $query->where(function ($q) use ($request) {
                        $q->where('barcode', $request->input('barcode'))
                          ->orWhere('product_code', $request->input('barcode'))
                          ->orWhere('line_item_id', $request->input('barcode'));
                    });
                } elseif ($request->filled('product_code')) {
                    $query->where('product_code', $request->input('product_code'));
                }
            } elseif ($request->filled('barcode')) {
                $query->where('barcode', $request->input('barcode'))
                      ->orWhere('product_code', $request->input('barcode'))
                      ->orWhere('line_item_id', $request->input('barcode'));
            }

            $orderItem = $query->first();

            if (!$orderItem) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order item not found for the provided criteria.',
                ], 404);
            }

            $oldStatus = $orderItem->status;
            $newStatus = $request->input('status');

            // Identify user performing the update
            $user = Auth::user();
            $userId = $user ? $user->id : $request->input('user_id');
            $dbUser = $userId ? \App\Models\User::find($userId) : null;
            $validUserId = $dbUser ? $dbUser->id : null;
            $userName = $user ? $user->name : ($dbUser ? $dbUser->name : ($request->input('user_name') ?? 'System User'));

            // Set timestamps & user tracking based on status
            if ($newStatus === 'picked') {
                $orderItem->picked_by = $validUserId;
                $orderItem->picked_user_name = $userName;
                if (!$orderItem->picked_at) {
                    $orderItem->picked_at = now();
                }
            } elseif ($newStatus === 'packed') {
                $orderItem->packed_by = $validUserId;
                $orderItem->packed_user_name = $userName;
                if (!$orderItem->packed_at) {
                    $orderItem->packed_at = now();
                }
            } elseif ($newStatus === 'delivered') {
                $orderItem->delivered_by = $validUserId;
                $orderItem->delivered_user_name = $userName;
                if (!$orderItem->delivered_at) {
                    $orderItem->delivered_at = now();
                }
            }

            $orderItem->status = $newStatus;
            $orderItem->save();

            // Record status change log with user info
            $log = OrderStatusLog::create([
                'order_id' => $orderItem->order_id,
                'order_item_id' => $orderItem->id,
                'user_id' => $validUserId,
                'user_name' => $userName,
                'action' => 'item_status_updated',
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'notes' => $request->input('notes', "Updated item status for {$orderItem->product_name}"),
            ]);

            // Re-evaluate parent order status based on item statuses
            $order = Order::with('items')->find($orderItem->order_id);
            $allStatuses = $order->items->pluck('status');

            $newOrderStatus = $order->status;
            if ($allStatuses->every(fn($s) => $s === 'delivered')) {
                $newOrderStatus = 'delivered';
            } elseif ($allStatuses->every(fn($s) => in_array($s, ['packed', 'delivered']))) {
                $newOrderStatus = 'packed';
            } elseif ($allStatuses->every(fn($s) => in_array($s, ['picked', 'packed', 'delivered']))) {
                $newOrderStatus = 'picking';
            }

            if ($newOrderStatus !== $order->status) {
                $oldOrderStatus = $order->status;
                $order->status = $newOrderStatus;

                if ($newOrderStatus === 'delivered') {
                    $order->delivered_by = $validUserId;
                    $order->delivered_user_name = $userName;
                    $order->delivered_at = now();
                }

                $order->save();

                OrderStatusLog::create([
                    'order_id' => $order->id,
                    'user_id' => $validUserId,
                    'user_name' => $userName,
                    'action' => 'order_status_auto_updated',
                    'old_status' => $oldOrderStatus,
                    'new_status' => $newOrderStatus,
                    'notes' => "Order overall status updated to {$newOrderStatus} after item status update.",
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => "Order item status updated from '{$oldStatus}' to '{$newStatus}'.",
                'data' => [
                    'order_item' => $orderItem->fresh(),
                    'order' => $order->fresh(),
                    'log' => $log,
                ],
            ]);
        });
    }

    /**
     * Update overall order status with user log
     */
    public function updateOrderStatus(Request $request, $orderId)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:pending,picking,packed,out_for_delivery,delivered,cancelled',
            'notes' => 'nullable|string',
            'user_name' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $order = Order::find($orderId);
        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found.',
            ], 404);
        }

        $oldStatus = $order->status;
        $newStatus = $request->input('status');

        $user = Auth::user();
        $userId = $user ? $user->id : $request->input('user_id');
        $dbUser = $userId ? \App\Models\User::find($userId) : null;
        $validUserId = $dbUser ? $dbUser->id : null;
        $userName = $user ? $user->name : ($dbUser ? $dbUser->name : ($request->input('user_name') ?? 'System User'));

        if ($newStatus === 'out_for_delivery' || $newStatus === 'delivered') {
            $order->delivered_by = $validUserId;
            $order->delivered_user_name = $userName;
            $order->delivered_at = now();
        }

        $order->status = $newStatus;
        $order->save();

        $user = Auth::user();
        $userId = $user ? $user->id : $request->input('user_id');
        $userName = $user ? $user->name : ($request->input('user_name') ?? 'System User');

        $log = OrderStatusLog::create([
            'order_id' => $order->id,
            'user_id' => $validUserId,
            'user_name' => $userName,
            'action' => 'order_status_updated',
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'notes' => $request->input('notes', "Order status updated from '{$oldStatus}' to '{$newStatus}'"),
        ]);

        return response()->json([
            'success' => true,
            'message' => "Order status updated successfully.",
            'data' => [
                'order' => $order->fresh(['items']),
                'log' => $log,
            ],
        ]);
    }

    /**
     * Get user audit history logs for an order
     */
    public function getLogs($orderId)
    {
        $order = Order::find($orderId);
        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found.',
            ], 404);
        }

        $logs = OrderStatusLog::where('order_id', $order->id)
            ->with(['orderItem', 'user'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'logs' => $logs,
        ]);
    }
}
