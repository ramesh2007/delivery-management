<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\OrderInstallation;
use App\Models\OrderStatusLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ScheduleController extends Controller
{
    /**
     * Make schedule assign for an order item (sets is_scheduled_assigned boolean).
     *
     * Route: POST /api/admin/schedule/assign/{order_item_id?}
     */
    public function assignSchedule(Request $request, $orderItemId = null): JsonResponse
    {
        try {
            $rawJson = json_decode($request->getContent(), true) ?? [];

            // 1. Determine boolean value (default: true)
            $isScheduledAssigned = true;
            if ($request->has('is_scheduled_assigned')) {
                $val = $request->input('is_scheduled_assigned');
                $isScheduledAssigned = filter_var($val, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($isScheduledAssigned === null) {
                    $isScheduledAssigned = (bool) $val;
                }
            } elseif (isset($rawJson['is_scheduled_assigned'])) {
                $val = $rawJson['is_scheduled_assigned'];
                $isScheduledAssigned = filter_var($val, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($isScheduledAssigned === null) {
                    $isScheduledAssigned = (bool) $val;
                }
            } elseif ($request->has('scheduled')) {
                $isScheduledAssigned = filter_var($request->input('scheduled'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
            } elseif (isset($rawJson['scheduled'])) {
                $isScheduledAssigned = filter_var($rawJson['scheduled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
            }

            // 2. Check if array of items or line_items provided
            $itemIds = $request->input('order_item_ids')
                ?? ($rawJson['order_item_ids'] ?? null)
                ?? $request->input('line_item_ids')
                ?? ($rawJson['line_item_ids'] ?? null)
                ?? $request->input('item_ids')
                ?? ($rawJson['item_ids'] ?? null);

            $resolvedItemId = $orderItemId;

            $lineItems = $request->input('line_items') ?? ($rawJson['line_items'] ?? null);
            if (is_array($lineItems) && count($lineItems) > 0) {
                $extractedIds = [];
                foreach ($lineItems as $item) {
                    if (is_array($item)) {
                        $extractedIds[] = $item['id'] ?? $item['line_item_id'] ?? $item['order_item_id'] ?? null;
                    } else {
                        $extractedIds[] = $item;
                    }
                }
                $extractedIds = array_values(array_filter($extractedIds));
                if (count($extractedIds) === 1) {
                    $resolvedItemId = $extractedIds[0];
                } elseif (count($extractedIds) > 1) {
                    $itemIds = $extractedIds;
                }
            }

            if (is_array($itemIds) && count($itemIds) > 0) {
                return $this->assignMultipleItems($itemIds, $isScheduledAssigned);
            }

            // 3. Resolve single order item ID or line item ID
            $resolvedItemId = $resolvedItemId
                ?? $request->input('order_item_id')
                ?? ($rawJson['order_item_id'] ?? null)
                ?? $request->input('line_item_id')
                ?? ($rawJson['line_item_id'] ?? null)
                ?? $request->input('item_id')
                ?? ($rawJson['item_id'] ?? null)
                ?? $request->input('id')
                ?? ($rawJson['id'] ?? null);

            if (empty($resolvedItemId)) {
                return response()->json([
                    'status' => 'error',
                    'success' => false,
                    'message' => 'The order_item_id or line_item_id field (or line_items array) is required.'
                ], 422);
            }

            // 4. Find order item by id or line_item_id
            $orderItem = OrderItem::where('id', $resolvedItemId)
                ->orWhere('line_item_id', (string) $resolvedItemId)
                ->first();

            // Fallback: check if $resolvedItemId is an existing OrderInstallation ID
            if (!$orderItem) {
                $installationById = OrderInstallation::find($resolvedItemId);
                if ($installationById && $installationById->orderItem) {
                    $orderItem = $installationById->orderItem;
                }
            }

            if (!$orderItem) {
                return response()->json([
                    'status' => 'error',
                    'success' => false,
                    'message' => "Order item with ID / line item ID '{$resolvedItemId}' not found."
                ], 404);
            }

            // 5. Update or create OrderInstallation record
            $installation = OrderInstallation::updateOrCreate(
                ['order_item_id' => $orderItem->id],
                ['is_scheduled_assigned' => (bool) $isScheduledAssigned]
            );

            // 6. Audit logging in OrderStatusLog
            try {
                if (class_exists(OrderStatusLog::class)) {
                    OrderStatusLog::create([
                        'order_id' => $orderItem->order_id,
                        'order_item_id' => $orderItem->id,
                        'user_id' => Auth::id(),
                        'user_name' => Auth::user()?->name ?? 'Admin User',
                        'action' => $isScheduledAssigned ? 'schedule_assigned' : 'schedule_unassigned',
                        'notes' => 'Installation schedule assigned: ' . ($isScheduledAssigned ? 'true' : 'false'),
                    ]);
                }
            } catch (\Throwable $logEx) {
                Log::warning("Could not write OrderStatusLog for item {$orderItem->id}: " . $logEx->getMessage());
            }

            return response()->json([
                'status' => 'success',
                'success' => true,
                'message' => $isScheduledAssigned
                    ? 'Schedule assigned successfully for order item.'
                    : 'Schedule unassigned successfully for order item.',
                'data' => [
                    'order_item_id' => $orderItem->id,
                    'order_id' => $orderItem->order_id,
                    'is_scheduled_assigned' => (bool) $installation->is_scheduled_assigned,
                    'installation' => [
                        'id' => $installation->id,
                        'order_item_id' => $installation->order_item_id,
                        'installation_type' => $installation->installation_type,
                        'installation_level' => $installation->installation_level,
                        'is_scheduled_assigned' => (bool) $installation->is_scheduled_assigned,
                        'created_at' => $installation->created_at,
                        'updated_at' => $installation->updated_at,
                    ],
                    'order_item' => [
                        'id' => $orderItem->id,
                        'order_id' => $orderItem->order_id,
                        'product_name' => $orderItem->product_name,
                        'product_code' => $orderItem->product_code,
                        'status' => $orderItem->status,
                        'is_installable' => (bool) $orderItem->is_installable,
                        'is_scheduled_assigned' => (bool) $installation->is_scheduled_assigned,
                    ]
                ]
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Error in ScheduleController@assignSchedule: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'success' => false,
                'message' => 'An error occurred while assigning schedule: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Batch assign schedule for multiple order items.
     */
    protected function assignMultipleItems(array $itemIds, bool $isScheduledAssigned): JsonResponse
    {
        $updatedItems = [];
        $notFoundIds = [];

        foreach ($itemIds as $id) {
            $orderItem = OrderItem::where('id', $id)
                ->orWhere('line_item_id', (string) $id)
                ->first();
            if (!$orderItem) {
                $notFoundIds[] = $id;
                continue;
            }

            $installation = OrderInstallation::updateOrCreate(
                ['order_item_id' => $orderItem->id],
                ['is_scheduled_assigned' => $isScheduledAssigned]
            );

            try {
                if (class_exists(OrderStatusLog::class)) {
                    OrderStatusLog::create([
                        'order_id' => $orderItem->order_id,
                        'order_item_id' => $orderItem->id,
                        'user_id' => Auth::id(),
                        'user_name' => Auth::user()?->name ?? 'Admin User',
                        'action' => $isScheduledAssigned ? 'schedule_assigned' : 'schedule_unassigned',
                        'notes' => 'Installation schedule assigned: ' . ($isScheduledAssigned ? 'true' : 'false'),
                    ]);
                }
            } catch (\Throwable $logEx) {
                // Ignore log error
            }

            $updatedItems[] = [
                'order_item_id' => $orderItem->id,
                'order_id' => $orderItem->order_id,
                'is_scheduled_assigned' => (bool) $installation->is_scheduled_assigned,
            ];
        }

        return response()->json([
            'status' => 'success',
            'success' => true,
            'message' => count($updatedItems) . ' order items updated successfully.',
            'data' => [
                'updated_count' => count($updatedItems),
                'not_found_ids' => $notFoundIds,
                'items' => $updatedItems,
            ]
        ], 200);
    }

    /**
     * Alias methods for convenience
     */
    public function scheduleAssign(Request $request, $orderItemId = null): JsonResponse
    {
        return $this->assignSchedule($request, $orderItemId);
    }

    public function makeScheduleAssign(Request $request, $orderItemId = null): JsonResponse
    {
        return $this->assignSchedule($request, $orderItemId);
    }

    /**
     * Admin API to list order items details along with order number for is_scheduled_assigned = true (item-wise view).
     * Formatted like /orders/status/all but structured item-wise for admin display.
     *
     * Route: GET|POST /api/admin/orders/status/scheduled-items
     * Route: GET|POST /api/admin/orders/scheduled-items
     * Route: GET|POST /api/admin/schedule/items
     */
    public function scheduledItems(Request $request): JsonResponse
    {
        try {
            $perPage = max(
                1,
                min(
                    (int) ($request->input('per_page') ?? $request->query('per_page', 15)),
                    100
                )
            );

            /*
            |--------------------------------------------------------------------------
            | Determine scheduled filter
            |--------------------------------------------------------------------------
            */
            $scheduledParam = $request->input('is_scheduled_assigned')
                ?? $request->query('is_scheduled_assigned', 'true');

            $isAll = in_array(
                strtolower((string) $scheduledParam),
                ['all', 'any', '*']
            );

            $isScheduledAssigned = filter_var(
                $scheduledParam,
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            );

            if ($isScheduledAssigned === null && !$isAll) {
                $isScheduledAssigned = true;
            }

            /*
            |--------------------------------------------------------------------------
            | Base query
            |--------------------------------------------------------------------------
            */
            $query = OrderItem::with([
                'order.driverAssignment',
                'order.payment',
                'order.assignedUser',
                'order.deliveredUser',

                'installation',

                'assignedUser',
                'pickedUser',
                'packedUser',
                'deliveredUser',
                'packerVerifiedUser',
            ]);

            /*
            |--------------------------------------------------------------------------
            | Scheduled order filter
            |--------------------------------------------------------------------------
            |
            | IMPORTANT:
            |
            | We are NOT checking the installation of the current OrderItem.
            |
            | Instead:
            |
            |   1. Find orders having at least one scheduled item.
            |   2. Return ALL items belonging to those orders.
            |
            | Example:
            |
            | Order #1010
            |   Item A -> scheduled
            |   Item B -> not scheduled
            |
            | Both Item A and Item B will be returned.
            |
            |--------------------------------------------------------------------------
            */
            if (!$isAll) {
                $query->whereHas('order', function ($orderQuery) use ($isScheduledAssigned) {

                    $orderQuery->whereHas('items', function ($itemQuery) use ($isScheduledAssigned) {

                        $itemQuery->whereHas('installation', function ($installationQuery) use ($isScheduledAssigned) {

                            $installationQuery->where(
                                'is_scheduled_assigned',
                                (bool) $isScheduledAssigned
                            );

                        });

                    });

                });
            } else {
                /*
                |--------------------------------------------------------------------------
                | ALL
                |--------------------------------------------------------------------------
                |
                | When is_scheduled_assigned=all, return items from orders that
                | have an installation record.
                |
                */
                $query->whereHas('order', function ($orderQuery) {

                    $orderQuery->whereHas('items', function ($itemQuery) {

                        $itemQuery->whereHas('installation');

                    });

                });
            }

            /*
            |--------------------------------------------------------------------------
            | Search filter
            |--------------------------------------------------------------------------
            */
            $search = trim(
                (string) (
                    $request->input('search')
                    ?? $request->query('search', '')
                )
            );

            if ($search !== '') {
                $query->where(function ($q) use ($search) {

                    $q->where('product_name', 'like', "%{$search}%")
                        ->orWhere('product_code', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%")

                        ->orWhereHas('order', function ($oq) use ($search) {

                            $oq->where('order_number', 'like', "%{$search}%")
                                ->orWhere('customer_name', 'like', "%{$search}%")
                                ->orWhere('customer_phone', 'like', "%{$search}%")
                                ->orWhere('delivery_address', 'like', "%{$search}%");

                        });

                });
            }

            /*
            |--------------------------------------------------------------------------
            | Order Item Status filter
            |--------------------------------------------------------------------------
            */
            $status = $request->input('status')
                ?? $request->query('status');

            if (!empty($status) && strtolower((string) $status) !== 'all') {

                $query->where('status', $status);

            }

            /*
            |--------------------------------------------------------------------------
            | Order Status filter
            |--------------------------------------------------------------------------
            */
            $orderStatus = $request->input('order_status')
                ?? $request->query('order_status');

            if (!empty($orderStatus) && strtolower((string) $orderStatus) !== 'all') {

                $query->whereHas('order', function ($oq) use ($orderStatus) {

                    $oq->where('status', $orderStatus);

                });

            }

            /*
            |--------------------------------------------------------------------------
            | Installation Type filter
            |--------------------------------------------------------------------------
            */
            $installationType = $request->input('installation_type')
                ?? $request->query('installation_type');

            if (
                !empty($installationType)
                && strtolower((string) $installationType) !== 'all'
            ) {

                /*
                |--------------------------------------------------------------------------
                | Important:
                |
                | Since we are returning ALL items belonging to a scheduled order,
                | applying installation_type directly to the current item would
                | remove other items from that order.
                |
                | Therefore, check whether the ORDER has an item with the requested
                | installation type.
                |--------------------------------------------------------------------------
                */
                $query->whereHas('order', function ($orderQuery) use ($installationType) {

                    $orderQuery->whereHas('items', function ($itemQuery) use ($installationType) {

                        $itemQuery->whereHas('installation', function ($installationQuery) use ($installationType) {

                            $installationQuery->where(
                                'installation_type',
                                $installationType
                            );

                        });

                    });

                });
            }

            /*
            |--------------------------------------------------------------------------
            | Sorting
            |--------------------------------------------------------------------------
            */
            $sortBy = $request->input('sort_by')
                ?? $request->query('sort_by', 'created_at');

            $sortDirection = strtolower(
                (string) (
                    $request->input('sort_direction')
                    ?? $request->query('sort_direction', 'desc')
                )
            ) === 'asc'
                ? 'asc'
                : 'desc';

            if (
                in_array(
                    $sortBy,
                    [
                        'id',
                        'created_at',
                        'updated_at',
                        'product_name',
                        'unit_price',
                        'quantity',
                        'status'
                    ]
                )
            ) {

                $query->orderBy($sortBy, $sortDirection);

            } else {

                $query->orderBy('created_at', 'desc');

            }

            /*
            |--------------------------------------------------------------------------
            | Pagination
            |--------------------------------------------------------------------------
            */
            $paginator = $query->paginate($perPage);

            /*
            |--------------------------------------------------------------------------
            | Format response
            |--------------------------------------------------------------------------
            */
            $formattedItems = collect($paginator->items())
                ->map(function (OrderItem $item) {

                    return $this->formatItemDetails($item);

                })
                ->values();

            /*
            |--------------------------------------------------------------------------
            | Response
            |--------------------------------------------------------------------------
            */
            return response()->json([

                'success' => true,

                'status' => 'success',

                'message' => 'Scheduled order items retrieved successfully.',

                'status_filter' => $isAll
                    ? 'all'
                    : (
                        $isScheduledAssigned
                            ? 'scheduled_assigned'
                            : 'not_scheduled_assigned'
                    ),

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

                'data' => $formattedItems,

            ], 200);

        } catch (\Throwable $e) {

            Log::error(
                'Error in ScheduleController@scheduledItems: ' . $e->getMessage(),
                [
                    'trace' => $e->getTraceAsString()
                ]
            );

            return response()->json([

                'status' => 'error',

                'success' => false,

                'message' =>
                    'An error occurred while retrieving scheduled items: '
                    . $e->getMessage(),

            ], 500);
        }
    }

    /**
     * Alias for scheduledItems.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->scheduledItems($request);
    }

    /**
     * Helper to format a single OrderItem into an item-wise admin structure with order details.
     */
    protected function formatItemDetails(OrderItem $item): array
    {
        $order = $item->order;
        $installation = $item->installation;

        return [
            'item_id' => $item->id,
            'order_item_id' => $item->id,
            'id' => $item->id,
            'order_id' => $item->order_id,
            'order_number' => $order ? $order->order_number : null,
            'order_status' => $order ? $order->status : null,
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
            'total_price' => (float) ($item->quantity * $item->unit_price),
            'status' => $item->status,
            'is_installable' => (bool) $item->is_installable,
            'is_scheduled_assigned' => (bool) ($installation ? $installation->is_scheduled_assigned : false),
            'installation_type' => $installation ? $installation->installation_type : null,
            'installation_level' => $installation ? $installation->installation_level : null,
            'installation' => $installation ? [
                'id' => $installation->id,
                'order_item_id' => $installation->order_item_id,
                'installation_type' => $installation->installation_type,
                'installation_level' => $installation->installation_level,
                'is_scheduled_assigned' => (bool) $installation->is_scheduled_assigned,
                'created_at' => $installation->created_at ? $installation->created_at->toIso8601String() : null,
                'updated_at' => $installation->updated_at ? $installation->updated_at->toIso8601String() : null,
            ] : null,
            'customer' => [
                'name' => $order ? ($order->customer_name ?? 'N/A') : 'N/A',
                'phone' => $order ? ($order->customer_phone ?? 'N/A') : 'N/A',
                'delivery_address' => $order ? ($order->delivery_address ?? 'N/A') : 'N/A',
            ],
            'customer_name' => $order ? ($order->customer_name ?? 'N/A') : 'N/A',
            'customer_phone' => $order ? ($order->customer_phone ?? 'N/A') : 'N/A',
            'delivery_address' => $order ? ($order->delivery_address ?? 'N/A') : 'N/A',
            'order' => $order ? [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'customer_name' => $order->customer_name,
                'customer_phone' => $order->customer_phone,
                'delivery_address' => $order->delivery_address,
                'total_amount' => (float) $order->total_amount,
                'bag_count' => (int) ($order->bag_count ?? 0),
                'created_at' => $order->created_at ? $order->created_at->toIso8601String() : null,
            ] : null,
            'assigned_to' => $item->assigned_to ? (int) $item->assigned_to : null,
            'assigned_user_name' => $item->assigned_user_name ?: ($item->assignedUser->name ?? null),
            'assigned_user' => $item->assigned_to ? [
                'id' => (int) $item->assigned_to,
                'name' => $item->assignedUser ? $item->assignedUser->name : ($item->assigned_user_name ?? 'Picker User'),
                'assigned_at' => $item->assigned_at ? $item->assigned_at->toIso8601String() : null,
            ] : null,
            'picked_by' => $item->picked_by ? (int) $item->picked_by : null,
            'picked_user_name' => $item->picked_user_name ?: ($item->pickedUser->name ?? null),
            'picked_user' => ($item->picked_by || $item->picked_user_name) ? [
                'id' => $item->picked_by ? (int) $item->picked_by : null,
                'name' => $item->pickedUser ? $item->pickedUser->name : ($item->picked_user_name ?? 'Picker User'),
                'picked_at' => $item->picked_at ? $item->picked_at->toIso8601String() : null,
            ] : null,
            'packed_by' => $item->packed_by ? (int) $item->packed_by : null,
            'packed_user_name' => $item->packed_user_name ?: ($item->packedUser->name ?? null),
            'packed_user' => ($item->packed_by || $item->packed_user_name) ? [
                'id' => $item->packed_by ? (int) $item->packed_by : null,
                'name' => $item->packedUser ? $item->packedUser->name : ($item->packed_user_name ?? 'Packer User'),
                'packed_at' => $item->packed_at ? $item->packed_at->toIso8601String() : null,
            ] : null,
            'delivered_by' => $item->delivered_by ? (int) $item->delivered_by : null,
            'delivered_user_name' => $item->delivered_user_name ?: ($item->deliveredUser->name ?? null),
            'delivered_user' => ($item->delivered_by || $item->delivered_user_name) ? [
                'id' => $item->delivered_by ? (int) $item->delivered_by : null,
                'name' => $item->deliveredUser ? $item->deliveredUser->name : ($item->delivered_user_name ?? 'Driver User'),
                'delivered_at' => $item->delivered_at ? $item->delivered_at->toIso8601String() : null,
            ] : null,
            'driver_assignment' => ($order && $order->driverAssignment) ? [
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
            'is_flagged' => (bool) $item->is_flagged,
            'flag_reason' => $item->flag_reason,
            'is_packer_verified' => (bool) $item->is_packer_verified,
            'packer_verified_user' => ($item->packer_verified_by || $item->packer_verified_user_name) ? [
                'id' => $item->packer_verified_by ? (int) $item->packer_verified_by : null,
                'name' => $item->packerVerifiedUser ? $item->packerVerifiedUser->name : ($item->packer_verified_user_name ?? 'Packer User'),
                'verified_at' => $item->packer_verified_at ? $item->packer_verified_at->toIso8601String() : null,
            ] : null,
            'picked_at' => $item->picked_at ? $item->picked_at->toIso8601String() : null,
            'packed_at' => $item->packed_at ? $item->packed_at->toIso8601String() : null,
            'delivered_at' => $item->delivered_at ? $item->delivered_at->toIso8601String() : null,
            'created_at' => $item->created_at ? $item->created_at->toIso8601String() : null,
            'updated_at' => $item->updated_at ? $item->updated_at->toIso8601String() : null,
        ];
    }
}
