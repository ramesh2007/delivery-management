<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\ShopifyService;
use Illuminate\Http\Request;

class OrderStatusApiController extends Controller
{
    protected ShopifyService $shopifyService;

    public function __construct(ShopifyService $shopifyService)
    {
        $this->shopifyService = $shopifyService;
    }

    /**
     * Helper to perform optional silent Shopify auto-sync if requested
     */
    protected function handleSilentSync(Request $request): void
    {
        if ($request->boolean('auto_sync', true)) {
            try {
                $this->shopifyService->syncOrdersToDatabase();
            } catch (\Throwable $e) {
                // Ignore sync errors to serve cached local database orders
            }
        }
    }

    /**
     * Helper to get standard base query with eager-loaded relationships to prevent N+1 queries.
     */
    protected function getBaseOrderQuery(Request $request)
    {
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
            'driverAssignment',
            'payment',
            'discrepancies.user',
            'logs.user',
        ]);

        // Search filter across order metadata
        if ($request->filled('search')) {
            $search = trim($request->query('search'));
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%")
                  ->orWhere('customer_phone', 'like', "%{$search}%")
                  ->orWhere('assigned_user_name', 'like', "%{$search}%")
                  ->orWhere('packed_user_name', 'like', "%{$search}%")
                  ->orWhere('delivered_user_name', 'like', "%{$search}%");
            });
        }

        return $query;
    }

    /**
     * Helper to format paginated Eloquent response
     */
    protected function buildPaginatedResponse($paginator, string $statusFilter, string $message)
    {
        $formattedOrders = collect($paginator->items())->map(function ($order) {
            return $this->formatOrderDetails($order);
        })->values();

        return response()->json([
            'success' => true,
            'message' => $message,
            'status_filter' => $statusFilter,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'has_more_pages' => $paginator->hasMorePages(),
                'next_page_url' => $paginator->nextPageUrl(),
                'prev_page_url' => $paginator->previousPageUrl(),
            ],
            'data' => $formattedOrders,
        ]);
    }

    /**
     * 1. GET /api/orders/all or /api/orders/status/all
     * Retrieve all orders regardless of status with pagination.
     */
    public function all(Request $request)
    {
        $this->handleSilentSync($request);
        $perPage = max(1, min((int) $request->query('per_page', 15), 100));

        $paginator = $this->getBaseOrderQuery($request)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return $this->buildPaginatedResponse($paginator, 'all', 'All orders retrieved successfully.');
    }

    /**
     * 2. GET /api/orders/new or /api/orders/status/new
     * Retrieve newly synced/pending orders (status = 'pending').
     */
    public function newOrders(Request $request)
    {
        $this->handleSilentSync($request);
        $perPage = max(1, min((int) $request->query('per_page', 15), 100));

        $paginator = $this->getBaseOrderQuery($request)
            ->where('status', 'pending')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return $this->buildPaginatedResponse($paginator, 'new', 'New orders retrieved successfully.');
    }

    /**
     * 3. GET /api/orders/ready-to-assign or /api/orders/status/ready-to-assign
     * Retrieve packed orders ready to be assigned to a driver for delivery (status = 'ready_to_assign' or 'packed', with all items packed and driver unassigned).
     */
    public function readyToAssign(Request $request)
    {
        $this->handleSilentSync($request);
        $perPage = max(1, min((int) $request->query('per_page', 15), 100));

        $paginator = $this->getBaseOrderQuery($request)
            ->where(function ($q) {
                $q->where('status', 'ready_to_assign')
                  ->orWhere('status', 'packed');
            })
            ->whereNull('delivered_by')
            ->whereDoesntHave('driverAssignment')
            ->whereDoesntHave('items', function ($iq) {
                $iq->where('status', '!=', 'packed');
            })
            ->orderBy('updated_at', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return $this->buildPaginatedResponse($paginator, 'ready_to_assign', 'Orders ready to assign retrieved successfully.');
    }

    /**
     * 4. GET /api/orders/picking or /api/orders/status/picking
     * Retrieve orders currently in the picking phase (status = 'picking' or assigned to pickers).
     */
    public function picking(Request $request)
    {
        $this->handleSilentSync($request);
        $perPage = max(1, min((int) $request->query('per_page', 15), 100));

        $paginator = $this->getBaseOrderQuery($request)
            ->where(function ($q) {
                $q->where('status', 'picking')
                  ->orWhere(function ($sub) {
                      $sub->where('status', 'pending')
                          ->whereNotNull('assigned_to');
                  });
            })
            ->orderBy('updated_at', 'desc')
            ->paginate($perPage);

        return $this->buildPaginatedResponse($paginator, 'picking', 'Picking orders retrieved successfully.');
    }

    /**
     * 5. GET /api/orders/picked or /api/orders/status/picked
     * Retrieve orders where picking is complete and ready for packing (status = 'picked').
     */
    public function picked(Request $request)
    {
        $this->handleSilentSync($request);
        $perPage = max(1, min((int) $request->query('per_page', 15), 100));

        $paginator = $this->getBaseOrderQuery($request)
            ->where('status', 'picked')
            ->orderBy('updated_at', 'desc')
            ->paginate($perPage);

        return $this->buildPaginatedResponse($paginator, 'picked', 'Picked orders retrieved successfully.');
    }

    /**
     * 6. GET /api/orders/packing or /api/orders/status/packing
     * Retrieve orders currently being packed (status = 'packing' or has active packer assigned).
     */
    public function packing(Request $request)
    {
        $this->handleSilentSync($request);
        $perPage = max(1, min((int) $request->query('per_page', 15), 100));

        $paginator = $this->getBaseOrderQuery($request)
            ->where(function ($q) {
                $q->where('status', 'packing')
                  ->orWhere('status', 'packed')
                  ->orWhereHas('packerAssignment');
            })
            ->orderBy('updated_at', 'desc')
            ->paginate($perPage);

        return $this->buildPaginatedResponse($paginator, 'packing', 'Packing orders retrieved successfully.');
    }

    /**
     * 7. GET /api/orders/in-delivery or /api/orders/status/in-delivery
     * Retrieve orders currently out for delivery (status = 'assigned_to_driver', 'out_for_delivery', or 'in_delivery').
     */
    public function inDelivery(Request $request)
    {
        $this->handleSilentSync($request);
        $perPage = max(1, min((int) $request->query('per_page', 15), 100));

        $paginator = $this->getBaseOrderQuery($request)
            ->where(function ($q) {
                $q->whereIn('status', ['assigned_to_driver', 'out_for_delivery', 'in_delivery', 'driver_accepted', 'started'])
                  ->orWhereHas('driverAssignment');
            })
            ->where('status', '!=', 'delivered')
            ->orderBy('updated_at', 'desc')
            ->paginate($perPage);

        return $this->buildPaginatedResponse($paginator, 'in_delivery', 'In-delivery orders retrieved successfully.');
    }

    /**
     * 8. GET /api/orders/delivered or /api/orders/status/delivered
     * Retrieve completed/delivered orders (status = 'delivered').
     */
    public function delivered(Request $request)
    {
        $this->handleSilentSync($request);
        $perPage = max(1, min((int) $request->query('per_page', 15), 100));

        $paginator = $this->getBaseOrderQuery($request)
            ->where('status', 'delivered')
            ->orderBy('delivered_at', 'desc')
            ->orderBy('updated_at', 'desc')
            ->paginate($perPage);

        return $this->buildPaginatedResponse($paginator, 'delivered', 'Delivered orders retrieved successfully.');
    }

    /**
     * 9. GET /api/orders/flagged, /api/orders/status/flagged, /api/orders/flagged-items
     * Retrieve orders containing flagged items (order_items.is_flagged = true) order-wise and item-wise.
     */
    public function flaggedOrders(Request $request)
    {
        $this->handleSilentSync($request);
        $perPage = max(1, min((int) $request->query('per_page', 15), 100));

        $query = $this->getBaseOrderQuery($request)
            ->whereHas('items', function ($iq) {
                $iq->where('is_flagged', true);
            });

        if ($request->filled('search')) {
            $search = trim($request->query('search'));
            $query->where(function ($q) use ($search) {
                $q->whereHas('items', function ($iq) use ($search) {
                    $iq->where('flag_reason', 'like', "%{$search}%")
                       ->orWhere('product_name', 'like', "%{$search}%")
                       ->orWhere('product_code', 'like', "%{$search}%")
                       ->orWhere('barcode', 'like', "%{$search}%");
                });
            });
        }

        $paginator = $query->orderBy('updated_at', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        $formattedOrders = collect($paginator->items())->map(function ($order) {
            $formatted = $this->formatOrderDetails($order);

            $flaggedItems = $order->items->filter(fn($item) => (bool) $item->is_flagged)->map(function ($item) {
                return [
                    'item_id' => $item->id,
                    'line_item_id' => $item->line_item_id,
                    'product_id' => $item->product_id,
                    'product_code' => $item->product_code,
                    'barcode' => $item->barcode,
                    'product_name' => $item->product_name,
                    'image' => $item->image,
                    'quantity' => $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'status' => $item->status,
                    'is_flagged' => true,
                    'flag_reason' => $item->flag_reason,
                    'assigned_user' => $item->assigned_to ? [
                        'id' => (int) $item->assigned_to,
                        'name' => $item->assignedUser ? $item->assignedUser->name : ($item->assigned_user_name ?? 'Picker User'),
                    ] : null,
                    'picked_user' => ($item->picked_by || $item->picked_user_name) ? [
                        'id' => $item->picked_by ? (int) $item->picked_by : null,
                        'name' => $item->pickedUser ? $item->pickedUser->name : ($item->picked_user_name ?? 'Picker User'),
                    ] : null,
                    'packed_user' => ($item->packed_by || $item->packed_user_name) ? [
                        'id' => $item->packed_by ? (int) $item->packed_by : null,
                        'name' => $item->packedUser ? $item->packedUser->name : ($item->packed_user_name ?? 'Packer User'),
                    ] : null,
                ];
            })->values();

            $formatted['flagged_items_count'] = $flaggedItems->count();
            $formatted['flagged_items'] = $flaggedItems;

            return $formatted;
        })->values();

        return response()->json([
            'success' => true,
            'message' => 'Flagged order items retrieved successfully.',
            'status_filter' => 'flagged',
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'has_more_pages' => $paginator->hasMorePages(),
                'next_page_url' => $paginator->nextPageUrl(),
                'prev_page_url' => $paginator->previousPageUrl(),
            ],
            'data' => $formattedOrders,
        ]);
    }

    /**
     * Helper method to format single order details consistently for API responses.
     */
    protected function formatOrderDetails(Order $order): array
    {
        $totalItems = $order->items->count();
        $pickedItems = $order->items->whereIn('status', ['picked', 'packed', 'delivered'])->count();
        $packedItems = $order->items->whereIn('status', ['packed', 'delivered'])->count();
        $deliveredItems = $order->items->where('status', 'delivered')->count();

        // Unique Pickers
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

        // Unique Packers
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
            'driver_assignment' => $order->driverAssignment ? [
                'id' => $order->driverAssignment->id,
                'assigned_driver_user_id' => (int) $order->driverAssignment->assigned_driver_user_id,
                'driver_name' => $order->driverAssignment->driver_name,
                'zone' => $order->driverAssignment->zone,
                'driver_status' => $order->driverAssignment->driver_status,
                'assigned_at' => $order->driverAssignment->assigned_at ? $order->driverAssignment->assigned_at->toIso8601String() : null,
                'accepted_at' => $order->driverAssignment->accepted_at ? $order->driverAssignment->accepted_at->toIso8601String() : null,
                'started_at' => $order->driverAssignment->started_at ? $order->driverAssignment->started_at->toIso8601String() : null,
                'delivered_at' => $order->driverAssignment->delivered_at ? $order->driverAssignment->delivered_at->toIso8601String() : null,
            ] : null,
            'driver_user' => $order->driverAssignment ? [
                'id' => (int) $order->driverAssignment->assigned_driver_user_id,
                'name' => $order->driverAssignment->driver_name,
                'assigned_at' => $order->driverAssignment->assigned_at ? $order->driverAssignment->assigned_at->toIso8601String() : null,
            ] : (($order->delivered_by || $order->delivered_user_name) ? [
                'id' => $order->delivered_by ? (int) $order->delivered_by : null,
                'name' => $order->deliveredUser ? $order->deliveredUser->name : ($order->delivered_user_name ?? 'Driver User'),
                'delivered_at' => $order->delivered_at ? $order->delivered_at->toIso8601String() : null,
            ] : null),
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
            'payment' => $order->payment ? [
                'id' => $order->payment->id,
                'shopify_order_id' => $order->payment->shopify_order_id,
                'payment_method' => $order->payment->payment_method,
                'payment_status' => $order->payment->payment_status,
                'paid_amount' => (float) $order->payment->paid_amount,
                'total_price' => (float) $order->payment->total_price,
                'total_outstanding' => (float) $order->payment->total_outstanding,
                'currency' => $order->payment->currency,
                'processed_at' => $order->payment->processed_at ? $order->payment->processed_at->toIso8601String() : null,
                'shopify_created_at' => $order->payment->shopify_created_at ? $order->payment->shopify_created_at->toIso8601String() : null,
                'shopify_updated_at' => $order->payment->shopify_updated_at ? $order->payment->shopify_updated_at->toIso8601String() : null,
            ] : [
                'payment_method' => $order->payment_method,
                'payment_status' => $order->payment_status,
                'paid_amount' => (float) ($order->collected_amount ?? 0.00),
                'total_price' => (float) $order->total_amount,
                'total_outstanding' => (float) $order->total_amount,
                'currency' => 'QAR',
                'processed_at' => null,
            ],
            'items' => $order->items->map(function ($item) {
                return [
                    'item_id' => $item->id,
                    'line_item_id' => $item->line_item_id,
                    'product_id' => $item->product_id,
                    'product_code' => $item->product_code,
                    'barcode' => $item->barcode,
                    'product_name' => $item->product_name,
                    'image' => $item->image,
                    'image_url' => $item->image,
                    'product_image' => $item->image,
                    'product_image_url' => $item->image,
                    'quantity' => $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'status' => $item->status,
                    'is_flagged' => (bool) $item->is_flagged,
                    'flag_reason' => $item->flag_reason,
                    'is_packer_verified' => (bool) $item->is_packer_verified,
                    'packer_verified_user' => ($item->packer_verified_by || $item->packer_verified_user_name) ? [
                        'id' => $item->packer_verified_by ? (int) $item->packer_verified_by : null,
                        'name' => $item->packerVerifiedUser ? $item->packerVerifiedUser->name : ($item->packer_verified_user_name ?? 'Packer User'),
                        'verified_at' => $item->packer_verified_at ? $item->packer_verified_at->toIso8601String() : null,
                    ] : null,
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
            'updated_at' => $order->updated_at ? $order->updated_at->toIso8601String() : null,
        ];
    }

    /**
     * 8. GET/POST /api/orders/delivered or /api/orders/status/delivered
     * Retrieve completed/delivered orders, optionally filtered by driver_id (via query param, body, or route param).
     */
    public function deliveredCompleted(Request $request, $driver_user_id = null)
    {
        $this->handleSilentSync($request);
        $perPage = max(1, min((int) $request->query('per_page', 15), 100));

        $rawJson = json_decode($request->getContent(), true) ?? [];

        $driverId = $driver_user_id
            ?? $request->input('driver_id')
            ?? ($rawJson['driver_id'] ?? null)
            ?? $request->input('driver_user_id')
            ?? ($rawJson['driver_user_id'] ?? null)
            ?? $request->input('user_id')
            ?? ($rawJson['user_id'] ?? null)
            ?? $request->input('assigned_driver_user_id')
            ?? ($rawJson['assigned_driver_user_id'] ?? null)
            ?? $request->query('driver_id')
            ?? $request->query('driver_user_id')
            ?? $request->query('user_id');

        $query = $this->getBaseOrderQuery($request)
            ->where(function ($q) {
                $q->where('status', 'delivered')
                    ->orWhereHas('driverAssignment', function ($dq) {
                        $dq->where('driver_status', 'delivered')
                            ->orWhere('order_status', 'delivered');
                    });
            });

        if (!empty($driverId)) {
            $query->where(function ($q) use ($driverId) {
                $q->where('delivered_by', $driverId)
                    ->orWhereHas('driverAssignment', function ($dq) use ($driverId) {
                        $dq->where('assigned_driver_user_id', $driverId);
                    });
            });
        }

        $paginator = $query->orderBy('delivered_at', 'desc')
            ->orderBy('updated_at', 'desc')
            ->paginate($perPage);

        return $this->buildPaginatedResponse($paginator, 'delivered', 'Delivered orders retrieved successfully.');
    }
/**
     * 9. GET /api/orders/installation or /api/orders/status/installation
     * Retrieve installation orders list (status = 'installation', 'ready_for_installation', 'in_installation', 'installed', or tagged/containing installation).
     */
    public function installationOrders(Request $request)
    {
        $this->handleSilentSync($request);
        $perPage = max(1, min((int) $request->query('per_page', 15), 100));

        $query = $this->getBaseOrderQuery($request);

        // Filter for installation orders
        if ($request->has('status') && !empty($request->query('status'))) {
            $query->where('status', $request->query('status'));
        } else {
            $query->where(function ($q) {
                $q->whereIn('status', ['installation', 'ready_for_installation', 'in_installation', 'installed', 'installation_pending'])
                    ->orWhere('status', 'like', '%install%')
                    ->orWhereHas('items', function ($iq) {
                        $iq->where('product_name', 'like', '%install%')
                            ->orWhere('product_code', 'like', '%install%');
                    });
            });
        }

        $paginator = $query->orderBy('updated_at', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return $this->buildPaginatedResponse($paginator, 'installation', 'Installation orders retrieved successfully.');
    }

    /**
     * 10. GET /api/orders/cancelled-delivery
     * Retrieve cancelled delivery orders list (status = 'cancelled', 'delivery_cancelled', 'delivery_failed', or driver assignment status = 'cancelled').
     */
    public function cancelledDelivery(Request $request)
    {
        $this->handleSilentSync($request);
        $perPage = max(1, min((int) $request->query('per_page', 15), 100));

        $query = $this->getBaseOrderQuery($request);

        if ($request->has('status') && !empty($request->query('status'))) {
            $query->where('status', $request->query('status'));
        } else {
            $query->where(function ($q) {
                $q->whereIn('status', ['cancelled', 'delivery_cancelled', 'cancelled_delivery', 'delivery_failed', 'failed'])
                    ->orWhere('status', 'like', '%cancel%')
                    ->orWhereHas('driverAssignment', function ($dq) {
                        $dq->whereIn('driver_status', ['cancelled', 'cancelled_delivery', 'delivery_failed', 'refund', 'failed']);
                    });
            });
        }

        $paginator = $query->orderBy('updated_at', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return $this->buildPaginatedResponse($paginator, 'cancelled_delivery', 'Cancelled delivery orders retrieved successfully.');
    }

    /**
     * 11. GET /api/orders/flagged or /api/orders/status/flagged
     * Retrieve all flagged orders list for admin panel (orders with status='flagged', items flagged, or driver assigned flagged).
     */
    public function flaggedOrders(Request $request)
    {
        $this->handleSilentSync($request);
        $perPage = max(1, min((int) $request->query('per_page', 15), 100));

        $query = $this->getBaseOrderQuery($request);

        if ($request->has('status') && !empty($request->query('status'))) {
            $query->where('status', $request->query('status'));
        } else {
            $query->where(function ($q) {
                $q->where('status', 'flagged')
                    ->orWhereHas('items', function ($iq) {
                        $iq->where('is_flagged', true);
                    })
                    ->orWhereHas('discrepancies')
                    ->orWhereHas('driverAssignment', function ($dq) {
                        $dq->where('driver_status', 'flagged')
                            ->orWhere('order_status', 'flagged');
                    });
            });
        }

        $paginator = $query->orderBy('updated_at', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return $this->buildPaginatedResponse($paginator, 'flagged', 'Flagged orders retrieved successfully.');
    }

    /**
     * Helper method to format single order details consistently for API responses.
     */
    protected function formatOrderDetails(Order $order): array
    {
        $totalItems = $order->items->count();
        $pickedItems = $order->items->whereIn('status', ['picked', 'packed', 'delivered'])->count();
        $packedItems = $order->items->whereIn('status', ['packed', 'delivered'])->count();
        $deliveredItems = $order->items->where('status', 'delivered')->count();

        // Unique Pickers
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

        // Unique Packers
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
            'bag_count' => (int) ($order->bag_count ?? 0),
            'assigned_user' => $order->assigned_to ? [
                'id' => (int) $order->assigned_to,
                'name' => $order->assignedUser ? $order->assignedUser->name : ($order->assigned_user_name ?? 'Picker User'),
                'assigned_at' => $order->assigned_at ? $order->assigned_at->toIso8601String() : null,
            ] : null,
            'driver_assignment' => $order->driverAssignment ? [
                'id' => $order->driverAssignment->id,
                'assigned_driver_user_id' => (int) $order->driverAssignment->assigned_driver_user_id,
                'driver_name' => $order->driverAssignment->driver_name,
                'zone' => $order->driverAssignment->zone,
                'driver_status' => $order->driverAssignment->driver_status,
                'assigned_at' => $order->driverAssignment->assigned_at ? $order->driverAssignment->assigned_at->toIso8601String() : null,
                'accepted_at' => $order->driverAssignment->accepted_at ? $order->driverAssignment->accepted_at->toIso8601String() : null,
                'started_at' => $order->driverAssignment->started_at ? $order->driverAssignment->started_at->toIso8601String() : null,
                'delivered_at' => $order->driverAssignment->delivered_at ? $order->driverAssignment->delivered_at->toIso8601String() : null,
            ] : null,
            'driver_user' => $order->driverAssignment ? [
                'id' => (int) $order->driverAssignment->assigned_driver_user_id,
                'name' => $order->driverAssignment->driver_name,
                'assigned_at' => $order->driverAssignment->assigned_at ? $order->driverAssignment->assigned_at->toIso8601String() : null,
            ] : (($order->delivered_by || $order->delivered_user_name) ? [
                    'id' => $order->delivered_by ? (int) $order->delivered_by : null,
                    'name' => $order->deliveredUser ? $order->deliveredUser->name : ($order->delivered_user_name ?? 'Driver User'),
                    'delivered_at' => $order->delivered_at ? $order->delivered_at->toIso8601String() : null,
                ] : null),
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
                    'image' => $item->image,
                    'image_url' => $item->image,
                    'product_image' => $item->image,
                    'product_image_url' => $item->image,
                    'quantity' => $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'status' => $item->status,
                    'is_flagged' => (bool) $item->is_flagged,
                    'flag_reason' => $item->flag_reason,
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
            })->values(),
            'created_at' => $order->created_at ? $order->created_at->toIso8601String() : null,
            'updated_at' => $order->updated_at ? $order->updated_at->toIso8601String() : null,
        ];
    }
}
