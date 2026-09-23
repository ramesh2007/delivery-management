<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusLog;
use App\Services\ShopifyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PickerManagementController extends Controller
{
        protected ShopifyService $shopifyService;

    public function __construct(ShopifyService $shopifyService)
    {
        $this->shopifyService = $shopifyService;
    }

    /**
     * Get list of all orders with customer details and line items for mobile app.
     * Auto-syncs with Shopify silently so mobile users don't need a manual sync endpoint.
     * Route: GET /api/orders
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

    //     $assignedToFilter = $request->query('assigned_to');

    //     $relations = [
    //         'items' => function ($q) use ($assignedToFilter) {
    //             $q->where('status', 'pending');
    //             if (!empty($assignedToFilter)) {
    //                 $q->where('assigned_to', $assignedToFilter);
    //             } else {
    //                 $q->whereNull('assigned_to');
    //             }
    //         },

    //         'items.assignedUser',
    //         'items.pickedUser',
    //         'items.packedUser',
    //         'items.deliveredUser',
    //         'items.packerVerifiedUser',

    //         'assignedUser',
    //         'pickedUser',
    //         'packedUser',
    //         'deliveredUser',
    //     ];

    //     static $hasOrderInstallationsTable = null;
    //     if ($hasOrderInstallationsTable === null) {
    //         try {
    //             $hasOrderInstallationsTable = \Illuminate\Support\Facades\Schema::hasTable('order_installations');
    //         } catch (\Throwable $e) {
    //             $hasOrderInstallationsTable = false;
    //         }
    //     }

    //     if ($hasOrderInstallationsTable) {
    //         $relations[] = 'items.installation';
    //     }

    //     $query = Order::with($relations);

    //     // Optional order status filter
    //     if ($request->has('status') && !empty($request->query('status'))) {
    //         $query->where('status', $request->query('status'));
    //     }

    //     if (!empty($assignedToFilter)) {

    //         $query->whereHas('items', function ($q) use ($assignedToFilter) {
    //             $q->where('assigned_to', $assignedToFilter)
    //               ->where('status', 'pending');
    //         });

    //         $query->with([
    //             'items' => function ($q) use ($assignedToFilter) {
    //                 $q->where('assigned_to', $assignedToFilter)
    //                   ->where('status', 'pending');
    //             },
    //             'items.assignedUser',
    //             'items.pickedUser',
    //             'items.packedUser',
    //             'items.deliveredUser',
    //             'items.packerVerifiedUser',
    //         ]);

    //     } elseif ($request->boolean('unassigned')) {

    //         $query->whereHas('items', function ($q) {
    //             $q->whereNull('assigned_to')
    //               ->where('status', 'pending');
    //         });

    //     } else {

    //         $query->whereHas('items', function ($q) {
    //             $q->whereNull('assigned_to')
    //               ->where('status', 'pending');
    //         });
    //     }

    //     // Search
    //     if ($request->has('search')) {

    //         $search = $request->query('search');

    //         $query->where(function ($q) use ($search) {

    //             $q->where('order_number', 'like', "%{$search}%")
    //               ->orWhere('customer_name', 'like', "%{$search}%")
    //               ->orWhere('customer_phone', 'like', "%{$search}%")
    //               ->orWhere('assigned_user_name', 'like', "%{$search}%");

    //         });
    //     }

    //     $orders = $query
    //         ->orderBy('created_at', 'desc')
    //         ->get();

    //     // Format response
    //     $formattedOrders = $orders
    //         ->map(function ($order) use ($assignedToFilter) {

    //             $order->setRelation(
    //                 'items',
    //                 $order->items
    //                     ->filter(function ($item) use ($assignedToFilter) {
    //                         if (!empty($assignedToFilter)) {
    //                             return $item->status === 'pending'
    //                                 && (string)$item->assigned_to === (string)$assignedToFilter;
    //                         }
    //                         return $item->status === 'pending'
    //                             && is_null($item->assigned_to);
    //                     })
    //                     ->values()
    //             );

    //             // If no matching items left, don't return the order
    //             if ($order->items->isEmpty()) {
    //                 return null;
    //             }

    //             $totalItems = $order->items->count();

    //             $pickedItems = $order->items
    //                 ->where('status', 'picked')
    //                 ->count();

    //             $packedItems = $order->items
    //                 ->where('status', 'packed')
    //                 ->count();

    //             $deliveredItems = $order->items
    //                 ->where('status', 'delivered')
    //                 ->count();

    //             $pickers = $order->items
    //                 ->map(function ($item) {

    //                     if ($item->picked_by || $item->picked_user_name) {

    //                         return [
    //                             'id' => $item->picked_by
    //                                 ? (int) $item->picked_by
    //                                 : null,

    //                             'name' => $item->pickedUser
    //                                 ? $item->pickedUser->name
    //                                 : ($item->picked_user_name ?? 'Picker User'),

    //                             'picked_at' => $item->picked_at
    //                                 ? $item->picked_at->toIso8601String()
    //                                 : null,
    //                         ];
    //                     }

    //                     return null;

    //                 })
    //                 ->filter()
    //                 ->unique('name')
    //                 ->values();

    //             $packers = $order->items
    //                 ->map(function ($item) {

    //                     if ($item->packed_by || $item->packed_user_name) {

    //                         return [
    //                             'id' => $item->packed_by
    //                                 ? (int) $item->packed_by
    //                                 : null,

    //                             'name' => $item->packedUser
    //                                 ? $item->packedUser->name
    //                                 : ($item->packed_user_name ?? 'Packer User'),

    //                             'packed_at' => $item->packed_at
    //                                 ? $item->packed_at->toIso8601String()
    //                                 : null,
    //                         ];
    //                     }

    //                     return null;

    //                 })
    //                 ->filter()
    //                 ->unique('name')
    //                 ->values();

    //             return [
    //                 'order_id' => $order->id,

    //                 'order_number' => $order->order_number,

    //                 'status' => $order->status,

    //                 'bag_count' => (int) ($order->bag_count ?? 0),

    //                 'assigned_user' => $order->assigned_to ? [
    //                     'id' => (int) $order->assigned_to,

    //                     'name' => $order->assignedUser
    //                         ? $order->assignedUser->name
    //                         : ($order->assigned_user_name ?? 'Picker User'),

    //                     'assigned_at' => $order->assigned_at
    //                         ? $order->assigned_at->toIso8601String()
    //                         : null,
    //                 ] : null,

    //                 'driver_user' => ($order->delivered_by || $order->delivered_user_name)
    //                     ? [
    //                         'id' => $order->delivered_by
    //                             ? (int) $order->delivered_by
    //                             : null,

    //                         'name' => $order->deliveredUser
    //                             ? $order->deliveredUser->name
    //                             : ($order->delivered_user_name ?? 'Driver User'),

    //                         'delivered_at' => $order->delivered_at
    //                             ? $order->delivered_at->toIso8601String()
    //                             : null,
    //                     ]
    //                     : null,

    //                 'pickers' => $pickers,

    //                 'packers' => $packers,

    //                 'customer' => [
    //                     'name' => $order->customer_name ?? 'N/A',

    //                     'phone' => $order->customer_phone ?? 'N/A',

    //                     'delivery_address' => $order->delivery_address ?? 'N/A',
    //                 ],

    //                 'summary' => [
    //                     'total_amount' => (float) $order->total_amount,

    //                     'total_items' => $totalItems,

    //                     'picked_items' => $pickedItems,

    //                     'packed_items' => $packedItems,

    //                     'delivered_items' => $deliveredItems,
    //                 ],

    //                 'items' => $order->items
    //                     ->map(function ($item) {

    //                         return [
    //                             'item_id' => $item->id,

    //                             'line_item_id' => $item->line_item_id,

    //                             'product_id' => $item->product_id,

    //                             'product_code' => $item->product_code,

    //                             'barcode' => $item->barcode,

    //                             'product_name' => $item->product_name,

    //                             'image' => $item->image,

    //                             'image_url' => $item->image,

    //                             'product_image' => $item->image,

    //                             'product_image_url' => $item->image,

    //                             'quantity' => $item->quantity,

    //                             'unit_price' => (float) $item->unit_price,

    //                             'status' => $item->status,

    //                             'is_packer_verified' => (bool) $item->is_packer_verified,

    //                             'packer_verified_user' =>
    //                                 ($item->packer_verified_by || $item->packer_verified_user_name)
    //                                     ? [
    //                                         'id' => $item->packer_verified_by
    //                                             ? (int) $item->packer_verified_by
    //                                             : null,

    //                                         'name' => $item->packerVerifiedUser
    //                                             ? $item->packerVerifiedUser->name
    //                                             : ($item->packer_verified_user_name ?? 'Packer User'),

    //                                         'verified_at' => $item->packer_verified_at
    //                                             ? $item->packer_verified_at->toIso8601String()
    //                                             : null,
    //                                     ]
    //                                     : null,
    //                             'is_installable' => (bool) $item->is_installable,
    //                             'installation' => $item->installation ? [
    //                                 'id' => $item->installation->id,
    //                                 'installation_type' => $item->installation->installation_type,
    //                                 'installation_level' => $item->installation->installation_level,
    //                             ] : null,
    //                             'is_flagged' => $item->is_flagged,
    //                             'flag_reason' => $item->flag_reason,
    //                             'assigned_user' => $item->assigned_to
    //                                 ? [
    //                                     'id' => (int) $item->assigned_to,

    //                                     'name' => $item->assignedUser
    //                                         ? $item->assignedUser->name
    //                                         : ($item->assigned_user_name ?? 'Picker User'),

    //                                     'assigned_at' => $item->assigned_at
    //                                         ? $item->assigned_at->toIso8601String()
    //                                         : null,
    //                                 ]
    //                                 : null,

    //                             'picked_user' =>
    //                                 ($item->picked_by || $item->picked_user_name)
    //                                     ? [
    //                                         'id' => $item->picked_by
    //                                             ? (int) $item->picked_by
    //                                             : null,

    //                                         'name' => $item->pickedUser
    //                                             ? $item->pickedUser->name
    //                                             : ($item->picked_user_name ?? 'Picker User'),

    //                                         'picked_at' => $item->picked_at
    //                                             ? $item->picked_at->toIso8601String()
    //                                             : null,
    //                                     ]
    //                                     : null,

    //                             'packed_user' =>
    //                                 ($item->packed_by || $item->packed_user_name)
    //                                     ? [
    //                                         'id' => $item->packed_by
    //                                             ? (int) $item->packed_by
    //                                             : null,

    //                                         'name' => $item->packedUser
    //                                             ? $item->packedUser->name
    //                                             : ($item->packed_user_name ?? 'Packer User'),

    //                                         'packed_at' => $item->packed_at
    //                                             ? $item->packed_at->toIso8601String()
    //                                             : null,
    //                                     ]
    //                                     : null,

    //                             'delivered_user' =>
    //                                 ($item->delivered_by || $item->delivered_user_name)
    //                                     ? [
    //                                         'id' => $item->delivered_by
    //                                             ? (int) $item->delivered_by
    //                                             : null,

    //                                         'name' => $item->deliveredUser
    //                                             ? $item->deliveredUser->name
    //                                             : ($item->delivered_user_name ?? 'Driver User'),

    //                                         'delivered_at' => $item->delivered_at
    //                                             ? $item->delivered_at->toIso8601String()
    //                                             : null,
    //                                     ]
    //                                     : null,

    //                             'picked_at' => $item->picked_at
    //                                 ? $item->picked_at->toIso8601String()
    //                                 : null,

    //                             'packed_at' => $item->packed_at
    //                                 ? $item->packed_at->toIso8601String()
    //                                 : null,

    //                             'delivered_at' => $item->delivered_at
    //                                 ? $item->delivered_at->toIso8601String()
    //                                 : null,
    //                         ];
    //                     })
    //                     ->values(),

    //                 'created_at' => $order->created_at
    //                     ? $order->created_at->toIso8601String()
    //                     : null,
    //             ];
    //         })
    //         ->filter()
    //         ->values();

    //     return response()->json([
    //         'success' => true,
    //         'message' => 'Orders retrieved successfully.',
    //         'count' => $formattedOrders->count(),
    //         'data' => $formattedOrders,
    //     ]);
    // }
//   public function index(Request $request)
// {
//     $query = Order::query()
//         ->with([
//             'items',
//             // 'items.vendor',
//             // 'items.product',
//             // 'items.rack',
//             // 'items.bin',
//         ]);

//     /*
//     |--------------------------------------------------------------------------
//     | Search
//     |--------------------------------------------------------------------------
//     */
//     if ($request->filled('search')) {
//         $search = $request->input('search');

//         $query->where(function ($q) use ($search) {
//             $q->where('order_number', 'like', "%{$search}%")
//                 ->orWhere('customer_name', 'like', "%{$search}%")
//                 ->orWhere('customer_phone', 'like', "%{$search}%")
//                 ->orWhere('email', 'like', "%{$search}%");
//         });
//     }

//     /*
//     |--------------------------------------------------------------------------
//     | Order status filter
//     |--------------------------------------------------------------------------
//     */
//     if ($request->filled('status')) {
//         $query->where('status', $request->input('status'));
//     }

//     /*
//     |--------------------------------------------------------------------------
//     | Assigned picker filter
//     |--------------------------------------------------------------------------
//     */
//     if ($request->filled('assigned_to')) {
//         $assignedTo = $request->input('assigned_to');

//         $query->whereHas('items', function ($q) use ($assignedTo) {
//             $q->where('assigned_to', $assignedTo);
//         });
//     }

//     /*
//     |--------------------------------------------------------------------------
//     | Pagination
//     |--------------------------------------------------------------------------
//     | Always 15 orders per page
//     |--------------------------------------------------------------------------
//     */
//     $orders = $query
//         ->orderBy('created_at', 'desc')
//         ->paginate(15);

//     /*
//     |--------------------------------------------------------------------------
//     | Format orders
//     |--------------------------------------------------------------------------
//     */
//     $orders->getCollection()->transform(function ($order) {

//         return [
//             /*
//             |--------------------------------------------------------------------------
//             | Order details
//             |--------------------------------------------------------------------------
//             */
//             'order_number' => $order->order_number,

//             'created_at' => $order->created_at
//                 ? $order->created_at->toIso8601String()
//                 : null,

//             'status' => $order->status,

//             'financial_status' => $order->financial_status,

//             'total_price' => $order->total_price,

//             'currency' => $order->currency,

//             'customer_name' => $order->customer_name,

//             'email' => $order->email,

//             'phone' => $order->customer_phone ?? $order->phone,

//             'bag_count' => $order->bag_count,

//             'shipping_address' => $order->shipping_address,

//             /*
//             |--------------------------------------------------------------------------
//             | Order items
//             |--------------------------------------------------------------------------
//             */
//             'items' => $order->items->map(function ($item) {

//                 return [
//                     'line_item_id' => $item->line_item_id,

//                     'product_name' => $item->product_name,

//                     'sku' => $item->sku ?? $item->product_code,

//                     'barcode' => $item->barcode,

//                     'quantity' => (int) $item->quantity,

//                     'unit_price' => $item->unit_price,

//                     'vendor' => $item->vendor,

//                     'rack' => $item->rack,

//                     'bin' => $item->bin,

//                     'imageUrl' => $item->imageUrl
//                         ?? $item->image_url
//                         ?? $item->image,

//                     'status' => $item->status,

//                     'is_assigned' => !is_null($item->assigned_to),

//                     'is_flagged' => (bool) $item->is_flagged,

//                     'flag_reason' => $item->flag_reason,

//                     /*
//                     |--------------------------------------------------------------------------
//                     | Uncomment if required later
//                     |--------------------------------------------------------------------------
//                     */

//                     // 'item_id' => $item->id,

//                     // 'product_id' => $item->product_id,

//                     // 'assigned_to' => $item->assigned_to,

//                     // 'assigned_user_name' => $item->assigned_user_name,

//                     // 'picked_by' => $item->picked_by,

//                     // 'picked_user_name' => $item->picked_user_name,

//                     // 'picked_at' => $item->picked_at,

//                     // 'packed_by' => $item->packed_by,

//                     // 'packed_user_name' => $item->packed_user_name,

//                     // 'packed_at' => $item->packed_at,

//                     // 'delivered_by' => $item->delivered_by,

//                     // 'delivered_user_name' => $item->delivered_user_name,

//                     // 'delivered_at' => $item->delivered_at,

//                     // 'is_packer_verified' => $item->is_packer_verified,

//                     // 'packer_verified_by' => $item->packer_verified_by,

//                     // 'packer_verified_user_name' => $item->packer_verified_user_name,

//                     // 'packer_verified_at' => $item->packer_verified_at,
//                 ];
//             })->values(),
//         ];
//     });

//     /*
//     |--------------------------------------------------------------------------
//     | Response
//     |--------------------------------------------------------------------------
//     */
//     return response()->json([
//         'success' => true,

//         'data' => $orders->items(),

//         'pagination' => [
//             'current_page' => $orders->currentPage(),
//             'per_page' => $orders->perPage(),
//             'total' => $orders->total(),
//             'last_page' => $orders->lastPage(),
//             'from' => $orders->firstItem(),
//             'to' => $orders->lastItem(),
//             'has_more_pages' => $orders->hasMorePages(),
//             'next_page_url' => $orders->nextPageUrl(),
//             'previous_page_url' => $orders->previousPageUrl(),
//         ],
//     ]);
// }

    public function index(Request $request)
    {
        $query = Order::query()
            ->with([
                'items',
                // 'items.vendor',
                // 'items.product',
                // 'items.rack',
                // 'items.bin',
            ])
            ->where('status', 'pending'); // Only pending orders
    
        /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        */
        if ($request->filled('search')) {
            $search = $request->input('search');
    
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }
    
        /*
        |--------------------------------------------------------------------------
        | Assigned picker filter
        |--------------------------------------------------------------------------
        */
        if ($request->filled('assigned_to')) {
            $assignedTo = $request->input('assigned_to');
    
            $query->whereHas('items', function ($q) use ($assignedTo) {
                $q->where('assigned_to', $assignedTo);
            });
        }
    
        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */
        $orders = $query
            ->orderBy('created_at', 'desc')
            ->paginate(15);
    
        /*
        |--------------------------------------------------------------------------
        | Format orders
        |--------------------------------------------------------------------------
        */
        $orders->getCollection()->transform(function ($order) {
    
            return [
                'order_number' => $order->order_number,
    
                'created_at' => $order->created_at
                    ? $order->created_at->toIso8601String()
                    : null,
    
                'status' => $order->status,
    
                'financial_status' => $order->financial_status,
    
                'total_price' => $order->total_price,
    
                'currency' => $order->currency,
    
                'customer_name' => $order->customer_name,
    
                'email' => $order->email,
    
                'phone' => $order->customer_phone ?? $order->phone,
    
                'bag_count' => $order->bag_count,
    
                'shipping_address' => $order->shipping_address,
    
                'items' => $order->items->map(function ($item) {
    
                    return [
                        'line_item_id' => $item->line_item_id,
    
                        'product_name' => $item->product_name,
    
                        'sku' => $item->sku ?? $item->product_code,
    
                        'barcode' => $item->barcode,
    
                        'quantity' => (int) $item->quantity,
    
                        'unit_price' => $item->unit_price,
    
                        'vendor' => $item->vendor,
    
                        'rack' => $item->rack,
    
                        'bin' => $item->bin,
    
                        'imageUrl' => $item->imageUrl
                            ?? $item->image_url
                            ?? $item->image,
    
                        'status' => $item->status,
    
                        'is_assigned' => !is_null($item->assigned_to),
    
                        'is_flagged' => (bool) $item->is_flagged,
    
                        'flag_reason' => $item->flag_reason,
                    ];
                })->values(),
            ];
        });
    
        /*
        |--------------------------------------------------------------------------
        | Response
        |--------------------------------------------------------------------------
        */
        return response()->json([
            'success' => true,
    
            'data' => $orders->items(),
    
            'pagination' => [
                'current_page' => $orders->currentPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
                'last_page' => $orders->lastPage(),
                'from' => $orders->firstItem(),
                'to' => $orders->lastItem(),
                'has_more_pages' => $orders->hasMorePages(),
                'next_page_url' => $orders->nextPageUrl(),
                'previous_page_url' => $orders->previousPageUrl(),
            ],
        ]);
    }
    /**
     * Get all assigned orders and line item details for a specific user ID (or order ID)
     * Route: GET /api/orders/{id}
     *
     * Returns an array of orders with line items assigned to user_id that are NOT YET PICKED.
     */


    // public function apiOrdersById(Request $request, $id)
    // {
    //     $idStr = trim((string) $id);
    
    //     /*
    //     |--------------------------------------------------------------------------
    //     | Get orders from local database
    //     |
    //     | orders.status = picking
    //     | order_items.assigned_to = requested picker/user ID
    //     |--------------------------------------------------------------------------
    //     */
    //     $query = Order::with([
    //         'items' => function ($query) use ($idStr) {
    //             $query->where('assigned_to', $idStr);
    //         }
    //     ])
    //     ->where('status', 'picking')
    //     ->whereHas('items', function ($query) use ($idStr) {
    //         $query->where('assigned_to', $idStr);
    //     });
    
    //     /*
    //     |--------------------------------------------------------------------------
    //     | Pagination
    //     |--------------------------------------------------------------------------
    //     */
    //     $orders = $query
    //         ->orderBy('created_at', 'desc')
    //         ->paginate(15);
    
    //     /*
    //     |--------------------------------------------------------------------------
    //     | Format orders
    //     |--------------------------------------------------------------------------
    //     */
    //     $orders->getCollection()->transform(function ($order) {
    
    //         return [
    //             'order_number' => $order->order_number,
    
    //             'created_at' => $order->created_at
    //                 ? $order->created_at->toIso8601String()
    //                 : null,
    
    //             'status' => $order->status,
    
    //             // Keep commented for now
    //             // 'financial_status' => $order->financial_status,
    
    //             // 'total_price' => $order->total_price,
    
    //             // 'currency' => $order->currency,
    
    //             'customer_name' => $order->customer_name ?? null,
    
    //             // 'email' => $order->email,
    
    //             // 'phone' => $order->customer_phone
    //             //     ?? $order->phone,
    
    //             'bag_count' => (string) ($order->bag_count ?? 0),
    
    //             // 'shipping_address' => $order->shipping_address,
    
    //             /*
    //             |--------------------------------------------------------------------------
    //             | Only items assigned to this picker
    //             |--------------------------------------------------------------------------
    //             */
    //             'items' => $order->items
    //                 ->map(function ($item) {
    
    //                     return [
    //                         'line_item_id' => $item->line_item_id,
    
    //                         'product_name' => $item->product_name,
    
    //                         'sku' => $item->product_code ?? null,
    
    //                         'barcode' => $item->barcode,
    
    //                         'quantity' => (int) $item->quantity,
    
    //                         'unit_price' => $item->unit_price !== null
    //                             ? (string) $item->unit_price
    //                             : null,
    
    //                         'vendor' => $item->vendor
    //                             ?? $item->vendor_name
    //                             ?? null,
    
    //                         'rack' => $item->rack ?? null,
    
    //                         'bin' => $item->bin ?? null,
    
    //                         'imageUrl' => $item->image
    //                             ?? $item->image_url
    //                             ?? null,
    
    //                         'status' => $item->status,
    
    //                         'is_assigned' => !empty($item->assigned_to),
    
    //                         'is_flagged' => (bool) $item->is_flagged,
    
    //                         'flag_reason' => $item->flag_reason,
    //                     ];
    //                 })
    //                 ->values(),
    //         ];
    //     });
    
    //     /*
    //     |--------------------------------------------------------------------------
    //     | Response
    //     |--------------------------------------------------------------------------
    //     */
    //     return response()->json([
    //         'success' => true,
    
    //         'data' => $orders->items(),
    
    //         'pagination' => [
    //             'current_page' => $orders->currentPage(),
    //             'per_page' => $orders->perPage(),
    //             'total' => $orders->total(),
    //             'last_page' => $orders->lastPage(),
    //             'from' => $orders->firstItem(),
    //             'to' => $orders->lastItem(),
    //             'has_more_pages' => $orders->hasMorePages(),
    //             'next_page_url' => $orders->nextPageUrl(),
    //             'previous_page_url' => $orders->previousPageUrl(),
    //         ],
    //     ]);
    // }
    public function apiOrdersById(Request $request, $id)
{
    $idStr = trim((string) $id);

    /*
    |--------------------------------------------------------------------------
    | Get orders where at least ONE item is assigned to this picker
    |
    | Example:
    |
    | Order #1101
    |   Item 1 -> assigned_to = 5
    |   Item 2 -> assigned_to = 6
    |   Item 3 -> NULL
    |
    | Picker 5 will see Order #1101 with Item 1.
    | Picker 6 will see Order #1101 with Item 2.
    |--------------------------------------------------------------------------
    */

    $query = Order::with([
        'items' => function ($query) use ($idStr) {
            // Only return items assigned to requested picker
            $query->where('assigned_to', $idStr);
        }
    ])
    ->where('status', 'picking')

    // Important:
    // Order must have at least one item assigned
    // to this requested picker.
    ->whereHas('items', function ($query) use ($idStr) {
        $query->where('assigned_to', $idStr);
    });


    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    */

    $orders = $query
        ->orderBy('created_at', 'desc')
        ->paginate(15);


    /*
    |--------------------------------------------------------------------------
    | Format orders
    |--------------------------------------------------------------------------
    */

    $orders->getCollection()->transform(function ($order) {

        return [
            'order_number' => $order->order_number,

            'created_at' => $order->created_at
                ? $order->created_at->toIso8601String()
                : null,

            'status' => $order->status,

            'customer_name' => $order->customer_name ?? null,

            'bag_count' => (string) ($order->bag_count ?? 0),

            /*
            |--------------------------------------------------------------------------
            | ONLY items assigned to this picker
            |--------------------------------------------------------------------------
            |
            | Even if the order has 3 items, if this picker owns
            | only 1 item, only that 1 item is returned.
            |
            */

            'items' => $order->items
                ->map(function ($item) {

                    return [
                        'id' => $item->id,

                        'line_item_id' => $item->line_item_id,

                        'product_name' => $item->product_name,

                        'sku' => $item->product_code ?? null,

                        'barcode' => $item->barcode,

                        'quantity' => (int) $item->quantity,

                        'unit_price' => $item->unit_price !== null
                            ? (string) $item->unit_price
                            : null,

                        'vendor' => $item->vendor
                            ?? $item->vendor_name
                            ?? null,

                        'rack' => $item->rack ?? null,

                        'bin' => $item->bin ?? null,

                        'imageUrl' => $item->image
                            ?? $item->image_url
                            ?? null,

                        'status' => $item->status,

                        'assigned_to' => $item->assigned_to,

                        'assigned_user_name' =>
                            $item->assigned_user_name,

                        'assigned_at' => $item->assigned_at
                            ? $item->assigned_at->toIso8601String()
                            : null,

                        'is_assigned' => !empty($item->assigned_to),

                        'is_flagged' => (bool) $item->is_flagged,

                        'flag_reason' => $item->flag_reason,
                    ];
                })
                ->values(),
        ];
    });


    /*
    |--------------------------------------------------------------------------
    | Response
    |--------------------------------------------------------------------------
    */

    return response()->json([
        'success' => true,

        'data' => $orders->items(),

        'pagination' => [
            'current_page' => $orders->currentPage(),

            'per_page' => $orders->perPage(),

            'total' => $orders->total(),

            'last_page' => $orders->lastPage(),

            'from' => $orders->firstItem(),

            'to' => $orders->lastItem(),

            'has_more_pages' => $orders->hasMorePages(),

            'next_page_url' => $orders->nextPageUrl(),

            'previous_page_url' => $orders->previousPageUrl(),
        ],
    ]);
}
    /**
     * Get completed/picked order details for a specific user ID where items were picked by that user
     * Route: GET /api/orders-complete/{id}
     *
     * Returns an array of orders & picked line items by user_id.
     */
    // public function apiOrdersComplete(Request $request, $id)
    // {
    //     $idStr = trim((string) $id);

    //   $completedOrders = Order::with([
    //         'items.assignedUser',
    //         'items.pickedUser',
    //         'items.packedUser',
    //         'items.deliveredUser',
    //         'items.packerVerifiedUser',
    //         'assignedUser',
    //         'deliveredUser',
    //         'logs.user'
    //     ])
    //     ->where('status', 'picked')
    //     ->whereHas('items', function ($q) use ($idStr) {
    //         $q->where('picked_by', $idStr)
    //           ->orWhere(function ($sub) use ($idStr) {
    //               $sub->where('assigned_to', $idStr)
    //                   ->where('status', 'picked');
    //           });
    //     })
    //     ->orderBy('updated_at', 'desc')
    //     ->get();

    //     $formattedOrders = $completedOrders->map(fn($order) => $this->formatOrderDetails($order, 'picked'))
    //         ->filter(fn($ord) => count($ord['items']) > 0)
    //         ->values();

    //     return response()->json([
    //         'success' => true,
    //         'data' => $formattedOrders,
    //     ]);
    // }
    public function apiOrdersComplete(Request $request, $id)
{
    $idStr = trim((string) $id);

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    */
    $perPage = (int) $request->query('per_page', 15);
    $perPage = max(1, min($perPage, 100));

    /*
    |--------------------------------------------------------------------------
    | Get picked orders
    |
    | Order status = picked
    | AND item is either:
    |   - picked_by = requested user
    |   - OR assigned_to = requested user AND item status = picked
    |--------------------------------------------------------------------------
    */
    $orders = Order::with([
        'items.assignedUser',
        'items.pickedUser',
        'items.packedUser',
        'items.deliveredUser',
        'items.packerVerifiedUser',
        'assignedUser',
        'deliveredUser',
    ])
    ->where('status', 'picked')
    ->whereHas('items', function ($q) use ($idStr) {
        $q->where('picked_by', $idStr)
            ->orWhere(function ($sub) use ($idStr) {
                $sub->where('assigned_to', $idStr)
                    ->where('status', 'picked');
            });
    })
    ->orderBy('updated_at', 'desc')
    ->paginate($perPage);

    /*
    |--------------------------------------------------------------------------
    | Format orders
    |--------------------------------------------------------------------------
    */
    $orders->getCollection()->transform(function ($order) use ($idStr) {

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
        | Only items related to this user
        |--------------------------------------------------------------------------
        */
        $items = $order->items
            ->filter(function ($item) use ($idStr) {
                return (string) $item->picked_by === $idStr
                    || (
                        (string) $item->assigned_to === $idStr
                        && $item->status === 'picked'
                    );
            })
            ->values();

        return [
            'order_id' => $order->id,

            'order_number' => $order->order_number,

            'status' => $order->status,

            'bag_count' => (int) ($order->bag_count ?? 0),

            'assigned_to' => $order->assigned_to
                ? (int) $order->assigned_to
                : null,

            'assigned_user_name' => $order->assigned_user_name
                ?: ($order->assignedUser->name ?? null),

            'picked_by' => $order->items
                ->whereNotNull('picked_by')
                ->pluck('picked_by')
                ->first()
                ? (int) $order->items
                    ->whereNotNull('picked_by')
                    ->pluck('picked_by')
                    ->first()
                : (
                    $order->assigned_to
                        ? (int) $order->assigned_to
                        : null
                ),

            'picked_user_name' => $order->assigned_user_name
                ?: ($order->assignedUser->name ?? null),

            'packed_by' => $order->items
                ->whereNotNull('packed_by')
                ->pluck('packed_by')
                ->first()
                ? (int) $order->items
                    ->whereNotNull('packed_by')
                    ->pluck('packed_by')
                    ->first()
                : null,

            'packed_user_name' => $order->items
                ->whereNotNull('packed_user_name')
                ->pluck('packed_user_name')
                ->first()
                ?: (
                    $order->items
                        ->map(fn($i) => $i->packedUser->name ?? null)
                        ->filter()
                        ->first()
                ),

            'delivered_by' => $order->delivered_by
                ? (int) $order->delivered_by
                : null,

            'delivered_user_name' => $order->delivered_user_name
                ?: ($order->deliveredUser->name ?? null),

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

                'total_items' => $order->items->count(),

                'picked_items' => $order->items
                    ->where('status', 'picked')
                    ->count(),

                'packed_items' => $order->items
                    ->where('status', 'packed')
                    ->count(),

                'delivered_items' => $order->items
                    ->where('status', 'delivered')
                    ->count(),
            ],

            /*
            |--------------------------------------------------------------------------
            | Items
            |--------------------------------------------------------------------------
            */
            'items' => $items->map(function ($item) {

                return [
                    'item_id' => $item->id,

                    'line_item_id' => $item->line_item_id,

                    'product_id' => $item->product_id,

                    'product_code' => $item->product_code,

                    'barcode' => $item->barcode,

                    'product_name' => $item->product_name,

                    'image' => $item->image,

                    'image_url' => $item->image,

                    'quantity' => (int) $item->quantity,

                    'unit_price' => (float) $item->unit_price,

                    'status' => $item->status,

                    'is_packer_verified' => (bool) $item->is_packer_verified,

                    'packer_verified_user' => (
                        $item->packer_verified_by
                        || $item->packer_verified_user_name
                    ) ? [
                        'id' => $item->packer_verified_by
                            ? (int) $item->packer_verified_by
                            : null,

                        'name' => $item->packerVerifiedUser
                            ? $item->packerVerifiedUser->name
                            : ($item->packer_verified_user_name ?? 'Packer User'),

                        'verified_at' => $item->packer_verified_at
                            ? $item->packer_verified_at->toIso8601String()
                            : null,
                    ] : null,

                    'is_installable' => (bool) $item->is_installable,

                    'installation' => $item->installation ? [
                        'id' => $item->installation->id,

                        'installation_type' => $item->installation->installation_type,

                        'installation_level' => $item->installation->installation_level,
                    ] : null,

                    'is_flagged' => (bool) $item->is_flagged,

                    'flag_reason' => $item->flag_reason,

                    'assigned_to' => $item->assigned_to
                        ? (int) $item->assigned_to
                        : null,

                    'assigned_user_name' => $item->assigned_user_name
                        ?: ($item->assignedUser->name ?? null),

                    'picked_by' => $item->picked_by
                        ? (int) $item->picked_by
                        : ($item->picked_user_name ?: null),

                    'picked_user_name' => $item->picked_user_name
                        ?: ($item->pickedUser->name ?? null),

                    'packed_by' => $item->packed_by
                        ? (int) $item->packed_by
                        : ($item->packed_user_name ?: null),

                    'packed_user_name' => $item->packed_user_name
                        ?: ($item->packedUser->name ?? null),

                    'delivered_by' => $item->delivered_by
                        ? (int) $item->delivered_by
                        : ($item->delivered_user_name ?: null),

                    'delivered_user_name' => $item->delivered_user_name
                        ?: ($item->deliveredUser->name ?? null),

                    'assigned_user' => $item->assigned_to ? [
                        'id' => (int) $item->assigned_to,

                        'name' => $item->assignedUser
                            ? $item->assignedUser->name
                            : ($item->assigned_user_name ?? 'Picker User'),

                        'assigned_at' => $item->assigned_at
                            ? $item->assigned_at->toIso8601String()
                            : null,
                    ] : null,

                    'picked_user' => (
                        $item->picked_by
                        || $item->picked_user_name
                    ) ? [
                        'id' => $item->picked_by
                            ? (int) $item->picked_by
                            : null,

                        'name' => $item->pickedUser
                            ? $item->pickedUser->name
                            : ($item->picked_user_name ?? 'Picker User'),

                        'picked_at' => $item->picked_at
                            ? $item->picked_at->toIso8601String()
                            : null,
                    ] : null,

                    'packed_user' => (
                        $item->packed_by
                        || $item->packed_user_name
                    ) ? [
                        'id' => $item->packed_by
                            ? (int) $item->packed_by
                            : null,

                        'name' => $item->packedUser
                            ? $item->packedUser->name
                            : ($item->packed_user_name ?? 'Packer User'),

                        'packed_at' => $item->packed_at
                            ? $item->packed_at->toIso8601String()
                            : null,
                    ] : null,

                    'delivered_user' => (
                        $item->delivered_by
                        || $item->delivered_user_name
                    ) ? [
                        'id' => $item->delivered_by
                            ? (int) $item->delivered_by
                            : null,

                        'name' => $item->deliveredUser
                            ? $item->deliveredUser->name
                            : ($item->delivered_user_name ?? 'Driver User'),

                        'delivered_at' => $item->delivered_at
                            ? $item->delivered_at->toIso8601String()
                            : null,
                    ] : null,

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
            })->values(),

            'created_at' => $order->created_at
                ? $order->created_at->toIso8601String()
                : null,
        ];
    });

    /*
    |--------------------------------------------------------------------------
    | Response - same pagination structure as index()
    |--------------------------------------------------------------------------
    */
    return response()->json([
        'success' => true,

        'data' => $orders->items(),

        'pagination' => [
            'current_page' => $orders->currentPage(),

            'per_page' => $orders->perPage(),

            'total' => $orders->total(),

            'last_page' => $orders->lastPage(),

            'from' => $orders->firstItem(),

            'to' => $orders->lastItem(),

            'has_more_pages' => $orders->hasMorePages(),

            'next_page_url' => $orders->nextPageUrl(),

            'previous_page_url' => $orders->previousPageUrl(),
        ],
    ]);
}

    /**
     * Assign order to picker ("Assign Me")
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

        $user = Auth::user();
        $userId = $user ? $user->id : ($request->input('picker_id') ?? $request->input('user_id') ?? $request->input('assigned_to'));
        if ($userId && is_numeric($userId)) {
            $userId = (int) $userId;
        }

        $dbUser = $userId ? \App\Models\User::find($userId) : null;
        $userName = $user ? $user->name : ($dbUser ? $dbUser->name : ($request->input('picker_name') ?? $request->input('user_name')));

        if (!$userId && $userName) {
            $foundUser = \App\Models\User::where('name', 'like', "%{$userName}%")->first();
            if ($foundUser) {
                $userId = $foundUser->id;
                $userName = $foundUser->name;
            }
        }

        if (!$userName) {
            $userName = 'Picker User';
        }

        if (!$userId && !$userName) {
            return response()->json([
                'success' => false,
                'message' => 'User identification required. Pass user_id, picker_id, assigned_to, user_name, or authenticate with Sanctum.',
            ], 422);
        }

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

    // ============================================================
    // 1. Identify picker
    // ============================================================

    $authUser = Auth::user();

    $userId = $authUser
        ? $authUser->id
        : (
            $request->input('picker_id')
            ?? $request->input('user_id')
            ?? $request->input('assigned_to')
        );

    if ($userId !== null && is_numeric($userId)) {
        $userId = (int) $userId;
    }

    $dbUser = $userId
        ? \App\Models\User::find($userId)
        : null;

    $userName = $authUser
        ? $authUser->name
        : (
            $dbUser
                ? $dbUser->name
                : (
                    $request->input('picker_name')
                    ?? $request->input('user_name')
                )
        );

    // If user ID was not supplied, try finding by name
    if (!$userId && $userName) {

        $foundUser = \App\Models\User::where(
            'name',
            'like',
            "%{$userName}%"
        )->first();

        if ($foundUser) {
            $userId = $foundUser->id;
            $userName = $foundUser->name;
        }
    }

    if (!$userId) {
        return response()->json([
            'success' => false,
            'message' => 'Picker user ID is required.',
        ], 422);
    }

    if (!$userName) {
        $userName = 'Picker User';
    }


    // ============================================================
    // 2. Transaction
    // ============================================================

    return DB::transaction(function () use (
        $request,
        $userId,
        $userName
    ) {

        // ========================================================
        // 3. Normalize order ID
        // ========================================================

        $orderIdStr = trim((string) $request->input('order_id'));
        $cleanOrderId = ltrim($orderIdStr, '#');


        // ========================================================
        // 4. Get requested line item IDs
        // ========================================================

        $lineItemIds = [];

        if ($request->filled('line_item_id')) {
            $lineItemIds[] = (string) $request->input('line_item_id');
        }

        if ($request->filled('line_item_ids')) {

            foreach (
                (array) $request->input('line_item_ids')
                as $lineItemId
            ) {
                if ($lineItemId !== null && $lineItemId !== '') {
                    $lineItemIds[] = (string) $lineItemId;
                }
            }
        }

        $lineItemIds = array_values(
            array_unique($lineItemIds)
        );


        // ========================================================
        // 5. Get requested order item IDs
        // ========================================================

        $orderItemIds = [];

        if ($request->filled('order_item_id')) {
            $orderItemIds[] = $request->input('order_item_id');
        }

        if ($request->filled('order_item_ids')) {

            foreach (
                (array) $request->input('order_item_ids')
                as $orderItemId
            ) {
                if ($orderItemId !== null && $orderItemId !== '') {
                    $orderItemIds[] = $orderItemId;
                }
            }
        }

        $orderItemIds = array_values(
            array_unique($orderItemIds)
        );


        // ========================================================
        // 6. Validate item identifiers
        // ========================================================

        if (
            empty($lineItemIds) &&
            empty($orderItemIds)
        ) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Please provide line_item_id, line_item_ids, '
                    . 'order_item_id, or order_item_ids.',
            ], 422);
        }


        // ========================================================
        // 7. Find order
        // ========================================================

        $order = Order::where(function ($query) use (
            $orderIdStr,
            $cleanOrderId
        ) {
            $query
                ->where('id', $orderIdStr)
                ->orWhere('order_number', $orderIdStr)
                ->orWhere('order_number', $cleanOrderId)
                ->orWhere('order_number', '#' . $cleanOrderId);
        })->first();


        // ========================================================
        // 8. If order doesn't exist, sync Shopify
        // ========================================================

        if (!$order) {

            try {

                $this->shopifyService->syncOrdersToDatabase();

                $order = Order::where(function ($query) use (
                    $orderIdStr,
                    $cleanOrderId
                ) {
                    $query
                        ->where('id', $orderIdStr)
                        ->orWhere('order_number', $orderIdStr)
                        ->orWhere(
                            'order_number',
                            $cleanOrderId
                        )
                        ->orWhere(
                            'order_number',
                            '#' . $cleanOrderId
                        );
                })->first();

            } catch (\Exception $e) {

                \Log::error(
                    'Shopify order sync failed during item assignment',
                    [
                        'order_id' => $orderIdStr,
                        'error' => $e->getMessage(),
                    ]
                );
            }
        }


        // ========================================================
        // 9. Order not found
        // ========================================================

        if (!$order) {

            return response()->json([
                'success' => false,
                'message' => "Order '{$orderIdStr}' not found.",
            ], 404);
        }


        // ========================================================
        // 10. Find requested order items
        // ========================================================

        $itemsQuery = OrderItem::where(
            'order_id',
            $order->id
        );

        $itemsQuery->where(function ($query) use (
            $lineItemIds,
            $orderItemIds
        ) {

            if (!empty($lineItemIds)) {
                $query->whereIn(
                    'line_item_id',
                    $lineItemIds
                );
            }

            if (!empty($orderItemIds)) {

                if (!empty($lineItemIds)) {
                    $query->orWhereIn(
                        'id',
                        $orderItemIds
                    );
                } else {
                    $query->whereIn(
                        'id',
                        $orderItemIds
                    );
                }
            }
        });

        $items = $itemsQuery
            ->lockForUpdate()
            ->get();


        // ========================================================
        // 11. No matching items
        // ========================================================

        if ($items->isEmpty()) {

            return response()->json([
                'success' => false,
                'message' =>
                    'No matching order items found for this order.',
                'data' => [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'requested_line_item_ids' => $lineItemIds,
                    'requested_order_item_ids' => $orderItemIds,
                ],
            ], 404);
        }


        // ========================================================
        // 12. Prevent assigning already assigned items
        //
        // If assigned to SAME picker, we also don't overwrite it.
        // ========================================================

        $alreadyAssignedItems = $items->filter(
            function ($item) {
                return !is_null($item->assigned_to);
            }
        );

        if ($alreadyAssignedItems->isNotEmpty()) {

            return response()->json([
                'success' => false,
                'message' =>
                    'Cannot assign item(s). One or more items are already assigned. '
                    . 'Please unassign them first.',
                'data' => [
                    'already_assigned_count' =>
                        $alreadyAssignedItems->count(),

                    'already_assigned_items' =>
                        $alreadyAssignedItems
                            ->map(function ($item) {

                                return [
                                    'item_id' => $item->id,
                                    'line_item_id' =>
                                        $item->line_item_id,
                                    'product_name' =>
                                        $item->product_name,
                                    'assigned_to' =>
                                        $item->assigned_to,
                                    'assigned_user_name' =>
                                        $item->assigned_user_name,
                                    'assigned_at' =>
                                        $item->assigned_at,
                                ];
                            })
                            ->values(),
                ],
            ], 400);
        }


        // ========================================================
        // 13. Assign requested items to picker
        // ========================================================

        $assignedAt = now();

        $assignedItems = collect();

        foreach ($items as $item) {

            $oldItemStatus = $item->status;

            $item->update([
                'assigned_to' => $userId,
                'assigned_user_name' => $userName,
                'assigned_at' => $assignedAt,
            ]);

            $item->refresh();

            $assignedItems->push($item);


            // ----------------------------------------------------
            // Log item assignment
            // ----------------------------------------------------

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


        // ========================================================
        // 14. IMPORTANT:
        // Check ALL items belonging to this order
        // ========================================================

        $allOrderItems = OrderItem::where(
            'order_id',
            $order->id
        )->get();


        $totalItems = $allOrderItems->count();

        $assignedItemsCount = $allOrderItems
            ->whereNotNull('assigned_to')
            ->count();

        $unassignedItemsCount = $allOrderItems
            ->whereNull('assigned_to')
            ->count();

        $allItemsAssigned =
            $totalItems > 0 &&
            $unassignedItemsCount === 0;


        // ========================================================
        // 15. Order-level assignment
        //
        // ONLY update order assignment when ALL items are assigned.
        // ========================================================

        $oldOrderStatus = $order->status;

        if ($allItemsAssigned) {

            $order->update([
                'assigned_to' => $userId,
                'assigned_user_name' => $userName,
                'assigned_at' => $assignedAt,

                // Final item assigned -> order is now picking
                'status' => 'picking',
            ]);

        } else {

            // Some items are still unassigned.
            //
            // Do NOT mark the order as fully assigned.
            // Keep existing status if it is already picking.
            //
            // If it is pending, it can remain pending.

            if ($order->status !== 'picking') {
                $order->update([
                    'status' => 'pending',
                ]);
            }
        }


        $order->refresh();


        // ========================================================
        // 16. Response
        // ========================================================

        return response()->json([
            'success' => true,

            'message' => $allItemsAssigned
                ? "Successfully assigned {$assignedItems->count()} item(s) to picker {$userName}. All items in this order are now assigned."
                : "Successfully assigned {$assignedItems->count()} item(s) to picker {$userName}. {$unassignedItemsCount} item(s) are still unassigned.",

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
                    'assigned_user_name' =>
                        $order->assigned_user_name,
                    'assigned_at' =>
                        $order->assigned_at,

                    'total_items' => $totalItems,

                    'assigned_items' => $assignedItemsCount,

                    'unassigned_items' => $unassignedItemsCount,

                    'all_items_assigned' => $allItemsAssigned,
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
     * Helper to extract order item IDs / line item IDs from flexible input types (array of IDs, strings, or objects).
     */
    protected function extractItemIdentifiers($itemsInput): array
    {
        $itemIds = [];
        $lineItemIds = [];

        if (!is_array($itemsInput)) {
            return ['item_ids' => [], 'line_item_ids' => []];
        }

        foreach ($itemsInput as $raw) {
            if (is_numeric($raw) || is_string($raw)) {
                $val = trim((string) $raw);
                if ($val !== '' && $val !== '*' && strtolower($val) !== 'all') {
                    if (is_numeric($val)) {
                        $itemIds[] = (int) $val;
                    }
                    $lineItemIds[] = $val;
                }
            } elseif (is_array($raw)) {
                if (isset($raw['id']) && $raw['id'] !== null && $raw['id'] !== '') {
                    if (is_numeric($raw['id'])) {
                        $itemIds[] = (int) $raw['id'];
                    }
                    $lineItemIds[] = (string) $raw['id'];
                }
                if (isset($raw['order_item_id']) && $raw['order_item_id'] !== null && $raw['order_item_id'] !== '') {
                    if (is_numeric($raw['order_item_id'])) {
                        $itemIds[] = (int) $raw['order_item_id'];
                    }
                    $lineItemIds[] = (string) $raw['order_item_id'];
                }
                if (isset($raw['line_item_id']) && $raw['line_item_id'] !== null && $raw['line_item_id'] !== '') {
                    $lineItemIds[] = (string) $raw['line_item_id'];
                    if (is_numeric($raw['line_item_id'])) {
                        $itemIds[] = (int) $raw['line_item_id'];
                    }
                }
                if (isset($raw['item_id']) && $raw['item_id'] !== null && $raw['item_id'] !== '') {
                    if (is_numeric($raw['item_id'])) {
                        $itemIds[] = (int) $raw['item_id'];
                    }
                    $lineItemIds[] = (string) $raw['item_id'];
                }
            }
        }

        return [
            'item_ids' => array_values(array_unique($itemIds)),
            'line_item_ids' => array_values(array_unique($lineItemIds)),
        ];
    }

    /**
     * Helper to resolve User ID and User Name from various request inputs by ID, email, or name.
     */
    protected function resolvePickerUser($rawId = null, $rawName = null, $rawEmail = null): array
    {
        $userId = null;
        $userName = $rawName;
        $userEmail = $rawEmail;

        if ($rawId && is_numeric($rawId)) {
            $userId = (int) $rawId;
        } elseif ($rawId && is_string($rawId)) {
            if (filter_var($rawId, FILTER_VALIDATE_EMAIL)) {
                $userEmail = $userEmail ?: $rawId;
            } else {
                $userName = $userName ?: $rawId;
            }
        }

        // If numeric user ID was provided, verify user exists in users table
        if ($userId) {
            $user = \App\Models\User::find($userId);
            if ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                ];
            }
        }

        // Search users table by email or name if user ID is missing/not found
        if (!empty($userEmail) || !empty($userName)) {
            $foundUser = \App\Models\User::query()
                ->where(function ($q) use ($userEmail, $userName) {
                    if (!empty($userEmail)) {
                        $q->where('email', $userEmail);
                    }
                    if (!empty($userName)) {
                        if (!empty($userEmail)) {
                            $q->orWhere('email', $userName)
                              ->orWhere('name', $userName)
                              ->orWhere('name', 'like', "%{$userName}%");
                        } else {
                            $q->where('email', $userName)
                              ->orWhere('name', $userName)
                              ->orWhere('name', 'like', "%{$userName}%");
                        }
                    }
                })
                ->first();

            if ($foundUser) {
                return [
                    'id' => $foundUser->id,
                    'name' => $foundUser->name,
                ];
            }
        }

        return [
            'id' => $userId,
            'name' => $userName,
        ];
    }

    /**
     * Assign an order to picker with order items specified in an array.
     * Supports both single order payload and batch array of orders.
     * Route: POST /api/orders/assign-picker-items
     * Route: POST /api/orders/picker/assign-items
     * Route: POST /api/orders/assign-with-items
     */
    public function assignOrderWithItems(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'orders' => 'nullable|array',
            'order_id' => 'required_without:orders',
            'order_items' => 'nullable|array',
            'order_item_ids' => 'nullable|array',
            'items' => 'nullable|array',
            'line_item_ids' => 'nullable|array',
            'picker_id' => 'nullable',
            'user_id' => 'nullable',
            'assigned_to' => 'nullable',
            'picker_name' => 'nullable|string',
            'user_name' => 'nullable|string',
            'picker_email' => 'nullable|string',
            'user_email' => 'nullable|string',
            'email' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        // Global / fallback picker user identification
        $authUser = Auth::user();
        $globalUserId = $authUser ? $authUser->id : null;
        $globalUserName = $authUser ? $authUser->name : null;

        if (!$globalUserId) {
            $rawId = $request->input('picker_id') ?? $request->input('user_id') ?? $request->input('assigned_to');
            $rawName = $request->input('picker_name') ?? $request->input('user_name') ?? $request->input('name');
            $rawEmail = $request->input('picker_email') ?? $request->input('user_email') ?? $request->input('email');

            $resolved = $this->resolvePickerUser($rawId, $rawName, $rawEmail);
            $globalUserId = $resolved['id'];
            $globalUserName = $resolved['name'];
        }

        if (!$globalUserId && !$globalUserName) {
            return response()->json([
                'success' => false,
                'message' => 'User identification required. Please pass picker_id, user_id, assigned_to, user_email, or user_name.',
            ], 422);
        }

        return DB::transaction(function () use ($request, $authUser, $globalUserId, $globalUserName) {
            // Prepare order entries to process
            $ordersPayload = [];
            if ($request->filled('orders') && is_array($request->input('orders'))) {
                $ordersPayload = $request->input('orders');
            } else {
                $itemsInput = $request->input('order_items')
                    ?? $request->input('order_item_ids')
                    ?? $request->input('items')
                    ?? $request->input('line_item_ids')
                    ?? [];

                $ordersPayload[] = [
                    'order_id' => $request->input('order_id'),
                    'order_items' => $itemsInput,
                    'picker_id' => $request->input('picker_id') ?? $request->input('user_id') ?? $request->input('assigned_to'),
                    'picker_name' => $request->input('picker_name') ?? $request->input('user_name'),
                    'picker_email' => $request->input('picker_email') ?? $request->input('user_email') ?? $request->input('email'),
                ];
            }

            $processedOrders = [];
            $totalAssignedItemsCount = 0;

            foreach ($ordersPayload as $orderEntry) {
                $orderIdRaw = trim((string) ($orderEntry['order_id'] ?? $orderEntry['id'] ?? ''));
                if (empty($orderIdRaw)) {
                    continue;
                }

                // Resolve per-entry picker user ID & name from users table if not numeric ID
                $rawEntryId = $orderEntry['picker_id'] ?? $orderEntry['user_id'] ?? $orderEntry['assigned_to'] ?? null;
                $rawEntryName = $orderEntry['picker_name'] ?? $orderEntry['user_name'] ?? $orderEntry['name'] ?? null;
                $rawEntryEmail = $orderEntry['picker_email'] ?? $orderEntry['user_email'] ?? $orderEntry['email'] ?? null;

                $entryUserId = null;
                $entryUserName = null;

                if ($rawEntryId || $rawEntryName || $rawEntryEmail) {
                    $resolvedEntry = $this->resolvePickerUser($rawEntryId, $rawEntryName, $rawEntryEmail);
                    $entryUserId = $resolvedEntry['id'];
                    $entryUserName = $resolvedEntry['name'];
                }

                if (!$entryUserId) {
                    $entryUserId = $globalUserId;
                }
                if (!$entryUserName) {
                    $entryUserName = $globalUserName ?: 'Picker User';
                }

                $cleanOrderId = ltrim($orderIdRaw, '#');
                $order = Order::with('items')
                    ->where(function ($q) use ($orderIdRaw, $cleanOrderId) {
                        $q->where('id', $orderIdRaw)
                          ->orWhere('order_number', $orderIdRaw)
                          ->orWhere('order_number', $cleanOrderId)
                          ->orWhere('order_number', '#' . $cleanOrderId);
                    })
                    ->first();

                if (!$order) {
                    try {
                        $this->shopifyService->syncOrdersToDatabase();
                        $order = Order::with('items')
                            ->where(function ($q) use ($orderIdRaw, $cleanOrderId) {
                                $q->where('id', $orderIdRaw)
                                  ->orWhere('order_number', $orderIdRaw)
                                  ->orWhere('order_number', $cleanOrderId)
                                  ->orWhere('order_number', '#' . $cleanOrderId);
                            })
                            ->first();
                    } catch (\Exception $e) {
                        // Continue if offline
                    }
                }

                if (!$order) {
                    if (count($ordersPayload) === 1) {
                        return response()->json([
                            'success' => false,
                            'message' => "Order '{$orderIdRaw}' not found in system.",
                        ], 404);
                    }
                    continue;
                }

                // Extract item identifiers
                $itemsInput = $orderEntry['order_items']
                    ?? $orderEntry['order_item_ids']
                    ?? $orderEntry['items']
                    ?? $orderEntry['line_item_ids']
                    ?? [];

                $parsedIds = $this->extractItemIdentifiers($itemsInput);
                $itemIds = $parsedIds['item_ids'];
                $lineItemIds = $parsedIds['line_item_ids'];

                $itemsQuery = OrderItem::where('order_id', $order->id);

                if (!empty($itemIds) || !empty($lineItemIds)) {
                    $itemsQuery->where(function ($q) use ($itemIds, $lineItemIds) {
                        if (!empty($itemIds)) {
                            $q->orWhereIn('id', $itemIds);
                        }
                        if (!empty($lineItemIds)) {
                            $q->orWhereIn('line_item_id', $lineItemIds);
                        }
                    });
                }

                $items = $itemsQuery->get();

                if ($items->isEmpty()) {
                    if (count($ordersPayload) === 1) {
                        return response()->json([
                            'success' => false,
                            'message' => 'No matching order items found for this order.',
                        ], 404);
                    }
                    continue;
                }

                $assignedItems = collect();
                $oldOrderStatus = $order->status;

                foreach ($items as $item) {
                    $oldItemStatus = $item->status;

                    $item->update([
                        'assigned_to' => $entryUserId,
                        'assigned_user_name' => $entryUserName,
                        'assigned_at' => now(),
                    ]);

                    $assignedItems->push($item->fresh());

                    OrderStatusLog::create([
                        'order_id' => $order->id,
                        'order_item_id' => $item->id,
                        'user_id' => $entryUserId,
                        'user_name' => $entryUserName,
                        'action' => 'item_assigned_to_picker',
                        'old_status' => $oldItemStatus,
                        'new_status' => $oldItemStatus,
                        'notes' => $request->input('notes', "Assigned item {$item->product_name} to picker {$entryUserName}"),
                    ]);
                }

                // Update order assigned_to column with user ID and user name
                $order->update([
                    'assigned_to' => $entryUserId,
                    'assigned_user_name' => $entryUserName,
                    'assigned_at' => now(),
                ]);

                if ($order->status === 'pending') {
                    $order->update(['status' => 'picking']);
                }

                OrderStatusLog::create([
                    'order_id' => $order->id,
                    'user_id' => $entryUserId,
                    'user_name' => $entryUserName,
                    'action' => 'order_assigned_to_picker',
                    'old_status' => $oldOrderStatus,
                    'new_status' => $order->status,
                    'notes' => $request->input('notes', "Assigned order {$order->order_number} to picker {$entryUserName} with {$assignedItems->count()} item(s)"),
                ]);

                $totalAssignedItemsCount += $assignedItems->count();

                $processedOrders[] = [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'old_status' => $oldOrderStatus,
                    'new_status' => $order->status,
                    'assigned_to' => $order->assigned_to,
                    'assigned_user_name' => $order->assigned_user_name,
                    'assigned_at' => $order->assigned_at ? $order->assigned_at->toIso8601String() : null,
                    'assigned_items_count' => $assignedItems->count(),
                    'assigned_items' => $assignedItems,
                ];
            }

            return response()->json([
                'success' => true,
                'message' => "Order(s) successfully assigned to picker with order items.",
                'data' => [
                    'picker' => [
                        'id' => $globalUserId,
                        'name' => $globalUserName,
                    ],
                    'assigned_orders_count' => count($processedOrders),
                    'total_assigned_items_count' => $totalAssignedItemsCount,
                    'orders' => $processedOrders,
                ],
            ]);
        });
    }

    /**
     * Unassign an order with order items (in array) which is assigned to picker.
     * Route: POST /api/orders/unassign-picker-items
     * Route: POST /api/orders/picker/unassign-items
     * Route: POST /api/orders/unassign-with-items
     */
    public function unassignOrderWithItems(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'orders' => 'nullable|array',
            'order_id' => 'required_without:orders',
            'order_items' => 'nullable|array',
            'order_item_ids' => 'nullable|array',
            'items' => 'nullable|array',
            'line_item_ids' => 'nullable|array',
            'picker_id' => 'nullable',
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
        $userId = $authUser ? $authUser->id : ($request->input('picker_id') ?? $request->input('user_id'));
        if ($userId && is_numeric($userId)) {
            $userId = (int) $userId;
        }

        $dbUser = $userId ? \App\Models\User::find($userId) : null;
        $userName = $authUser ? $authUser->name : ($dbUser ? $dbUser->name : ($request->input('user_name') ?? 'System User'));

        return DB::transaction(function () use ($request, $userId, $userName) {
            $ordersPayload = [];
            if ($request->filled('orders') && is_array($request->input('orders'))) {
                $ordersPayload = $request->input('orders');
            } else {
                $itemsInput = $request->input('order_items')
                    ?? $request->input('order_item_ids')
                    ?? $request->input('items')
                    ?? $request->input('line_item_ids')
                    ?? [];

                $ordersPayload[] = [
                    'order_id' => $request->input('order_id'),
                    'order_items' => $itemsInput,
                ];
            }

            $processedOrders = [];
            $totalUnassignedItemsCount = 0;

            foreach ($ordersPayload as $orderEntry) {
                $orderIdRaw = trim((string) ($orderEntry['order_id'] ?? $orderEntry['id'] ?? ''));
                if (empty($orderIdRaw)) {
                    continue;
                }

                $cleanOrderId = ltrim($orderIdRaw, '#');
                $order = Order::with('items')
                    ->where(function ($q) use ($orderIdRaw, $cleanOrderId) {
                        $q->where('id', $orderIdRaw)
                          ->orWhere('order_number', $orderIdRaw)
                          ->orWhere('order_number', $cleanOrderId)
                          ->orWhere('order_number', '#' . $cleanOrderId);
                    })
                    ->first();

                if (!$order) {
                    try {
                        $this->shopifyService->syncOrdersToDatabase();
                        $order = Order::with('items')
                            ->where(function ($q) use ($orderIdRaw, $cleanOrderId) {
                                $q->where('id', $orderIdRaw)
                                  ->orWhere('order_number', $orderIdRaw)
                                  ->orWhere('order_number', $cleanOrderId)
                                  ->orWhere('order_number', '#' . $cleanOrderId);
                            })
                            ->first();
                    } catch (\Exception $e) {
                        // Ignore sync exception if offline
                    }
                }

                if (!$order) {
                    if (count($ordersPayload) === 1) {
                        return response()->json([
                            'success' => false,
                            'message' => "Order '{$orderIdRaw}' not found in system.",
                        ], 404);
                    }
                    continue;
                }

                $itemsInput = $orderEntry['order_items']
                    ?? $orderEntry['order_item_ids']
                    ?? $orderEntry['items']
                    ?? $orderEntry['line_item_ids']
                    ?? [];

                $parsedIds = $this->extractItemIdentifiers($itemsInput);
                $itemIds = $parsedIds['item_ids'];
                $lineItemIds = $parsedIds['line_item_ids'];

                $itemsQuery = OrderItem::where('order_id', $order->id);

                if (!empty($itemIds) || !empty($lineItemIds)) {
                    $itemsQuery->where(function ($q) use ($itemIds, $lineItemIds) {
                        if (!empty($itemIds)) {
                            $q->orWhereIn('id', $itemIds);
                        }
                        if (!empty($lineItemIds)) {
                            $q->orWhereIn('line_item_id', $lineItemIds);
                        }
                    });
                }

                $items = $itemsQuery->get();

                if ($items->isEmpty()) {
                    if (count($ordersPayload) === 1) {
                        return response()->json([
                            'success' => false,
                            'message' => 'No matching order items found for this order.',
                        ], 404);
                    }
                    continue;
                }

                $unassignedItems = collect();
                $skippedItems = collect();

                foreach ($items as $item) {
                    $isPicked = in_array($item->status, ['picked', 'packed', 'delivered']) || !is_null($item->picked_by);

                    if ($isPicked) {
                        $skippedItems->push([
                            'item_id' => $item->id,
                            'line_item_id' => $item->line_item_id,
                            'product_name' => $item->product_name,
                            'status' => $item->status,
                            'reason' => 'Item has already been picked/packed and cannot be unassigned.',
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
                            'notes' => $request->input('notes', "Unassigned item {$item->product_name} from picker"),
                        ]);
                    }
                }

                // Evaluate order assignment status after items unassignment
                $allItems = OrderItem::where('order_id', $order->id)->get();
                $hasAssignedItems = $allItems->whereNotNull('assigned_to')->count() > 0 || $allItems->whereNotNull('assigned_user_name')->count() > 0;
                $hasPickedItems = $allItems->whereIn('status', ['picked', 'packed', 'delivered'])->count() > 0;

                $oldOrderStatus = $order->status;
                if (!$hasAssignedItems && !$hasPickedItems) {
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

                $totalUnassignedItemsCount += $unassignedItems->count();

                $processedOrders[] = [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'old_status' => $oldOrderStatus,
                    'new_status' => $order->status,
                    'assigned_to' => $order->assigned_to,
                    'assigned_user_name' => $order->assigned_user_name,
                    'unassigned_items_count' => $unassignedItems->count(),
                    'unassigned_items' => $unassignedItems,
                    'skipped_items' => $skippedItems,
                ];
            }

            return response()->json([
                'success' => true,
                'message' => "Order items successfully unassigned ({$totalUnassignedItemsCount} item(s) unassigned across " . count($processedOrders) . " order(s)).",
                'data' => [
                    'unassigned_orders_count' => count($processedOrders),
                    'total_unassigned_items_count' => $totalUnassignedItemsCount,
                    'orders' => $processedOrders,
                ],
            ]);
        });
    }


    /**
     * Update individual item status (picked, packed, delivered) with user log
     * Route: POST /api/orders/items/update-status
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

            if (!$orderItem->assigned_to) {
                return response()->json([
                    'success' => false,
                    'message' => "Cannot update status. Item '{$orderItem->product_name}' is not assigned to any picker.",
                ], 400);
            }

            $oldStatus = $orderItem->status;
            $newStatus = $request->input('status');

            $user = Auth::user();
            $userId = $user ? $user->id : $request->input('user_id');
            $dbUser = $userId ? \App\Models\User::find($userId) : null;
            $validUserId = $dbUser ? $dbUser->id : null;
            $userName = $user ? $user->name : ($dbUser ? $dbUser->name : ($request->input('user_name') ?? 'System User'));

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
     * Helper method to format order & items details consistently for response
     */
    protected function formatOrderDetails(Order $order, $filterStatus = null)
    {
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

        $items = $order->items;
        if ($filterStatus === 'unpicked') {
            $items = $items->filter(fn($item) => $item->status === 'pending');
        } elseif ($filterStatus === 'picked') {
            $items = $items->filter(fn($item) => in_array($item->status, ['picked', 'packed', 'delivered']));
        } elseif ($filterStatus === 'packed') {
            $items = $items->filter(fn($item) => in_array($item->status, ['packed', 'delivered']));
        }

        return [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'bag_count' => (int) ($order->bag_count ?? 0),
            'assigned_to' => $order->assigned_to ? (int) $order->assigned_to : null,
            'assigned_user_name' => $order->assigned_user_name ?: ($order->assignedUser->name ?? null),
            'picked_by' => $order->items->whereNotNull('picked_by')->pluck('picked_by')->first() ? (int) $order->items->whereNotNull('picked_by')->pluck('picked_by')->first() : ($order->assigned_to ? (int) $order->assigned_to : null),
            'picked_user_name' => $order->assigned_user_name ?: ($order->assignedUser->name ?? null),
            'packed_by' => $order->items->whereNotNull('packed_by')->pluck('packed_by')->first() ? (int) $order->items->whereNotNull('packed_by')->pluck('packed_by')->first() : null,
            'packed_user_name' => $order->items->whereNotNull('packed_user_name')->pluck('packed_user_name')->first() ?: ($order->items->map(fn($i) => $i->packedUser->name ?? null)->filter()->first()),
            'delivered_by' => $order->delivered_by ? (int) $order->delivered_by : null,
            'delivered_user_name' => $order->delivered_user_name ?: ($order->deliveredUser->name ?? null),
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
                'total_items' => $order->items->count(),
                'picked_items' => $order->items->where('status', 'picked')->count(),
                'packed_items' => $order->items->where('status', 'packed')->count(),
                'delivered_items' => $order->items->where('status', 'delivered')->count(),
            ],
            'items' => $items->map(function ($item) {
                return [
                    'item_id' => $item->id,
                    'line_item_id' => $item->line_item_id,
                    'product_id' => $item->product_id,
                    'product_code' => $item->product_code,
                    'barcode' => $item->barcode,
                    'product_name' => $item->product_name,
                    'image' => $item->image,
                    'image_url' => $item->image,
                    'quantity' => $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'status' => $item->status,
                    'is_packer_verified' => (bool) $item->is_packer_verified,
                    'packer_verified_user' => ($item->packer_verified_by || $item->packer_verified_user_name) ? [
                        'id' => $item->packer_verified_by ? (int) $item->packer_verified_by : null,
                        'name' => $item->packerVerifiedUser ? $item->packerVerifiedUser->name : ($item->packer_verified_user_name ?? 'Packer User'),
                        'verified_at' => $item->packer_verified_at ? $item->packer_verified_at->toIso8601String() : null,
                    ] : null,
                    'is_installable' => (bool) $item->is_installable,
                    'installation' => $item->installation ? [
                        'id' => $item->installation->id,
                        'installation_type' => $item->installation->installation_type,
                        'installation_level' => $item->installation->installation_level,
                    ] : null,
                    'is_flagged' => $item->is_flagged,
                    'flag_reason' => $item->flag_reason,
                    'assigned_to' => $item->assigned_to ? (int) $item->assigned_to : null,
                    'assigned_user_name' => $item->assigned_user_name ?: ($item->assignedUser->name ?? null),
                    'picked_by' => $item->picked_by ? (int) $item->picked_by : ($item->picked_user_name ?: null),
                    'picked_user_name' => $item->picked_user_name ?: ($item->pickedUser->name ?? null),
                    'packed_by' => $item->packed_by ? (int) $item->packed_by : ($item->packed_user_name ?: null),
                    'packed_user_name' => $item->packed_user_name ?: ($item->packedUser->name ?? null),
                    'delivered_by' => $item->delivered_by ? (int) $item->delivered_by : ($item->delivered_user_name ?: null),
                    'delivered_user_name' => $item->delivered_user_name ?: ($item->deliveredUser->name ?? null),
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
            })->values(),
            'created_at' => $order->created_at ? $order->created_at->toIso8601String() : null,
        ];
    }
    public function pickerupdateItemStatus(Request $request)
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

            $query = OrderItem::query();

            /*
            |--------------------------------------------------------------------------
            | Find Order Item
            |--------------------------------------------------------------------------
            */
            if ($request->filled('order_item_id')) {

                $query->where('id', $request->input('order_item_id'));

            } elseif ($request->filled('line_item_id')) {

                $query->where('line_item_id', $request->input('line_item_id'));

            } elseif ($request->filled('order_id')) {

                $query->where('order_id', $request->input('order_id'));

                if ($request->filled('barcode')) {

                    $query->where('barcode', $request->input('barcode'));

                } elseif ($request->filled('product_code')) {

                    $query->where('product_code', $request->input('product_code'));
                }

            } elseif ($request->filled('barcode')) {

                $query->where('barcode', $request->input('barcode'));

            } elseif ($request->filled('product_code')) {

                $query->where('product_code', $request->input('product_code'));
            }

            $orderItem = $query->first();

            /*
            |--------------------------------------------------------------------------
            | Item Not Found
            |--------------------------------------------------------------------------
            */
            if (!$orderItem) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order item not found for the provided criteria.',
                ], 404);
            }

            /*
            |--------------------------------------------------------------------------
            | BARCODE VERIFICATION FOR PICKER
            |--------------------------------------------------------------------------
            |
            | When picker changes status to "picked", barcode MUST be provided.
            | The scanned barcode must exactly match the item's stored barcode.
            |
            |--------------------------------------------------------------------------
            */
            if ($request->input('status') === 'picked') {

                if (!$request->filled('barcode')) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Barcode is required to pick this item.',
                        'code' => 'BARCODE_REQUIRED',
                    ], 400);
                }

                $scannedBarcode = trim((string) $request->input('barcode'));
                $itemBarcode = trim((string) $orderItem->barcode);

                if ($itemBarcode === '') {
                    return response()->json([
                        'success' => false,
                        'message' => 'This item does not have a barcode configured.',
                        'code' => 'ITEM_BARCODE_MISSING',
                    ], 400);
                }

                /*
                 * Exact barcode comparison.
                 */
                if ($scannedBarcode !== $itemBarcode) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Barcode does not match this item. Please scan the correct item.',
                        'code' => 'BARCODE_MISMATCH',
                        'data' => [
                            'order_item_id' => $orderItem->id,
                            'scanned_barcode' => $scannedBarcode,
                            'expected_barcode' => $itemBarcode,
                            'product_name' => $orderItem->product_name,
                        ],
                    ], 400);
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Assigned Picker Check
            |--------------------------------------------------------------------------
            */
            if (!$orderItem->assigned_to) {
                return response()->json([
                    'success' => false,
                    'message' => "Cannot update status. Item '{$orderItem->product_name}' is not assigned to any picker.",
                ], 400);
            }

            /*
            |--------------------------------------------------------------------------
            | User
            |--------------------------------------------------------------------------
            */
            $oldStatus = $orderItem->status;
            $newStatus = $request->input('status');

            $user = Auth::user();

            $userId = $user
                ? $user->id
                : $request->input('user_id');

            $dbUser = $userId
                ? \App\Models\User::find($userId)
                : null;

            $validUserId = $dbUser
                ? $dbUser->id
                : null;

            $userName = $user
                ? $user->name
                : ($dbUser
                    ? $dbUser->name
                    : ($request->input('user_name') ?? 'System User'));

            /*
            |--------------------------------------------------------------------------
            | Update Item Status
            |--------------------------------------------------------------------------
            */
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

            /*
            |--------------------------------------------------------------------------
            | Item Status Log
            |--------------------------------------------------------------------------
            */
            $log = OrderStatusLog::create([
                'order_id' => $orderItem->order_id,
                'order_item_id' => $orderItem->id,
                'user_id' => $validUserId,
                'user_name' => $userName,
                'action' => 'item_status_updated',
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'notes' => $request->input(
                    'notes',
                    "Updated item status for {$orderItem->product_name}"
                ),
            ]);

            /*
            |--------------------------------------------------------------------------
            | Update Overall Order Status
            |--------------------------------------------------------------------------
            */
            $order = Order::with('items')->find($orderItem->order_id);

            $allStatuses = $order->items->pluck('status');

            $newOrderStatus = $order->status;

            if ($allStatuses->every(fn($s) => $s === 'delivered')) {

                $newOrderStatus = 'delivered';

            } elseif (
                $allStatuses->every(
                    fn($s) => in_array($s, ['packed', 'delivered'])
                )
            ) {

                $newOrderStatus = 'packed';

            } elseif (
                $allStatuses->every(
                    fn($s) => in_array($s, ['picked', 'packed', 'delivered'])
                )
            ) {

                $newOrderStatus = 'picked';

            } elseif (
                $allStatuses->contains(
                    fn($s) => in_array($s, ['picked', 'packed', 'delivered'])
                )
            ) {

                $newOrderStatus = 'picking';
            }

            /*
            |--------------------------------------------------------------------------
            | Save Overall Order Status
            |--------------------------------------------------------------------------
            */
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

            /*
            |--------------------------------------------------------------------------
            | Response
            |--------------------------------------------------------------------------
            */
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
}
