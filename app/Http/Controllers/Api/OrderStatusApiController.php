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
        if ($request->boolean('auto_sync', false)) {
            try {
                $this->shopifyService->syncOrdersToDatabase();
            } catch (\Exception $e) {
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
        $perPage = min((int) $request->query('per_page', 15), 100);

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
        $perPage = min((int) $request->query('per_page', 15), 100);

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
        $perPage = min((int) $request->query('per_page', 15), 100);

        $paginator = $this->getBaseOrderQuery($request)
            ->where(function ($q) {
                $q->where('status', 'ready_to_assign');
                //   ->orWhere('status', 'packed');
            })
            ->whereNull('delivered_by')
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
        $perPage = min((int) $request->query('per_page', 15), 100);

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
        $perPage = min((int) $request->query('per_page', 15), 100);

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
        $perPage = min((int) $request->query('per_page', 15), 100);

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
        $perPage = min((int) $request->query('per_page', 15), 100);

        $paginator = $this->getBaseOrderQuery($request)
            ->whereIn('status', ['assigned_to_driver', 'out_for_delivery', 'in_delivery'])
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
        $perPage = min((int) $request->query('per_page', 15), 100);

        $paginator = $this->getBaseOrderQuery($request)
            ->where('status', 'delivered')
            ->orderBy('delivered_at', 'desc')
            ->orderBy('updated_at', 'desc')
            ->paginate($perPage);

        return $this->buildPaginatedResponse($paginator, 'delivered', 'Delivered orders retrieved successfully.');
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
