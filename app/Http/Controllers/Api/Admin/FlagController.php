<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemDiscrepancy;
use App\Models\OrderDriverAssigned;
use App\Models\OrderStatusLog;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Exception;

class FlagController extends Controller
{
    /**
     * Get list of all orders where status is flagged or containing flagged items.
     * Route: GET /api/admin/orders/status/flagged
     * Route: GET /api/admin/orders/flagged
     * Route: GET /api/admin/flag/list
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = max(1, min((int) $request->query('per_page', 15), 100));

            $relations = [
                'items.assignedUser',
                'items.pickedUser',
                'items.packedUser',
                'items.deliveredUser',
                'assignedUser',
                'pickedUser',
                'packedUser',
                'deliveredUser',
                'driverAssignment',
                'packerAssignment',
                'payment',
                'discrepancies.user',
                'discrepancies.orderItem',
                'logs.user',
            ];

            $query = Order::with($relations);

            // Filter for flagged orders in the orders table
            // Includes orders where status = 'flagged', or any order item is flagged, or order has discrepancies
            $query->where(function ($q) {
                $q->where('status', 'flagged')
                    ->orWhereHas('items', function ($iq) {
                        $iq->where('is_flagged', true)
                            ->orWhere('status', 'flagged');
                    })
                    ->orWhereHas('discrepancies', function ($dq) {
                        $dq->where('status', 'open')
                            ->orWhere('status', 'flagged');
                    });
            });

            // Search filter
            if ($request->filled('search')) {
                $search = trim($request->query('search'));
                $cleanSearch = ltrim($search, '#');
                $query->where(function ($q) use ($search, $cleanSearch) {
                    $q->where('order_number', 'like', "%{$search}%")
                        ->orWhere('order_number', 'like', "%{$cleanSearch}%")
                        ->orWhere('customer_name', 'like', "%{$search}%")
                        ->orWhere('customer_phone', 'like', "%{$search}%")
                        ->orWhere('assigned_user_name', 'like', "%{$search}%")
                        ->orWhere('delivered_user_name', 'like', "%{$search}%")
                        ->orWhereHas('discrepancies', function ($dq) use ($search) {
                            $dq->where('issue_type', 'like', "%{$search}%")
                                ->orWhere('comment', 'like', "%{$search}%")
                                ->orWhere('user_name', 'like', "%{$search}%");
                        });
                });
            }

            // Issue type / reason filter
            if ($request->filled('issue_type') || $request->filled('reason')) {
                $reason = $request->query('issue_type') ?? $request->query('reason');
                $query->where(function ($q) use ($reason) {
                    $q->whereHas('discrepancies', function ($dq) use ($reason) {
                        $dq->where('issue_type', $reason);
                    })->orWhereHas('items', function ($iq) use ($reason) {
                        $iq->where('flag_reason', $reason);
                    });
                });
            }

            // Date filtering
            if ($request->filled('from_date')) {
                $query->whereDate('created_at', '>=', $request->query('from_date'));
            }
            if ($request->filled('to_date')) {
                $query->whereDate('created_at', '<=', $request->query('to_date'));
            }

            $paginator = $query->orderBy('updated_at', 'desc')
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);

            $formattedOrders = collect($paginator->items())->map(function ($order) {
                return $this->formatFlaggedOrder($order);
            })->values();

            return response()->json([
                'success' => true,
                'message' => 'Flagged orders retrieved successfully.',
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
            ], 200);

        } catch (Exception $e) {
            Log::error('FlagController index error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve flagged orders: ' . $e->getMessage(),
                'data' => [],
            ], 500);
        }
    }

    /**
     * Alias for index to maintain backwards compatibility
     */
    public function flaggedOrders(Request $request): JsonResponse
    {
        return $this->index($request);
    }

    /**
     * Alias for list endpoint
     */
    public function list(Request $request): JsonResponse
    {
        return $this->index($request);
    }



    /**
     * Format flagged order model for JSON response
     */
    protected function formatFlaggedOrder(Order $order): array
    {
        $discrepancies = $order->discrepancies->map(function ($disc) {
            return [
                'id' => $disc->id,
                'order_item_id' => $disc->order_item_id,
                'issue_type' => $disc->issue_type,
                'reason' => $disc->issue_type,
                'comment' => $disc->comment,
                'status' => $disc->status,
                'photo_url' => $disc->photo_url,
                'reported_by' => [
                    'id' => $disc->user_id ? (int) $disc->user_id : null,
                    'name' => $disc->user_name ?? ($disc->user->name ?? 'N/A'),
                ],
                'created_at' => $disc->created_at ? $disc->created_at->toIso8601String() : null,
            ];
        })->values();

        $items = $order->items->map(function ($item) {
            return [
                'id' => $item->id,
                'line_item_id' => $item->line_item_id,
                'product_id' => $item->product_id,
                'product_code' => $item->product_code,
                'barcode' => $item->barcode,
                'product_name' => $item->product_name,
                'image' => $item->image,
                'quantity' => (int) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'status' => $item->status,
                'is_flagged' => (bool) $item->is_flagged,
                'flag_reason' => $item->flag_reason,
            ];
        })->values();

        $driver = $order->driverAssignment ? [
            'id' => (int) $order->driverAssignment->assigned_driver_user_id,
            'name' => $order->driverAssignment->driver_name,
            'driver_status' => $order->driverAssignment->driver_status,
            'zone' => $order->driverAssignment->zone,
            'assigned_at' => $order->driverAssignment->assigned_at ? $order->driverAssignment->assigned_at->toIso8601String() : null,
        ] : (($order->delivered_by || $order->delivered_user_name) ? [
                'id' => $order->delivered_by ? (int) $order->delivered_by : null,
                'name' => $order->deliveredUser ? $order->deliveredUser->name : ($order->delivered_user_name ?? 'Driver User'),
                'delivered_at' => $order->delivered_at ? $order->delivered_at->toIso8601String() : null,
            ] : null);

        return [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'bag_count' => (int) ($order->bag_count ?? 0),
            'customer' => [
                'name' => $order->customer_name ?? 'N/A',
                'phone' => $order->customer_phone ?? 'N/A',
                'delivery_address' => $order->delivery_address ?? 'N/A',
            ],
            'total_amount' => (float) $order->total_amount,
            'payment_method' => $order->payment_method ?? ($order->payment->payment_method ?? 'N/A'),
            'payment_status' => $order->payment_status ?? ($order->payment->payment_status ?? 'N/A'),
            'driver' => $driver,
            'assigned_to' => $order->assigned_to ? (int) $order->assigned_to : null,
            'assigned_user_name' => $order->assigned_user_name ?: ($order->assignedUser->name ?? null),
            'items_count' => $order->items->count(),
            'flagged_items_count' => $order->items->where('is_flagged', true)->count(),
            'items' => $items,
            'discrepancies' => $discrepancies,
            'created_at' => $order->created_at ? $order->created_at->toIso8601String() : null,
            'updated_at' => $order->updated_at ? $order->updated_at->toIso8601String() : null,
        ];
    }
}
