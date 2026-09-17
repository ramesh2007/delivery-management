<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemDiscrepancy;
use App\Models\OrderDriverAssigned;
use App\Models\OrderStatusLog;
use Illuminate\Support\Facades\Auth;

class PaymentsManagementController extends Controller
{
        /**
     * Update payment status and payment method for an order number.
     * Route: POST /api/orders/update-payment-status
     * Route: POST /api/orders/payment-status
     * Route: POST /api/resource/sales-order/update-payment-status
     * Route: POST /api/resource/Sales Order/update-payment-status
     */
    public function updatePaymentStatus(Request $request)
    {
        $rawJson = json_decode($request->getContent(), true) ?? [];

        $orderNumberInput = $request->input('order_number')
            ?? ($rawJson['order_number'] ?? null)
            ?? $request->input('ordernumber')
            ?? ($rawJson['ordernumber'] ?? null)
            ?? $request->input('order_id')
            ?? ($rawJson['order_id'] ?? null)
            ?? $request->input('order')
            ?? ($rawJson['order'] ?? null);

        $paymentMethod = $request->input('payment_method')
            ?? ($rawJson['payment_method'] ?? null)
            ?? $request->input('payment_type')
            ?? ($rawJson['payment_type'] ?? null)
            ?? 'cash';

        $paymentStatus = $request->input('payment_status')
            ?? ($rawJson['payment_status'] ?? null)
            ?? $request->input('status')
            ?? ($rawJson['status'] ?? null)
            ?? 'paid';

        $amountInput = $request->input('collected_amount')
            ?? ($rawJson['collected_amount'] ?? null)
            ?? $request->input('amount')
            ?? ($rawJson['amount'] ?? null)
            ?? $request->input('total_amount')
            ?? ($rawJson['total_amount'] ?? null);

        if (empty($orderNumberInput)) {
            return response()->json([
                'status' => 'error',
                'success' => false,
                'message' => 'The order_number parameter is required.',
            ], 422);
        }

        $numOrIdStr = trim((string) $orderNumberInput);
        $cleanNum = ltrim($numOrIdStr, '#');

        $order = Order::where('order_number', $numOrIdStr)
            ->orWhere('order_number', $cleanNum)
            ->orWhere('order_number', '#' . $cleanNum)
            ->orWhere('order_number', 'SO-' . $cleanNum)
            ->orWhere('id', $numOrIdStr)
            ->first();

        if (!$order) {
            $order = Order::create([
                'order_number' => $numOrIdStr,
                'customer_name' => 'Guest Customer',
                'total_amount' => !is_null($amountInput) ? (float) $amountInput : 0.00,
                'status' => 'Pending',
                'payment_method' => $paymentMethod,
                'payment_status' => $paymentStatus,
                'collected_amount' => !is_null($amountInput) ? (float) $amountInput : 0.00,
            ]);
        } else {
            $collectedAmount = !is_null($amountInput) ? (float) $amountInput : (float) ($order->collected_amount ?: $order->total_amount);
            $order->update([
                'payment_method' => $paymentMethod,
                'payment_status' => $paymentStatus,
                'collected_amount' => $collectedAmount,
            ]);
        }

        // Also update OrderDriverAssigned if assignment exists
        $assignment = OrderDriverAssigned::where('order_id', $order->id)
            ->orWhere('order_number', $order->order_number)
            ->first();

        if ($assignment) {
            $assignment->update([
                'payment_method' => $paymentMethod,
                'payment_status' => $paymentStatus,
                'collected_amount' => $order->collected_amount,
            ]);
        }

        // Log audit history
        $user = Auth::user();
        $userId = $user ? $user->id : ($request->input('user_id') ?? ($rawJson['user_id'] ?? null));
        $dbUser = $userId ? \App\Models\User::find($userId) : null;
        $userName = $user ? $user->name : ($dbUser ? $dbUser->name : ($request->input('user_name') ?? 'System User'));

        OrderStatusLog::create([
            'order_id' => $order->id,
            'user_id' => $user ? $user->id : ($dbUser ? $dbUser->id : null),
            'user_name' => $userName,
            'action' => 'payment_status_updated',
            'old_status' => $order->status,
            'new_status' => $order->status,
            'notes' => "Payment status updated to '{$paymentStatus}' (Method: {$paymentMethod}, Amount: {$order->collected_amount})",
        ]);

        return response()->json([
            'status' => 'success',
            'success' => true,
            'message' => "Payment status updated successfully for order '{$order->order_number}'.",
            'data' => [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'payment_method' => $order->payment_method,
                'payment_status' => $order->payment_status,
                'collected_amount' => (float) $order->collected_amount,
                'total_amount' => (float) $order->total_amount,
                'order_status' => $order->status,
                'order' => $order->fresh(['items']),
            ],
        ], 200);
    }
        /**
     * Create Return or Replacement request for order items.
     * Route: POST /api/orders/return-replacement
     * Route: POST /api/orders/create-return-replacement
     */
    public function createReturnReplacement(Request $request)
    {
        $rawJson = json_decode($request->getContent(), true) ?? [];

        $orderNumberInput = $request->input('order_number')
            ?? ($rawJson['order_number'] ?? null)
            ?? $request->input('ordernumber')
            ?? ($rawJson['ordernumber'] ?? null)
            ?? $request->input('order_id')
            ?? ($rawJson['order_id'] ?? null);

        if (empty($orderNumberInput)) {
            return response()->json([
                'status' => 'error',
                'success' => false,
                'message' => 'The order_number parameter is required.',
            ], 422);
        }

        $actionTypeInput = $request->input('action_type')
            ?? ($rawJson['action_type'] ?? null)
            ?? $request->input('type')
            ?? ($rawJson['type'] ?? null)
            ?? $request->input('action')
            ?? ($rawJson['action'] ?? null)
            ?? 'refund';

        $actionType = strtolower(trim((string) $actionTypeInput));
        if (in_array($actionType, ['return', 'refund'])) {
            $actionType = 'refund';
        } elseif (in_array($actionType, ['replace', 'replacement', 'exchange'])) {
            $actionType = 'replace';
        } else {
            $actionType = 'refund';
        }

        $reason = $request->input('reason')
            ?? ($rawJson['reason'] ?? null)
            ?? 'Other';

        $notes = $request->input('notes')
            ?? ($rawJson['notes'] ?? null)
            ?? $request->input('comment')
            ?? ($rawJson['comment'] ?? null);

        // Raw items input: item_id, item_ids, order_item_id, order_item_ids, items
        $itemInput = $request->input('item_ids')
            ?? ($rawJson['item_ids'] ?? null)
            ?? $request->input('item_id')
            ?? ($rawJson['item_id'] ?? null)
            ?? $request->input('order_item_ids')
            ?? ($rawJson['order_item_ids'] ?? null)
            ?? $request->input('order_item_id')
            ?? ($rawJson['order_item_id'] ?? null)
            ?? $request->input('items')
            ?? ($rawJson['items'] ?? null);

        $itemIds = [];
        if (is_array($itemInput)) {
            foreach ($itemInput as $val) {
                if (is_array($val) && isset($val['item_id'])) {
                    $itemIds[] = $val['item_id'];
                } elseif (is_scalar($val)) {
                    $itemIds[] = $val;
                }
            }
        } elseif (!is_null($itemInput)) {
            $itemIds[] = $itemInput;
        }

        // Find Order
        $numOrIdStr = trim((string) $orderNumberInput);
        $cleanNum = ltrim($numOrIdStr, '#');

        $order = Order::with('items')->where('order_number', $numOrIdStr)
            ->orWhere('order_number', $cleanNum)
            ->orWhere('order_number', '#' . $cleanNum)
            ->orWhere('order_number', 'SO-' . $cleanNum)
            ->orWhere('id', $numOrIdStr)
            ->first();

        if (!$order) {
            return response()->json([
                'status' => 'error',
                'success' => false,
                'message' => "Order '{$numOrIdStr}' not found.",
            ], 404);
        }

        // Get matching items
        $orderItems = collect();
        if (!empty($itemIds)) {
            $orderItems = OrderItem::where('order_id', $order->id)
                ->where(function ($q) use ($itemIds) {
                    $q->whereIn('id', $itemIds)
                      ->orWhereIn('line_item_id', $itemIds)
                      ->orWhereIn('product_code', $itemIds);
                })->get();
        }

        if ($orderItems->isEmpty()) {
            $orderItems = $order->items;
        }

        $user = Auth::user();
        $userId = $user ? $user->id : ($request->input('user_id') ?? ($rawJson['user_id'] ?? null));
        $dbUser = $userId ? \App\Models\User::find($userId) : null;
        $userName = $user ? $user->name : ($dbUser ? $dbUser->name : ($request->input('user_name') ?? 'Admin User'));

        $affectedItems = [];

        foreach ($orderItems as $item) {
            // Flag item
            $item->update([
                'is_flagged' => true,
                'flag_reason' => "{$actionType}: {$reason}",
            ]);

            // Create discrepancy record
            $discrepancy = OrderItemDiscrepancy::create([
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'user_id' => $userId,
                'user_name' => $userName,
                'issue_type' => $actionType,
                'comment' => "Reason: {$reason}" . (!empty($notes) ? " | Notes: {$notes}" : ""),
                'status' => 'open',
            ]);

            $affectedItems[] = [
                'item_id' => $item->id,
                'line_item_id' => $item->line_item_id,
                'product_name' => $item->product_name,
                'product_code' => $item->product_code,
                'quantity' => $item->quantity,
                'action_type' => $actionType,
                'reason' => $reason,
                'discrepancy_id' => $discrepancy->id,
            ];
        }

        // Update Order status
        $newOrderStatus = ($actionType === 'refund') ? 'refund' : 'exchange';
        $order->update([
            'status' => $newOrderStatus,
        ]);

        // Sync driver assignment if exists
        $assignment = OrderDriverAssigned::where('order_id', $order->id)
            ->orWhere('order_number', $order->order_number)
            ->first();

        if ($assignment) {
            $assignment->update([
                'order_status' => $newOrderStatus,
                'driver_status' => $actionType === 'refund' ? 'refund' : 'exchange',
            ]);
        }

        // Log audit entry
        OrderStatusLog::create([
            'order_id' => $order->id,
            'user_id' => $userId,
            'user_name' => $userName,
            'action' => 'return_replacement_created',
            'old_status' => $order->status,
            'new_status' => $newOrderStatus,
            'notes' => "Created {$actionType} request. Reason: {$reason}" . (!empty($notes) ? " ({$notes})" : ""),
        ]);

        return response()->json([
            'status' => 'success',
            'success' => true,
            'message' => ucfirst($actionType) . ' request created successfully.',
            'data' => [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'action_type' => $actionType,
                'reason' => $reason,
                'notes' => $notes,
                'order_status' => $newOrderStatus,
                'items_count' => count($affectedItems),
                'items' => $affectedItems,
            ],
        ], 200);
    }
}
