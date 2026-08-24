<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderDriverAssigned;
use App\Models\OrderItemDiscrepancy;
use App\Models\OrderStatusLog;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Exception;

class DeliveryManagementController extends Controller
{
    /**
     * Get list of drivers.
     */
    public function index(): JsonResponse
    {
        $drivers = User::where('role', 'driver')
            ->orWhere('role', 'Driver')
            ->orWhereHas('roles', function ($query) {
                $query->whereIn('name', ['driver', 'Driver']);
            })
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $drivers
        ]);
    }

    /**
     * Assign driver to an order.
     * Route: POST /api/orders/driver/assign
     */
    public function assignDriver(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'order_number' => 'nullable|string',
                'order_id' => 'nullable|integer',
                'assigned_driver_user_id' => 'required|exists:users,id',
                'zone' => 'nullable|string',
            ]);

            if (empty($validated['order_number']) && empty($validated['order_id'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Either order_number or order_id is required.'
                ], 422);
            }

            $order = $this->findOrder($validated);
            if (!$order) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Order not found.'
                ], 404);
            }

            $driver = User::find($validated['assigned_driver_user_id']);
            $oldStatus = $order->status;
            $newStatus = 'assigned_to_driver';

            $assignment = OrderDriverAssigned::updateOrCreate(
                ['order_id' => $order->id],
                [
                    'order_number' => $order->order_number,
                    'assigned_driver_user_id' => $driver->id,
                    'driver_name' => $driver->name,
                    'zone' => $validated['zone'] ?? null,
                    'order_status' => $newStatus,
                    'driver_status' => 'assigned',
                    'assigned_at' => now(),
                ]
            );

            $order->update([
                'status' => $newStatus,
            ]);

            OrderStatusLog::create([
                'order_id' => $order->id,
                'user_id' => auth()->id() ?? $driver->id,
                'user_name' => auth()->user()?->name ?? 'System',
                'action' => 'driver_assigned',
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'notes' => "Assigned to driver: {$driver->name} (User ID: {$driver->id})" . (!empty($validated['zone']) ? " [Zone: {$validated['zone']}]" : ""),
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Driver assigned successfully.',
                'data' => $assignment->load(['order.items', 'driver'])
            ], 200);

        } catch (Exception $e) {
            Log::error('Assign Driver Error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to assign driver: ' . $e->getMessage()
            ], 500);
        }
    }


    /**
     * Get list of assigned driver orders / assignments.
     * Route: POST /api/orders/driver/assigned
     * Route: GET /api/orders/driver/assigned
     */
    public function assignedDriver(Request $request): JsonResponse
    {
        try {
            $query = OrderDriverAssigned::with(['order.items', 'driver']);

            // Optional driver filter from request body or query parameter
            $driverId = $request->input('assigned_driver_user_id')
                ?? $request->input('driver_user_id')
                ?? $request->input('driver_id')
                ?? $request->query('driver_user_id')
                ?? $request->query('assigned_driver_user_id');

            if (!empty($driverId)) {
                $query->where('assigned_driver_user_id', $driverId);
            }

            // Optional driver status filter (assigned, accepted, started, delivered, cancelled, refund, exchange)
            $driverStatus = $request->input('driver_status') ?? $request->query('driver_status');
            if (!empty($driverStatus)) {
                $query->where('driver_status', $driverStatus);
            }

            // Optional order status filter
            $orderStatus = $request->input('order_status') ?? $request->query('order_status');
            if (!empty($orderStatus)) {
                $query->where('order_status', $orderStatus);
            }

            // Optional search filter
            $search = $request->input('search') ?? $request->query('search');
            if (!empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->where('order_number', 'like', "%{$search}%")
                      ->orWhere('driver_name', 'like', "%{$search}%")
                      ->orWhere('zone', 'like', "%{$search}%");
                });
            }

            $assignments = $query->orderBy('updated_at', 'desc')->get();

            $formattedAssignments = $assignments->map(function ($assignment) {
                $order = $assignment->order;
                $bagCount = $order ? (int) ($order->bag_count ?? 0) : 0;
                $itemsCount = $order && $order->items ? $order->items->count() : 0;

                return [
                    'id' => $assignment->id,
                    'order_id' => $assignment->order_id,
                    'order_number' => $assignment->order_number,
                    'assigned_driver_user_id' => $assignment->assigned_driver_user_id ? (int) $assignment->assigned_driver_user_id : null,
                    'driver_name' => $assignment->driver_name,
                    'zone' => $assignment->zone,
                    'order_status' => $assignment->order_status,
                    'driver_status' => $assignment->driver_status,
                    'bag_count' => $bagCount,
                    'items_count' => $itemsCount,
                    'total_items' => $itemsCount,
                    'assigned_at' => $assignment->assigned_at ? $assignment->assigned_at->toIso8601String() : null,
                    'accepted_at' => $assignment->accepted_at ? $assignment->accepted_at->toIso8601String() : null,
                    'started_at' => $assignment->started_at ? $assignment->started_at->toIso8601String() : null,
                    'delivered_at' => $assignment->delivered_at ? $assignment->delivered_at->toIso8601String() : null,
                    'cancelled_at' => $assignment->cancelled_at ? $assignment->cancelled_at->toIso8601String() : null,
                    'refund_at' => $assignment->refund_at ? $assignment->refund_at->toIso8601String() : null,
                    'exchange_at' => $assignment->exchange_at ? $assignment->exchange_at->toIso8601String() : null,
                    'created_at' => $assignment->created_at ? $assignment->created_at->toIso8601String() : null,
                    'updated_at' => $assignment->updated_at ? $assignment->updated_at->toIso8601String() : null,
                    'order' => $order ? [
                        'id' => $order->id,
                        'order_number' => $order->order_number,
                        'customer_name' => $order->customer_name ?? 'N/A',
                        'customer_phone' => $order->customer_phone ?? 'N/A',
                        'delivery_address' => $order->delivery_address ?? 'N/A',
                        'total_amount' => (float) $order->total_amount,
                        'status' => $order->status,
                        'bag_count' => $bagCount,
                        'items_count' => $itemsCount,
                        'total_items' => $itemsCount,
                        'created_at' => $order->created_at ? $order->created_at->toIso8601String() : null,
                        'items' => $order->items ? $order->items->map(function ($item) {
                            return [
                                'item_id' => $item->id,
                                'line_item_id' => $item->line_item_id,
                                'product_id' => $item->product_id,
                                'product_code' => $item->product_code,
                                'barcode' => $item->barcode,
                                'product_name' => $item->product_name,
                                'quantity' => (int) $item->quantity,
                                'unit_price' => (float) $item->unit_price,
                                'status' => $item->status,
                            ];
                        })->values() : [],
                    ] : null,
                    'driver' => $assignment->driver,
                ];
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Assigned driver orders retrieved successfully.',
                'count' => $formattedAssignments->count(),
                'data' => $formattedAssignments
            ], 200);

        } catch (Exception $e) {
            Log::error('Get Assigned Driver List Error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve assigned driver orders: ' . $e->getMessage()
            ], 500);
        }
    }
    /**
     * Driver accepts an order.
     * Route: POST /api/orders/driver/accepted
     */
    public function orderAccepted(Request $request): JsonResponse
    {
        return $this->processDriverStatusUpdate($request, 'accepted', 'driver_order_accepted');
    }

    /**
     * Driver unaccepts an order assignment.
     * Route: POST /api/orders/driver/unaccepted
     */
    public function orderUnaccepted(Request $request): JsonResponse
    {
        return $this->processDriverStatusUpdate($request, 'assigned', 'driver_order_unaccepted');
    }

    /**
     * General status update endpoint for driver.
     * Route: POST /api/orders/driver/update-status
     */
    public function updateDriverStatus(Request $request): JsonResponse
    {
        $rawJson = json_decode($request->getContent(), true) ?? [];
        $status = $request->input('status')
            ?? ($rawJson['status'] ?? null)
            ?? $request->input('driver_status')
            ?? ($rawJson['driver_status'] ?? null);

        if (!$status || !in_array($status, ['assigned', 'accepted', 'started', 'delivered', 'cancelled', 'refund', 'exchange', 'flagged'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'The status field is required and must be one of: assigned, accepted, started, delivered, cancelled, refund, exchange, flagged.'
            ], 422);
        }

        return $this->processDriverStatusUpdate($request, $status, 'driver_status_updated');
    }

    /**
     * Driver starts delivery for order(s).
     * Rule: All items in the order(s) MUST be packer verified before starting delivery.
     * Route: POST /api/orders/driver/start-delivery
     * Route: POST /api/orders/driver/start
     */
    public function startDelivery(Request $request): JsonResponse
    {
        try {
            $rawJson = json_decode($request->getContent(), true) ?? [];

            // Collect order numbers / IDs from input (handles single string, array, order_number, order_numbers, order_id, order_ids)
            $orderNumbersInput = $request->input('order_numbers') ?? ($rawJson['order_numbers'] ?? null);
            $orderNumberInput = $request->input('order_number') ?? ($rawJson['order_number'] ?? null);
            $orderIdsInput = $request->input('order_ids') ?? ($rawJson['order_ids'] ?? null);
            $orderIdInput = $request->input('order_id') ?? ($rawJson['order_id'] ?? null);

            $orderList = [];

            if (is_array($orderNumbersInput)) {
                $orderList = array_merge($orderList, $orderNumbersInput);
            } elseif (is_array($orderNumberInput)) {
                $orderList = array_merge($orderList, $orderNumberInput);
            } elseif (!empty($orderNumberInput)) {
                $orderList[] = $orderNumberInput;
            }

            if (is_array($orderIdsInput)) {
                $orderList = array_merge($orderList, $orderIdsInput);
            } elseif (is_array($orderIdInput)) {
                $orderList = array_merge($orderList, $orderIdInput);
            } elseif (!empty($orderIdInput)) {
                $orderList[] = $orderIdInput;
            }

            $orderList = array_values(array_unique(array_filter($orderList)));

            if (empty($orderList)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'The order_number or order_id parameter is required.',
                ], 422);
            }

            // Validate bag verification for ALL requested orders before starting delivery
            $unverifiedOrders = [];

            foreach ($orderList as $numOrId) {
                $numOrIdStr = trim((string) $numOrId);
                $cleanNum = ltrim($numOrIdStr, '#');

                $order = Order::with('items')
                    ->where('order_number', $numOrIdStr)
                    ->orWhere('order_number', $cleanNum)
                    ->orWhere('order_number', '#' . $cleanNum)
                    ->orWhere('id', $numOrIdStr)
                    ->first();

                if (!$order) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "Order '{$numOrIdStr}' not found.",
                    ], 404);
                }

                // Check if all items in this order are packer verified
                $unverifiedItems = $order->items->filter(function ($item) {
                    return !$item->is_packer_verified;
                });

                if ($unverifiedItems->isNotEmpty()) {
                    $unverifiedOrders[] = [
                        'order_number' => $order->order_number,
                        'order_id' => $order->id,
                        'total_items' => $order->items->count(),
                        'unverified_items_count' => $unverifiedItems->count(),
                        'unverified_items' => $unverifiedItems->map(fn($item) => [
                            'item_id' => $item->id,
                            'product_name' => $item->product_name,
                            'barcode' => $item->barcode,
                            'is_packer_verified' => false,
                        ])->values(),
                    ];
                }
            }

            if (!empty($unverifiedOrders)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cannot start delivery. One or more orders have unverified bags/items. Please verify all bags/items before starting delivery.',
                    'unverified_orders_count' => count($unverifiedOrders),
                    'unverified_orders' => $unverifiedOrders,
                ], 400);
            }

            // All orders are verified! Proceed to update status to 'started' / 'out_for_delivery'
            $updatedAssignments = [];
            $errors = [];

            foreach ($orderList as $numOrId) {
                $subRequest = new Request(['order_number' => (string) $numOrId]);
                $res = $this->processDriverStatusUpdate($subRequest, 'started', 'driver_start_delivery');
                $resData = json_decode($res->getContent(), true);

                if (isset($resData['status']) && $resData['status'] === 'success') {
                    $updatedAssignments[] = $resData['data'];
                } else {
                    $errors[] = $resData['message'] ?? "Failed to start delivery for order {$numOrId}";
                }
            }

            if (empty($updatedAssignments)) {
                return response()->json([
                    'status' => 'error',
                    'message' => $errors[0] ?? 'Failed to start delivery for specified order(s).',
                ], 400);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Delivery started successfully for ' . count($updatedAssignments) . ' order(s).',
                'count' => count($updatedAssignments),
                'data' => count($updatedAssignments) === 1 ? $updatedAssignments[0] : $updatedAssignments,
            ], 200);

        } catch (Exception $e) {
            Log::error('Start Delivery Error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to start delivery: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mark order as delivered.
     * Route: POST /api/orders/driver/delivered
     */
    public function orderDelivered(Request $request): JsonResponse
    {
        return $this->processDriverStatusUpdate($request, 'delivered', 'driver_order_delivered');
    }

    /**
     * Mark Order as Delivered with Collected Amount and Payment Method.
     * Route: POST /api/orders/driver/mark-as-delivered
     * Route: POST /api/orders/driver/mark-delivered
     */
    public function markAsDelivered(Request $request): JsonResponse
    {
        try {
            $rawJson = json_decode($request->getContent(), true) ?? [];

            $orderNumberRaw = $request->input('order_number') ?? ($rawJson['order_number'] ?? '');
            if (is_array($orderNumberRaw)) {
                $orderNumberRaw = $orderNumberRaw[0] ?? '';
            }
            $orderNumber = trim((string) $orderNumberRaw);
            $orderId = $request->input('order_id') ?? ($rawJson['order_id'] ?? null);
            $assignmentId = $request->input('assignment_id') ?? ($rawJson['assignment_id'] ?? null);

            // Payment & Delivery inputs
            $paymentMethod = $request->input('payment_method') 
                ?? ($rawJson['payment_method'] ?? null) 
                ?? $request->input('payment_type') 
                ?? ($rawJson['payment_type'] ?? 'cash');

            $paymentStatus = $request->input('payment_status') 
                ?? ($rawJson['payment_status'] ?? 'paid');

            $amountInput = $request->input('amount') 
                ?? ($rawJson['amount'] ?? null) 
                ?? $request->input('collected_amount') 
                ?? ($rawJson['collected_amount'] ?? null) 
                ?? $request->input('total_amount') 
                ?? ($rawJson['total_amount'] ?? null);

            $notes = $request->input('notes') ?? ($rawJson['notes'] ?? null);

            if (empty($orderNumber) && empty($orderId) && empty($assignmentId)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'The order_number, order_id, or assignment_id parameter is required.',
                ], 422);
            }

            // Find Order
            $order = null;
            if (!empty($orderId)) {
                $order = Order::with('items')->find($orderId);
            } elseif (!empty($orderNumber)) {
                $cleanNum = ltrim($orderNumber, '#');
                $order = Order::with('items')
                    ->where('order_number', $orderNumber)
                    ->orWhere('order_number', $cleanNum)
                    ->orWhere('order_number', '#' . $cleanNum)
                    ->orWhere('id', $orderNumber)
                    ->first();
            }

            // Find Driver Assignment
            $assignment = null;
            if (!empty($assignmentId)) {
                $assignment = OrderDriverAssigned::find($assignmentId);
            } elseif ($order) {
                $assignment = OrderDriverAssigned::where('order_id', $order->id)->first();
            } elseif (!empty($orderNumber)) {
                $cleanNum = ltrim($orderNumber, '#');
                $assignment = OrderDriverAssigned::where('order_number', $orderNumber)
                    ->orWhere('order_number', $cleanNum)
                    ->orWhere('order_number', '#' . $cleanNum)
                    ->first();
            }

            if (!$order && $assignment) {
                $order = Order::with('items')->find($assignment->order_id);
            }

            if (!$order) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Order record not found.',
                ], 404);
            }

            $collectedAmount = !is_null($amountInput) ? (float) $amountInput : (float) $order->total_amount;

            // 1. Update Driver Assignment Record
            if ($assignment) {
                $oldDriverStatus = $assignment->driver_status;
                $assignment->driver_status = 'delivered';
                $assignment->order_status = 'delivered';
                $assignment->delivered_at = now();
                $assignment->payment_method = $paymentMethod;
                $assignment->payment_status = $paymentStatus;
                $assignment->collected_amount = $collectedAmount;
                $assignment->save();
            }

            // 2. Update Main Order Record
            $oldOrderStatus = $order->status;
            $user = Auth::user();
            $driverUserId = $user ? $user->id : ($assignment ? $assignment->assigned_driver_user_id : null);
            $driverName = $user ? $user->name : ($assignment ? $assignment->driver_name : 'Driver User');

            $order->update([
                'status' => 'delivered',
                'delivered_by' => $driverUserId,
                'delivered_user_name' => $driverName,
                'delivered_at' => now(),
                'payment_method' => $paymentMethod,
                'payment_status' => $paymentStatus,
                'collected_amount' => $collectedAmount,
            ]);

            // 3. Update Order Line Items to Delivered
            foreach ($order->items as $item) {
                $item->update([
                    'status' => 'delivered',
                    'delivered_by' => $driverUserId,
                    'delivered_user_name' => $driverName,
                    'delivered_at' => now(),
                ]);
            }

            // 4. Log Audit Status Change
            OrderStatusLog::create([
                'order_id' => $order->id,
                'user_id' => $driverUserId,
                'user_name' => $driverName,
                'action' => 'driver_mark_as_delivered',
                'old_status' => $oldOrderStatus,
                'new_status' => 'delivered',
                'notes' => "Order marked as delivered by driver {$driverName}. Payment Method: {$paymentMethod}, Amount: {$collectedAmount}" . (!empty($notes) ? ": {$notes}" : ""),
            ]);

            $order->refresh();
            $bagCount = (int) ($order->bag_count ?? 0);
            $itemsCount = $order->items ? $order->items->count() : 0;

            return response()->json([
                'status' => 'success',
                'message' => "Order {$order->order_number} marked as delivered successfully.",
                'data' => [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                    'driver_status' => 'delivered',
                    'bag_count' => $bagCount,
                    'items_count' => $itemsCount,
                    'total_items' => $itemsCount,
                    'payment' => [
                        'total_amount' => (float) $order->total_amount,
                        'collected_amount' => (float) $collectedAmount,
                        'payment_method' => $paymentMethod,
                        'payment_status' => $paymentStatus,
                    ],
                    'delivered_by' => [
                        'id' => $driverUserId ? (int) $driverUserId : null,
                        'name' => $driverName,
                        'delivered_at' => $order->delivered_at ? $order->delivered_at->toIso8601String() : null,
                    ],
                    'order' => $order->load('items'),
                ]
            ], 200);

        } catch (Exception $e) {
            Log::error('Mark As Delivered Error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to mark order as delivered: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Flag a delivery order with issue reason, uploaded file/photo, and comment.
     * Route: POST /api/orders/driver/flag
     * Route: POST /api/orders/driver/flag-delivery
     */
    public function flagDelivery(Request $request): JsonResponse
    {
        try {
            $rawJson = json_decode($request->getContent(), true) ?? [];

            $orderNumberRaw = $request->input('order_number') ?? ($rawJson['order_number'] ?? '');
            if (is_array($orderNumberRaw)) {
                $orderNumberRaw = $orderNumberRaw[0] ?? '';
            }
            $orderNumber = trim((string) $orderNumberRaw);
            $orderId = $request->input('order_id') ?? ($rawJson['order_id'] ?? null);
            $assignmentId = $request->input('assignment_id') ?? ($rawJson['assignment_id'] ?? null);

            $orderItemId = $request->input('order_item_id') ?? ($rawJson['order_item_id'] ?? null) ?? $request->input('item_id') ?? ($rawJson['item_id'] ?? null);
            $lineItemId = $request->input('line_item_id') ?? ($rawJson['line_item_id'] ?? null);

            $reason = strtolower(trim((string) (
                $request->input('reason') 
                ?? ($rawJson['reason'] ?? null) 
                ?? $request->input('issue_type') 
                ?? ($rawJson['issue_type'] ?? null) 
                ?? $request->input('flag_reason') 
                ?? ($rawJson['flag_reason'] ?? 'damaged')
            )));

            $comment = trim((string) (
                $request->input('comment') 
                ?? ($rawJson['comment'] ?? null) 
                ?? $request->input('notes') 
                ?? ($rawJson['notes'] ?? '')
            ));

            if (empty($orderNumber) && empty($orderId) && empty($assignmentId)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'The order_number, order_id, or assignment_id parameter is required.',
                ], 422);
            }

            // Find Order
            $order = null;
            if (!empty($orderId)) {
                $order = Order::with('items')->find($orderId);
            } elseif (!empty($orderNumber)) {
                $cleanNum = ltrim($orderNumber, '#');
                $order = Order::with('items')
                    ->where('order_number', $orderNumber)
                    ->orWhere('order_number', $cleanNum)
                    ->orWhere('order_number', '#' . $cleanNum)
                    ->orWhere('id', $orderNumber)
                    ->first();
            }

            // Find Driver Assignment
            $assignment = null;
            if (!empty($assignmentId)) {
                $assignment = OrderDriverAssigned::find($assignmentId);
            } elseif ($order) {
                $assignment = OrderDriverAssigned::where('order_id', $order->id)->first();
            } elseif (!empty($orderNumber)) {
                $cleanNum = ltrim($orderNumber, '#');
                $assignment = OrderDriverAssigned::where('order_number', $orderNumber)
                    ->orWhere('order_number', $cleanNum)
                    ->orWhere('order_number', '#' . $cleanNum)
                    ->first();
            }

            if (!$order && $assignment) {
                $order = Order::with('items')->find($assignment->order_id);
            }

            if (!$order) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Order record not found.',
                ], 404);
            }

            // Target order item if specified, or first item
            $targetItem = null;
            if ($orderItemId) {
                $targetItem = $order->items->where('id', $orderItemId)->first();
            } elseif ($lineItemId) {
                $targetItem = $order->items->where('line_item_id', $lineItemId)->first();
            }

            if (!$targetItem && $order->items->isNotEmpty()) {
                $targetItem = $order->items->first();
            }

            // File / Photo Upload Handling
            $photoUrl = $request->input('photo_url') ?? ($rawJson['photo_url'] ?? null);

            $fileKey = null;
            if ($request->hasFile('file')) {
                $fileKey = 'file';
            } elseif ($request->hasFile('photo')) {
                $fileKey = 'photo';
            } elseif ($request->hasFile('image')) {
                $fileKey = 'image';
            } elseif ($request->hasFile('attachment')) {
                $fileKey = 'attachment';
            }

            if ($fileKey && $request->file($fileKey)->isValid()) {
                $uploadedFile = $request->file($fileKey);
                $uploadDir = public_path('uploads/delivery_flags');
                if (!file_exists($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                $extension = $uploadedFile->getClientOriginalExtension() ?: 'jpg';
                $filename = 'flag_' . $order->order_number . '_' . time() . '_' . substr(md5(uniqid()), 0, 6) . '.' . $extension;
                $uploadedFile->move($uploadDir, $filename);
                $photoUrl = url('uploads/delivery_flags/' . $filename);
            }

            // Determine user
            $user = Auth::user();
            $driverUserId = $user ? $user->id : ($assignment ? $assignment->assigned_driver_user_id : $request->input('user_id'));
            $driverName = $user ? $user->name : ($assignment ? $assignment->driver_name : ($request->input('user_name') ?? 'Driver User'));

            // 1. Create OrderItemDiscrepancy record
            $discrepancy = OrderItemDiscrepancy::create([
                'order_id' => $order->id,
                'order_item_id' => $targetItem ? $targetItem->id : ($order->items->first()?->id ?? $order->id),
                'user_id' => $driverUserId,
                'user_name' => $driverName,
                'issue_type' => $reason,
                'comment' => $comment,
                'status' => 'open',
                'photo_url' => $photoUrl,
            ]);

            // 2. Mark item as flagged
            if ($targetItem) {
                $targetItem->update([
                    'is_flagged' => true,
                    'flag_reason' => $reason,
                ]);
            } else {
                foreach ($order->items as $item) {
                    $item->update([
                        'is_flagged' => true,
                        'flag_reason' => $reason,
                    ]);
                }
            }

            // 3. Update Driver Assignment driver_status to 'flagged'
            if ($assignment) {
                $assignment->update([
                    'driver_status' => 'flagged',
                    'order_status' => 'flagged',
                ]);
            }

            // 4. Update Order status
            $oldOrderStatus = $order->status;
            $order->update([
                'status' => 'flagged',
            ]);

            // 5. Log audit trail
            OrderStatusLog::create([
                'order_id' => $order->id,
                'order_item_id' => $targetItem ? $targetItem->id : null,
                'user_id' => $driverUserId,
                'user_name' => $driverName,
                'action' => 'driver_flagged_order',
                'old_status' => $oldOrderStatus,
                'new_status' => 'flagged',
                'notes' => "Order flagged by driver {$driverName}. Reason: {$reason}. Comment: {$comment}",
            ]);

            return response()->json([
                'status' => 'success',
                'message' => "Order {$order->order_number} flagged successfully.",
                'data' => [
                    'discrepancy_id' => $discrepancy->id,
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'order_item_id' => $targetItem ? $targetItem->id : null,
                    'product_name' => $targetItem ? $targetItem->product_name : null,
                    'reason' => $reason,
                    'comment' => $comment,
                    'photo_url' => $photoUrl,
                    'status' => 'flagged',
                    'reported_by' => [
                        'id' => $driverUserId ? (int) $driverUserId : null,
                        'name' => $driverName,
                    ],
                    'created_at' => $discrepancy->created_at ? $discrepancy->created_at->toIso8601String() : null,
                    'discrepancy' => $discrepancy,
                ]
            ], 200);

        } catch (Exception $e) {
            Log::error('Flag Delivery Error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to flag delivery order: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get list of delivery order discrepancies / flags.
     * Route: GET /api/orders/driver/discrepancies/{driver_user_id?}
     * Route: GET /api/orders/driver/flagged-orders/{driver_user_id?}
     */
    public function getDeliveryDiscrepancies(Request $request, $driver_user_id = null): JsonResponse
    {
        try {
            $driverId = $driver_user_id 
                ?? $request->input('driver_user_id') 
                ?? $request->query('driver_user_id');

            $query = OrderItemDiscrepancy::with(['order.items', 'orderItem', 'user']);

            if (!empty($driverId)) {
                $query->where('user_id', $driverId);
            }

            $discrepancies = $query->orderBy('created_at', 'desc')->get();

            $formatted = $discrepancies->map(function ($disc) {
                $order = $disc->order;
                return [
                    'id' => $disc->id,
                    'order_id' => $disc->order_id,
                    'order_number' => $order ? $order->order_number : null,
                    'order_item_id' => $disc->order_item_id,
                    'product_name' => $disc->orderItem ? $disc->orderItem->product_name : null,
                    'reason' => $disc->issue_type,
                    'comment' => $disc->comment,
                    'photo_url' => $disc->photo_url,
                    'status' => $disc->status,
                    'reported_by' => [
                        'id' => $disc->user_id ? (int) $disc->user_id : null,
                        'name' => $disc->user_name ?? ($disc->user ? $disc->user->name : 'Driver User'),
                    ],
                    'created_at' => $disc->created_at ? $disc->created_at->toIso8601String() : null,
                    'order' => $order ? [
                        'id' => $order->id,
                        'order_number' => $order->order_number,
                        'customer_name' => $order->customer_name ?? 'N/A',
                        'customer_phone' => $order->customer_phone ?? 'N/A',
                        'delivery_address' => $order->delivery_address ?? 'N/A',
                        'status' => $order->status,
                        'bag_count' => (int) ($order->bag_count ?? 0),
                    ] : null,
                ];
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Delivery discrepancies retrieved successfully.',
                'count' => $formatted->count(),
                'data' => $formatted
            ], 200);

        } catch (Exception $e) {
            Log::error('Get Delivery Discrepancies Error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve delivery discrepancies: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get list of flagged delivery orders for a driver user.
     * Route: GET /api/orders/driver/flagged/{driver_user_id?}
     * Route: GET /api/orders/driver/flagged-orders/{driver_user_id?}
     */
    public function getFlaggedOrders(Request $request, $driver_user_id = null): JsonResponse
    {
        try {
            $driverId = $driver_user_id 
                ?? $request->input('driver_user_id') 
                ?? $request->input('assigned_driver_user_id') 
                ?? $request->query('driver_user_id') 
                ?? $request->query('assigned_driver_user_id');

            $query = OrderDriverAssigned::with(['order.items', 'driver'])
                ->where(function ($q) {
                    $q->where('driver_status', 'flagged')
                      ->orWhere('order_status', 'flagged')
                      ->orWhereHas('order', function ($oq) {
                          $oq->where('status', 'flagged')
                            ->orWhereHas('items', function ($iq) {
                                $iq->where('is_flagged', true);
                            });
                      });
                });

            if (!empty($driverId)) {
                $driver = User::find($driverId);
                if (!$driver) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "Driver user ID '{$driverId}' not found."
                    ], 404);
                }
                $query->where('assigned_driver_user_id', $driverId);
            }

            $assignments = $query->orderBy('updated_at', 'desc')->get();

            $formattedAssignments = $assignments->map(function ($assignment) {
                $order = $assignment->order;
                $bagCount = $order ? (int) ($order->bag_count ?? 0) : 0;
                $itemsCount = $order && $order->items ? $order->items->count() : 0;

                // Load discrepancy report if available
                $discrepancy = $order ? OrderItemDiscrepancy::where('order_id', $order->id)->latest()->first() : null;

                return [
                    'id' => $assignment->id,
                    'order_id' => $assignment->order_id,
                    'order_number' => $assignment->order_number,
                    'assigned_driver_user_id' => $assignment->assigned_driver_user_id ? (int) $assignment->assigned_driver_user_id : null,
                    'driver_name' => $assignment->driver_name,
                    'zone' => $assignment->zone,
                    'order_status' => $assignment->order_status,
                    'driver_status' => $assignment->driver_status,
                    'bag_count' => $bagCount,
                    'items_count' => $itemsCount,
                    'total_items' => $itemsCount,
                    'flag' => $discrepancy ? [
                        'discrepancy_id' => $discrepancy->id,
                        'reason' => $discrepancy->issue_type,
                        'comment' => $discrepancy->comment,
                        'photo_url' => $discrepancy->photo_url,
                        'status' => $discrepancy->status,
                        'flagged_at' => $discrepancy->created_at ? $discrepancy->created_at->toIso8601String() : null,
                    ] : null,
                    'created_at' => $assignment->created_at ? $assignment->created_at->toIso8601String() : null,
                    'updated_at' => $assignment->updated_at ? $assignment->updated_at->toIso8601String() : null,
                    'order' => $order ? [
                        'id' => $order->id,
                        'order_number' => $order->order_number,
                        'customer_name' => $order->customer_name ?? 'N/A',
                        'customer_phone' => $order->customer_phone ?? 'N/A',
                        'delivery_address' => $order->delivery_address ?? 'N/A',
                        'total_amount' => (float) $order->total_amount,
                        'status' => $order->status,
                        'bag_count' => $bagCount,
                        'items_count' => $itemsCount,
                        'total_items' => $itemsCount,
                        'created_at' => $order->created_at ? $order->created_at->toIso8601String() : null,
                        'items' => $order->items ? $order->items->map(function ($item) {
                            return [
                                'item_id' => $item->id,
                                'line_item_id' => $item->line_item_id,
                                'product_id' => $item->product_id,
                                'product_code' => $item->product_code,
                                'barcode' => $item->barcode,
                                'product_name' => $item->product_name,
                                'quantity' => (int) $item->quantity,
                                'unit_price' => (float) $item->unit_price,
                                'status' => $item->status,
                                'is_flagged' => (bool) $item->is_flagged,
                                'flag_reason' => $item->flag_reason,
                            ];
                        })->values() : [],
                    ] : null,
                    'driver' => $assignment->driver,
                ];
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Flagged delivery orders retrieved successfully.',
                'driver_user_id' => $driverId ? (int) $driverId : null,
                'count' => $formattedAssignments->count(),
                'data' => $formattedAssignments
            ], 200);

        } catch (Exception $e) {
            Log::error('Get Flagged Orders Error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve flagged orders: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mark order as cancelled by driver.
     * Route: POST /api/orders/driver/cancelled
     */
    public function orderCancelled(Request $request): JsonResponse
    {
        return $this->processDriverStatusUpdate($request, 'cancelled', 'driver_order_cancelled');
    }

    /**
     * Mark order for refund by driver.
     * Route: POST /api/orders/driver/refund
     */
    public function orderRefund(Request $request): JsonResponse
    {
        return $this->processDriverStatusUpdate($request, 'refund', 'driver_order_refund');
    }

    /**
     * Mark order for exchange by driver.
     * Route: POST /api/orders/driver/exchange
     */
    public function orderExchange(Request $request): JsonResponse
    {
        return $this->processDriverStatusUpdate($request, 'exchange', 'driver_order_exchange');
    }

    /**
     * Get assigned orders for driver (Flutter App API using driver user id).
     * Route: GET /api/orders/driver/assigned/{driver_user_id}
     */
    public function getAssignedOrders(Request $request, $driver_user_id): JsonResponse
    {
        try {
            $driver = User::find($driver_user_id);
            if (!$driver) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Driver user not found.'
                ], 404);
            }

            $query = OrderDriverAssigned::where('assigned_driver_user_id', $driver_user_id)
                ->with(['order.items']);

            if ($request->has('driver_status')) {
                $query->where('driver_status', $request->query('driver_status'));
            }

            $assignments = $query->orderBy('updated_at', 'desc')->get();

            $formattedAssignments = $assignments->map(function ($assignment) {
                $order = $assignment->order;
                $bagCount = $order ? (int) ($order->bag_count ?? 0) : 0;
                $itemsCount = $order && $order->items ? $order->items->count() : 0;

                return [
                    'id' => $assignment->id,
                    'order_id' => $assignment->order_id,
                    'order_number' => $assignment->order_number,
                    'assigned_driver_user_id' => $assignment->assigned_driver_user_id ? (int) $assignment->assigned_driver_user_id : null,
                    'driver_name' => $assignment->driver_name,
                    'zone' => $assignment->zone,
                    'order_status' => $assignment->order_status,
                    'driver_status' => $assignment->driver_status,
                    'bag_count' => $bagCount,
                    'items_count' => $itemsCount,
                    'total_items' => $itemsCount,
                    'assigned_at' => $assignment->assigned_at ? $assignment->assigned_at->toIso8601String() : null,
                    'accepted_at' => $assignment->accepted_at ? $assignment->accepted_at->toIso8601String() : null,
                    'started_at' => $assignment->started_at ? $assignment->started_at->toIso8601String() : null,
                    'delivered_at' => $assignment->delivered_at ? $assignment->delivered_at->toIso8601String() : null,
                    'cancelled_at' => $assignment->cancelled_at ? $assignment->cancelled_at->toIso8601String() : null,
                    'refund_at' => $assignment->refund_at ? $assignment->refund_at->toIso8601String() : null,
                    'exchange_at' => $assignment->exchange_at ? $assignment->exchange_at->toIso8601String() : null,
                    'created_at' => $assignment->created_at ? $assignment->created_at->toIso8601String() : null,
                    'updated_at' => $assignment->updated_at ? $assignment->updated_at->toIso8601String() : null,
                    'order' => $order ? [
                        'id' => $order->id,
                        'order_number' => $order->order_number,
                        'customer_name' => $order->customer_name ?? 'N/A',
                        'customer_phone' => $order->customer_phone ?? 'N/A',
                        'delivery_address' => $order->delivery_address ?? 'N/A',
                        'total_amount' => (float) $order->total_amount,
                        'status' => $order->status,
                        'bag_count' => $bagCount,
                        'items_count' => $itemsCount,
                        'total_items' => $itemsCount,
                        'created_at' => $order->created_at ? $order->created_at->toIso8601String() : null,
                        'items' => $order->items ? $order->items->map(function ($item) {
                            return [
                                'item_id' => $item->id,
                                'line_item_id' => $item->line_item_id,
                                'product_id' => $item->product_id,
                                'product_code' => $item->product_code,
                                'barcode' => $item->barcode,
                                'product_name' => $item->product_name,
                                'quantity' => (int) $item->quantity,
                                'unit_price' => (float) $item->unit_price,
                                'status' => $item->status,
                            ];
                        })->values() : [],
                    ] : null,
                ];
            });

            return response()->json([
                'status' => 'success',
                'driver_user_id' => (int) $driver_user_id,
                'driver_name' => $driver->name,
                'count' => $formattedAssignments->count(),
                'data' => $formattedAssignments
            ], 200);

        } catch (Exception $e) {
            Log::error('Get Assigned Orders Error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve assigned orders: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get started delivery orders for a particular driver user.
     * Route: GET /api/orders/driver/started/{driver_user_id?}
     * Route: GET /api/orders/driver/started-orders/{driver_user_id?}
     */
    public function getStartedOrders(Request $request, $driver_user_id = null): JsonResponse
    {
        try {
            $driverId = $driver_user_id 
                ?? $request->input('driver_user_id') 
                ?? $request->input('assigned_driver_user_id') 
                ?? $request->query('driver_user_id') 
                ?? $request->query('assigned_driver_user_id');

            $query = OrderDriverAssigned::with(['order.items', 'driver'])
                ->where('driver_status', 'started');

            if (!empty($driverId)) {
                $driver = User::find($driverId);
                if (!$driver) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "Driver user ID '{$driverId}' not found."
                    ], 404);
                }
                $query->where('assigned_driver_user_id', $driverId);
            }

            $assignments = $query->orderBy('started_at', 'desc')->orderBy('updated_at', 'desc')->get();

            $formattedAssignments = $assignments->map(function ($assignment) {
                $order = $assignment->order;
                $bagCount = $order ? (int) ($order->bag_count ?? 0) : 0;
                $itemsCount = $order && $order->items ? $order->items->count() : 0;

                return [
                    'id' => $assignment->id,
                    'order_id' => $assignment->order_id,
                    'order_number' => $assignment->order_number,
                    'assigned_driver_user_id' => $assignment->assigned_driver_user_id ? (int) $assignment->assigned_driver_user_id : null,
                    'driver_name' => $assignment->driver_name,
                    'zone' => $assignment->zone,
                    'order_status' => $assignment->order_status,
                    'driver_status' => $assignment->driver_status,
                    'bag_count' => $bagCount,
                    'items_count' => $itemsCount,
                    'total_items' => $itemsCount,
                    'assigned_at' => $assignment->assigned_at ? $assignment->assigned_at->toIso8601String() : null,
                    'accepted_at' => $assignment->accepted_at ? $assignment->accepted_at->toIso8601String() : null,
                    'started_at' => $assignment->started_at ? $assignment->started_at->toIso8601String() : null,
                    'delivered_at' => $assignment->delivered_at ? $assignment->delivered_at->toIso8601String() : null,
                    'created_at' => $assignment->created_at ? $assignment->created_at->toIso8601String() : null,
                    'updated_at' => $assignment->updated_at ? $assignment->updated_at->toIso8601String() : null,
                    'order' => $order ? [
                        'id' => $order->id,
                        'order_number' => $order->order_number,
                        'customer_name' => $order->customer_name ?? 'N/A',
                        'customer_phone' => $order->customer_phone ?? 'N/A',
                        'delivery_address' => $order->delivery_address ?? 'N/A',
                        'total_amount' => (float) $order->total_amount,
                        'status' => $order->status,
                        'bag_count' => $bagCount,
                        'items_count' => $itemsCount,
                        'total_items' => $itemsCount,
                        'created_at' => $order->created_at ? $order->created_at->toIso8601String() : null,
                        'items' => $order->items ? $order->items->map(function ($item) {
                            return [
                                'item_id' => $item->id,
                                'line_item_id' => $item->line_item_id,
                                'product_id' => $item->product_id,
                                'product_code' => $item->product_code,
                                'barcode' => $item->barcode,
                                'product_name' => $item->product_name,
                                'quantity' => (int) $item->quantity,
                                'unit_price' => (float) $item->unit_price,
                                'status' => $item->status,
                                'is_packer_verified' => (bool) $item->is_packer_verified,
                            ];
                        })->values() : [],
                    ] : null,
                    'driver' => $assignment->driver,
                ];
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Started delivery orders retrieved successfully.',
                'driver_user_id' => $driverId ? (int) $driverId : null,
                'count' => $formattedAssignments->count(),
                'data' => $formattedAssignments
            ], 200);

        } catch (Exception $e) {
            Log::error('Get Started Delivery Orders Error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve started delivery orders: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Helper to process status updates consistently.
     */
    protected function processDriverStatusUpdate(Request $request, string $targetDriverStatus, string $actionName): JsonResponse
    {
        try {
            $rawJson = json_decode($request->getContent(), true) ?? [];

            $orderNumberRaw = $request->input('order_number') ?? ($rawJson['order_number'] ?? '');
            if (is_array($orderNumberRaw)) {
                $orderNumberRaw = $orderNumberRaw[0] ?? '';
            }
            $orderNumber = trim((string) $orderNumberRaw);
            $orderId = $request->input('order_id') ?? ($rawJson['order_id'] ?? null);
            $assignmentId = $request->input('assignment_id') ?? ($rawJson['assignment_id'] ?? null);
            $statusInput = $request->input('status') ?? ($rawJson['status'] ?? null) ?? $request->input('driver_status') ?? ($rawJson['driver_status'] ?? null);
            $notes = $request->input('notes') ?? ($rawJson['notes'] ?? null) ?? $statusInput;

            $assignment = null;

            if (!empty($assignmentId)) {
                $assignment = OrderDriverAssigned::find($assignmentId);
            } elseif (!empty($orderId)) {
                $assignment = OrderDriverAssigned::where('order_id', $orderId)->first();
            } elseif (!empty($orderNumber)) {
                $cleanOrderNum = ltrim($orderNumber, '#');
                $assignment = OrderDriverAssigned::where('order_number', $orderNumber)
                    ->orWhere('order_number', $cleanOrderNum)
                    ->orWhere('order_number', '#' . $cleanOrderNum)
                    ->first();
            }

            if (!$assignment) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Driver assignment record not found for the specified order.'
                ], 404);
            }

            $oldDriverStatus = $assignment->driver_status;
            $assignment->driver_status = $targetDriverStatus;

            // Set corresponding timestamp
            $timestampColumn = "{$targetDriverStatus}_at";
            if (
                in_array($timestampColumn, [
                    'assigned_at',
                    'accepted_at',
                    'started_at',
                    'delivered_at',
                    'cancelled_at',
                    'refund_at',
                    'exchange_at'
                ])
            ) {
                $assignment->{$timestampColumn} = now();
            }

            // Sync main order status & delivery fields
            $order = Order::find($assignment->order_id);
            $oldOrderStatus = $order ? $order->status : null;
            if ($order) {
                if ($targetDriverStatus === 'delivered') {
                    $order->status = 'delivered';
                    $order->delivered_by = $assignment->assigned_driver_user_id;
                    $order->delivered_user_name = $assignment->driver_name;
                    $order->delivered_at = now();
                } elseif ($targetDriverStatus === 'cancelled') {
                    $order->status = 'cancelled';
                } elseif ($targetDriverStatus === 'refund') {
                    $order->status = 'refund';
                } elseif ($targetDriverStatus === 'exchange') {
                    $order->status = 'exchange';
                } elseif ($targetDriverStatus === 'started' || $targetDriverStatus === 'accepted') {
                    $order->status = 'out_for_delivery';
                } elseif ($targetDriverStatus === 'assigned') {
                    $order->status = 'assigned_to_driver';
                }

                $order->save();
                $assignment->order_status = $order->status;
            }

            $assignment->save();

            // Log status change audit
            OrderStatusLog::create([
                'order_id' => $assignment->order_id,
                'user_id' => auth()->id() ?? $assignment->assigned_driver_user_id,
                'user_name' => auth()->user()?->name ?? ($assignment->driver_name ?? 'Driver'),
                'action' => $actionName,
                'old_status' => $oldDriverStatus,
                'new_status' => $targetDriverStatus,
                'notes' => "Driver status updated to '{$targetDriverStatus}'" . (!empty($notes) ? ": {$notes}" : ""),
            ]);

            $loadedAssignment = $assignment->load(['order.items', 'driver']);
            $orderObj = $loadedAssignment->order;
            $bagCount = $orderObj ? (int) ($orderObj->bag_count ?? 0) : 0;
            $itemsCount = $orderObj && $orderObj->items ? $orderObj->items->count() : 0;

            return response()->json([
                'status' => 'success',
                'message' => "Driver status updated to '{$targetDriverStatus}' successfully.",
                'data' => array_merge($loadedAssignment->toArray(), [
                    'bag_count' => $bagCount,
                    'items_count' => $itemsCount,
                    'total_items' => $itemsCount,
                ])
            ], 200);

        } catch (Exception $e) {
            Log::error("Process Driver Status Update Error ({$targetDriverStatus}): " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update driver status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Helper to find order by ID or order_number.
     */
    protected function findOrder(array $validated): ?Order
    {
        $orderQuery = Order::query();
        if (!empty($validated['order_id'])) {
            $orderQuery->where('id', $validated['order_id']);
        } else {
            $orderQuery->where('order_number', $validated['order_number']);
        }
        return $orderQuery->first();
    }

    /**
     * Verified Bags API
     * Returns verification status of order bags and items by order_number (or order_id).
     * If all items are verified, returns is_verified: true where verified_items_count == total_items.
     * Route: POST /api/orders/packer/verify-bags
     * Route: POST /api/orders/verify-bags
     * Route: GET /api/orders/verified-bags/{order_number?}
     */
    public function verifyBags(Request $request, $order_number = null)
    {
        $rawJson = json_decode($request->getContent(), true) ?? [];

        $orderNumStr = trim((string) (
            $request->input('order_number')
            ?? ($rawJson['order_number'] ?? null)
            ?? $order_number
            ?? $request->input('order_id')
            ?? ($rawJson['order_id'] ?? null)
            ?? ''
        ));

        if (empty($orderNumStr)) {
            return response()->json([
                'success' => false,
                'message' => 'The order_number parameter is required.',
            ], 422);
        }

        $cleanOrderNum = ltrim($orderNumStr, '#');

        $order = Order::with(['items.packerVerifiedUser', 'packerVerifications'])
            ->where('order_number', $orderNumStr)
            ->orWhere('order_number', $cleanOrderNum)
            ->orWhere('order_number', '#' . $cleanOrderNum)
            ->orWhere('id', $orderNumStr)
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => "Order number '{$orderNumStr}' not found.",
            ], 404);
        }

        $bagCount = (int) (
            $request->input('bag_count')
            ?? ($rawJson['bag_count'] ?? null)
            ?? $request->input('verified_bag_count')
            ?? ($rawJson['verified_bag_count'] ?? null)
            ?? $order->bag_count
            ?? 0
        );

        // On POST request, verify all items in the order and update bag_count
        if ($request->isMethod('post')) {
            $user = Auth::user();
            $userId = $user ? $user->id : ($request->input('user_id') ?? ($rawJson['user_id'] ?? null));
            $dbUser = $userId ? User::find($userId) : null;
            $userName = $user ? $user->name : ($dbUser ? $dbUser->name : 'Verifier User');

            foreach ($order->items as $item) {
                $item->update([
                    'is_packer_verified' => true,
                    'packer_verified_by' => $dbUser ? $dbUser->id : $item->packer_verified_by,
                    'packer_verified_user_name' => $item->packer_verified_user_name ?? $userName,
                    'packer_verified_at' => $item->packer_verified_at ?? now(),
                ]);
            }

            if ($bagCount > 0) {
                $order->update(['bag_count' => $bagCount]);
            }

            $order->refresh();
        }

        $totalItems = $order->items->count();
        $verifiedItemsCount = $order->items->where('is_packer_verified', true)->count();
        $remainingCount = $totalItems - $verifiedItemsCount;
        $isAllVerified = ($totalItems > 0) && ($verifiedItemsCount === $totalItems);

        return response()->json([
            'success' => true,
            'message' => $isAllVerified 
                ? "All {$totalItems} item(s) in Order {$order->order_number} are verified. Bag verification complete."
                : "{$verifiedItemsCount} of {$totalItems} item(s) verified for Order {$order->order_number}.",
            'data' => [
                'order_number' => $order->order_number,
                'order_id' => $order->id,
                'status' => $order->status,
                'bag_count' => $bagCount,
                'verified_bag_count' => $bagCount,
                'items_count' => $totalItems,
                'total_items' => $totalItems,
                'verified_items_count' => $verifiedItemsCount,
                'remaining_unverified_items' => $remainingCount,
                'is_verified' => $isAllVerified,
                'is_all_items_verified' => $isAllVerified,
                'items' => $order->items->map(function ($item) {
                    return [
                        'item_id' => $item->id,
                        'line_item_id' => $item->line_item_id,
                        'product_name' => $item->product_name,
                        'barcode' => $item->barcode,
                        'status' => $item->status,
                        'is_packer_verified' => (bool) $item->is_packer_verified,
                        'packer_verified_at' => $item->packer_verified_at ? $item->packer_verified_at->toIso8601String() : null,
                        'packer_verified_user' => $item->packer_verified_by ? [
                            'id' => (int) $item->packer_verified_by,
                            'name' => $item->packer_verified_user_name ?? 'Packer User',
                        ] : null,
                    ];
                })->values(),
            ],
        ]);
    }
}
