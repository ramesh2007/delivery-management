<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturnReplacement;
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

class ReturnReplacementController extends Controller
{

    public function createReturnReplacement(Request $request)
    {
        $content = $request->getContent();
        $rawJson = [];
        if (!empty($content)) {
            $decoded = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return response()->json([
                    'status' => 'error',
                    'success' => false,
                    'message' => 'Invalid JSON payload: ' . json_last_error_msg(),
                ], 400);
            }
            $rawJson = is_array($decoded) ? $decoded : [];
        }

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

        // Process photo upload or photo_url parameter
        $photoUrl = null;
        if ($request->hasFile('photo_url')) {
            $path = $request->file('photo_url')->store('discrepancies', 'public');
            $photoUrl = asset('storage/' . $path);
        } elseif ($request->hasFile('photo')) {
            $path = $request->file('photo')->store('discrepancies', 'public');
            $photoUrl = asset('storage/' . $path);
        } elseif ($request->hasFile('image')) {
            $path = $request->file('image')->store('discrepancies', 'public');
            $photoUrl = asset('storage/' . $path);
        } else {
            $incomingPhotoUrl = $request->input('photo_url')
                ?? ($rawJson['photo_url'] ?? null)
                ?? $request->input('photo')
                ?? ($rawJson['photo'] ?? null)
                ?? $request->input('image_url')
                ?? ($rawJson['image_url'] ?? null);

            if (is_string($incomingPhotoUrl) && trim($incomingPhotoUrl) !== '') {
                $photoUrl = trim($incomingPhotoUrl);
            } elseif (is_array($incomingPhotoUrl)) {
                if (isset($incomingPhotoUrl['url']) && is_string($incomingPhotoUrl['url'])) {
                    $photoUrl = trim($incomingPhotoUrl['url']);
                } elseif (isset($incomingPhotoUrl[0]) && is_string($incomingPhotoUrl[0])) {
                    $photoUrl = trim($incomingPhotoUrl[0]);
                }
            }
        }

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

            // Create return & replacement record
            $returnReplacement = OrderReturnReplacement::create([
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'user_id' => $userId,
                'user_name' => $userName,
                'action_type' => $actionType,
                'reason' => $reason,
                'notes' => $notes,
                'photo_url' => $photoUrl,
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
                'photo_url' => $photoUrl,
                'return_replacement_id' => $returnReplacement->id,
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
                'photo_url' => $photoUrl,
                'order_status' => $newOrderStatus,
                'items_count' => count($affectedItems),
                'items' => $affectedItems,
            ],
        ], 200);
    }

    /**
     * Get list of created order return & replacement requests
     * Route: GET /api/orders/return-replacements
     */
    public function getReturnReplacements(Request $request)
    {
        $query = OrderReturnReplacement::with(['order', 'orderItem', 'user']);

        if ($request->filled('order_id')) {
            $query->where('order_id', $request->input('order_id'));
        }

        if ($request->filled('order_item_id')) {
            $query->where('order_item_id', $request->input('order_item_id'));
        }

        if ($request->filled('action_type')) {
            $query->where('action_type', $request->input('action_type'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $records = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $records->map(function ($r) {
                return [
                    'id' => $r->id,
                    'order_id' => $r->order_id,
                    'order_number' => $r->order ? $r->order->order_number : null,
                    'order_item_id' => $r->order_item_id,
                    'line_item_id' => $r->orderItem ? $r->orderItem->line_item_id : null,
                    'product_code' => $r->orderItem ? $r->orderItem->product_code : null,
                    'product_name' => $r->orderItem ? $r->orderItem->product_name : null,
                    'user_id' => $r->user_id,
                    'user_name' => $r->user_name ?? ($r->user ? $r->user->name : null),
                    'action_type' => $r->action_type,
                    'reason' => $r->reason,
                    'notes' => $r->notes,
                    'status' => $r->status,
                    'photo_url' => $r->photo_url,
                    'created_at' => $r->created_at ? $r->created_at->toIso8601String() : null,
                ];
            }),
        ]);
    }
    protected function formatReturnReplacement(OrderReturnReplacement $record): array
    {
        $order = $record->order;
        $orderItem = $record->orderItem;
        $driver = $order?->driverAssignment;

        return [
            'id' => $record->id,
            'order_id' => $record->order_id,
            'order_number' => $order?->order_number ?? 'N/A',
            'order_status' => $order?->status ?? 'N/A',
            'action_type' => $record->action_type,
            'reason' => $record->reason,
            'notes' => $record->notes,
            'photo_url' => $record->photo_url,
            'status' => $record->status ?? 'pending',
            'requested_by' => [
                'id' => $record->user_id ? (int) $record->user_id : null,
                'name' => $record->user_name ?? ($record->user->name ?? 'N/A'),
            ],
            'customer' => [
                'name' => $order?->customer_name ?? 'N/A',
                'phone' => $order?->customer_phone ?? 'N/A',
                'delivery_address' => $order?->delivery_address ?? 'N/A',
            ],
            'driver' => $driver ? [
                'id' => (int) $driver->assigned_driver_user_id,
                'name' => $driver->driver_name,
                'driver_status' => $driver->driver_status,
                'zone' => $driver->zone,
            ] : null,
            'product' => $orderItem ? [
                'id' => $orderItem->id,
                'line_item_id' => $orderItem->line_item_id,
                'product_id' => $orderItem->product_id,
                'product_code' => $orderItem->product_code,
                'barcode' => $orderItem->barcode,
                'product_name' => $orderItem->product_name,
                'image' => $orderItem->image,
                'quantity' => (int) $orderItem->quantity,
                'unit_price' => (float) $orderItem->unit_price,
                'status' => $orderItem->status,
                'is_flagged' => (bool) $orderItem->is_flagged,
            ] : null,
            'created_at' => $record->created_at ? $record->created_at->toIso8601String() : null,
            'updated_at' => $record->updated_at ? $record->updated_at->toIso8601String() : null,
        ];
    }
}
