<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemDiscrepancy;
use App\Models\OrderPackerAssigned;
use App\Models\OrderStatusLog;
use App\Models\PackerVerification;
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
    // public function index(Request $request)
    // {
    //     // Auto-sync orders from Shopify if requested or by default
    //     if ($request->boolean('auto_sync', true)) {
    //         try {
    //             $this->shopifyService->syncOrdersToDatabase();
    //         } catch (\Exception $e) {
    //             // Continue using local DB if sync fails
    //         }
    //     }

    //     $query = Order::with([
    //         'items.assignedUser',
    //         'items.pickedUser',
    //         'items.packedUser',
    //         'items.deliveredUser',
    //         'assignedUser',
    //         'pickedUser',
    //         'packedUser',
    //         'deliveredUser'
    //     ]);

    //     // Optional status filter
    //     if ($request->has('status') && !empty($request->query('status'))) {
    //         $query->where('status', $request->query('status'));
    //     }

    //     // Optional assigned picker filter
    //     if ($request->has('assigned_to') && !empty($request->query('assigned_to'))) {
    //         $query->where('assigned_to', $request->query('assigned_to'));
    //     } elseif ($request->boolean('unassigned')) {
    //         $query->whereNull('assigned_to');
    //     }

    //     if ($request->has('search')) {
    //         $search = $request->query('search');
    //         $query->where(function ($q) use ($search) {
    //             $q->where('order_number', 'like', "%{$search}%")
    //               ->orWhere('customer_name', 'like', "%{$search}%")
    //               ->orWhere('customer_phone', 'like', "%{$search}%")
    //               ->orWhere('assigned_user_name', 'like', "%{$search}%");
    //         });
    //     }

    //     $orders = $query->orderBy('created_at', 'desc')->get();

    //     // Format response structure optimized for mobile list view
    //     $formattedOrders = $orders->map(function ($order) {
    //         $totalItems = $order->items->count();
    //         $pickedItems = $order->items->where('status', 'picked')->count();
    //         $packedItems = $order->items->where('status', 'packed')->count();
    //         $deliveredItems = $order->items->where('status', 'delivered')->count();

    //         // Collect unique pickers and packers involved across all line items
    //         $pickers = $order->items->map(function ($item) {
    //             if ($item->picked_by || $item->picked_user_name) {
    //                 return [
    //                     'id' => $item->picked_by ? (int) $item->picked_by : null,
    //                     'name' => $item->pickedUser ? $item->pickedUser->name : ($item->picked_user_name ?? 'Picker User'),
    //                     'picked_at' => $item->picked_at ? $item->picked_at->toIso8601String() : null,
    //                 ];
    //             }
    //             return null;
    //         })->filter()->unique('name')->values();

    //         $packers = $order->items->map(function ($item) {
    //             if ($item->packed_by || $item->packed_user_name) {
    //                 return [
    //                     'id' => $item->packed_by ? (int) $item->packed_by : null,
    //                     'name' => $item->packedUser ? $item->packedUser->name : ($item->packed_user_name ?? 'Packer User'),
    //                     'packed_at' => $item->packed_at ? $item->packed_at->toIso8601String() : null,
    //                 ];
    //             }
    //             return null;
    //         })->filter()->unique('name')->values();

    //         return [
    //             'order_id' => $order->id,
    //             'order_number' => $order->order_number,
    //             'status' => $order->status,
    //             'bag_count' => (int) ($order->bag_count ?? 0),
    //             'assigned_user' => $order->assigned_to ? [
    //                 'id' => (int) $order->assigned_to,
    //                 'name' => $order->assignedUser ? $order->assignedUser->name : ($order->assigned_user_name ?? 'Picker User'),
    //                 'assigned_at' => $order->assigned_at ? $order->assigned_at->toIso8601String() : null,
    //             ] : null,
    //             'driver_user' => ($order->delivered_by || $order->delivered_user_name) ? [
    //                 'id' => $order->delivered_by ? (int) $order->delivered_by : null,
    //                 'name' => $order->deliveredUser ? $order->deliveredUser->name : ($order->delivered_user_name ?? 'Driver User'),
    //                 'delivered_at' => $order->delivered_at ? $order->delivered_at->toIso8601String() : null,
    //             ] : null,
    //             'pickers' => $pickers,
    //             'packers' => $packers,
    //             'customer' => [
    //                 'name' => $order->customer_name ?? 'N/A',
    //                 'phone' => $order->customer_phone ?? 'N/A',
    //                 'delivery_address' => $order->delivery_address ?? 'N/A',
    //             ],
    //             'summary' => [
    //                 'total_amount' => (float) $order->total_amount,
    //                 'total_items' => $totalItems,
    //                 'picked_items' => $pickedItems,
    //                 'packed_items' => $packedItems,
    //                 'delivered_items' => $deliveredItems,
    //             ],
    //             'items' => $order->items->map(function ($item) {
    //                 return [
    //                     'item_id' => $item->id,
    //                     'line_item_id' => $item->line_item_id,
    //                     'product_id' => $item->product_id,
    //                     'product_code' => $item->product_code,
    //                     'barcode' => $item->barcode,
    //                     'product_name' => $item->product_name,
    //                     'quantity' => $item->quantity,
    //                     'unit_price' => (float) $item->unit_price,
    //                     'status' => $item->status,
    //                     'is_packer_verified' => (bool) $item->is_packer_verified,
    //                     'packer_verified_user' => ($item->packer_verified_by || $item->packer_verified_user_name) ? [
    //                         'id' => $item->packer_verified_by ? (int) $item->packer_verified_by : null,
    //                         'name' => $item->packerVerifiedUser ? $item->packerVerifiedUser->name : ($item->packer_verified_user_name ?? 'Packer User'),
    //                         'verified_at' => $item->packer_verified_at ? $item->packer_verified_at->toIso8601String() : null,
    //                     ] : null,
    //                     'assigned_user' => $item->assigned_to ? [
    //                         'id' => (int) $item->assigned_to,
    //                         'name' => $item->assignedUser ? $item->assignedUser->name : ($item->assigned_user_name ?? 'Picker User'),
    //                         'assigned_at' => $item->assigned_at ? $item->assigned_at->toIso8601String() : null,
    //                     ] : null,
    //                     'picked_user' => ($item->picked_by || $item->picked_user_name) ? [
    //                         'id' => $item->picked_by ? (int) $item->picked_by : null,
    //                         'name' => $item->pickedUser ? $item->pickedUser->name : ($item->picked_user_name ?? 'Picker User'),
    //                         'picked_at' => $item->picked_at ? $item->picked_at->toIso8601String() : null,
    //                     ] : null,
    //                     'packed_user' => ($item->packed_by || $item->packed_user_name) ? [
    //                         'id' => $item->packed_by ? (int) $item->packed_by : null,
    //                         'name' => $item->packedUser ? $item->packedUser->name : ($item->packed_user_name ?? 'Packer User'),
    //                         'packed_at' => $item->packed_at ? $item->packed_at->toIso8601String() : null,
    //                     ] : null,
    //                     'delivered_user' => ($item->delivered_by || $item->delivered_user_name) ? [
    //                         'id' => $item->delivered_by ? (int) $item->delivered_by : null,
    //                         'name' => $item->deliveredUser ? $item->deliveredUser->name : ($item->delivered_user_name ?? 'Driver User'),
    //                         'delivered_at' => $item->delivered_at ? $item->delivered_at->toIso8601String() : null,
    //                     ] : null,
    //                     'picked_at' => $item->picked_at ? $item->picked_at->toIso8601String() : null,
    //                     'packed_at' => $item->packed_at ? $item->packed_at->toIso8601String() : null,
    //                     'delivered_at' => $item->delivered_at ? $item->delivered_at->toIso8601String() : null,
    //                 ];
    //             }),
    //             'created_at' => $order->created_at->toIso8601String(),
    //         ];
    //     });

    //     return response()->json([
    //         'success' => true,
    //         'message' => 'Orders retrieved successfully.',
    //         'count' => $formattedOrders->count(),
    //         'data' => $formattedOrders,
    //     ]);
    // }
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
            /*
            |--------------------------------------------------------------------------
            | Load only pending + unassigned items
            |--------------------------------------------------------------------------
            */
            'items' => function ($q) {
                $q->where('status', 'pending')
                  ->whereNull('assigned_to');
            },
    
            'items.assignedUser',
            'items.pickedUser',
            'items.packedUser',
            'items.deliveredUser',
            'items.packerVerifiedUser',
    
            'assignedUser',
            'pickedUser',
            'packedUser',
            'deliveredUser',
        ]);
    
        // Optional order status filter
        if ($request->has('status') && !empty($request->query('status'))) {
            $query->where('status', $request->query('status'));
        }
    
        /*
        |--------------------------------------------------------------------------
        | Optional assigned picker filter
        |--------------------------------------------------------------------------
        |
        | If assigned_to is passed, use the original order-level filtering.
        |
        */
        if ($request->has('assigned_to') && !empty($request->query('assigned_to'))) {
    
            $assignedTo = $request->query('assigned_to');
    
            $query->whereHas('items', function ($q) use ($assignedTo) {
                $q->where('assigned_to', $assignedTo)
                  ->where('status', 'pending');
            });
    
            /*
            |--------------------------------------------------------------------------
            | Load only pending items assigned to this user
            |--------------------------------------------------------------------------
            */
            $query->with([
                'items' => function ($q) use ($assignedTo) {
                    $q->where('assigned_to', $assignedTo)
                      ->where('status', 'pending');
                },
                'items.assignedUser',
                'items.pickedUser',
                'items.packedUser',
                'items.deliveredUser',
                'items.packerVerifiedUser',
            ]);
    
        } elseif ($request->boolean('unassigned')) {
    
            /*
            |--------------------------------------------------------------------------
            | Explicit unassigned filter
            |--------------------------------------------------------------------------
            |
            | Only orders having pending + unassigned items
            |
            */
            $query->whereHas('items', function ($q) {
                $q->whereNull('assigned_to')
                  ->where('status', 'pending');
            });
    
        } else {
    
            /*
            |--------------------------------------------------------------------------
            | DEFAULT:
            | Return orders having pending + unassigned items
            |--------------------------------------------------------------------------
            */
            $query->whereHas('items', function ($q) {
                $q->whereNull('assigned_to')
                  ->where('status', 'pending');
            });
        }
    
        // Search
        if ($request->has('search')) {
    
            $search = $request->query('search');
    
            $query->where(function ($q) use ($search) {
    
                $q->where('order_number', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%")
                  ->orWhere('customer_phone', 'like', "%{$search}%")
                  ->orWhere('assigned_user_name', 'like', "%{$search}%");
    
            });
        }
    
        $orders = $query
            ->orderBy('created_at', 'desc')
            ->get();
    
    
        // Format response
        $formattedOrders = $orders
            ->map(function ($order) {
    
                /*
                |--------------------------------------------------------------------------
                | Safety filter
                |--------------------------------------------------------------------------
                |
                | Make sure ONLY:
                |
                | status = pending
                | assigned_to = null
                |
                | items are returned.
                |
                */
                $order->setRelation(
                    'items',
                    $order->items
                        ->filter(function ($item) {
    
                            return $item->status === 'pending'
                                && is_null($item->assigned_to);
    
                        })
                        ->values()
                );
    
                // If no matching items, don't return the order
                if ($order->items->isEmpty()) {
                    return null;
                }
    
    
                // Summary
                $totalItems = $order->items->count();
    
                $pickedItems = $order->items
                    ->where('status', 'picked')
                    ->count();
    
                $packedItems = $order->items
                    ->where('status', 'packed')
                    ->count();
    
                $deliveredItems = $order->items
                    ->where('status', 'delivered')
                    ->count();
    
    
                /*
                |--------------------------------------------------------------------------
                | Pickers
                |--------------------------------------------------------------------------
                */
                $pickers = $order->items
                    ->map(function ($item) {
    
                        if ($item->picked_by || $item->picked_user_name) {
    
                            return [
                                'id' => $item->picked_by
                                    ? (int) $item->picked_by
                                    : null,
    
                                'name' => $item->pickedUser
                                    ? $item->pickedUser->name
                                    : ($item->picked_user_name ?? 'Picker User'),
    
                                'picked_at' => $item->picked_at
                                    ? $item->picked_at->toIso8601String()
                                    : null,
                            ];
                        }
    
                        return null;
    
                    })
                    ->filter()
                    ->unique('name')
                    ->values();
    
    
                /*
                |--------------------------------------------------------------------------
                | Packers
                |--------------------------------------------------------------------------
                */
                $packers = $order->items
                    ->map(function ($item) {
    
                        if ($item->packed_by || $item->packed_user_name) {
    
                            return [
                                'id' => $item->packed_by
                                    ? (int) $item->packed_by
                                    : null,
    
                                'name' => $item->packedUser
                                    ? $item->packedUser->name
                                    : ($item->packed_user_name ?? 'Packer User'),
    
                                'packed_at' => $item->packed_at
                                    ? $item->packed_at->toIso8601String()
                                    : null,
                            ];
                        }
    
                        return null;
    
                    })
                    ->filter()
                    ->unique('name')
                    ->values();
    
    
                /*
                |--------------------------------------------------------------------------
                | Return same response structure
                |--------------------------------------------------------------------------
                */
                return [
                    'order_id' => $order->id,
    
                    'order_number' => $order->order_number,
    
                    'status' => $order->status,
    
                    'bag_count' => (int) ($order->bag_count ?? 0),
    
                    'assigned_user' => $order->assigned_to ? [
                        'id' => (int) $order->assigned_to,
    
                        'name' => $order->assignedUser
                            ? $order->assignedUser->name
                            : ($order->assigned_user_name ?? 'Picker User'),
    
                        'assigned_at' => $order->assigned_at
                            ? $order->assigned_at->toIso8601String()
                            : null,
                    ] : null,
    
                    'driver_user' => ($order->delivered_by || $order->delivered_user_name)
                        ? [
                            'id' => $order->delivered_by
                                ? (int) $order->delivered_by
                                : null,
    
                            'name' => $order->deliveredUser
                                ? $order->deliveredUser->name
                                : ($order->delivered_user_name ?? 'Driver User'),
    
                            'delivered_at' => $order->delivered_at
                                ? $order->delivered_at->toIso8601String()
                                : null,
                        ]
                        : null,
    
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
    
                    'items' => $order->items
                        ->map(function ($item) {
    
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
    
                                'is_packer_verified' => (bool) $item->is_packer_verified,
    
                                'packer_verified_user' =>
                                    ($item->packer_verified_by || $item->packer_verified_user_name)
                                        ? [
                                            'id' => $item->packer_verified_by
                                                ? (int) $item->packer_verified_by
                                                : null,
    
                                            'name' => $item->packerVerifiedUser
                                                ? $item->packerVerifiedUser->name
                                                : ($item->packer_verified_user_name ?? 'Packer User'),
    
                                            'verified_at' => $item->packer_verified_at
                                                ? $item->packer_verified_at->toIso8601String()
                                                : null,
                                        ]
                                        : null,
                                'is_flagged' => $item->is_flagged,
                                'flag_reason' => $item->flag_reason,
                                'assigned_user' => $item->assigned_to
                                    ? [
                                        'id' => (int) $item->assigned_to,
    
                                        'name' => $item->assignedUser
                                            ? $item->assignedUser->name
                                            : ($item->assigned_user_name ?? 'Picker User'),
    
                                        'assigned_at' => $item->assigned_at
                                            ? $item->assigned_at->toIso8601String()
                                            : null,
                                    ]
                                    : null,
    
                                'picked_user' =>
                                    ($item->picked_by || $item->picked_user_name)
                                        ? [
                                            'id' => $item->picked_by
                                                ? (int) $item->picked_by
                                                : null,
    
                                            'name' => $item->pickedUser
                                                ? $item->pickedUser->name
                                                : ($item->picked_user_name ?? 'Picker User'),
    
                                            'picked_at' => $item->picked_at
                                                ? $item->picked_at->toIso8601String()
                                                : null,
                                        ]
                                        : null,
    
                                'packed_user' =>
                                    ($item->packed_by || $item->packed_user_name)
                                        ? [
                                            'id' => $item->packed_by
                                                ? (int) $item->packed_by
                                                : null,
    
                                            'name' => $item->packedUser
                                                ? $item->packedUser->name
                                                : ($item->packed_user_name ?? 'Packer User'),
    
                                            'packed_at' => $item->packed_at
                                                ? $item->packed_at->toIso8601String()
                                                : null,
                                        ]
                                        : null,
    
                                'delivered_user' =>
                                    ($item->delivered_by || $item->delivered_user_name)
                                        ? [
                                            'id' => $item->delivered_by
                                                ? (int) $item->delivered_by
                                                : null,
    
                                            'name' => $item->deliveredUser
                                                ? $item->deliveredUser->name
                                                : ($item->delivered_user_name ?? 'Driver User'),
    
                                            'delivered_at' => $item->delivered_at
                                                ? $item->delivered_at->toIso8601String()
                                                : null,
                                        ]
                                        : null,
    
                                'picked_at' => $item->picked_at
                                    ? $item->picked_at->toIso8601String()
                                    : null,
    
                                'packed_at' => $item->packed_at
                                    ? $item->packed_at->toIso8601String()
                                    : null,
    
                                'delivered_at' => $item->delivered_at
                                    ? $item->delivered_at->toIso8601String()
                                    : null,
                            ];
                        })
                        ->values(),
    
                    'created_at' => $order->created_at
                        ? $order->created_at->toIso8601String()
                        : null,
                ];
            })
            ->filter()
            ->values();
    
    
        return response()->json([
            'success' => true,
            'message' => 'Orders retrieved successfully.',
            'count' => $formattedOrders->count(),
            'data' => $formattedOrders,
        ]);
    }
    /**
     * Get a single order detail by ID or order_number.
     * Route: GET /api/orders/{id}
     */
    public function show($id)
    {
        $idStr = (string) $id;

        $order = Order::with(['items', 'logs', 'assignedUser', 'deliveredUser', 'items.packerVerifiedUser'])
            ->where('id', $idStr)
            ->orWhere('order_number', $idStr)
            ->orWhere('order_number', ltrim($idStr, '#'))
            ->orWhere('order_number', '#' . ltrim($idStr, '#'))
            ->first();

        if (!$order) {
            try {
                $this->shopifyService->syncOrdersToDatabase();
                $order = Order::with(['items', 'logs', 'assignedUser', 'deliveredUser', 'items.packerVerifiedUser'])
                    ->where('id', $idStr)
                    ->orWhere('order_number', $idStr)
                    ->orWhere('order_number', ltrim($idStr, '#'))
                    ->orWhere('order_number', '#' . ltrim($idStr, '#'))
                    ->first();
            } catch (\Exception $e) {
                // Ignore sync error if offline
            }
        }

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
                'bag_count' => (int) ($order->bag_count ?? 0),
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
                        'is_packer_verified' => (bool) $item->is_packer_verified,
                        'packer_verified_user' => ($item->packer_verified_by || $item->packer_verified_user_name) ? [
                            'id' => $item->packer_verified_by ? (int) $item->packer_verified_by : null,
                            'name' => $item->packerVerifiedUser ? $item->packerVerifiedUser->name : ($item->packer_verified_user_name ?? 'Packer User'),
                            'verified_at' => $item->packer_verified_at ? $item->packer_verified_at->toIso8601String() : null,
                        ] : null,
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
     * Get all assigned orders and line item details for a specific user ID (or order ID)
     * Route: GET /api/orders/{id}
     *
     * Returns an array of orders with line items assigned to user_id that are NOT YET PICKED.
     */
    public function apiOrdersById(Request $request, $id)
    {
        $idStr = trim((string) $id);
        $cleanOrderId = ltrim($idStr, '#');
    
        // Auto-sync orders from Shopify silently
        if ($request->boolean('auto_sync', true)) {
            try {
                $this->shopifyService->syncOrdersToDatabase();
            } catch (\Exception $e) {
                // Ignore sync error if offline
            }
        }
    
        /*
        |--------------------------------------------------------------------------
        | Find orders having items assigned to this user and still pending
        |--------------------------------------------------------------------------
        |
        | Example:
        |
        | Order 1:
        |   Item 1 -> assigned_to = 5, status = picked
        |   Item 2 -> assigned_to = 5, status = pending
        |
        | Request:
        |   /api/orders/5
        |
        | Result:
        |   Order 1
        |      Item 2 only
        |
        |--------------------------------------------------------------------------
        */
    
        $userOrders = Order::with([
            /*
            |--------------------------------------------------------------------------
            | IMPORTANT:
            | Load only items that belong to this user AND are pending
            |--------------------------------------------------------------------------
            */
            'items' => function ($q) use ($idStr) {
                $q->where('assigned_to', $idStr)
                    ->where('status', 'pending');
            },
    
            // Item relationships
            'items.assignedUser',
            'items.pickedUser',
            'items.packedUser',
            'items.deliveredUser',
            'items.packerVerifiedUser',
    
            // Order relationships
            'assignedUser',
            'deliveredUser',
            'logs.user',
        ])
    
        /*
        |--------------------------------------------------------------------------
        | Order must have at least one matching pending item
        |--------------------------------------------------------------------------
        */
        ->whereHas('items', function ($q) use ($idStr) {
            $q->where('assigned_to', $idStr)
                ->where('status', 'pending');
        })
    
        ->orderBy('updated_at', 'desc')
        ->get();
    

        /*
        |--------------------------------------------------------------------------
        | Format orders using existing response structure
        |--------------------------------------------------------------------------
        */
    
        if ($userOrders->isNotEmpty()) {
    
            $formattedOrders = $userOrders
                ->map(function ($order) {
    
                    /*
                    |--------------------------------------------------------------------------
                    | Safety filter
                    |--------------------------------------------------------------------------
                    |
                    | Make absolutely sure only:
                    |
                    | assigned_to = requested user
                    | AND
                    | status = pending
                    |
                    | items are passed to formatOrderDetails().
                    |
                    |--------------------------------------------------------------------------
                    */
    
                    $order->setRelation(
                        'items',
                        $order->items
                            ->filter(function ($item) {
    
                                return $item->status === 'pending';
                            })
                            ->values()
                    );
    
                    return $this->formatOrderDetails(
                        $order,
                        'unpicked'
                    );
                })
    
                /*
                |--------------------------------------------------------------------------
                | Remove orders if somehow no matching items remain
                |--------------------------------------------------------------------------
                */
                ->filter(function ($order) {
                    return !empty($order['items']);
                })
                ->values();
    
    
            return response()->json([
                'success' => true,
                'data' => $formattedOrders,
            ]);
        }
    
    
        /*
        |--------------------------------------------------------------------------
        | No pending items for this user
        |--------------------------------------------------------------------------
        */
    
        return response()->json([
            'success' => true,
            'data' => [],
        ]);
    }

    /**
     * Get completed/picked order details for a specific user ID where items were picked by that user
     * Route: GET /api/orders-complete/{id}
     *
     * Returns an array of orders & picked line items by user_id.
     */
    public function apiOrdersComplete(Request $request, $id)
    {
        $idStr = trim((string) $id);

        // Query orders where items were picked by this user (picked_by = $id OR assigned_to = $id with picked/packed/delivered status)
        $completedOrders = Order::with([
            'items.assignedUser',
            'items.pickedUser',
            'items.packedUser',
            'items.deliveredUser',
            'items.packerVerifiedUser',
            'assignedUser',
            'deliveredUser',
            'logs.user'
        ])
        ->whereHas('items', function ($q) use ($idStr) {
            $q->where('picked_by', $idStr)
              ->orWhere(function ($sub) use ($idStr) {
                  $sub->where('assigned_to', $idStr)
                      ->whereIn('status', ['picked', 'packed', 'delivered']);
              });
        })
        ->orderBy('updated_at', 'desc')
        ->get();

        $formattedOrders = $completedOrders->map(fn($order) => $this->formatOrderDetails($order, 'picked'))
            ->filter(fn($ord) => count($ord['items']) > 0)
            ->values();

        return response()->json([
            'success' => true,
            'data' => $formattedOrders,
        ]);
    }

    /**
     * Helper method to format single order details consistently for mobile frontend
     *
     * @param Order $order
     * @param string $itemFilter 'unpicked', 'picked', or 'all'
     * @return array
     */
    private function formatOrderDetails(Order $order, string $itemFilter = 'all')
    {
        $allItems = $order->items;

        $totalItems = $allItems->count();
        $pickedItems = $allItems->whereIn('status', ['picked', 'packed', 'delivered'])->count();
        $packedItems = $allItems->whereIn('status', ['packed', 'delivered'])->count();
        $deliveredItems = $allItems->where('status', 'delivered')->count();

        // Filter line items based on context
        if ($itemFilter === 'unpicked') {
            $filteredItems = $allItems->filter(function ($item) {
                return is_null($item->picked_by) && !in_array($item->status, ['picked', 'packed', 'delivered']);
            });
        } elseif ($itemFilter === 'picked') {
            $filteredItems = $allItems->filter(function ($item) {
                return !is_null($item->picked_by) || in_array($item->status, ['picked', 'packed', 'delivered']);
            });
        } elseif ($itemFilter === 'packed') {
            $filteredItems = $allItems->filter(function ($item) {
                return !is_null($item->packed_by) || in_array($item->status, ['packed', 'delivered']);
            });
        } else {
            $filteredItems = $allItems;
        }

        $pickers = $allItems->map(function ($item) {
            if ($item->picked_by || $item->picked_user_name) {
                return [
                    'id' => $item->picked_by ? (int) $item->picked_by : null,
                    'name' => $item->pickedUser ? $item->pickedUser->name : ($item->picked_user_name ?? 'Picker User'),
                    'picked_at' => $item->picked_at ? $item->picked_at->toIso8601String() : null,
                ];
            }
            return null;
        })->filter()->unique('name')->values();

        $packers = $allItems->map(function ($item) {
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
            'bag_count' => (int) ($order->bag_count ?? 0),
            'assigned_user' => $order->assigned_to ? [
                'id' => (int) $order->assigned_to,
                'name' => $order->assignedUser ? $order->assignedUser->name : ($order->assigned_user_name ?? 'Picker User'),
                'assigned_at' => $order->assigned_at ? $order->assigned_at->toIso8601String() : null,
            ] : null,
            'packed_user' => ($order->packed_by || $order->packed_user_name || $order->packerAssignment) ? [
                'id' => $order->packerAssignment ? (int) $order->packerAssignment->packer_assigned_user_id : ($order->packed_by ? (int) $order->packed_by : null),
                'name' => $order->packerAssignment ? $order->packerAssignment->packer_assigned_user_name : ($order->packedUser ? $order->packedUser->name : ($order->packed_user_name ?? 'Packer User')),
                'assigned_at' => ($order->packerAssignment && $order->packerAssignment->assigned_at) ? $order->packerAssignment->assigned_at->toIso8601String() : null,
                'packed_at' => $order->packed_at ? $order->packed_at->toIso8601String() : null,
            ] : null,
            'packer_assignment' => $order->packerAssignment ? [
                'id' => $order->packerAssignment->id,
                'order_id' => $order->packerAssignment->order_id,
                'packer_assigned_user_id' => $order->packerAssignment->packer_assigned_user_id ? (int) $order->packerAssignment->packer_assigned_user_id : null,
                'packer_assigned_user_name' => $order->packerAssignment->packer_assigned_user_name,
                'assigned_at' => $order->packerAssignment->assigned_at ? $order->packerAssignment->assigned_at->toIso8601String() : null,
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
            'items' => $filteredItems->map(function ($item) {
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
                    'is_flagged' => (bool) ($item->is_flagged ?? false),
                    'flag_reason' => $item->flag_reason ?? null,
                    'is_packer_verified' => (bool) $item->is_packer_verified,
                    'packer_verified_user' => $item->packer_verified_by ? [
                        'id' => (int) $item->packer_verified_by,
                        'name' => $item->packerVerifiedUser ? $item->packerVerifiedUser->name : 'Packer User',
                        'verified_at' => $item->packer_verified_at ? $item->packer_verified_at->toIso8601String() : null,
                    ] : null,
                    'assigned_user' => $item->assigned_to ? [
                        'id' => (int) $item->assigned_to,
                        'name' => $item->assignedUser ? $item->assignedUser->name : ($item->assigned_user_name ?? 'Picker User'),
                        'assigned_at' => $item->assigned_at ? $item->assigned_at->toIso8601String() : null,
                    ] : null,
                    'picked_user' => $item->picked_by ? [
                        'id' => (int) $item->picked_by,
                        'name' => $item->pickedUser ? $item->pickedUser->name : ($item->picked_user_name ?? 'Picker User'),
                        'picked_at' => $item->picked_at ? $item->picked_at->toIso8601String() : null,
                    ] : null,
                    'packed_user' => $item->packed_by ? [
                        'id' => (int) $item->packed_by,
                        'name' => $item->packedUser ? $item->packedUser->name : ($item->packed_user_name ?? 'Packer User'),
                        'packed_at' => $item->packed_at ? $item->packed_at->toIso8601String() : null,
                    ] : null,
                    'delivered_user' => $item->delivered_by ? [
                        'id' => (int) $item->delivered_by,
                        'name' => $item->deliveredUser ? $item->deliveredUser->name : ($item->delivered_user_name ?? 'Driver User'),
                        'delivered_at' => $item->delivered_at ? $item->delivered_at->toIso8601String() : null,
                    ] : null,
                    'picked_at' => $item->picked_at ? $item->picked_at->toIso8601String() : null,
                    'packed_at' => $item->packed_at ? $item->packed_at->toIso8601String() : null,
                    'delivered_at' => $item->delivered_at ? $item->delivered_at->toIso8601String() : null,
                ];
            })->values(),
            'logs' => $order->logs ? $order->logs->map(function ($log) {
                return [
                    'id' => $log->id,
                    'order_id' => $log->order_id,
                    'order_item_id' => $log->order_item_id,
                    'user_id' => $log->user_id ? (int) $log->user_id : null,
                    'user_name' => $log->user ? $log->user->name : ($log->user_name ?? null),
                    'action' => $log->action,
                    'old_status' => $log->old_status,
                    'new_status' => $log->new_status,
                    'notes' => $log->notes,
                    'created_at' => $log->created_at ? $log->created_at->toIso8601String() : null,
                    'updated_at' => $log->updated_at ? $log->updated_at->toIso8601String() : null,
                    'user' => $log->user,
                ];
            })->values() : [],
            'created_at' => $order->created_at ? $order->created_at->toIso8601String() : null,
        ];
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
        if ($request->filled('order_item_id') || $request->filled('order_item_ids') || $request->filled('line_item_id') || $request->filled('line_item_ids')) {
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

            if (!empty($lineItemIds) || !empty($orderItemIds)) {
                $itemsQuery->where(function ($q) use ($lineItemIds, $orderItemIds) {
                    if (!empty($lineItemIds)) {
                        $q->orWhereIn('line_item_id', $lineItemIds);
                    }
                    if (!empty($orderItemIds)) {
                        $q->orWhereIn('id', $orderItemIds);
                    }
                });
            }

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
            | Check if any items are already assigned
            |--------------------------------------------------------------------------
            */

            $alreadyAssignedItems = $items->filter(function ($item) {
                return !is_null($item->assigned_to);
            });

            if ($alreadyAssignedItems->isNotEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot assign item(s). One or more items are already assigned. Please unassign them first using /api/orders/items/unassign-me.',
                    'data' => [
                        'already_assigned_count' => $alreadyAssignedItems->count(),
                        'already_assigned_items' => $alreadyAssignedItems->map(function ($item) {
                            return [
                                'item_id' => $item->id,
                                'line_item_id' => $item->line_item_id,
                                'product_name' => $item->product_name,
                                'assigned_to' => $item->assigned_to,
                                'assigned_user_name' => $item->assigned_user_name,
                            ];
                        })->values(),
                    ],
                ], 400);
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
     * Unassign entire order and its unpicked items from picker ("Unassign Order")
     * Route: POST /api/orders/unassign or POST /api/orders/unassign-me
     */
    public function unassignOrder(Request $request)
    {
        if (!$request->filled('order_id') && ($request->filled('order_item_id') || $request->filled('order_item_ids') || $request->filled('line_item_id') || $request->filled('line_item_ids'))) {
            return $this->unassignItems($request);
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

        $user = Auth::user();
        $userId = $user ? $user->id : $request->input('user_id');

        $dbUser = null;
        if ($userId) {
            $dbUser = \App\Models\User::find($userId);
        }

        $userName = $user ? $user->name : ($dbUser ? $dbUser->name : ($request->input('user_name') ?? 'Picker User'));

        return DB::transaction(function () use ($request, $userId, $userName) {
            $orderIdStr = (string) $request->input('order_id');

            $order = Order::with('items')
                ->where('id', $orderIdStr)
                ->orWhere('order_number', $orderIdStr)
                ->orWhere('order_number', ltrim($orderIdStr, '#'))
                ->orWhere('order_number', '#' . ltrim($orderIdStr, '#'))
                ->first();

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

            $unassignedItems = collect();
            $skippedItems = collect();

            foreach ($order->items as $item) {
                // If picker has not picked the item, unassign it
                $isPicked = in_array($item->status, ['picked', 'packed', 'delivered']) || !is_null($item->picked_by);

                if ($isPicked) {
                    $skippedItems->push([
                        'item_id' => $item->id,
                        'line_item_id' => $item->line_item_id,
                        'product_name' => $item->product_name,
                        'status' => $item->status,
                        'reason' => 'Item has already been picked and cannot be unassigned.',
                    ]);
                } else {
                    $oldItemStatus = $item->status;
                    $item->update([
                        'assigned_to' => null,
                        'assigned_user_name' => null,
                        'assigned_at' => null,
                        'status' => ($item->status === 'picking') ? 'pending' : $item->status,
                    ]);

                    $unassignedItems->push($item->fresh());

                    OrderStatusLog::create([
                        'order_id' => $order->id,
                        'order_item_id' => $item->id,
                        'user_id' => $userId,
                        'user_name' => $userName,
                        'action' => 'item_unassigned_from_picker',
                        'old_status' => $oldItemStatus,
                        'new_status' => $item->status,
                        'notes' => $request->input('notes', "Unassigned item {$item->product_name} from picker {$userName}"),
                    ]);
                }
            }

            // Check remaining status of order items
            $allItems = OrderItem::where('order_id', $order->id)->get();
            $hasAssignedItems = $allItems->whereNotNull('assigned_to')->count() > 0 || $allItems->whereNotNull('assigned_user_name')->count() > 0;
            $hasPickedItems = $allItems->whereIn('status', ['picked', 'packed', 'delivered'])->count() > 0;

            if (!$hasAssignedItems && !$hasPickedItems) {
                $oldOrderStatus = $order->status;
                $order->update([
                    'assigned_to' => null,
                    'assigned_user_name' => null,
                    'assigned_at' => null,
                    'status' => ($order->status === 'picking') ? 'pending' : $order->status,
                ]);

                OrderStatusLog::create([
                    'order_id' => $order->id,
                    'user_id' => $userId,
                    'user_name' => $userName,
                    'action' => 'order_unassigned_from_picker',
                    'old_status' => $oldOrderStatus,
                    'new_status' => $order->status,
                    'notes' => $request->input('notes', "Unassigned entire order {$order->order_number} from picker {$userName}"),
                ]);
            } elseif (!$hasAssignedItems) {
                $order->update([
                    'assigned_to' => null,
                    'assigned_user_name' => null,
                    'assigned_at' => null,
                ]);
            }

            if ($unassignedItems->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => "Order {$order->order_number} could not be unassigned because all items have already been picked.",
                    'data' => [
                        'order' => $order->fresh(['items']),
                        'skipped_items' => $skippedItems,
                    ],
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => "Order {$order->order_number} successfully unassigned ({$unassignedItems->count()} item(s) unassigned).",
                'data' => [
                    'order' => $order->fresh(['items']),
                    'unassigned_items' => $unassignedItems,
                    'skipped_items' => $skippedItems,
                ],
            ]);
        });
    }

    /**
     * Unassign specific order item(s) from picker ("Unassign Items")
     * Route: POST /api/orders/items/unassign or POST /api/orders/items/unassign-me
     */
    public function unassignItems(Request $request)
    {
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

        $authUser = Auth::user();
        $userId = $authUser ? $authUser->id : $request->input('user_id');
        $dbUser = $userId ? \App\Models\User::find($userId) : null;
        $userName = $authUser ? $authUser->name : ($dbUser ? $dbUser->name : ($request->input('user_name') ?? 'Picker User'));

        return DB::transaction(function () use ($request, $userId, $userName) {
            $orderIdStr = trim((string) $request->input('order_id'));
            $cleanOrderId = ltrim($orderIdStr, '#');

            $lineItemIds = [];
            if ($request->filled('line_item_id')) {
                $lineItemIds[] = (string) $request->input('line_item_id');
            }
            if ($request->filled('line_item_ids')) {
                foreach ((array) $request->input('line_item_ids') as $lineItemId) {
                    if ($lineItemId !== null && $lineItemId !== '') {
                        $lineItemIds[] = (string) $lineItemId;
                    }
                }
            }

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

            $order = Order::with('items')
                ->where(function ($query) use ($orderIdStr, $cleanOrderId) {
                    $query->where('id', $orderIdStr)
                        ->orWhere('order_number', $orderIdStr)
                        ->orWhere('order_number', $cleanOrderId)
                        ->orWhere('order_number', '#' . $cleanOrderId);
                })
                ->first();

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
                    \Log::error('Shopify order sync failed during item unassignment', [
                        'order_id' => $orderIdStr,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => "Order '{$orderIdStr}' not found.",
                ], 404);
            }

            $itemsQuery = OrderItem::where('order_id', $order->id);

            if (!empty($lineItemIds) || !empty($orderItemIds)) {
                $itemsQuery->where(function ($q) use ($lineItemIds, $orderItemIds) {
                    if (!empty($lineItemIds)) {
                        $q->orWhereIn('line_item_id', $lineItemIds);
                    }
                    if (!empty($orderItemIds)) {
                        $q->orWhereIn('id', $orderItemIds);
                    }
                });
            }

            $items = $itemsQuery->get();

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

            $unassignedItems = collect();
            $skippedItems = collect();

            foreach ($items as $item) {
                // If picker has not picked the item, unassign it
                $isPicked = in_array($item->status, ['picked', 'packed', 'delivered']) || !is_null($item->picked_by);

                if ($isPicked) {
                    $skippedItems->push([
                        'item_id' => $item->id,
                        'line_item_id' => $item->line_item_id,
                        'product_name' => $item->product_name,
                        'status' => $item->status,
                        'reason' => 'Item has already been picked and cannot be unassigned.',
                    ]);
                } else {
                    $oldItemStatus = $item->status;
                    $item->update([
                        'assigned_to' => null,
                        'assigned_user_name' => null,
                        'assigned_at' => null,
                        'status' => ($item->status === 'picking') ? 'pending' : $item->status,
                    ]);

                    $unassignedItems->push($item->fresh());

                    OrderStatusLog::create([
                        'order_id' => $order->id,
                        'order_item_id' => $item->id,
                        'user_id' => $userId,
                        'user_name' => $userName,
                        'action' => 'item_unassigned_from_picker',
                        'old_status' => $oldItemStatus,
                        'new_status' => $item->status,
                        'notes' => $request->input('notes', "Unassigned item {$item->product_name} from picker {$userName}"),
                    ]);
                }
            }

            // Check overall order items status
            $allItems = OrderItem::where('order_id', $order->id)->get();
            $hasAssignedItems = $allItems->whereNotNull('assigned_to')->count() > 0 || $allItems->whereNotNull('assigned_user_name')->count() > 0;
            $hasPickedItems = $allItems->whereIn('status', ['picked', 'packed', 'delivered'])->count() > 0;

            if (!$hasAssignedItems && !$hasPickedItems) {
                $oldOrderStatus = $order->status;
                $order->update([
                    'assigned_to' => null,
                    'assigned_user_name' => null,
                    'assigned_at' => null,
                    'status' => ($order->status === 'picking') ? 'pending' : $order->status,
                ]);

                OrderStatusLog::create([
                    'order_id' => $order->id,
                    'user_id' => $userId,
                    'user_name' => $userName,
                    'action' => 'order_unassigned_from_picker',
                    'old_status' => $oldOrderStatus,
                    'new_status' => $order->status,
                    'notes' => $request->input('notes', "Unassigned order {$order->order_number} as all items are now unassigned"),
                ]);
            } elseif (!$hasAssignedItems) {
                $order->update([
                    'assigned_to' => null,
                    'assigned_user_name' => null,
                    'assigned_at' => null,
                ]);
            }

            if ($unassignedItems->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No items could be unassigned because they have already been picked.',
                    'data' => [
                        'order' => [
                            'id' => $order->id,
                            'order_number' => $order->order_number,
                            'status' => $order->status,
                        ],
                        'skipped_items' => $skippedItems,
                    ],
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => "Successfully unassigned {$unassignedItems->count()} item(s).",
                'data' => [
                    'order' => [
                        'id' => $order->id,
                        'order_number' => $order->order_number,
                        'status' => $order->status,
                        'assigned_to' => $order->assigned_to,
                        'assigned_user_name' => $order->assigned_user_name,
                        'assigned_at' => $order->assigned_at,
                    ],
                    'items' => $unassignedItems,
                    'skipped_items' => $skippedItems,
                ],
            ]);
        });
    }

    /**
     * Unassign order or order items from current picker (Unified endpoint)
     * Route: POST /api/orders/unassign-me
     */
    public function unassignMe(Request $request)
    {
        if ($request->has('order_id') && !$request->has('order_item_id') && !$request->has('order_item_ids') && !$request->has('line_item_id') && !$request->has('line_item_ids')) {
            return $this->unassignOrder($request);
        }
        return $this->unassignItems($request);
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

            // Check if item is assigned to a picker
            if (!$orderItem->assigned_to) {
                return response()->json([
                    'success' => false,
                    'message' => "Cannot update status. Item '{$orderItem->product_name}' is not assigned to any picker.",
                ], 400);
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
                $newOrderStatus = 'picked';
            } elseif ($allStatuses->contains(fn($s) => in_array($s, ['picked', 'packed', 'delivered']))) {
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

    /**
     * Packer scans item barcode to verify item before packing
     * Route: POST /api/orders/packer/verify-item
     */
    public function verifyItemBarcode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required',
            'scanned_barcode' => 'required_without_all:barcode|nullable|string',
            'barcode' => 'nullable|string',
            'order_item_id' => 'nullable',
            'line_item_id' => 'nullable|string',
            'user_id' => 'nullable',
            'user_name' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $scannedBarcode = (string) ($request->input('scanned_barcode') ?? $request->input('barcode'));
        $orderIdStr = (string) $request->input('order_id');

        // Find Order
        $order = Order::with('items')
            ->where('id', $orderIdStr)
            ->orWhere('order_number', $orderIdStr)
            ->orWhere('order_number', ltrim($orderIdStr, '#'))
            ->orWhere('order_number', '#' . ltrim($orderIdStr, '#'))
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => "Order '{$orderIdStr}' not found.",
            ], 404);
        }

        // Determine user (packer)
        $user = Auth::user();
        $userId = $user ? $user->id : $request->input('user_id');
        $dbUser = $userId ? \App\Models\User::find($userId) : null;
        $validUserId = $dbUser ? $dbUser->id : null;
        $userName = $user ? $user->name : ($dbUser ? $dbUser->name : ($request->input('user_name') ?? 'Packer User'));

        // Query item belonging to order
        $itemQuery = OrderItem::where('order_id', $order->id);

        if ($request->filled('order_item_id')) {
            $itemQuery->where('id', $request->input('order_item_id'));
        } elseif ($request->filled('line_item_id')) {
            $itemQuery->where('line_item_id', $request->input('line_item_id'));
        } else {
            $itemQuery->where(function ($q) use ($scannedBarcode) {
                $q->where('barcode', $scannedBarcode)
                  ->orWhere('product_code', $scannedBarcode)
                  ->orWhere('line_item_id', $scannedBarcode);
            });
        }

        $orderItem = $itemQuery->first();

        if (!$orderItem) {
            return response()->json([
                'success' => false,
                'message' => "Scanned barcode '{$scannedBarcode}' does not match any item in Order {$order->order_number}.",
                'is_verified' => false,
            ], 400);
        }

        // Check if item has been picked
        $isPicked = in_array($orderItem->status, ['picked', 'packed', 'delivered']) || !is_null($orderItem->picked_by);

        if (!$isPicked) {
            return response()->json([
                'success' => false,
                'message' => "Item '{$orderItem->product_name}' is not yet picked.",
                'is_verified' => false,
                'status' => $orderItem->status,
            ], 400);
        }

        // Record verification
        $orderItem->update([
            'is_packer_verified' => true,
            'packer_verified_by' => $validUserId,
            'packer_verified_user_name' => $userName,
            'packer_verified_at' => now(),
        ]);

        $verificationLog = PackerVerification::create([
            'order_id' => $order->id,
            'order_item_id' => $orderItem->id,
            'packer_id' => $validUserId,
            'packer_name' => $userName,
            'scanned_barcode' => $scannedBarcode,
            'is_verified' => true,
            'verified_at' => now(),
        ]);

        $auditLog = OrderStatusLog::create([
            'order_id' => $order->id,
            'order_item_id' => $orderItem->id,
            'user_id' => $validUserId,
            'user_name' => $userName,
            'action' => 'packer_barcode_verified',
            'old_status' => $orderItem->status,
            'new_status' => $orderItem->status,
            'notes' => "Packer {$userName} verified barcode {$scannedBarcode} for item {$orderItem->product_name}",
        ]);

        $order->refresh();
        $totalItems = $order->items->count();
        $verifiedItemsCount = $order->items->where('is_packer_verified', true)->count();
        $remainingCount = $totalItems - $verifiedItemsCount;

        return response()->json([
            'success' => true,
            'message' => "Item '{$orderItem->product_name}' successfully verified by packer {$userName}.",
            'data' => [
                'is_verified' => true,
                'verified_at' => $orderItem->packer_verified_at ? $orderItem->packer_verified_at->toIso8601String() : null,
                'packer' => [
                    'id' => $validUserId,
                    'name' => $userName,
                ],
                'order_item' => $orderItem->fresh(),
                'verification_record' => $verificationLog,
                'log' => $auditLog,
                'packing_summary' => [
                    'total_items' => $totalItems,
                    'verified_items' => $verifiedItemsCount,
                    'remaining_unverified_items' => $remainingCount,
                    'all_items_verified' => ($verifiedItemsCount === $totalItems),
                ],
            ],
        ]);
    }

    /**
     * Packer completes packing and enters bag count
     * Route: POST /api/orders/packer/complete-packing
     */
    public function completePacking(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required',
            'bag_count' => 'required|integer|min:1',
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

        $orderIdStr = (string) $request->input('order_id');
        $bagCount = (int) $request->input('bag_count');

        $order = Order::with('items')
            ->where('id', $orderIdStr)
            ->orWhere('order_number', $orderIdStr)
            ->orWhere('order_number', ltrim($orderIdStr, '#'))
            ->orWhere('order_number', '#' . ltrim($orderIdStr, '#'))
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => "Order '{$orderIdStr}' not found.",
            ], 404);
        }

        // Verify all items in order are packer verified before completing packing
        $unverifiedItems = $order->items->filter(function ($item) {
            return !$item->is_packer_verified;
        });

        if ($unverifiedItems->isNotEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot complete packing. Please verify all items before completing packing.',
                'unverified_items_count' => $unverifiedItems->count(),
                'total_items_count' => $order->items->count(),
                'unverified_items' => $unverifiedItems->map(function ($item) {
                    return [
                        'item_id' => $item->id,
                        'line_item_id' => $item->line_item_id,
                        'product_name' => $item->product_name,
                        'barcode' => $item->barcode,
                        'is_packer_verified' => false,
                    ];
                })->values(),
            ], 400);
        }

        // Determine user (packer)
        $user = Auth::user();
        $userId = $user ? $user->id : $request->input('user_id');
        $dbUser = $userId ? \App\Models\User::find($userId) : null;
        $validUserId = $dbUser ? $dbUser->id : null;
        $userName = $user ? $user->name : ($dbUser ? $dbUser->name : ($request->input('user_name') ?? 'Packer User'));

        return DB::transaction(function () use ($order, $bagCount, $validUserId, $userName, $request) {
            $oldOrderStatus = $order->status;

            // Update item statuses to packed
            foreach ($order->items as $item) {
                $item->update([
                    'status' => 'packed',
                    'packed_by' => $validUserId,
                    'packed_user_name' => $userName,
                    'packed_at' => now(),
                ]);
            }

            // Update parent order
            $order->update([
                'status' => 'packed',
                'bag_count' => $bagCount,
                'packed_by' => $validUserId,
                'packed_user_name' => $userName,
                'packed_at' => now(),
            ]);

            $log = OrderStatusLog::create([
                'order_id' => $order->id,
                'user_id' => $validUserId,
                'user_name' => $userName,
                'action' => 'order_packed',
                'old_status' => $oldOrderStatus,
                'new_status' => 'packed',
                'notes' => $request->input('notes', "Order packed into {$bagCount} bag(s) by packer {$userName}"),
            ]);

            return response()->json([
                'success' => true,
                'message' => "Order {$order->order_number} successfully packed into {$bagCount} bag(s).",
                'data' => [
                    'order' => $order->fresh(['items', 'packerVerifications']),
                    'bag_count' => $bagCount,
                    'packed_by' => [
                        'id' => $validUserId,
                        'name' => $userName,
                        'packed_at' => $order->packed_at ? $order->packed_at->toIso8601String() : null,
                    ],
                    'log' => $log,
                ],
            ]);
        });
    }

    /**
     * Assign a packer user to pack an order.
     * Rule: Order overall status must be 'picked' and ALL items in order must be picked.
     * Route: POST /api/orders/packer/assign-me
     * Route: POST /api/orders/packer/assign
     */
    public function assignPacker(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required',
            'user_id' => 'nullable',
            'packer_assigned_user_id' => 'nullable',
            'user_name' => 'nullable|string',
            'packer_assigned_user_name' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $orderIdStr = (string) $request->input('order_id');

        // Find Order
        $order = Order::with(['items', 'packerAssignment'])
            ->Where('order_number', $orderIdStr)
            ->orWhere('order_number', ltrim($orderIdStr, '#'))
            ->orWhere('order_number', '#' . ltrim($orderIdStr, '#'))
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => "Order '{$orderIdStr}' not found.",
            ], 404);
        }

        // Rule Check: Order status must be 'picked' AND all items must be picked
        $unpickedItems = $order->items->filter(function ($item) {
            return $item->status !== 'picked' && !in_array($item->status, ['packed', 'delivered']) && is_null($item->picked_by);
        });

        if ($order->status !== 'picked' || $unpickedItems->isNotEmpty()) {
            return response()->json([
                'success' => false,
                'message' => "Cannot assign packer. Order status must be 'picked' and all items in the order must be picked before a packer can assign themselves.",
                'order_status' => $order->status,
                'unpicked_items_count' => $unpickedItems->count(),
                'total_items_count' => $order->items->count(),
                'unpicked_items' => $unpickedItems->map(function ($item) {
                    return [
                        'item_id' => $item->id,
                        'line_item_id' => $item->line_item_id,
                        'product_name' => $item->product_name,
                        'status' => $item->status,
                    ];
                })->values(),
            ], 400);
        }

        // Determine user (packer)
        $user = Auth::user();
        $userId = $user ? $user->id : ($request->input('packer_assigned_user_id') ?? $request->input('user_id'));
        $dbUser = $userId ? \App\Models\User::find($userId) : null;
        $validUserId = $dbUser ? $dbUser->id : null;
        $userName = $user ? $user->name : ($dbUser ? $dbUser->name : ($request->input('packer_assigned_user_name') ?? $request->input('user_name') ?? 'Packer User'));

        return DB::transaction(function () use ($order, $validUserId, $userName, $request) {
            $assignment = OrderPackerAssigned::updateOrCreate(
                ['order_id' => $order->id],
                [
                    'packer_assigned_user_id' => $validUserId,
                    'packer_assigned_user_name' => $userName,
                    'assigned_at' => now(),
                ]
            );

            // Update order packed_by details
            $order->update([
                'packed_by' => $validUserId,
                'packed_user_name' => $userName,
            ]);

            $log = OrderStatusLog::create([
                'order_id' => $order->id,
                'user_id' => $validUserId,
                'user_name' => $userName,
                'action' => 'packer_assigned_to_order',
                'old_status' => $order->status,
                'new_status' => $order->status,
                'notes' => $request->input('notes', "Packer {$userName} assigned to pack order {$order->order_number}"),
            ]);

            return response()->json([
                'success' => true,
                'message' => "Packer '{$userName}' successfully assigned to pack order {$order->order_number}.",
                'data' => [
                    'order' => $order->fresh(['items', 'packerAssignment']),
                    'packer_assignment' => $assignment->fresh(),
                    'log' => $log,
                ],
            ]);
        });
    }

    /**
     * Unassign a packer from an order.
     * Route: POST /api/orders/packer/unassign-me
     * Route: POST /api/orders/packer/unassign
     */
    public function unassignPacker(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required',
            'user_id' => 'nullable',
            'packer_assigned_user_id' => 'nullable',
            'user_name' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $orderIdStr = (string) $request->input('order_id');

        $order = Order::with(['items', 'packerAssignment'])
            ->where('id', $orderIdStr)
            ->orWhere('order_number', $orderIdStr)
            ->orWhere('order_number', ltrim($orderIdStr, '#'))
            ->orWhere('order_number', '#' . ltrim($orderIdStr, '#'))
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => "Order '{$orderIdStr}' not found.",
            ], 404);
        }

        $assignment = OrderPackerAssigned::where('order_id', $order->id)->first();

        if (!$assignment && is_null($order->packed_by)) {
            return response()->json([
                'success' => false,
                'message' => "Order {$order->order_number} is not assigned to any packer.",
            ], 400);
        }

        // Cannot unassign if packing is already completed
        if (in_array($order->status, ['packed', 'delivered'])) {
            return response()->json([
                'success' => false,
                'message' => "Cannot unassign packer. Order {$order->order_number} has already been completed/packed.",
            ], 400);
        }

        return DB::transaction(function () use ($order, $assignment, $request) {
            $user = Auth::user();
            $userId = $user ? $user->id : ($request->input('packer_assigned_user_id') ?? $request->input('user_id'));
            $dbUser = $userId ? \App\Models\User::find($userId) : null;
            $validUserId = $dbUser ? $dbUser->id : null;
            $userName = $user ? $user->name : ($dbUser ? $dbUser->name : ($request->input('packer_assigned_user_name') ?? $request->input('user_name') ?? 'System User'));

            if ($assignment) {
                $assignment->delete();
            }

            $order->update([
                'packed_by' => null,
                'packed_user_name' => null,
                'packed_at' => null,
            ]);

            $log = OrderStatusLog::create([
                'order_id' => $order->id,
                'user_id' => $validUserId,
                'user_name' => $userName,
                'action' => 'packer_unassigned_from_order',
                'old_status' => $order->status,
                'new_status' => $order->status,
                'notes' => $request->input('notes', "Unassigned packer from order {$order->order_number}"),
            ]);

            return response()->json([
                'success' => true,
                'message' => "Packer successfully unassigned from order {$order->order_number}.",
                'data' => [
                    'order' => $order->fresh(['items', 'packerAssignment']),
                    'log' => $log,
                ],
            ]);
        });
    }

    /**
     * Get list of picked orders ready for packer.
     * Criteria: Order status is 'picked' and all items in order items table are picked.
     * Route: GET /api/orders/packer/picked/{user_id?}
     * Route: GET /api/orders/packer/ready-to-pack
     * Route: GET /api/orders-picked/{id?}
     */
    public function getPickedOrdersForPacker(Request $request, $userId = null)
    {
        $packerId = $userId ? trim((string) $userId) : null;
        if (!$packerId) {
            $packerId = $request->query('user_id') ?? $request->query('packer_id') ?? $request->query('id');
            if ($packerId) {
                $packerId = trim((string) $packerId);
            }
        }

        // Auto-sync orders from Shopify silently
        if ($request->boolean('auto_sync', true)) {
            try {
                $this->shopifyService->syncOrdersToDatabase();
            } catch (\Exception $e) {
                // Ignore sync error if offline
            }
        }

        $query = Order::with([
            'items.assignedUser',
            'items.pickedUser',
            'items.packedUser',
            'items.deliveredUser',
            'items.packerVerifiedUser',
            'assignedUser',
            'pickedUser',
            'packedUser',
            'deliveredUser',
            'packerAssignment',
            'logs.user'
        ]);

        // Condition 1: Order overall status is 'picked'
        $query->where('status', 'picked');

        // Condition 2: Ensure all items in order_items table are picked
        $query->whereDoesntHave('items', function ($iq) {
            $iq->where('status', 'pending');
        });

        // Optional filter by assigned packer ID if passed
        if (!empty($packerId)) {
            $query->where(function ($q) use ($packerId) {
                $q->where('packed_by', $packerId)
                  ->orWhere('packed_user_name', $packerId)
                  ->orWhereHas('packerAssignment', function ($pa) use ($packerId) {
                      $pa->where('packer_assigned_user_id', $packerId)
                        ->orWhere('packer_assigned_user_name', $packerId);
                  });
            });
        } elseif ($request->boolean('unassigned')) {
            // Unassigned orders to any packer
            $query->whereNull('packed_by')
                  ->whereDoesntHave('packerAssignment');
        }

        // Search filter
        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%")
                  ->orWhere('customer_phone', 'like', "%{$search}%")
                  ->orWhere('assigned_user_name', 'like', "%{$search}%")
                  ->orWhere('packed_user_name', 'like', "%{$search}%");
            });
        }

        $orders = $query->orderBy('updated_at', 'desc')
                        ->orderBy('created_at', 'desc')
                        ->get();

        // Format orders for mobile API
        $formattedOrders = $orders->map(function ($order) {
            // Additional check: Ensure order has items and no item is unpicked
            $hasUnpicked = $order->items->contains(fn($item) => $item->status === 'pending' || is_null($item->picked_by));
            if ($hasUnpicked) {
                return null;
            }
            return $this->formatOrderDetails($order, 'picked');
        })
        ->filter()
        ->values();

        return response()->json([
            'success' => true,
            'message' => 'Picked orders ready for packing retrieved successfully.',
            'count' => $formattedOrders->count(),
            'data' => $formattedOrders,
        ]);
    }

    /**
     * Get packed status orders for packer.
     * Route: GET /api/orders/packer/packed/{user_id?}
     * Route: GET /api/orders-packed/{id?}
     */
    public function getPackedOrders(Request $request, $userId = null)
    {
        $idStr = $userId ? trim((string) $userId) : null;
        if (!$idStr) {
            $idStr = $request->query('user_id') ?? $request->query('packer_id') ?? $request->query('id');
            if ($idStr) {
                $idStr = trim((string) $idStr);
            }
        }

        // Auto-sync orders from Shopify silently
        if ($request->boolean('auto_sync', true)) {
            try {
                $this->shopifyService->syncOrdersToDatabase();
            } catch (\Exception $e) {
                // Ignore sync error if offline
            }
        }

        $query = Order::with([
            'items.assignedUser',
            'items.pickedUser',
            'items.packedUser',
            'items.deliveredUser',
            'items.packerVerifiedUser',
            'assignedUser',
            'pickedUser',
            'packedUser',
            'deliveredUser',
            'logs.user'
        ]);

        if (!empty($idStr)) {
            // Filter orders packed by specific user ID/name or containing items packed by specific user ID
            $query->where(function ($q) use ($idStr) {
                $q->where('packed_by', $idStr)
                  ->orWhere('packed_user_name', $idStr)
                  ->orWhereHas('items', function ($sub) use ($idStr) {
                      $sub->where('packed_by', $idStr)
                          ->orWhere('packed_user_name', $idStr);
                  });
            });
        } else {
            // Return all orders that have status 'packed' or have items packed
            $query->where(function ($q) {
                $q->where('status', 'packed')
                  ->orWhereHas('items', function ($sub) {
                      $sub->where('status', 'packed');
                  });
            });
        }

        // Optional status filter override if supplied
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        // Search filter
        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%")
                  ->orWhere('customer_phone', 'like', "%{$search}%")
                  ->orWhere('packed_user_name', 'like', "%{$search}%");
            });
        }

        $orders = $query->orderBy('packed_at', 'desc')
                        ->orderBy('updated_at', 'desc')
                        ->get();

        $formattedOrders = $orders->map(fn($order) => $this->formatOrderDetails($order, 'packed'))
            ->filter(fn($ord) => count($ord['items']) > 0 || $ord['status'] === 'packed')
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Packed status orders retrieved successfully.',
            'count' => $formattedOrders->count(),
            'data' => $formattedOrders,
        ]);
    }

    /**
     * Flag an order item with a discrepancy (damaged, missing, wrong item, expired, etc.)
     * Route: POST /api/orders/items/flag-discrepancy
     */
    // public function flagItemDiscrepancy(Request $request)
    // {
    //     $validator = Validator::make($request->all(), [
    //         'order_id' => 'nullable',
    //         'order_item_id' => 'nullable',
    //         'line_item_id' => 'nullable',
    //         'barcode' => 'nullable',
    //         'product_code' => 'nullable',
    //         'user_id' => 'nullable',
    //         'user_name' => 'nullable',
    //         'issue_type' => 'nullable|string',
    //         'comment' => 'required|string',
    //         'photo' => 'nullable|image|max:10240',
    //         'photo_url' => 'nullable|string',
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Validation failed',
    //             'errors' => $validator->errors(),
    //         ], 422);
    //     }

    //     // 1. Locate the OrderItem
    //     $itemQuery = OrderItem::query();

    //     if ($request->filled('order_item_id')) {
    //         $itemQuery->where('id', $request->input('order_item_id'));
    //     } elseif ($request->filled('line_item_id')) {
    //         $itemQuery->where('line_item_id', $request->input('line_item_id'));
    //     } elseif ($request->filled('barcode')) {
    //         $itemQuery->where('barcode', $request->input('barcode'));
    //     } elseif ($request->filled('product_code')) {
    //         $itemQuery->where('product_code', $request->input('product_code'));
    //     }

    //     if ($request->filled('order_id')) {
    //         $itemQuery->where('order_id', $request->input('order_id'));
    //     }

    //     $orderItem = $itemQuery->first();

    //     if (!$orderItem) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Order item not found with provided identifiers.',
    //         ], 404);
    //     }

    //     // 2. Handle optional photo upload
    //     $photoUrl = $request->input('photo_url');
    //     if ($request->hasFile('photo')) {
    //         $path = $request->file('photo')->store('discrepancies', 'public');
    //         $photoUrl = asset('storage/' . $path);
    //     }

    //     // 3. Resolve user details
    //     $userId = $request->input('user_id') ?? Auth::id();
    //     $userName = $request->input('user_name');
    //     if (!$userName && $userId) {
    //         $userObj = \App\Models\User::find($userId);
    //         $userName = $userObj ? $userObj->name : 'Warehouse User';
    //     }
    //     if (!$userName) {
    //         $userName = 'Warehouse User';
    //     }

    //     $issueType = strtolower(trim($request->input('issue_type', 'damaged')));
    //     $comment = trim($request->input('comment'));

    //     // 4. Create OrderItemDiscrepancy record
    //     $discrepancy = OrderItemDiscrepancy::create([
    //         'order_id' => $orderItem->order_id,
    //         'order_item_id' => $orderItem->id,
    //         'user_id' => $userId,
    //         'user_name' => $userName,
    //         'issue_type' => $issueType,
    //         'comment' => $comment,
    //         'status' => 'open',
    //         'photo_url' => $photoUrl,
    //     ]);

    //     // 5. Mark item as flagged
    //     $orderItem->update([
    //         'is_flagged' => true,
    //         'flag_reason' => $issueType,
    //     ]);

    //     // 6. Log status action
    //     OrderStatusLog::create([
    //         'order_id' => $orderItem->order_id,
    //         'order_item_id' => $orderItem->id,
    //         'user_id' => $userId,
    //         'user_name' => $userName,
    //         'action' => 'item_discrepancy_flagged',
    //         'old_status' => $orderItem->status,
    //         'new_status' => $orderItem->status,
    //         'notes' => "Flagged discrepancy ({$issueType}): {$comment}",
    //     ]);

    //     return response()->json([
    //         'success' => true,
    //         'message' => 'Order item discrepancy flagged successfully.',
    //         'data' => [
    //             'id' => $discrepancy->id,
    //             'order_id' => $discrepancy->order_id,
    //             'order_item_id' => $discrepancy->order_item_id,
    //             'line_item_id' => $orderItem->line_item_id,
    //             'product_name' => $orderItem->product_name,
    //             'user_id' => $discrepancy->user_id,
    //             'user_name' => $discrepancy->user_name,
    //             'issue_type' => $discrepancy->issue_type,
    //             'comment' => $discrepancy->comment,
    //             'status' => $discrepancy->status,
    //             'photo_url' => $discrepancy->photo_url,
    //             'created_at' => $discrepancy->created_at ? $discrepancy->created_at->toIso8601String() : null,
    //         ],
    //     ]);
    // }
    public function flagItemDiscrepancy(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'nullable',
            'order_item_id' => 'nullable',
            'line_item_id' => 'nullable',
            'barcode' => 'nullable',
            'product_code' => 'nullable',
            'user_id' => 'nullable',
            'user_name' => 'nullable|string',
            'issue_type' => 'nullable|string',
            'comment' => 'required|string',
            'photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:10240',
            'photo_url' => 'nullable',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {

            // =========================================================
            // 1. Locate the OrderItem
            // =========================================================

            $itemQuery = OrderItem::query();

            if ($request->filled('order_item_id')) {

                $itemQuery->where(
                    'id',
                    $request->input('order_item_id')
                );

            } elseif ($request->filled('line_item_id')) {

                $itemQuery->where(
                    'line_item_id',
                    $request->input('line_item_id')
                );

            } elseif ($request->filled('barcode')) {

                $itemQuery->where(
                    'barcode',
                    $request->input('barcode')
                );

            } elseif ($request->filled('product_code')) {

                $itemQuery->where(
                    'product_code',
                    $request->input('product_code')
                );
            }

            // If order_id is provided, also filter by order_id
            if ($request->filled('order_id')) {
                $itemQuery->where(
                    'order_id',
                    $request->input('order_id')
                );
            }

            $orderItem = $itemQuery->first();

            if (!$orderItem) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order item not found with provided identifiers.',
                ], 404);
            }


            // =========================================================
            // 2. Handle Photo
            // =========================================================

            $photoUrl = null;

            /*
            * If an actual image file is uploaded,
            * store it and generate the public URL.
            *
            * This takes priority over photo_url.
            */
            if ($request->hasFile('photo')) {

                $path = $request->file('photo')->store(
                    'discrepancies',
                    'public'
                );

                $photoUrl = asset('storage/' . $path);

            } else {

                /*
                * photo_url is optional.
                *
                * Sometimes frontend applications may send:
                *
                * "photo_url": "https://example.com/image.jpg"
                *
                * or accidentally send an array/object.
                *
                * We only save it when it is actually a string.
                */
                $incomingPhotoUrl = $request->input('photo_url');

                if (is_string($incomingPhotoUrl)) {

                    $incomingPhotoUrl = trim($incomingPhotoUrl);

                    if ($incomingPhotoUrl !== '') {
                        $photoUrl = $incomingPhotoUrl;
                    }

                } elseif (is_array($incomingPhotoUrl)) {

                    /*
                    * If frontend sends:
                    *
                    * photo_url: {
                    *     url: "https://..."
                    * }
                    *
                    * or:
                    *
                    * photo_url: ["https://..."]
                    *
                    * try to extract the URL safely.
                    */

                    if (
                        isset($incomingPhotoUrl['url']) &&
                        is_string($incomingPhotoUrl['url'])
                    ) {
                        $photoUrl = trim($incomingPhotoUrl['url']);

                    } elseif (
                        isset($incomingPhotoUrl[0]) &&
                        is_string($incomingPhotoUrl[0])
                    ) {
                        $photoUrl = trim($incomingPhotoUrl[0]);
                    }
                }
            }


            // =========================================================
            // 3. Resolve User Details
            // =========================================================

            $userId = $request->input('user_id');

            if (!$userId) {
                $userId = Auth::id();
            }

            $userName = $request->input('user_name');

            if (!$userName && $userId) {

                $userObj = \App\Models\User::find($userId);

                $userName = $userObj
                    ? $userObj->name
                    : 'Warehouse User';
            }

            if (!$userName) {
                $userName = 'Warehouse User';
            }


            // =========================================================
            // 4. Issue Type & Comment
            // =========================================================

            $issueType = strtolower(
                trim(
                    $request->input('issue_type', 'damaged')
                )
            );

            $comment = trim(
                $request->input('comment')
            );


            // =========================================================
            // 5. Create OrderItemDiscrepancy
            // =========================================================

            $discrepancy = OrderItemDiscrepancy::create([
                'order_id' => $orderItem->order_id,
                'order_item_id' => $orderItem->id,
                'user_id' => $userId,
                'user_name' => $userName,
                'issue_type' => $issueType,
                'comment' => $comment,
                'status' => 'open',
                'photo_url' => $photoUrl,
            ]);


            // =========================================================
            // 6. Mark Order Item as Flagged
            // =========================================================

            $orderItem->update([
                'is_flagged' => true,
                'flag_reason' => $issueType,
            ]);


            // =========================================================
            // 7. Log Status Action
            // =========================================================

            OrderStatusLog::create([
                'order_id' => $orderItem->order_id,
                'order_item_id' => $orderItem->id,
                'user_id' => $userId,
                'user_name' => $userName,
                'action' => 'item_discrepancy_flagged',
                'old_status' => $orderItem->status,
                'new_status' => $orderItem->status,
                'notes' => "Flagged discrepancy ({$issueType}): {$comment}",
            ]);


            // =========================================================
            // 8. Return Response
            // =========================================================

            return response()->json([
                'success' => true,
                'message' => 'Order item discrepancy flagged successfully.',

                'data' => [
                    'id' => $discrepancy->id,

                    'order_id' => $discrepancy->order_id,

                    'order_item_id' => $discrepancy->order_item_id,

                    'line_item_id' => $orderItem->line_item_id,

                    'product_code' => $orderItem->product_code,

                    'product_name' => $orderItem->product_name,

                    'barcode' => $orderItem->barcode,

                    'user_id' => $discrepancy->user_id,

                    'user_name' => $discrepancy->user_name,

                    'issue_type' => $discrepancy->issue_type,

                    'comment' => $discrepancy->comment,

                    'status' => $discrepancy->status,

                    'photo_url' => $discrepancy->photo_url,

                    'created_at' => $discrepancy->created_at
                        ? $discrepancy->created_at->toIso8601String()
                        : null,
                ],
            ], 200);

        } catch (\Throwable $e) {

            \Log::error(
                'Error flagging order item discrepancy',
                [
                    'message' => $e->getMessage(),
                    'order_id' => $request->input('order_id'),
                    'order_item_id' => $request->input('order_item_id'),
                    'line_item_id' => $request->input('line_item_id'),
                    'barcode' => $request->input('barcode'),
                    'product_code' => $request->input('product_code'),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return response()->json([
                'success' => false,
                'message' => 'Failed to flag order item discrepancy.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    /**
     * Get list of reported order item discrepancies
     * Route: GET /api/orders/items/discrepancies
     */
    public function getDiscrepancies(Request $request)
    {
        $query = OrderItemDiscrepancy::with(['order', 'orderItem', 'user']);

        if ($request->filled('order_id')) {
            $query->where('order_id', $request->input('order_id'));
        }

        if ($request->filled('order_item_id')) {
            $query->where('order_item_id', $request->input('order_item_id'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('issue_type')) {
            $query->where('issue_type', $request->input('issue_type'));
        }

        $discrepancies = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $discrepancies->map(function ($d) {
                return [
                    'id' => $d->id,
                    'order_id' => $d->order_id,
                    'order_number' => $d->order ? $d->order->order_number : null,
                    'order_item_id' => $d->order_item_id,
                    'line_item_id' => $d->orderItem ? $d->orderItem->line_item_id : null,
                    'product_code' => $d->orderItem ? $d->orderItem->product_code : null,
                    'product_name' => $d->orderItem ? $d->orderItem->product_name : null,
                    'user_id' => $d->user_id,
                    'user_name' => $d->user_name ?? ($d->user ? $d->user->name : null),
                    'issue_type' => $d->issue_type,
                    'comment' => $d->comment,
                    'status' => $d->status,
                    'photo_url' => $d->photo_url,
                    'created_at' => $d->created_at ? $d->created_at->toIso8601String() : null,
                ];
            }),
        ]);
    }

    /**
     * Resolve or update status of an item discrepancy
     * Route: POST /api/orders/items/resolve-discrepancy
     */
    public function resolveDiscrepancy(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'discrepancy_id' => 'required',
            'status' => 'required|string',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $discrepancy = OrderItemDiscrepancy::find($request->input('discrepancy_id'));

        if (!$discrepancy) {
            return response()->json([
                'success' => false,
                'message' => 'Discrepancy record not found.',
            ], 404);
        }

        $newStatus = strtolower(trim($request->input('status')));
        $discrepancy->update([
            'status' => $newStatus,
        ]);

        if (in_array($newStatus, ['resolved', 'rejected'])) {
            $hasOpen = OrderItemDiscrepancy::where('order_item_id', $discrepancy->order_item_id)
                ->where('status', 'open')
                ->exists();
            if (!$hasOpen && $discrepancy->orderItem) {
                $discrepancy->orderItem->update(['is_flagged' => false, 'flag_reason' => null]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Discrepancy status updated successfully.',
            'data' => $discrepancy,
        ]);
    }
}
