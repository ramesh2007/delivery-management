<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ScheduledInstallationController extends Controller
{
    /**
     * Create / Update Scheduled Installation status for an order item.
     * Accepts order_number/order_id and item_id/order_item_id/line_item_id/sku.
     */
    public function store(Request $request)
    {
        $payload = array_merge($request->all(), is_array($request->json()?->all()) ? $request->json()->all() : []);
        if ($request->getContent()) {
            $decoded = json_decode($request->getContent(), true);
            if (is_array($decoded)) {
                $payload = array_merge($payload, $decoded);
            }
        }

        $validator = Validator::make($payload, [
            'order_number' => 'nullable|string',
            'order_id' => 'nullable',
            'item_id' => 'nullable',
            'order_item_id' => 'nullable',
            'line_item_id' => 'nullable',
            'sku' => 'nullable|string',
            'product_code' => 'nullable|string',
            'status' => 'nullable|string',
            'notes' => 'nullable|string',
            'scheduled_date' => 'nullable|string',
            'user_name' => 'nullable|string',
            'user_id' => 'nullable',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $orderNumber = $payload['order_number'] ?? $request->input('order_number');
        $orderId = $payload['order_id'] ?? $request->input('order_id');
        $itemId = $payload['item_id'] ?? $payload['order_item_id'] ?? $request->input('item_id') ?? $request->input('order_item_id');
        $lineItemId = $payload['line_item_id'] ?? $request->input('line_item_id');
        $sku = $payload['sku'] ?? $payload['product_code'] ?? $request->input('sku') ?? $request->input('product_code');

        if (!$orderNumber && !$orderId) {
            return response()->json([
                'success' => false,
                'message' => 'Either order_number or order_id is required.',
            ], 422);
        }

        if (!$itemId && !$lineItemId && !$sku) {
            return response()->json([
                'success' => false,
                'message' => 'Either item_id, order_item_id, line_item_id, or sku is required.',
            ], 422);
        }

        return DB::transaction(function () use ($request, $orderNumber, $orderId, $itemId, $lineItemId, $sku) {
            // Find order
            $order = null;
            if ($orderNumber || $orderId) {
                $orderQuery = Order::query();
                $cleanOrderNum = $orderNumber ? trim(ltrim($orderNumber, '#')) : null;

                $orderQuery->where(function ($q) use ($orderNumber, $orderId, $cleanOrderNum) {
                    if ($orderId) {
                        $q->orWhere('id', $orderId);
                    }
                    if ($orderNumber) {
                        $q->orWhere('order_number', $orderNumber)
                            ->orWhere('order_number', $cleanOrderNum)
                            ->orWhere('order_number', '#' . $cleanOrderNum)
                            ->orWhere('order_number', 'like', "%{$cleanOrderNum}%");
                    }
                });
                $order = $orderQuery->first();
            }

            // Find item within order (or globally if order not specified)
            $itemQuery = OrderItem::query();
            if ($order) {
                $itemQuery->where('order_id', $order->id);
            }

            $searchItemVal = $itemId ?? $lineItemId ?? $sku;
            if ($searchItemVal) {
                $cleanItemVal = trim((string) $searchItemVal);
                $itemQuery->where(function ($q) use ($cleanItemVal) {
                    $q->where('id', $cleanItemVal)
                        ->orWhere('line_item_id', $cleanItemVal)
                        ->orWhere('product_code', $cleanItemVal)
                        ->orWhere('product_id', $cleanItemVal)
                        ->orWhere('barcode', $cleanItemVal);
                });
            }

            $orderItem = $itemQuery->first();

            // If item found but order wasn't found yet, resolve order from item
            if (!$order && $orderItem) {
                $order = Order::find($orderItem->order_id);
            }

            if (!$orderItem || !$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order or item not found for the provided criteria.',
                ], 404);
            }

            $oldItemStatus = $orderItem->status;
            $newStatus = $request->input('status', 'scheduled');

            // Update item status
            $orderItem->update([
                'status' => $newStatus,
            ]);

            $oldOrderStatus = $order->status;
            // Update order status to scheduled if appropriate
            if (in_array($order->status, ['pending', 'new', 'ready_to_assign', 'ready_for_installation', 'installation'])) {
                $order->update([
                    'status' => $newStatus,
                ]);
            }

            // Log status change
            $userName = $request->input('user_name', 'System');
            $userId = $request->input('user_id');

            OrderStatusLog::create([
                'order_id' => $order->id,
                'order_item_id' => $orderItem->id,
                'user_id' => $userId,
                'user_name' => $userName,
                'action' => 'scheduled_installation',
                'old_status' => $oldItemStatus,
                'new_status' => $newStatus,
                'notes' => $request->input('notes', "Scheduled installation for item {$orderItem->product_name} (Order {$order->order_number})"),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Installation scheduled successfully.',
                'data' => [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'order_status' => $order->fresh()->status,
                    'item' => [
                        'id' => $orderItem->id,
                        'line_item_id' => $orderItem->line_item_id,
                        'product_name' => $orderItem->product_name,
                        'product_code' => $orderItem->product_code,
                        'status' => $orderItem->fresh()->status,
                    ],
                ],
            ]);
        });
    }

    /**
     * Handle GET or POST on /scheduled-installation
     */
    public function indexOrStore(Request $request)
    {
        if ($request->isMethod('get')) {
            return $this->index($request);
        }
        return $this->store($request);
    }

    /**
     * Alias method for store
     */
    public function createScheduledInstallation(Request $request)
    {
        return $this->store($request);
    }

    /**
     * Get list of scheduled installations
     */
    public function index(Request $request)
    {
        $perPage = max(1, min((int) $request->query('per_page', 15), 100));

        $query = Order::with(['items', 'assignedUser', 'logs'])
            ->where(function ($q) {
                $q->where('status', 'scheduled')
                    ->orWhere('status', 'like', '%schedule%')
                    ->orWhereHas('items', function ($iq) {
                        $iq->where('status', 'scheduled')
                            ->orWhere('status', 'like', '%schedule%');
                    });
            });

        if ($request->filled('search')) {
            $search = trim($request->query('search'));
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_phone', 'like', "%{$search}%");
            });
        }

        $paginator = $query->orderBy('updated_at', 'desc')->paginate($perPage);

        $formattedOrders = collect($paginator->items())->map(function ($order) {
            return [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'customer' => [
                    'name' => $order->customer_name ?? 'N/A',
                    'phone' => $order->customer_phone ?? 'N/A',
                    'delivery_address' => $order->delivery_address ?? 'N/A',
                ],
                'total_amount' => (float) $order->total_amount,
                'items' => $order->items->map(function ($item) {
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
                        'is_scheduled' => in_array($item->status, ['scheduled', 'scheduled_installation']),
                    ];
                })->values(),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'message' => 'Scheduled installations retrieved successfully.',
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
            'data' => $formattedOrders,
        ]);
    }
}
