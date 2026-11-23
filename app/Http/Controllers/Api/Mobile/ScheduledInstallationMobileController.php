<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Admin\InstallationLevelController;
use App\Models\InstallationImage;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemDiscrepancy;
use App\Models\FlagImage;
use App\Models\OrderInstallation;
use App\Models\OrderPayment;
use App\Models\OrderDriverAssigned;
use App\Models\OrderStatusLog;
use App\Models\TechnicianScheduled;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Exception;

class ScheduledInstallationMobileController extends Controller
{
    /**
     * Retrieve and display a list of all installation orders assigned to a particular installer using installer_userid.
     *
     * Route: POST /api/mobile/scheduled/get/{installer_userid}
     *
     * @param Request $request
     * @param int|string|null $installer_userid
     * @return JsonResponse
     */
    public function getScheduledInstallationByInstallerUserId(Request $request, $installer_userid = null): JsonResponse
    {
        try {
            // 1. Resolve installer user ID from route param, query param, body, or Auth
            $resolvedId = $installer_userid
                ?? $request->route('installer_userid')
                ?? $request->input('installer_userid')
                ?? $request->input('installer_id')
                ?? $request->input('user_id')
                ?? $request->query('installer_userid')
                ?? $request->query('installer_id')
                ?? $request->query('user_id')
                ?? Auth::id();

            if (empty($resolvedId)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Installer user ID is required. Pass installer_userid in the URL path or request body.',
                ], 422);
            }

            $idStr = trim((string) $resolvedId);

            // 2. Resolve installer details (from users table or fallback constant technicians)
            $installerUser = User::find($idStr);
            $installerName = $installerUser?->name;

            if (!$installerName && class_exists(InstallationLevelController::class)) {
                $tech = collect(InstallationLevelController::TECHNICIANS)
                    ->first(function ($t) use ($idStr) {
                        return (string) ($t['id'] ?? '') === $idStr
                            || (string) ($t['technician_id'] ?? '') === $idStr;
                    });
                if ($tech) {
                    $installerName = $tech['name'] ?? ($tech['technician_name'] ?? null);
                }
            }

            // 3. Optional filters
            $statusFilter = $request->input('status') ?? $request->query('status');
            $search = trim((string) ($request->input('search') ?? $request->query('search', '')));

            // 4. Query orders assigned to this installer
            $ordersQuery = Order::with([
                'items.installation',
                'items.installationImages',
                'assignedUser',
                'technicianSchedules',
                'payment',
                'installationImages',
                'discrepancies',
                'logs' => function ($q) {
                    $q->latest()->limit(10);
                }
            ]);

            // Filter orders to only those having installable items
            $ordersQuery->whereHas('items', function ($iq) {
                $iq->where('is_installable', true);
            });

            // Filter for orders where this installer is assigned (unless 'all' is requested)
            if ($idStr !== 'all' && !filter_var($request->query('all', false), FILTER_VALIDATE_BOOLEAN)) {
                $ordersQuery->where(function ($q) use ($idStr, $installerName) {
                    // Direct assignment on line items (item-wise installer priority)
                    $q->whereHas('items', function ($iq) use ($idStr, $installerName) {
                        $iq->where('is_installable', true);
                        $iq->where(function ($sub) use ($idStr, $installerName) {
                            $sub->where('assigned_to', $idStr);
                            if (!empty($installerName)) {
                                $sub->orWhere('assigned_user_name', $installerName);
                            }
                        });
                    })
                        // Direct assignment on the order
                        ->orWhere('assigned_to', $idStr)
                        // Technician schedules
                        ->orWhereHas('technicianSchedules', function ($tq) use ($idStr, $installerName) {
                            $tq->where('technician_id', $idStr);
                            if (!empty($installerName)) {
                                $tq->orWhere('technician_name', $installerName);
                            }
                        })
                        // Or logged in order status audit logs
                        ->orWhereHas('logs', function ($lq) use ($idStr) {
                            $lq->where('user_id', $idStr)
                                ->whereIn('action', [
                                    'scheduled_installation',
                                    'installation_assigned',
                                    'scheduled_installation_mobile',
                                    'update_installation_status',
                                    'technician_scheduled'
                                ]);
                        });

                    if (!empty($installerName)) {
                        $q->orWhere('assigned_user_name', $installerName);
                    }
                });
            }

            // Optional status filter (default: remove already started and completed orders from main installation list)
            if (!empty($statusFilter)) {
                if ($statusFilter !== 'all') {
                    $ordersQuery->where(function ($q) use ($statusFilter) {
                        $q->where('status', $statusFilter)
                            ->orWhereHas('items', function ($iq) use ($statusFilter) {
                                $iq->where('is_installable', true)
                                    ->where('status', $statusFilter);
                            });
                    });
                }
            } elseif (!filter_var($request->query('include_all', false), FILTER_VALIDATE_BOOLEAN) && !filter_var($request->query('all', false), FILTER_VALIDATE_BOOLEAN)) {
                // By default, once an order is started or completed, remove it from the main installation list
                $ordersQuery->whereNotIn('status', ['in_progress', 'started', 'stated', 'completed', 'installed']);
                $ordersQuery->whereDoesntHave('items', function ($iq) {
                    $iq->where('is_installable', true)
                        ->where(function ($sub) {
                            $sub->whereIn('status', ['in_progress', 'started', 'stated', 'completed', 'installed'])
                                ->orWhereHas('installation', function ($instQ) {
                                    $instQ->whereIn('status', ['in_progress', 'started', 'stated', 'completed', 'installed']);
                                });
                        });
                });
            }

            // Optional keyword search (order number, customer name, phone, product name)
            if (!empty($search)) {
                $cleanSearch = trim(ltrim($search, '#'));
                $ordersQuery->where(function ($q) use ($search, $cleanSearch) {
                    $q->where('order_number', 'like', "%{$search}%")
                        ->orWhere('order_number', 'like', "%{$cleanSearch}%")
                        ->orWhere('customer_name', 'like', "%{$search}%")
                        ->orWhere('customer_phone', 'like', "%{$search}%")
                        ->orWhereHas('items', function ($iq) use ($search) {
                            $iq->where('is_installable', true)
                                ->where(function ($piq) use ($search) {
                                    $piq->where('product_name', 'like', "%{$search}%")
                                        ->orWhere('product_code', 'like', "%{$search}%");
                                });
                        });
                });
            }

            $orders = $ordersQuery->orderBy('updated_at', 'desc')->get();

            // 5. Format results into structured mobile payload
            $formattedOrders = $orders->map(function ($order) use ($idStr, $installerName, $request) {
                // Map only installable items with complete installation
                $installableItems = $order->items->filter(function ($item) {
                    return (bool) ($item->is_installable ?? false);
                });
                $allFormattedItems = $installableItems->map(function ($item) use ($idStr, $installerName, $order) {
                    $installation = $item->installation;
                    $isInstallableBool = (bool) ($item->is_installable ?? false);

                    // Resolve installer ID and name item-wise
                    $itemAssignedTo = $item->assigned_to ? (string) $item->assigned_to : null;
                    $itemInstallerId = $itemAssignedTo
                        ? (is_numeric($itemAssignedTo) ? (int) $itemAssignedTo : $itemAssignedTo)
                        : ($order->assigned_to ? (is_numeric($order->assigned_to) ? (int) $order->assigned_to : $order->assigned_to) : (is_numeric($idStr) ? (int) $idStr : $idStr));

                    $itemInstallerName = $item->assigned_user_name
                        ?: ($item->assignedUser?->name
                            ?: ($order->assigned_user_name ?? $installerName));

                    if (!$itemInstallerName && $itemInstallerId && class_exists(InstallationLevelController::class)) {
                        $tech = collect(InstallationLevelController::TECHNICIANS)
                            ->first(function ($t) use ($itemInstallerId) {
                                return (string) ($t['id'] ?? '') === (string) $itemInstallerId
                                    || (string) ($t['technician_id'] ?? '') === (string) $itemInstallerId;
                            });
                        if ($tech) {
                            $itemInstallerName = $tech['name'] ?? ($tech['technician_name'] ?? null);
                        }
                    }

                    if (!$itemInstallerName && $itemInstallerId) {
                        $itemInstallerName = 'Installer #' . $itemInstallerId;
                    }

                    $isAssignedToThisInstaller = ((string) $item->assigned_to === $idStr)
                        || (!empty($installerName) && $item->assigned_user_name === $installerName)
                        || (empty($item->assigned_to) && ((string) $order->assigned_to === $idStr || (!empty($installerName) && $order->assigned_user_name === $installerName)));

                    $latestImg = $item->installationImages?->sortByDesc('id')->first()
                        ?? InstallationImage::where('order_item_id', $item->id)->latest()->first()
                        ?? ($order->installationImages?->sortByDesc('id')->first());

                    $beforeImage = $latestImg?->before_install_image;
                    $afterImage = $latestImg?->after_install_image;

                    $instImages = is_array($installation?->images)
                        ? $installation->images
                        : (json_decode($installation?->images ?? '[]', true) ?: []);

                    if (!$beforeImage && !empty($instImages[0])) {
                        $beforeImage = $instImages[0];
                    }
                    if (!$afterImage && !empty($instImages[1])) {
                        $afterImage = $instImages[1];
                    }

                    $unitPrice = (float) $item->unit_price;
                    $qty = (int) $item->quantity;
                    $sku = $item->product_code ?: ($item->barcode ?: "SKU-{$item->id}");

                    $isInstalled = !empty($afterImage) || in_array($installation?->status, ['completed', 'installed']);

                    $installationPayload = $installation ? [
                        'id' => $installation->id,
                        'order_item_id' => $installation->order_item_id,
                        'installation_type' => $installation->installation_type,
                        'installation_level' => $installation->installation_level,
                        'status' => $isInstalled ? 'installed' : ($installation->status ?? ($item->status ?? 'scheduled')),
                        'notes' => $installation->notes ?? null,
                        'completed_at' => $installation->completed_at?->toIso8601String(),
                        'is_scheduled_assigned' => (bool) $installation->is_scheduled_assigned,
                        'created_at' => $installation->created_at?->toIso8601String(),
                        'updated_at' => $installation->updated_at?->toIso8601String(),
                    ] : null;

                    $itemData = [
                        'id' => $item->id,
                        'order_id' => $item->order_id,
                        'line_item_id' => $item->line_item_id,
                        'product_name' => $item->product_name,
                        'sku' => $sku,
                        'barcode' => $item->barcode,
                        'image' => $item->image,
                        'quantity' => $qty,
                        'unit_price' => $unitPrice,
                        'total_price' => (float) ($unitPrice * $qty),
                        'currency' => 'QAR',
                        'status' => $isInstalled ? 'installed' : $item->status,
                        'installation_proof_photos' => [
                            'before_install_image' => $beforeImage,
                            'after_install_image' => $afterImage,
                        ],
                        'is_installed' => $isInstalled,
                    ];

                    if ($installationPayload !== null) {
                        $itemData['installation'] = $installationPayload;
                    }

                    return $itemData;
                })->values();

                // Optional filter by query param: ?only_my_items=1 or ?filter_items=assigned
                $onlyMyItems = filter_var($request->query('only_my_items', false), FILTER_VALIDATE_BOOLEAN)
                    || filter_var($request->query('only_assigned_items', false), FILTER_VALIDATE_BOOLEAN)
                    || strtolower((string) $request->query('filter_items', '')) === 'assigned';

                $displayItems = $allFormattedItems;
                if ($onlyMyItems) {
                    $displayItems = $displayItems->filter(function ($item) {
                        return (bool) ($item['is_assigned_to_me'] ?? false);
                    })->values();
                }

                $isOrderAssignedToMe = ((string) $order->assigned_to === $idStr)
                    || (!empty($installerName) && $order->assigned_user_name === $installerName)
                    || $allFormattedItems->contains('is_assigned_to_me', true);

                $orderInstallerId = $order->assigned_to
                    ? (is_numeric($order->assigned_to) ? (int) $order->assigned_to : $order->assigned_to)
                    : ($allFormattedItems->firstWhere('assigned_installer_id')['assigned_installer_id'] ?? (is_numeric($idStr) ? (int) $idStr : $idStr));

                $orderInstallerName = $order->assigned_user_name
                    ?? ($allFormattedItems->firstWhere('assigned_installer_name')['assigned_installer_name'] ?? $installerName);

                // Shift text from technician schedule (e.g. "Shift: 9-10 Mar 2026")
                $techSchedule = $order->technicianSchedules?->first();
                $shiftText = null;
                if ($techSchedule) {
                    if (!empty($techSchedule->scheduled_time)) {
                        $shiftText = 'Shift: ' . $techSchedule->scheduled_time;
                    } elseif (!empty($techSchedule->scheduled_date)) {
                        $shiftText = 'Shift: ' . \Carbon\Carbon::parse($techSchedule->scheduled_date)->format('j M Y');
                    }
                }
                if (!$shiftText) {
                    $shiftText = 'Shift: ' . ($order->created_at ? $order->created_at->format('j M Y') : '9-10 Mar 2026');
                }

                // Payment Status
                $rawPaymentStatus = $order->payment_status ?: ($order->payment?->payment_status ?: 'paid');
                $paymentStatusFormatted = ucfirst(strtolower(str_replace(['_', '-'], ' ', (string) $rawPaymentStatus)));
                $isPaid = strtolower($rawPaymentStatus) === 'paid';
                $paymentMethod = $order->payment_method ?: ($order->payment?->payment_method ?: 'Card');

                // Financial calculations
                $subtotal = (float) $allFormattedItems->sum(function ($it) {
                    return (float) ($it['unit_price'] * $it['quantity']);
                });
                $totalAmount = (float) ($order->total_amount ?: $subtotal);
                $currency = $order->payment?->currency ?: 'QAR';

                // Date Formatting: e.g. "Nov 5 | 08:35" and relative "1h 12m ago"
                $orderDateFormatted = $order->created_at ? $order->created_at->format('M j | H:i') : null;
                $timeAgo = $order->created_at ? $order->created_at->diffForHumans() : null;

                // Contact & WhatsApp URL
                $cleanPhone = preg_replace('/[^0-9]/', '', (string) $order->customer_phone);
                $whatsappUrl = $cleanPhone ? "https://wa.me/{$cleanPhone}" : null;

                // Order summary breakdown
                $orderSummary = [
                    'subtotal' => $subtotal,
                    'discount' => 0.00,
                    'shipping' => 0.00,
                    'total' => $totalAmount,
                    'currency' => $currency,
                ];

                $isOrderFlagged = (bool) (
                    $order->status === 'flagged'
                    || $allFormattedItems->contains('status', 'flagged')
                    || $order->items->contains('is_flagged', true)
                );

                $flagReason = null;
                if ($isOrderFlagged) {
                    $latestDisc = $order->discrepancies?->sortByDesc('id')->first()
                        ?? OrderItemDiscrepancy::where('order_id', $order->id)->latest()->first();
                    $flagReason = $latestDisc?->issue_type
                        ?: ($order->items->firstWhere('flag_reason', '!=', null)?->flag_reason
                            ?: ($order->flag_reason ?? 'Customer Unavailable'));
                }

                $orderData = [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                ];

                if ($isOrderFlagged) {
                    $orderData['reason'] = $flagReason;
                }

                $orderData = array_merge($orderData, [
                    // Payment details
                    'payment' => [
                        'payment_status' => $paymentStatusFormatted,
                        'payment_method' => $paymentMethod,
                        'currency' => $currency,
                        'total' => $totalAmount,
                        'paid_amount' => $isPaid ? $totalAmount : (float) ($order->payment?->paid_amount ?? 0),
                    ],

                    // Customer & Contact
                    'customer_name' => $order->customer_name ?? 'Customer',
                    'customer_phone' => $order->customer_phone ?? 'N/A',
                    'delivery_address' => $order->delivery_address ?? 'N/A',
                    'latitude' => 25.286106,
                    'longitude' => 51.534817,

                    // Delivery receipt (Signed slip / Proof of delivery Optional)
                    'delivery_receipt' => null,

                    // Order Summary breakdown (Subtotal, Discount, Shipping, Total)
                    'order_summary' => $orderSummary,

                    'created_at' => $order->created_at?->toIso8601String(),
                    'updated_at' => $order->updated_at?->toIso8601String(),
                    'items' => $displayItems,
                ]);

                return $orderData;
            })->filter(function ($ord) {
                return count($ord['items']) > 0;
            })->values();

            return response()->json([
                'status' => 'success',
                'count' => $formattedOrders->count(),
                'data' => $formattedOrders,
            ], 200);

        } catch (Exception $e) {
            Log::error('Error in getScheduledInstallationByInstallerUserId: ' . $e->getMessage(), [
                'installer_userid' => $installer_userid,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve scheduled installations: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Retrieve and list all in-progress installation orders and items for a specific installer.
     *
     * Route: GET|POST /api/mobile/scheduled/in-progress/{installer_userid?}
     * Route: GET|POST /api/mobile/scheduled/inprogress/{installer_userid?}
     *
     * @param Request $request
     * @param int|string|null $installer_userid
     * @return JsonResponse
     */
    public function getInProgressInstallationsByInstallerUserId(Request $request, $installer_userid = null): JsonResponse
    {
        try {
            // 1. Resolve installer user ID from route param, query param, body, or Auth
            $resolvedId = $installer_userid
                ?? $request->route('installer_userid')
                ?? $request->input('installer_userid')
                ?? $request->input('installer_id')
                ?? $request->input('user_id')
                ?? $request->query('installer_userid')
                ?? $request->query('installer_id')
                ?? $request->query('user_id')
                ?? Auth::id();

            if (empty($resolvedId)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Installer user ID is required. Pass installer_userid in the URL path or query.',
                ], 422);
            }

            $idStr = trim((string) $resolvedId);

            // 2. Resolve installer details
            $installerUser = User::find($idStr);
            $installerName = $installerUser?->name;

            if (!$installerName && class_exists(InstallationLevelController::class)) {
                $tech = collect(InstallationLevelController::TECHNICIANS)
                    ->first(function ($t) use ($idStr) {
                        return (string) ($t['id'] ?? '') === $idStr
                            || (string) ($t['technician_id'] ?? '') === $idStr;
                    });
                if ($tech) {
                    $installerName = $tech['name'] ?? ($tech['technician_name'] ?? null);
                }
            }

            // 3. Query orders with items and installation records
            $ordersQuery = Order::with([
                'items.installation',
                'assignedUser',
                'logs' => function ($q) {
                    $q->latest()->limit(10);
                }
            ]);

            // Filter orders to only those having installable items
            $ordersQuery->whereHas('items', function ($iq) {
                $iq->where('is_installable', true);
            });

            // Filter for orders where this installer is assigned
            $ordersQuery->where(function ($q) use ($idStr, $installerName) {
                $q->whereHas('items', function ($iq) use ($idStr, $installerName) {
                    $iq->where('is_installable', true)
                        ->where(function ($sub) use ($idStr, $installerName) {
                            $sub->where('assigned_to', $idStr);
                            if (!empty($installerName)) {
                                $sub->orWhere('assigned_user_name', $installerName);
                            }
                        });
                })
                    ->orWhere('assigned_to', $idStr)
                    ->orWhereHas('logs', function ($lq) use ($idStr) {
                        $lq->where('user_id', $idStr)
                            ->whereIn('action', [
                                'scheduled_installation',
                                'installation_assigned',
                                'scheduled_installation_mobile',
                                'update_installation_status'
                            ]);
                    });

                if (!empty($installerName)) {
                    $q->orWhere('assigned_user_name', $installerName);
                }
            });

            // Filter for in_progress status across order_installations or orders
            $ordersQuery->where(function ($q) {
                $q->whereIn('status', ['in_progress', 'started', 'stated'])
                    ->orWhereHas('items', function ($iq) {
                        $iq->where('is_installable', true)
                            ->where(function ($iiq) {
                                $iiq->whereIn('status', ['in_progress', 'started', 'stated'])
                                    ->orWhereHas('installation', function ($instQ) {
                                        $instQ->whereIn('status', ['in_progress', 'started', 'stated']);
                                    });
                            });
                    });
            });

            // Optional keyword search
            if ($request->filled('search')) {
                $search = trim($request->input('search'));
                $cleanSearch = trim(ltrim($search, '#'));
                $ordersQuery->where(function ($q) use ($search, $cleanSearch) {
                    $q->where('order_number', 'like', "%{$search}%")
                        ->orWhere('order_number', 'like', "%{$cleanSearch}%")
                        ->orWhere('customer_name', 'like', "%{$search}%")
                        ->orWhere('customer_phone', 'like', "%{$search}%")
                        ->orWhereHas('items', function ($iq) use ($search) {
                            $iq->where('is_installable', true)
                                ->where(function ($piq) use ($search) {
                                    $piq->where('product_name', 'like', "%{$search}%")
                                        ->orWhere('product_code', 'like', "%{$search}%");
                                });
                        });
                });
            }

            $orders = $ordersQuery->orderBy('updated_at', 'desc')->get();

            // 4. Build both grouped orders and a flattened item list
            $flattenedItems = [];

            $formattedOrders = $orders->map(function ($order) use ($idStr, $installerName, &$flattenedItems) {
                // Filter items to in-progress items assigned to this installer (installable only)
                $inProgressItems = $order->items->filter(function ($item) use ($idStr, $installerName, $order) {
                    if (!($item->is_installable ?? false)) {
                        return false;
                    }

                    $installation = $item->installation;
                    $isItemInProgress = ($order->status === 'in_progress')
                        || in_array($item->status, ['in_progress', 'started', 'stated'])
                        || ($installation && in_array($installation->status, ['in_progress', 'started', 'stated']));

                    $isAssignedToThisInstaller = ((string) $item->assigned_to === $idStr)
                        || (!empty($installerName) && $item->assigned_user_name === $installerName)
                        || (empty($item->assigned_to) && ((string) $order->assigned_to === $idStr || (!empty($installerName) && $order->assigned_user_name === $installerName)));

                    return $isItemInProgress && $isAssignedToThisInstaller;
                })->values();

                // If no items directly matched in_progress filter, fallback to all installer items for this in-progress order
                if ($inProgressItems->isEmpty() && in_array($order->status, ['in_progress', 'started'])) {
                    $inProgressItems = $order->items->filter(function ($item) use ($idStr, $installerName, $order) {
                        return (bool) ($item->is_installable ?? false)
                            && (((string) $item->assigned_to === $idStr)
                                || (!empty($installerName) && $item->assigned_user_name === $installerName)
                                || ((string) $order->assigned_to === $idStr));
                    })->values();
                }

                $mappedItems = $inProgressItems->map(function ($item) use ($idStr, $installerName, $order, &$flattenedItems) {
                    $installation = $item->installation;

                    $itemAssignedTo = $item->assigned_to ? (string) $item->assigned_to : null;
                    $itemInstallerId = $itemAssignedTo
                        ? (is_numeric($itemAssignedTo) ? (int) $itemAssignedTo : $itemAssignedTo)
                        : ($order->assigned_to ? (is_numeric($order->assigned_to) ? (int) $order->assigned_to : $order->assigned_to) : (is_numeric($idStr) ? (int) $idStr : $idStr));

                    $itemInstallerName = $item->assigned_user_name
                        ?: ($item->assignedUser?->name
                            ?: ($order->assigned_user_name ?? $installerName));

                    if (!$itemInstallerName && $itemInstallerId && class_exists(InstallationLevelController::class)) {
                        $tech = collect(InstallationLevelController::TECHNICIANS)
                            ->first(function ($t) use ($itemInstallerId) {
                                return (string) ($t['id'] ?? '') === (string) $itemInstallerId
                                    || (string) ($t['technician_id'] ?? '') === (string) $itemInstallerId;
                            });
                        if ($tech) {
                            $itemInstallerName = $tech['name'] ?? ($tech['technician_name'] ?? null);
                        }
                    }

                    if (!$itemInstallerName && $itemInstallerId) {
                        $itemInstallerName = 'Installer #' . $itemInstallerId;
                    }

                    $itemData = [
                        'id' => $item->id,
                        'order_item_id' => $item->id,
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'line_item_id' => $item->line_item_id,
                        'product_id' => $item->product_id,
                        'product_code' => $item->product_code,
                        'barcode' => $item->barcode,
                        'product_name' => $item->product_name,
                        'image' => $item->image,
                        'quantity' => (int) $item->quantity,
                        'unit_price' => (float) $item->unit_price,
                        'status' => 'in_progress',
                        'order_status' => $order->status,
                        'is_installable' => (int) ($item->is_installable ? 1 : 0),
                        'is_installable_bool' => (bool) $item->is_installable,
                        'is_installable_label' => $item->is_installable ? 'yes' : 'no',
                        'is_assigned_to_me' => true,
                        'assigned_installer_id' => $itemInstallerId,
                        'assigned_installer_name' => $itemInstallerName,
                        'installer_id' => $itemInstallerId,
                        'installer_name' => $itemInstallerName,
                        'installer_user_id' => $itemInstallerId,
                        'installer' => [
                            'id' => $itemInstallerId,
                            'name' => $itemInstallerName,
                        ],
                        'customer_name' => $order->customer_name ?? 'N/A',
                        'customer_phone' => $order->customer_phone ?? 'N/A',
                        'delivery_address' => $order->delivery_address ?? 'N/A',
                        'installation' => [
                            'id' => $installation?->id,
                            'order_item_id' => $installation?->order_item_id ?? $item->id,
                            'installation_type' => $installation?->installation_type,
                            'installation_level' => $installation?->installation_level,
                            'status' => 'in_progress',
                            'notes' => $installation?->notes ?? null,
                            'images' => is_array($installation?->images) ? $installation->images : (json_decode($installation?->images ?? '[]', true) ?: []),
                            'completed_at' => $installation?->completed_at?->toIso8601String(),
                            'is_scheduled_assigned' => (bool) ($installation?->is_scheduled_assigned ?? true),
                        ],
                        'order_installation' => [
                            'id' => $installation?->id,
                            'order_item_id' => $installation?->order_item_id ?? $item->id,
                            'installation_type' => $installation?->installation_type,
                            'installation_level' => $installation?->installation_level,
                            'status' => 'in_progress',
                            'notes' => $installation?->notes ?? null,
                            'images' => is_array($installation?->images) ? $installation->images : (json_decode($installation?->images ?? '[]', true) ?: []),
                            'completed_at' => $installation?->completed_at?->toIso8601String(),
                            'is_scheduled_assigned' => (bool) ($installation?->is_scheduled_assigned ?? true),
                        ],
                        'updated_at' => $installation?->updated_at?->toIso8601String() ?? $order->updated_at?->toIso8601String(),
                    ];

                    $flattenedItems[] = $itemData;

                    return $itemData;
                })->values();

                $orderInstallerId = $order->assigned_to
                    ? (is_numeric($order->assigned_to) ? (int) $order->assigned_to : $order->assigned_to)
                    : ($mappedItems->firstWhere('assigned_installer_id')['assigned_installer_id'] ?? (is_numeric($idStr) ? (int) $idStr : $idStr));

                $orderInstallerName = $order->assigned_user_name
                    ?? ($mappedItems->firstWhere('assigned_installer_name')['assigned_installer_name'] ?? $installerName);

                $orderInstallations = $order->items
                    ->map(function ($it) {
                        return $it->installation;
                    })
                    ->filter()
                    ->map(function ($inst) {
                        return [
                            'id' => $inst->id,
                            'order_item_id' => $inst->order_item_id,
                            'installation_type' => $inst->installation_type,
                            'installation_level' => $inst->installation_level,
                            'status' => $inst->status,
                            'notes' => $inst->notes ?? null,
                            'images' => is_array($inst->images) ? $inst->images : (json_decode($inst->images ?? '[]', true) ?: []),
                            'completed_at' => $inst->completed_at?->toIso8601String(),
                            'is_scheduled_assigned' => (bool) $inst->is_scheduled_assigned,
                        ];
                    })->values();

                return [
                    'id' => $order->id,
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'order_status' => 'in_progress',
                    'status' => 'in_progress',
                    'customer_name' => $order->customer_name ?? 'N/A',
                    'customer_phone' => $order->customer_phone ?? 'N/A',
                    'delivery_address' => $order->delivery_address ?? 'N/A',
                    'total_amount' => (float) $order->total_amount,
                    'bag_count' => (int) ($order->bag_count ?? 0),
                    'is_assigned_to_me' => true,
                    'assigned_installer_id' => $orderInstallerId,
                    'assigned_installer_name' => $orderInstallerName,
                    'installer_id' => $orderInstallerId,
                    'installer_name' => $orderInstallerName,
                    'installer_user_id' => $orderInstallerId,
                    'updated_at' => $order->updated_at?->toIso8601String(),
                    'total_items_count' => $order->items->count(),
                    'items_count' => $mappedItems->count(),
                    'installation_items_count' => $mappedItems->count(),
                    'installations_count' => $orderInstallations->count(),
                    'items' => $mappedItems,
                    'order_items' => $mappedItems,
                    'installation_items' => $mappedItems,
                    'order_installations' => $orderInstallations,
                    'installations' => $orderInstallations,
                    'product_tags' => $item->product_tags ?? null,
                    'gift_wrap' => $item->gift_wrap ?? null,
                ];
            })->filter(function ($ord) {
                return $ord['items_count'] > 0;
            })->values();

            return response()->json([
                'status' => 'success',
                'installer_user_id' => is_numeric($idStr) ? (int) $idStr : $idStr,
                'installer_name' => $installerName ?? ('Installer #' . $idStr),
                'status_filter' => 'in_progress',
                'orders_count' => $formattedOrders->count(),
                'items_count' => count($flattenedItems),
                'data' => $formattedOrders,
                'items_list' => $flattenedItems,
                'product_tags' => $item->product_tags ?? null,
                'gift_wrap' => $item->gift_wrap ?? null,
            ], 200);

        } catch (Exception $e) {
            Log::error('Error in getInProgressInstallationsByInstallerUserId: ' . $e->getMessage(), [
                'installer_userid' => $installer_userid,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve in-progress installations: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Retrieve and list all completed / installed installation orders and items for a specific installer.
     *
     * Route: GET|POST /api/mobile/scheduled/completed/{installer_userid?}
     * Route: GET|POST /api/mobile/scheduled/installed/{installer_userid?}
     *
     * @param Request $request
     * @param int|string|null $installer_userid
     * @return JsonResponse
     */
    public function getCompletedInstallationsByInstallerUserId(Request $request, $installer_userid = null): JsonResponse
    {
        try {
            // 1. Resolve installer user ID from route param, query param, body, or Auth
            $resolvedId = $installer_userid
                ?? $request->route('installer_userid')
                ?? $request->input('installer_userid')
                ?? $request->input('installer_id')
                ?? $request->input('user_id')
                ?? $request->query('installer_userid')
                ?? $request->query('installer_id')
                ?? $request->query('user_id')
                ?? Auth::id();

            if (empty($resolvedId)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Installer user ID is required. Pass installer_userid in the URL path or query.',
                ], 422);
            }

            $idStr = trim((string) $resolvedId);

            // 2. Resolve installer details
            $installerUser = User::find($idStr);
            $installerName = $installerUser?->name;

            if (!$installerName && class_exists(InstallationLevelController::class)) {
                $tech = collect(InstallationLevelController::TECHNICIANS)
                    ->first(function ($t) use ($idStr) {
                        return (string) ($t['id'] ?? '') === $idStr
                            || (string) ($t['technician_id'] ?? '') === $idStr;
                    });
                if ($tech) {
                    $installerName = $tech['name'] ?? ($tech['technician_name'] ?? null);
                }
            }

            // 3. Query orders with items and installation records
            $ordersQuery = Order::with([
                'items.installation',
                'items.installationImages',
                'assignedUser',
                'technicianSchedules',
                'payment',
                'installationImages',
                'discrepancies',
                'logs' => function ($q) {
                    $q->latest()->limit(10);
                }
            ]);

            // Filter orders to only those having installable items
            $ordersQuery->whereHas('items', function ($iq) {
                $iq->where('is_installable', true);
            });

            // Filter for orders where this installer is assigned
            $isAdmin = strtolower($installerUser?->role ?? '') === 'admin' || $idStr === '1';

            if ($idStr !== 'all' && !$isAdmin && !filter_var($request->query('all', false), FILTER_VALIDATE_BOOLEAN)) {
                $ordersQuery->where(function ($q) use ($idStr, $installerName) {
                    $q->whereHas('items', function ($iq) use ($idStr, $installerName) {
                        $iq->where('is_installable', true);
                        $iq->where(function ($sub) use ($idStr, $installerName) {
                            $sub->where('assigned_to', $idStr);
                            if (!empty($installerName)) {
                                $sub->orWhere('assigned_user_name', $installerName);
                            }
                        });
                    })
                        ->orWhere('assigned_to', $idStr)
                        ->orWhereHas('technicianSchedules', function ($tq) use ($idStr, $installerName) {
                            $tq->where('technician_id', $idStr);
                            if (!empty($installerName)) {
                                $tq->orWhere('technician_name', $installerName);
                            }
                        })
                        ->orWhereHas('logs', function ($lq) use ($idStr) {
                            $lq->where('user_id', $idStr)
                                ->whereIn('action', [
                                    'start_installation',
                                    'installation_started',
                                    'scheduled_installation',
                                    'installation_assigned',
                                    'scheduled_installation_mobile',
                                    'update_installation_status',
                                    'technician_scheduled'
                                ]);
                        });

                    if (!empty($installerName)) {
                        $q->orWhere('assigned_user_name', $installerName);
                    }
                });
            }

            // Filter strictly for completed status across orders, order items, or order_installations
            $ordersQuery->where(function ($q) {
                $q->where('status', 'completed')
                    ->orWhereHas('items', function ($iq) {
                        $iq->where('is_installable', true)
                            ->where(function ($iiq) {
                                $iiq->where('status', 'completed')
                                    ->orWhereHas('installation', function ($instQ) {
                                        $instQ->where('status', 'completed');
                                    });
                            });
                    });
            });

            // Do not include orders that are in_progress, started, or flagged
            $ordersQuery->whereNotIn('status', ['in_progress', 'started', 'stated']);

            // Optional keyword search
            if ($request->filled('search')) {
                $search = trim($request->input('search'));
                $cleanSearch = trim(ltrim($search, '#'));
                $ordersQuery->where(function ($q) use ($search, $cleanSearch) {
                    $q->where('order_number', 'like', "%{$search}%")
                        ->orWhere('order_number', 'like', "%{$cleanSearch}%")
                        ->orWhere('customer_name', 'like', "%{$search}%")
                        ->orWhere('customer_phone', 'like', "%{$search}%")
                        ->orWhereHas('items', function ($iq) use ($search) {
                            $iq->where('is_installable', true)
                                ->where(function ($piq) use ($search) {
                                    $piq->where('product_name', 'like', "%{$search}%")
                                        ->orWhere('product_code', 'like', "%{$search}%");
                                });
                        });
                });
            }

            $orders = $ordersQuery->distinct()->orderBy('updated_at', 'desc')->get()->unique('id');

            // 4. Format results into structured mobile payload
            $formattedOrders = $orders->map(function ($order) use ($idStr, $installerName, $request) {
                // Filter items to completed installable items
                $installableItems = $order->items->filter(function ($item) use ($order) {
                    if (!($item->is_installable ?? false)) {
                        return false;
                    }
                    $installation = $item->installation;
                    return $order->status === 'completed'
                        || $item->status === 'completed'
                        || ($installation && $installation->status === 'completed');
                });

                $allFormattedItems = $installableItems->map(function ($item) use ($idStr, $installerName, $order) {
                    $installation = $item->installation;
                    $isInstallableBool = (bool) ($item->is_installable ?? false);

                    $latestImg = $item->installationImages?->sortByDesc('id')->first()
                        ?? InstallationImage::where('order_item_id', $item->id)->latest()->first()
                        ?? ($order->installationImages?->sortByDesc('id')->first());

                    $beforeImage = $latestImg?->before_install_image;
                    $afterImage = $latestImg?->after_install_image;

                    $instImages = is_array($installation?->images)
                        ? $installation->images
                        : (json_decode($installation?->images ?? '[]', true) ?: []);

                    if (!$beforeImage && !empty($instImages[0])) {
                        $beforeImage = $instImages[0];
                    }
                    if (!$afterImage && !empty($instImages[1])) {
                        $afterImage = $instImages[1];
                    }

                    $unitPrice = (float) $item->unit_price;
                    $qty = (int) $item->quantity;
                    $sku = $item->product_code ?: ($item->barcode ?: "SKU-{$item->id}");

                    $isInstalled = !empty($afterImage) || in_array($installation?->status, ['completed', 'installed']);

                    $resolvedStatus = 'completed';

                    $installationPayload = $installation ? [
                        'id' => $installation->id,
                        'order_item_id' => $installation->order_item_id,
                        'installation_type' => $installation->installation_type,
                        'installation_level' => $installation->installation_level,
                        'status' => 'completed',
                        'notes' => $installation->notes ?? null,
                        'completed_at' => $installation->completed_at?->toIso8601String(),
                        'is_scheduled_assigned' => (bool) $installation->is_scheduled_assigned,
                        'created_at' => $installation->created_at?->toIso8601String(),
                        'updated_at' => $installation->updated_at?->toIso8601String(),
                    ] : null;

                    $itemData = [
                        'id' => $item->id,
                        'order_id' => $item->order_id,
                        'line_item_id' => (string) $item->line_item_id,
                        'product_name' => $item->product_name,
                        'sku' => $sku,
                        'barcode' => $item->barcode,
                        'image' => $item->image,
                        'quantity' => $qty,
                        'unit_price' => $unitPrice,
                        'total_price' => (float) ($unitPrice * $qty),
                        'currency' => 'QAR',
                        'status' => 'completed',
                        'installation_proof_photos' => [
                            'before_install_image' => $beforeImage,
                            'after_install_image' => $afterImage,
                        ],
                        'is_installed' => $isInstalled,
                    ];

                    if ($installationPayload !== null) {
                        $itemData['installation'] = $installationPayload;
                    }

                    return $itemData;
                })->values();

                // Payment Status
                $rawPaymentStatus = $order->payment_status ?: ($order->payment?->payment_status ?: 'paid');
                $paymentStatusFormatted = ucfirst(strtolower(str_replace(['_', '-'], ' ', (string) $rawPaymentStatus)));
                $isPaid = strtolower($rawPaymentStatus) === 'paid';
                $paymentMethod = $order->payment_method ?: ($order->payment?->payment_method ?: 'Card');

                // Financial calculations
                $subtotal = (float) $allFormattedItems->sum(function ($it) {
                    return (float) ($it['unit_price'] * $it['quantity']);
                });
                $totalAmount = (float) ($order->total_amount ?: $subtotal);
                $currency = $order->payment?->currency ?: 'QAR';

                // Order summary breakdown
                $orderSummary = [
                    'subtotal' => $subtotal,
                    'discount' => 0.00,
                    'shipping' => 0.00,
                    'total' => $totalAmount,
                    'currency' => $currency,
                ];

                $isOrderFlagged = (bool) (
                    $order->status === 'flagged'
                    || $allFormattedItems->contains('status', 'flagged')
                    || $order->items->contains('is_flagged', true)
                );

                $flagReason = null;
                if ($isOrderFlagged) {
                    $latestDisc = $order->discrepancies?->sortByDesc('id')->first()
                        ?? OrderItemDiscrepancy::where('order_id', $order->id)->latest()->first();
                    $flagReason = $latestDisc?->issue_type
                        ?: ($order->items->firstWhere('flag_reason', '!=', null)?->flag_reason
                            ?: ($order->flag_reason ?? 'Customer Unavailable'));
                }

                $orderData = [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                ];

                if ($isOrderFlagged) {
                    $orderData['status'] = 'flagged';
                    $orderData['reason'] = $flagReason;
                }

                $orderData = array_merge($orderData, [
                    // Payment details
                    'payment' => [
                        'payment_status' => $paymentStatusFormatted,
                        'payment_method' => $paymentMethod,
                        'currency' => $currency,
                        'total' => $totalAmount,
                        'paid_amount' => $isPaid ? $totalAmount : (float) ($order->payment?->paid_amount ?? 0),
                    ],

                    // Customer & Contact
                    'customer_name' => $order->customer_name ?? 'Customer',
                    'customer_phone' => $order->customer_phone ?? 'N/A',
                    'delivery_address' => $order->delivery_address ?? 'N/A',
                    'latitude' => 25.286106,
                    'longitude' => 51.534817,

                    // Delivery receipt (Signed slip / Proof of delivery Optional)
                    'delivery_receipt' => null,

                    // Order Summary breakdown (Subtotal, Discount, Shipping, Total)
                    'order_summary' => $orderSummary,

                    'created_at' => $order->created_at?->toIso8601String(),
                    'updated_at' => $order->updated_at?->toIso8601String(),
                    'items' => $allFormattedItems,
                ]);

                return $orderData;
            })->filter(function ($ord) {
                return count($ord['items']) > 0;
            })->values();

            return response()->json([
                'status' => 'success',
                'count' => $formattedOrders->count(),
                'data' => $formattedOrders,
            ], 200);

        } catch (Exception $e) {
            Log::error('Error in getCompletedInstallationsByInstallerUserId: ' . $e->getMessage(), [
                'installer_userid' => $installer_userid,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve completed installations: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Admin or Mobile assign an order ID to an installer.
     *
     * Route: POST /api/mobile/scheduled/assign
     */
    public function assignInstaller(Request $request): JsonResponse
    {
        $payload = array_merge($request->all(), is_array($request->json()?->all()) ? $request->json()->all() : []);
        if ($request->getContent()) {
            $decoded = json_decode($request->getContent(), true);
            if (is_array($decoded)) {
                $payload = array_merge($payload, $decoded);
            }
        }

        $validator = Validator::make($payload, [
            'order_id' => 'nullable',
            'order_number' => 'nullable|string',
            'item_id' => 'nullable',
            'order_item_id' => 'nullable',
            'line_item_id' => 'nullable',
            'item_ids' => 'nullable|array',
            'order_item_ids' => 'nullable|array',
            'line_item_ids' => 'nullable|array',
            'installer_userid' => 'nullable',
            'installer_id' => 'nullable',
            'user_id' => 'nullable',
            'assigned_to' => 'nullable',
            'installer_name' => 'nullable|string',
            'user_name' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $installerId = $payload['installer_userid']
            ?? $payload['installer_id']
            ?? $payload['user_id']
            ?? $payload['assigned_to']
            ?? $request->input('installer_userid');

        if (!$installerId) {
            return response()->json([
                'status' => 'error',
                'message' => 'installer_userid is required.',
            ], 422);
        }

        $orderId = $payload['order_id'] ?? $request->input('order_id');
        $orderNumber = $payload['order_number'] ?? $request->input('order_number');
        $itemId = $payload['item_id'] ?? $payload['order_item_id'] ?? $payload['line_item_id'] ?? $request->input('item_id');
        $itemIds = $payload['item_ids'] ?? $payload['order_item_ids'] ?? $payload['line_item_ids'] ?? null;

        if (!$orderId && !$orderNumber && !$itemId && empty($itemIds)) {
            return response()->json([
                'status' => 'error',
                'message' => 'order_id, order_number, or item_id/item_ids is required.',
            ], 422);
        }

        try {
            return DB::transaction(function () use ($payload, $orderId, $orderNumber, $itemId, $itemIds, $installerId) {
                $idStr = trim((string) $installerId);
                $installerUser = User::find($idStr);
                $installerName = $payload['installer_name'] ?? $payload['user_name'] ?? $installerUser?->name;

                if (!$installerName && class_exists(InstallationLevelController::class)) {
                    $tech = collect(InstallationLevelController::TECHNICIANS)
                        ->first(function ($t) use ($idStr) {
                            return (string) ($t['id'] ?? '') === $idStr
                                || (string) ($t['technician_id'] ?? '') === $idStr;
                        });
                    if ($tech) {
                        $installerName = $tech['name'] ?? ($tech['technician_name'] ?? null);
                    }
                }

                if (!$installerName) {
                    $installerName = 'Installer #' . $idStr;
                }

                // If specific item(s) are provided for item-wise assignment
                $targetItemIds = [];
                if (!empty($itemIds) && is_array($itemIds)) {
                    $targetItemIds = $itemIds;
                } elseif ($itemId) {
                    $targetItemIds = [$itemId];
                }

                if (!empty($targetItemIds)) {
                    $itemsToUpdate = OrderItem::whereIn('id', $targetItemIds)
                        ->orWhereIn('line_item_id', $targetItemIds)
                        ->get();

                    if ($itemsToUpdate->isEmpty()) {
                        return response()->json([
                            'status' => 'error',
                            'message' => 'Specified items not found.',
                        ], 404);
                    }

                    $parentOrder = Order::find($itemsToUpdate->first()->order_id);

                    foreach ($itemsToUpdate as $item) {
                        $itemUpdates = [
                            'assigned_to' => $idStr,
                            'assigned_user_name' => $installerName,
                            'assigned_at' => now(),
                        ];
                        if (!in_array($item->status, ['installed', 'completed'])) {
                            $itemUpdates['status'] = 'scheduled';
                        }
                        $item->update($itemUpdates);

                        if (Schema::hasTable('order_installations')) {
                            OrderInstallation::updateOrCreate(
                                ['order_item_id' => $item->id],
                                ['is_scheduled_assigned' => true]
                            );
                        }

                        OrderStatusLog::create([
                            'order_id' => $item->order_id,
                            'order_item_id' => $item->id,
                            'user_id' => $idStr,
                            'user_name' => $installerName,
                            'action' => 'installation_assigned',
                            'old_status' => $item->status,
                            'new_status' => 'scheduled',
                            'notes' => $payload['notes'] ?? "Item {$item->product_name} assigned to installer {$installerName} (ID: {$idStr})",
                        ]);
                    }

                    if ($parentOrder && in_array($parentOrder->status, ['pending', 'new', 'ready_to_assign', 'ready_for_installation', 'installation'])) {
                        $parentOrder->update(['status' => 'scheduled']);
                    }

                    return response()->json([
                        'status' => 'success',
                        'message' => count($itemsToUpdate) . " item(s) successfully assigned to installer {$installerName}.",
                        'data' => [
                            'order_id' => $parentOrder?->id,
                            'order_number' => $parentOrder?->order_number,
                            'assigned_items_count' => $itemsToUpdate->count(),
                            'installer_user_id' => is_numeric($idStr) ? (int) $idStr : $idStr,
                            'installer_name' => $installerName,
                            'assigned_at' => now()->toIso8601String(),
                            'items' => $itemsToUpdate->map(function ($it) use ($idStr, $installerName) {
                                return [
                                    'item_id' => $it->id,
                                    'line_item_id' => $it->line_item_id,
                                    'product_name' => $it->product_name,
                                    'status' => $it->fresh()->status,
                                    'assigned_installer_id' => is_numeric($idStr) ? (int) $idStr : $idStr,
                                    'assigned_installer_name' => $installerName,
                                ];
                            })->values(),
                        ],
                    ], 200);
                }

                $cleanNum = $orderNumber ? trim(ltrim($orderNumber, '#')) : null;
                $order = Order::where(function ($q) use ($orderId, $orderNumber, $cleanNum) {
                    if ($orderId) {
                        $q->orWhere('id', $orderId);
                    }
                    if ($orderNumber) {
                        $q->orWhere('order_number', $orderNumber)
                            ->orWhere('order_number', $cleanNum)
                            ->orWhere('order_number', '#' . $cleanNum);
                    }
                })->first();

                if (!$order) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Order not found.',
                    ], 404);
                }

                // Update order assignment
                $orderUpdates = [
                    'assigned_to' => $idStr,
                    'assigned_user_name' => $installerName,
                    'assigned_at' => now(),
                ];
                if (in_array($order->status, ['pending', 'new', 'ready_to_assign', 'ready_for_installation', 'installation'])) {
                    $orderUpdates['status'] = 'scheduled';
                }
                $order->update($orderUpdates);

                // Update all items in this order
                foreach ($order->items as $item) {
                    $oldStatus = $item->status;
                    $itemUpdates = [
                        'assigned_to' => $idStr,
                        'assigned_user_name' => $installerName,
                        'assigned_at' => now(),
                    ];
                    if (!in_array($oldStatus, ['installed', 'completed'])) {
                        $itemUpdates['status'] = 'scheduled';
                    }
                    $item->update($itemUpdates);

                    if (Schema::hasTable('order_installations')) {
                        OrderInstallation::updateOrCreate(
                            ['order_item_id' => $item->id],
                            ['is_scheduled_assigned' => true]
                        );
                    }
                }

                // Log assignment
                OrderStatusLog::create([
                    'order_id' => $order->id,
                    'user_id' => $idStr,
                    'user_name' => $installerName,
                    'action' => 'installation_assigned',
                    'old_status' => $order->status,
                    'new_status' => 'scheduled',
                    'notes' => $payload['notes'] ?? "Order #{$order->order_number} assigned to installer {$installerName} (ID: {$idStr})",
                    'product_tags' => $item->product_tags ?? null,
                    'gift_wrap' => $item->gift_wrap ?? null,
                ]);

                return response()->json([
                    'status' => 'success',
                    'message' => "Order #{$order->order_number} successfully assigned to installer {$installerName}.",
                    'data' => [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'order_status' => $order->fresh()->status,
                        'installer_user_id' => is_numeric($idStr) ? (int) $idStr : $idStr,
                        'installer_name' => $installerName,
                        'assigned_at' => now()->toIso8601String(),
                    ],
                ], 200);
            });
        } catch (Exception $e) {
            Log::error('Error assigning installer to order: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to assign installer: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update installation status from mobile (e.g., scheduled, in_progress, installed, completed, cancelled).
     *
     * Route: POST /api/mobile/scheduled/update-status
     */
    public function updateStatus(Request $request, string|int|null $id = null): JsonResponse
    {
        $payload = array_merge($request->all(), is_array($request->json()?->all()) ? $request->json()->all() : []);
        if ($request->getContent()) {
            $decoded = json_decode($request->getContent(), true);
            if (is_array($decoded)) {
                $payload = array_merge($payload, $decoded);
            }
        }

        $validator = Validator::make($payload, [
            'status' => 'required|string',
            'order_id' => 'nullable',
            'order_number' => 'nullable|string',
            'item_id' => 'nullable',
            'order_item_id' => 'nullable',
            'line_item_id' => 'nullable',
            'notes' => 'nullable|string',
            'user_name' => 'nullable|string',
            'user_id' => 'nullable',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $rawStatus = trim((string) $payload['status']);
        $normalizedStatus = strtolower(str_replace(['-', ' '], '_', $rawStatus));
        // Support 'stated', 'started', 'start' -> normalize to 'in_progress'
        if ($normalizedStatus === 'stated' || $normalizedStatus === 'started' || $normalizedStatus === 'start') {
            $normalizedStatus = 'in_progress';
        } elseif ($normalizedStatus === 'complete' || $normalizedStatus === 'completed' || $normalizedStatus === 'finished' || $normalizedStatus === 'installed' || $normalizedStatus === 'install' || $normalizedStatus === 'done') {
            $normalizedStatus = 'completed';
        } elseif ($normalizedStatus === 'schedule') {
            $normalizedStatus = 'scheduled';
        }

        $orderId = $payload['order_id'] ?? $request->input('order_id');
        $orderNumber = $payload['order_number'] ?? $request->input('order_number');
        $itemId = $payload['item_id'] ?? $payload['order_item_id'] ?? $payload['line_item_id'] ?? $request->input('item_id');
        $targetId = $id ?? $itemId ?? $orderId;

        if (!$targetId && !$orderNumber) {
            return response()->json([
                'status' => 'error',
                'message' => 'Item ID, Order ID, or Order Number is required.',
            ], 422);
        }

        try {
            return DB::transaction(function () use ($request, $payload, $targetId, $itemId, $orderId, $orderNumber, $rawStatus, $normalizedStatus) {
                $orderItem = null;
                $order = null;
                $orderInstallation = null;

                // 1. If order_id or order_number is explicitly provided, resolve order first
                if ($orderId || $orderNumber) {
                    $cleanNum = $orderNumber ? trim(ltrim($orderNumber, '#')) : null;
                    $order = Order::where(function ($q) use ($orderId, $orderNumber, $cleanNum) {
                        if ($orderId) {
                            $q->orWhere('id', $orderId);
                        }
                        if ($orderNumber) {
                            $q->orWhere('order_number', $orderNumber)
                                ->orWhere('order_number', $cleanNum)
                                ->orWhere('order_number', '#' . $cleanNum);
                        }
                    })->first();
                }

                // 2. If itemId is explicitly provided, resolve item
                if ($itemId) {
                    $orderItem = OrderItem::where('id', $itemId)
                        ->orWhere('line_item_id', (string) $itemId)
                        ->first();
                    if ($orderItem && !$order) {
                        $order = Order::find($orderItem->order_id);
                    }
                }

                // 3. If targetId was provided (e.g. from URL /update-status/{id})
                if (!$orderItem && $targetId) {
                    // Check if targetId is an OrderInstallation ID
                    $inst = OrderInstallation::find($targetId);
                    if ($inst && $inst->orderItem) {
                        $orderItem = $inst->orderItem;
                        $orderInstallation = $inst;
                        if (!$order) {
                            $order = Order::find($orderItem->order_id);
                        }
                    }
                    if (!$orderItem) {
                        $orderItem = OrderItem::where('id', $targetId)
                            ->orWhere('line_item_id', (string) $targetId)
                            ->first();
                        if ($orderItem && !$order) {
                            $order = Order::find($orderItem->order_id);
                        }
                    }
                    if (!$order && !$orderItem) {
                        $order = Order::find($targetId);
                    }
                }

                // 4. If order is resolved but orderItem is still null, look for installable item within this order
                if ($order && !$orderItem) {
                    $orderItem = $order->items()->where('is_installable', true)->first()
                        ?? $order->items()->first();
                }

                if (!$orderItem && !$order) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Target order or order item not found.',
                    ], 404);
                }

                $oldStatus = $orderInstallation?->status ?? ($orderItem ? $orderItem->status : $order?->status);
                $validItemEnums = ['pending', 'picker_assigned', 'picked', 'packed', 'delivered', 'cancelled'];
                $validOrderEnums = ['pending', 'picking', 'picked', 'packing', 'packed', 'ready_to_assign', 'assigned_to_driver', 'out_for_delivery', 'delivered', 'completed', 'flagged', 'exchange', 'cancelled'];

                // 5. Update single item and its order_installations record
                if ($orderItem) {
                    if (in_array($normalizedStatus, $validItemEnums)) {
                        $orderItem->update(['status' => $normalizedStatus]);
                    }

                    if (Schema::hasTable('order_installations')) {
                        $orderInstallation = OrderInstallation::updateOrCreate(
                            ['order_item_id' => $orderItem->id],
                            [
                                'status' => $normalizedStatus,
                                'is_scheduled_assigned' => true,
                            ]
                        );
                    }

                    if (Schema::hasTable('technician_scheduled')) {
                        TechnicianScheduled::where('order_item_id', $orderItem->id)
                            ->update([
                                'status' => $normalizedStatus,
                                'completed_at' => ($normalizedStatus === 'installed' || $normalizedStatus === 'completed') ? now() : null,
                            ]);
                    }

                    if ($order) {
                        if (in_array($normalizedStatus, $validOrderEnums)) {
                            $order->update(['status' => $normalizedStatus]);
                        } elseif ($normalizedStatus === 'installed') {
                            $remaining = OrderItem::where('order_id', $order->id)
                                ->where(function ($remQ) {
                                    $remQ->whereDoesntHave('installation', function ($instQ) {
                                        $instQ->whereIn('status', ['installed', 'completed']);
                                    })
                                        ->whereNotIn('status', ['installed', 'completed', 'delivered']);
                                })
                                ->count();
                            if ($remaining === 0) {
                                $order->update(['status' => 'completed']);
                                DB::afterCommit(function () use ($order) {
                                    try {
                                        app(\App\Services\ShopifyService::class)->syncOrderFulfillmentToShopify($order, [
                                            'status' => 'completed',
                                            'action' => 'installation_completed',
                                        ]);
                                    } catch (\Throwable $e) {
                                    }
                                });
                            }
                        }
                    }
                } elseif ($order) {
                    // 6. Update whole order and all its items' order_installations records
                    if (in_array($normalizedStatus, $validOrderEnums)) {
                        $order->update(['status' => $normalizedStatus]);
                    } elseif ($normalizedStatus === 'installed') {
                        $order->update(['status' => 'completed']);
                    }

                    if (in_array($order->fresh()->status, ['completed', 'delivered'])) {
                        DB::afterCommit(function () use ($order) {
                            try {
                                app(\App\Services\ShopifyService::class)->syncOrderFulfillmentToShopify($order, [
                                    'status' => $order->status,
                                    'action' => 'installation_completed',
                                ]);
                            } catch (\Throwable $e) {
                            }
                        });
                    }

                    foreach ($order->items as $item) {
                        if (in_array($normalizedStatus, $validItemEnums)) {
                            $item->update(['status' => $normalizedStatus]);
                        }

                        if (Schema::hasTable('order_installations')) {
                            $orderInstallation = OrderInstallation::updateOrCreate(
                                ['order_item_id' => $item->id],
                                [
                                    'status' => $normalizedStatus,
                                    'is_scheduled_assigned' => true,
                                ]
                            );
                        }
                    }

                    if (Schema::hasTable('technician_scheduled')) {
                        TechnicianScheduled::where('order_id', $order->id)
                            ->update([
                                'status' => $normalizedStatus,
                                'completed_at' => ($normalizedStatus === 'installed' || $normalizedStatus === 'completed') ? now() : null,
                            ]);
                    }
                }

                $userName = $payload['user_name'] ?? $request->input('user_name') ?? (Auth::user()?->name ?? 'Mobile Installer');
                $userId = $payload['user_id'] ?? $request->input('user_id') ?? Auth::id();
                $notes = $payload['notes'] ?? $request->input('notes', "Installation status updated to {$normalizedStatus}" . ($rawStatus !== $normalizedStatus ? " (from '{$rawStatus}')" : ""));

                OrderStatusLog::create([
                    'order_id' => $order?->id ?? $orderItem?->order_id,
                    'order_item_id' => $orderItem?->id,
                    'user_id' => $userId,
                    'user_name' => $userName,
                    'action' => 'update_installation_status',
                    'old_status' => $oldStatus,
                    'new_status' => $normalizedStatus,
                    'notes' => $notes,
                ]);

                return response()->json([
                    'status' => 'success',
                    'message' => "Installation status updated to {$normalizedStatus}.",
                    'data' => [
                        'order_id' => $order?->id ?? $orderItem?->order_id,
                        'order_number' => $order?->order_number,
                        'order_status' => $order?->fresh()->status,
                        'order_item_id' => $orderItem?->id,
                        'item_status' => $orderItem?->fresh()->status,
                        'input_status' => $rawStatus,
                        'installation_status' => $normalizedStatus,
                        'is_in_progress' => in_array($normalizedStatus, ['in_progress', 'started', 'stated']),
                        'installation' => $orderInstallation ? [
                            'id' => $orderInstallation->id,
                            'order_item_id' => $orderInstallation->order_item_id,
                            'installation_type' => $orderInstallation->installation_type,
                            'installation_level' => $orderInstallation->installation_level,
                            'status' => $orderInstallation->status,
                            'is_scheduled_assigned' => (bool) $orderInstallation->is_scheduled_assigned,
                            'updated_at' => $orderInstallation->updated_at?->toIso8601String(),
                        ] : null,
                    ],
                ], 200);
            });
        } catch (Exception $e) {
            Log::error('Error updating installation status: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update installation status.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Upload multiple installed proof images and add notes for an order by order_number.
     *
     * Route: GET|POST /api/mobile/scheduled/installed-proof-upload
     *
     * @param Request $request
     * @return JsonResponse
     */
    /**
     * Upload installation proof images and store in installation_images table,
     * adding before_install_image and after_install_image fields (both nullable),
     * and storing status, notes, completed_at in order_installations table.
     *
     * Route: GET|POST /api/mobile/scheduled/installed-proof-upload
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function InstalledProofimageupload(Request $request): JsonResponse
    {
        try {
            $orderNumber = $request->input('order_number') ?? $request->query('order_number');
            $orderId = $request->input('order_id') ?? $request->query('order_id');
            $itemId = $request->input('item_id') ?? $request->input('order_item_id') ?? $request->input('line_item_id') ?? $request->query('item_id') ?? $request->query('order_item_id');

            // 1. Validate that order_number or order_id is provided
            if (empty($orderNumber) && empty($orderId)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Order number or order ID is required. Pass order_number (e.g. #1001 or 1001).',
                ], 422);
            }

            // 2. Resolve Target Order
            $cleanNum = $orderNumber ? trim(ltrim((string) $orderNumber, '#')) : null;
            $order = Order::with('items')->where(function ($q) use ($orderId, $orderNumber, $cleanNum) {
                if ($orderId) {
                    $q->orWhere('id', $orderId);
                }
                if ($orderNumber) {
                    $q->orWhere('order_number', $orderNumber)
                        ->orWhere('order_number', $cleanNum)
                        ->orWhere('order_number', '#' . $cleanNum);
                }
            })->first();

            if (!$order) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Order not found with order_number '{$orderNumber}'.",
                ], 404);
            }

            // Target item resolution
            $orderItems = $order->items;
            $targetItem = null;
            if (!empty($itemId)) {
                $targetItem = $orderItems->first(function ($it) use ($itemId) {
                    return (string) $it->id === (string) $itemId
                        || (string) $it->line_item_id === (string) $itemId;
                });
            }
            if (!$targetItem) {
                $targetItem = $orderItems->firstWhere('is_installable', true) ?? $orderItems->first();
            }

            // Resolve / ensure primary installation record
            $primaryInstallation = null;
            if ($targetItem) {
                $primaryInstallation = OrderInstallation::firstOrCreate(['order_item_id' => $targetItem->id]);
            } else {
                $primaryInstallation = OrderInstallation::whereIn('order_item_id', $orderItems->pluck('id'))->first();
            }

            // If GET request, return current installation details and installation_images records
            if ($request->isMethod('get')) {
                $storedImages = InstallationImage::where(function ($q) use ($order, $targetItem) {
                    $q->where('order_id', $order->id)
                        ->orWhere('order_number', $order->order_number);
                    if ($targetItem) {
                        $q->orWhere('order_item_id', $targetItem->id);
                    }
                })->latest()->get();

                $latestImage = $storedImages->first();

                $images = [];
                if ($latestImage?->before_install_image) {
                    $images[] = $latestImage->before_install_image;
                }
                if ($latestImage?->after_install_image) {
                    $images[] = $latestImage->after_install_image;
                }
                if (empty($images) && $primaryInstallation?->images) {
                    $images = is_array($primaryInstallation->images)
                        ? $primaryInstallation->images
                        : (json_decode($primaryInstallation->images, true) ?: [$primaryInstallation->images]);
                }

                $isInstalled = !empty($latestImage?->after_install_image) || in_array($primaryInstallation?->status, ['installed', 'completed']);
                $displayStatus = $isInstalled ? 'installed' : ($primaryInstallation?->status ?? 'in_progress');

                return response()->json([
                    'status' => 'success',
                    'message' => 'Installed proof details retrieved successfully.',
                    'data' => [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'customer_name' => $order->customer_name,
                        'status' => $displayStatus,
                        'order_item_id' => $targetItem?->id,
                        'notes' => $primaryInstallation?->notes ?? $latestImage?->notes,
                        'before_install_image' => $latestImage?->before_install_image,
                        'after_install_image' => $latestImage?->after_install_image,
                        'images_count' => count($images),
                        'images' => $images,
                        'completed_at' => $primaryInstallation?->completed_at?->toIso8601String(),
                        'installation' => $primaryInstallation,
                        'installation_images' => $storedImages,
                    ],
                ], 200);
            }

            // Setup upload directory
            $uploadDir = public_path('uploads/installations');
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $orderRef = preg_replace('/[^A-Za-z0-9_\-]/', '', $order->order_number) ?: 'order_' . $order->id;

            // Helper to save uploaded file or base64 data to local file and return asset URL
            $saveFileOrBase64 = function ($input, string $prefix) use ($uploadDir, $orderRef): ?string {
                if ($input instanceof \Illuminate\Http\UploadedFile && $input->isValid()) {
                    $ext = $input->getClientOriginalExtension() ?: 'jpg';
                    $filename = $prefix . '_' . $orderRef . '_' . time() . '_' . substr(md5(uniqid('', true)), 0, 6) . '.' . $ext;
                    $input->move($uploadDir, $filename);
                    return asset('uploads/installations/' . $filename);
                }
                if (is_string($input)) {
                    $trimmed = trim($input);
                    if (filter_var($trimmed, FILTER_VALIDATE_URL)) {
                        return $trimmed;
                    }
                    if (preg_match('/^data:image\/(\w+);base64,/', $trimmed, $typeMatches)) {
                        $b64Data = substr($trimmed, strpos($trimmed, ',') + 1);
                        $ext = strtolower($typeMatches[1]) ?: 'jpg';
                        $decodedData = base64_decode($b64Data);
                        if ($decodedData !== false) {
                            $filename = $prefix . '_' . $orderRef . '_' . time() . '_' . substr(md5(uniqid('', true)), 0, 6) . '.' . $ext;
                            file_put_contents($uploadDir . DIRECTORY_SEPARATOR . $filename, $decodedData);
                            return asset('uploads/installations/' . $filename);
                        }
                    }
                }
                return null;
            };

            // 3. Process before_install_image (nullable)
            $beforeInput = $request->file('before_install_image')
                ?? $request->file('before_image')
                ?? $request->file('before_installation_image')
                ?? $request->file('before')
                ?? $request->input('before_install_image')
                ?? $request->input('before_image')
                ?? $request->input('before_installation_image')
                ?? $request->input('before');

            $beforeImageUrl = $beforeInput ? $saveFileOrBase64($beforeInput, 'before_install') : null;

            // 4. Process after_install_image (nullable)
            $afterInput = $request->file('after_install_image')
                ?? $request->file('after_image')
                ?? $request->file('after_installation_image')
                ?? $request->file('after')
                ?? $request->input('after_install_image')
                ?? $request->input('after_image')
                ?? $request->input('after_installation_image')
                ?? $request->input('after');

            $afterImageUrl = $afterInput ? $saveFileOrBase64($afterInput, 'after_install') : null;

            // 5. Process any general / multiple uploaded proof images
            $generalImageUrls = [];
            $uploadedFiles = [];
            $fileKeys = ['images', 'photos', 'installed_images', 'files', 'image', 'photo', 'file', 'attachment', 'installed_image', 'proof_images'];

            foreach ($fileKeys as $fKey) {
                if ($request->hasFile($fKey)) {
                    $files = $request->file($fKey);
                    if (is_array($files)) {
                        foreach ($files as $f) {
                            if ($f instanceof \Illuminate\Http\UploadedFile && $f->isValid()) {
                                $uploadedFiles[] = $f;
                            }
                        }
                    } elseif ($files instanceof \Illuminate\Http\UploadedFile && $files->isValid()) {
                        $uploadedFiles[] = $files;
                    }
                }
            }

            foreach ($uploadedFiles as $index => $file) {
                $ext = $file->getClientOriginalExtension() ?: 'jpg';
                $filename = 'proof_' . $orderRef . '_' . time() . '_' . substr(md5(uniqid((string) $index, true)), 0, 6) . '.' . $ext;
                $file->move($uploadDir, $filename);
                $generalImageUrls[] = asset('uploads/installations/' . $filename);
            }

            // Also support input array / base64 array in 'images'
            $inputImages = $request->input('images')
                ?? $request->input('photos')
                ?? $request->input('installed_images')
                ?? $request->input('proof_images');

            if (!empty($inputImages)) {
                if (is_string($inputImages)) {
                    $decoded = json_decode($inputImages, true);
                    $inputImages = is_array($decoded) ? $decoded : [$inputImages];
                }
                if (is_array($inputImages)) {
                    foreach ($inputImages as $imgItem) {
                        $savedUrl = $saveFileOrBase64($imgItem, 'proof');
                        if ($savedUrl) {
                            $generalImageUrls[] = $savedUrl;
                        }
                    }
                }
            }

            // Combine all image URLs
            $allImageUrls = $generalImageUrls;
            if ($beforeImageUrl && !in_array($beforeImageUrl, $allImageUrls)) {
                $allImageUrls[] = $beforeImageUrl;
            }
            if ($afterImageUrl && !in_array($afterImageUrl, $allImageUrls)) {
                $allImageUrls[] = $afterImageUrl;
            }

            // 6. Notes Handling
            $notes = $request->input('notes')
                ?? $request->input('note')
                ?? $request->input('comment')
                ?? $request->input('comments')
                ?? $request->input('description')
                ?? $request->input('remarks');

            // 7. Status & Completed_at Updates
            $oldStatus = $primaryInstallation?->status ?? 'scheduled';
            $statusParam = $request->input('status') ?? $request->input('installation_status');
            $normalizedStatus = null;

            if ($statusParam) {
                $statusParam = strtolower(trim((string) $statusParam));
                if (in_array($statusParam, ['inprogress', 'in_progress', 'started', 'stated'])) {
                    $normalizedStatus = 'in_progress';
                } elseif (in_array($statusParam, ['installed', 'completed', 'done', 'finished', 'install', 'complete'])) {
                    $normalizedStatus = 'installed';
                } else {
                    $normalizedStatus = $statusParam;
                }
            } else {
                $normalizedStatus = !empty($afterImageUrl) ? 'installed' : 'in_progress';
            }

            // 8. Find existing record in installation_images table or create new if none exists
            // This ensures before_install_image and after_install_image are stored in the SAME row
            $installationImageQuery = InstallationImage::where(function ($q) use ($order) {
                $q->where('order_id', $order->id)
                    ->orWhere('order_number', $order->order_number);
            });

            if ($targetItem?->id) {
                $installationImageQuery->where('order_item_id', $targetItem->id);
            } elseif ($primaryInstallation?->id) {
                $installationImageQuery->where('order_installation_id', $primaryInstallation->id);
            }

            $installationImage = $installationImageQuery->latest()->first();

            if (!$installationImage) {
                $installationImage = new InstallationImage();
                $installationImage->order_id = $order->id;
                $installationImage->order_number = $order->order_number;
                $installationImage->order_item_id = $targetItem?->id;
                $installationImage->order_installation_id = $primaryInstallation?->id;
            } else {
                if (!$installationImage->order_id) {
                    $installationImage->order_id = $order->id;
                }
                if (!$installationImage->order_number) {
                    $installationImage->order_number = $order->order_number;
                }
                if (!$installationImage->order_item_id && $targetItem?->id) {
                    $installationImage->order_item_id = $targetItem->id;
                }
                if (!$installationImage->order_installation_id && $primaryInstallation?->id) {
                    $installationImage->order_installation_id = $primaryInstallation->id;
                }
            }

            // Only update before_install_image if a new one is provided in this request
            if ($beforeImageUrl !== null) {
                $installationImage->before_install_image = $beforeImageUrl;
            }
            // Only update after_install_image if a new one is provided in this request
            if ($afterImageUrl !== null) {
                $installationImage->after_install_image = $afterImageUrl;
            }
            if ($notes !== null) {
                $installationImage->notes = trim((string) $notes);
            }

            $installationImage->save();

            // Refresh combined images list including both before and after images
            if ($installationImage->before_install_image && !in_array($installationImage->before_install_image, $allImageUrls)) {
                $allImageUrls[] = $installationImage->before_install_image;
            }
            if ($installationImage->after_install_image && !in_array($installationImage->after_install_image, $allImageUrls)) {
                $allImageUrls[] = $installationImage->after_install_image;
            }

            if (!empty($installationImage->after_install_image) || !empty($afterImageUrl)) {
                $normalizedStatus = 'installed';
            }

            // 9. Store other fields in order_installations table
            if ($targetItem) {
                $targetItemsToUpdate = collect([$targetItem]);
            } else {
                $targetItemsToUpdate = $orderItems->where('is_installable', true);
                if ($targetItemsToUpdate->isEmpty()) {
                    $targetItemsToUpdate = $orderItems;
                }
            }

            $completedAt = ($normalizedStatus === 'completed') ? now() : null;

            foreach ($targetItemsToUpdate as $item) {
                $inst = OrderInstallation::firstOrCreate(['order_item_id' => $item->id]);

                $inst->status = $normalizedStatus;
                $inst->is_scheduled_assigned = true;

                if ($notes !== null) {
                    $inst->notes = trim((string) $notes);
                }
                if ($completedAt) {
                    $inst->completed_at = $completedAt;
                }
                if (!empty($allImageUrls)) {
                    $inst->images = array_values(array_unique($allImageUrls));
                }
                $inst->save();

                // Only update order item status to packed when actually completed
                if ($normalizedStatus === 'completed') {
                    $item->update(['status' => 'packed']);
                }
            }

            // 10. Update technician_scheduled if table exists
            if (Schema::hasTable('technician_scheduled')) {
                $techQuery = TechnicianScheduled::where('order_id', $order->id);
                if ($targetItem) {
                    $techQuery->orWhere('order_item_id', $targetItem->id);
                }
                $techQuery->update([
                    'status' => $normalizedStatus,
                    'notes' => $notes !== null ? trim((string) $notes) : null,
                    'images' => !empty($allImageUrls) ? array_values(array_unique($allImageUrls)) : null,
                    'completed_at' => $completedAt,
                ]);
            }

            // 11. Safe update for order status (respecting orders.status enum)
            $validOrderEnums = ['pending', 'picking', 'picked', 'packing', 'packed', 'ready_to_assign', 'assigned_to_driver', 'out_for_delivery', 'delivered', 'completed', 'flagged', 'exchange', 'cancelled'];
            if ($normalizedStatus === 'completed') {
                try {
                    $order->update(['status' => 'completed']);
                } catch (\Throwable $e) {
                    Log::warning('Could not update order status to completed: ' . $e->getMessage());
                }
            } elseif ($normalizedStatus === 'installed') {
                // Item is installed, but installation is not completed yet.
                // Do not modify orders table status.
            } elseif (in_array($normalizedStatus, $validOrderEnums)) {
                try {
                    $order->update(['status' => $normalizedStatus]);
                } catch (\Throwable $e) {
                    Log::warning("Could not update order status to {$normalizedStatus}: " . $e->getMessage());
                }
            }

            $userName = $request->input('user_name') ?? (Auth::user()?->name ?? 'Mobile Installer');
            $userId = $request->input('user_id') ?? Auth::id();

            if (in_array($order->fresh()->status, ['completed', 'delivered'])) {
                $instUser = $userName;
                DB::afterCommit(function () use ($order, $instUser) {
                    try {
                        app(\App\Services\ShopifyService::class)->syncOrderFulfillmentToShopify($order, [
                            'status' => $order->status,
                            'delivered_by' => $instUser,
                            'action' => 'installation_completed',
                        ]);
                    } catch (\Throwable $e) {
                    }
                });
            }

            // 12. Create audit log in order_status_logs
            $logAction = (!empty($beforeImageUrl) || !empty($afterImageUrl) || !empty($generalImageUrls))
                ? 'installed_proof_uploaded'
                : 'installation_notes_updated';

            OrderStatusLog::create([
                'order_id' => $order->id,
                'order_item_id' => $targetItem?->id,
                'user_id' => $userId,
                'user_name' => $userName,
                'action' => $logAction,
                'old_status' => $oldStatus,
                'new_status' => $normalizedStatus,
                'notes' => $notes ?? "Uploaded installation proof image(s) for Order #{$order->order_number}",
                'product_tags' => $item->product_tags ?? null,
                'gift_wrap' => $item->gift_wrap ?? null,
            ]);

            $isInstalled = !empty($installationImage->after_install_image) || !empty($afterImageUrl) || in_array($normalizedStatus, ['installed', 'completed']);
            $displayStatus = $isInstalled ? 'installed' : ($normalizedStatus ?: 'in_progress');

            return response()->json([
                'status' => 'success',
                'message' => 'Installed proof images and installation data saved successfully.',
                'data' => [
                    'status' => $displayStatus,
                ],
            ], 200);

        } catch (Exception $e) {
            Log::error('Error in InstalledProofimageupload: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request' => $request->except(['images', 'photos', 'proof_images']),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to upload installed proof images and save notes: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Start installation for multiple selected installation orders (Bulk Start Installation).
     * Moves selected orders to 'in_progress' status, updates order_installations and technician_scheduled records,
     * and records audit logs in order_status_logs. Once started, orders are removed from the main installation list.
     *
     * Route: POST|GET /api/mobile/scheduled/start-installation
     * Route: POST|GET /api/mobile/scheduled/start
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function startInstallation(Request $request): JsonResponse
    {
        try {
            $rawContent = $request->getContent();
            $decodedJson = !empty($rawContent) ? json_decode($rawContent, true) : null;

            $payload = array_merge($request->all(), is_array($request->json()?->all()) ? $request->json()->all() : []);
            if (is_array($decodedJson)) {
                $payload = array_merge($payload, $decodedJson);
            }

            // Collect selected order identifiers (IDs or numbers) from various formats (single or array)
            $orderIdentifiers = [];

            $collectItems = function ($input) use (&$orderIdentifiers, &$collectItems) {
                if ($input === null || $input === '') {
                    return;
                }
                if (is_array($input)) {
                    foreach ($input as $key => $val) {
                        if (is_array($val)) {
                            $idVal = $val['order_number'] ?? ($val['order_id'] ?? ($val['orderNumber'] ?? ($val['orderId'] ?? ($val['id'] ?? null))));
                            if ($idVal !== null && $idVal !== '') {
                                $orderIdentifiers[] = $idVal;
                            } else {
                                $collectItems($val);
                            }
                        } elseif ($val !== null && $val !== '') {
                            if (is_string($key) && in_array(strtolower($key), ['order_number', 'order_numbers', 'order_id', 'order_ids', 'order', 'orders', 'id', 'selected_orders', 'data'])) {
                                $collectItems($val);
                            } else {
                                $orderIdentifiers[] = $val;
                            }
                        }
                    }
                } elseif (is_string($input)) {
                    $trimmed = trim($input);
                    if ($trimmed === '') {
                        return;
                    }
                    if (
                        (str_starts_with($trimmed, '[') && str_ends_with($trimmed, ']')) ||
                        (str_starts_with($trimmed, '{') && str_ends_with($trimmed, '}'))
                    ) {
                        $inner = json_decode($trimmed, true);
                        if (is_array($inner)) {
                            $collectItems($inner);
                            return;
                        }
                    }
                    if (str_contains($trimmed, ',')) {
                        foreach (explode(',', $trimmed) as $part) {
                            $p = trim($part);
                            if ($p !== '') {
                                $orderIdentifiers[] = $p;
                            }
                        }
                    } else {
                        $orderIdentifiers[] = $trimmed;
                    }
                } elseif (is_numeric($input)) {
                    $orderIdentifiers[] = $input;
                }
            };

            // Support direct array in top-level JSON body: ["#1052", "#1053"] or [{"order_number": "#1052"}, ...]
            if (is_array($decodedJson)) {
                $collectItems($decodedJson);
            }

            // Support fields in payload/request (both array and string format): order_number, order_numbers, orders, order_id, order_ids, selected_orders
            $collectItems($payload['order_number'] ?? null);
            $collectItems($payload['order_numbers'] ?? null);
            $collectItems($payload['orders'] ?? null);
            $collectItems($payload['selected_orders'] ?? null);
            $collectItems($payload['order_ids'] ?? null);
            $collectItems($payload['order_id'] ?? null);
            $collectItems($payload['id'] ?? null);
            $collectItems($request->input('order_number'));
            $collectItems($request->input('order_numbers'));
            $collectItems($request->input('orders'));
            $collectItems($request->input('order_id'));
            $collectItems($request->input('order_ids'));
            $collectItems($request->query('order_number'));
            $collectItems($request->query('order_numbers'));

            $orderIdentifiers = array_values(array_unique(array_filter($orderIdentifiers)));

            if (empty($orderIdentifiers)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Please select at least one installation order to start (pass order_number array or order_id).',
                ], 422);
            }

            // Resolve installer details
            $installerUserId = $payload['installer_user_id']
                ?? $payload['installer_id']
                ?? $payload['user_id']
                ?? $request->input('installer_user_id')
                ?? $request->input('installer_id')
                ?? $request->input('user_id')
                ?? $request->route('installer_userid')
                ?? Auth::id();

            $installerUser = $installerUserId ? User::find($installerUserId) : null;
            $installerUserName = $payload['user_name']
                ?? $payload['installer_name']
                ?? $request->input('user_name')
                ?? $request->input('installer_name')
                ?? ($installerUser?->name ?? (Auth::user()?->name ?? 'Mobile Installer'));

            if (!$installerUserName && $installerUserId && class_exists(InstallationLevelController::class)) {
                $tech = collect(InstallationLevelController::TECHNICIANS)
                    ->first(function ($t) use ($installerUserId) {
                        return (string) ($t['id'] ?? '') === (string) $installerUserId
                            || (string) ($t['technician_id'] ?? '') === (string) $installerUserId;
                    });
                if ($tech) {
                    $installerUserName = $tech['name'] ?? ($tech['technician_name'] ?? null);
                }
            }

            $notes = $payload['notes'] ?? $request->input('notes', 'Installation started by mobile installer');

            $startedOrders = [];
            $failedOrders = [];

            DB::transaction(function () use ($orderIdentifiers, $installerUserId, $installerUserName, $notes, &$startedOrders, &$failedOrders) {
                foreach ($orderIdentifiers as $identifier) {
                    $idStr = trim((string) $identifier);
                    $cleanNum = ltrim($idStr, '#');

                    $order = Order::with(['items.installation'])
                        ->where(function ($q) use ($idStr, $cleanNum) {
                            $q->where('id', $idStr)
                                ->orWhere('order_number', $idStr)
                                ->orWhere('order_number', $cleanNum)
                                ->orWhere('order_number', '#' . $cleanNum);
                        })->first();

                    if (!$order) {
                        $failedOrders[] = [
                            'identifier' => $identifier,
                            'reason' => "Order '{$identifier}' not found.",
                        ];
                        continue;
                    }

                    $oldStatus = $order->status;

                    // 1. Update Order status to 'in_progress'
                    try {
                        $order->status = 'in_progress';
                        if (!empty($installerUserId) && empty($order->assigned_to)) {
                            $order->assigned_to = $installerUserId;
                            $order->assigned_user_name = $installerUserName;
                        }
                        $order->save();
                    } catch (\Throwable $e) {
                        Log::warning("Could not update orders.status to in_progress for order #{$order->order_number}: " . $e->getMessage());
                    }

                    // 2. Update installable items and order_installations table
                    $installableItems = $order->items->where('is_installable', true);
                    if ($installableItems->isEmpty()) {
                        $installableItems = $order->items;
                    }

                    foreach ($installableItems as $item) {
                        if (Schema::hasTable('order_installations')) {
                            OrderInstallation::updateOrCreate(
                                ['order_item_id' => $item->id],
                                [
                                    'status' => 'in_progress',
                                    'is_scheduled_assigned' => true,
                                    'notes' => $notes,
                                ]
                            );
                        }
                    }

                    // 3. Update technician_scheduled if table exists
                    if (Schema::hasTable('technician_scheduled')) {
                        TechnicianScheduled::where('order_id', $order->id)
                            ->update([
                                'status' => 'in_progress',
                            ]);
                    }

                    // 4. Audit log in order_status_logs
                    OrderStatusLog::create([
                        'order_id' => $order->id,
                        'user_id' => $installerUserId,
                        'user_name' => $installerUserName,
                        'action' => 'start_installation',
                        'old_status' => $oldStatus,
                        'new_status' => 'in_progress',
                        'notes' => $notes ?: "Installation started for Order #{$order->order_number}",
                    ]);

                    $startedOrders[] = [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'customer_name' => $order->customer_name ?? 'N/A',
                        'customer_phone' => $order->customer_phone ?? 'N/A',
                        'delivery_address' => $order->delivery_address ?? 'N/A',
                        'total_amount' => (float) $order->total_amount,
                        'old_status' => $oldStatus,
                        'status' => 'in_progress',
                        'order_status' => 'in_progress',
                        'installation_status' => 'in_progress',
                        'installer_user_id' => $installerUserId ? (is_numeric($installerUserId) ? (int) $installerUserId : $installerUserId) : null,
                        'installer_name' => $installerUserName,
                        'installable_items_count' => $installableItems->count(),
                        'started_at' => now()->toIso8601String(),
                    ];
                }
            });

            if (empty($startedOrders)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No orders could be started.',
                    'failed_orders' => $failedOrders,
                ], 400);
            }

            $response = [
                'status' => 'success',
                'message' => 'Installation started successfully for ' . count($startedOrders) . ' order(s).',
                'installer_user_id' => $installerUserId ? (is_numeric($installerUserId) ? (int) $installerUserId : $installerUserId) : null,
            ];

            if ($request->boolean('verbose') || $request->boolean('include_data')) {
                $response['installer_name'] = $installerUserName;
                $response['started_count'] = count($startedOrders);
                $response['failed_count'] = count($failedOrders);
                $response['data'] = $startedOrders;
                $response['orders'] = $startedOrders;
                $response['failed_orders'] = $failedOrders;
            }

            return response()->json($response, 200);

        } catch (Exception $e) {
            Log::error('Error in startInstallation: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to start installation: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display all orders that have been started by the mobile user (Started Installation list).
     *
     * Route: GET|POST /api/mobile/scheduled/started/{installer_userid?}
     * Route: GET|POST /api/mobile/scheduled/started-installations/{installer_userid?}
     * Route: GET|POST /api/mobile/scheduled/started-list/{installer_userid?}
     *
     * @param Request $request
     * @param int|string|null $installer_userid
     * @return JsonResponse
     */
    public function getStartedInstallationList(Request $request, $installer_userid = null): JsonResponse
    {
        try {
            // 1. Resolve installer user ID
            $resolvedId = $installer_userid
                ?? $request->route('installer_userid')
                ?? $request->input('installer_userid')
                ?? $request->input('installer_id')
                ?? $request->input('user_id')
                ?? $request->query('installer_userid')
                ?? $request->query('installer_id')
                ?? $request->query('user_id')
                ?? Auth::id();

            if (empty($resolvedId)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Installer user ID is required. Pass installer_userid in the URL path or request body.',
                ], 422);
            }

            $idStr = trim((string) $resolvedId);

            // 2. Resolve installer details
            $installerUser = User::find($idStr);
            $installerName = $installerUser?->name;

            if (!$installerName && class_exists(InstallationLevelController::class)) {
                $tech = collect(InstallationLevelController::TECHNICIANS)
                    ->first(function ($t) use ($idStr) {
                        return (string) ($t['id'] ?? '') === $idStr
                            || (string) ($t['technician_id'] ?? '') === $idStr;
                    });
                if ($tech) {
                    $installerName = $tech['name'] ?? ($tech['technician_name'] ?? null);
                }
            }

            // 3. Query orders with installable items assigned to this installer
            $ordersQuery = Order::with([
                'items.installation',
                'items.installationImages',
                'assignedUser',
                'technicianSchedules',
                'payment',
                'installationImages',
                'discrepancies',
                'logs' => function ($q) {
                    $q->latest()->limit(10);
                }
            ]);

            // Filter orders to only those having installable items
            $ordersQuery->whereHas('items', function ($iq) {
                $iq->where('is_installable', true);
            });

            // Filter for orders where this installer is assigned (Admin or 'all' can view all)
            $isAdmin = strtolower($installerUser?->role ?? '') === 'admin' || $idStr === '1';

            if ($idStr !== 'all' && !$isAdmin && !filter_var($request->query('all', false), FILTER_VALIDATE_BOOLEAN)) {
                $ordersQuery->where(function ($q) use ($idStr, $installerName) {
                    $q->whereHas('items', function ($iq) use ($idStr, $installerName) {
                        $iq->where('is_installable', true);
                        $iq->where(function ($sub) use ($idStr, $installerName) {
                            $sub->where('assigned_to', $idStr);
                            if (!empty($installerName)) {
                                $sub->orWhere('assigned_user_name', $installerName);
                            }
                        });
                    })
                        ->orWhere('assigned_to', $idStr)
                        ->orWhereHas('technicianSchedules', function ($tq) use ($idStr, $installerName) {
                            $tq->where('technician_id', $idStr);
                            if (!empty($installerName)) {
                                $tq->orWhere('technician_name', $installerName);
                            }
                        })
                        ->orWhereHas('logs', function ($lq) use ($idStr) {
                            $lq->where('user_id', $idStr)
                                ->whereIn('action', [
                                    'start_installation',
                                    'installation_started',
                                    'scheduled_installation',
                                    'installation_assigned',
                                    'scheduled_installation_mobile',
                                    'update_installation_status',
                                    'technician_scheduled'
                                ]);
                        });

                    if (!empty($installerName)) {
                        $q->orWhere('assigned_user_name', $installerName);
                    }
                });
            }

            // 4. Filter for STARTED / IN_PROGRESS / INSTALLED status
            // The item is installed, but once the status is completed, then only remove from the list
            $ordersQuery->where('status', '!=', 'completed')
                ->where(function ($q) {
                    $q->whereIn('status', ['in_progress', 'started', 'stated', 'installed'])
                        ->orWhereHas('items', function ($iq) {
                            $iq->where('is_installable', true)
                                ->where(function ($iiq) {
                                    $iiq->whereIn('status', ['in_progress', 'started', 'stated', 'installed'])
                                        ->orWhereHas('installation', function ($instQ) {
                                            $instQ->whereIn('status', ['in_progress', 'started', 'stated', 'installed']);
                                        });
                                });
                        });
                });

            // Optional keyword search
            if ($request->filled('search')) {
                $search = trim($request->input('search'));
                $cleanSearch = trim(ltrim($search, '#'));
                $ordersQuery->where(function ($q) use ($search, $cleanSearch) {
                    $q->where('order_number', 'like', "%{$search}%")
                        ->orWhere('order_number', 'like', "%{$cleanSearch}%")
                        ->orWhere('customer_name', 'like', "%{$search}%")
                        ->orWhere('customer_phone', 'like', "%{$search}%")
                        ->orWhereHas('items', function ($iq) use ($search) {
                            $iq->where('is_installable', true)
                                ->where(function ($piq) use ($search) {
                                    $piq->where('product_name', 'like', "%{$search}%")
                                        ->orWhere('product_code', 'like', "%{$search}%");
                                });
                        });
                });
            }

            $orders = $ordersQuery->orderBy('updated_at', 'desc')->get();

            // 5. Format results in the exact mobile payload structure
            $formattedOrders = $orders->map(function ($order) use ($idStr, $installerName, $request) {
                $installableItems = $order->items->filter(function ($item) {
                    return (bool) ($item->is_installable ?? false);
                });
                $allFormattedItems = $installableItems->map(function ($item) use ($idStr, $installerName, $order) {
                    $installation = $item->installation;
                    $isInstallableBool = (bool) ($item->is_installable ?? false);

                    $latestImg = $item->installationImages?->sortByDesc('id')->first()
                        ?? InstallationImage::where('order_item_id', $item->id)->latest()->first()
                        ?? ($order->installationImages?->sortByDesc('id')->first());

                    $beforeImage = $latestImg?->before_install_image;
                    $afterImage = $latestImg?->after_install_image;

                    $instImages = is_array($installation?->images)
                        ? $installation->images
                        : (json_decode($installation?->images ?? '[]', true) ?: []);

                    if (!$beforeImage && !empty($instImages[0])) {
                        $beforeImage = $instImages[0];
                    }
                    if (!$afterImage && !empty($instImages[1])) {
                        $afterImage = $instImages[1];
                    }

                    $unitPrice = (float) $item->unit_price;
                    $qty = (int) $item->quantity;
                    $sku = $item->product_code ?: ($item->barcode ?: "SKU-{$item->id}");

                    $itemAssignedTo = $item->assigned_to ? (string) $item->assigned_to : null;
                    $itemInstallerId = $itemAssignedTo
                        ? (is_numeric($itemAssignedTo) ? (int) $itemAssignedTo : $itemAssignedTo)
                        : ($order->assigned_to ? (is_numeric($order->assigned_to) ? (int) $order->assigned_to : $order->assigned_to) : (is_numeric($idStr) ? (int) $idStr : $idStr));

                    $itemInstallerName = $item->assigned_user_name
                        ?: ($item->assignedUser?->name
                            ?: ($order->assigned_user_name ?? $installerName));

                    if (!$itemInstallerName && $itemInstallerId && class_exists(InstallationLevelController::class)) {
                        $tech = collect(InstallationLevelController::TECHNICIANS)
                            ->first(function ($t) use ($itemInstallerId) {
                                return (string) ($t['id'] ?? '') === (string) $itemInstallerId
                                    || (string) ($t['technician_id'] ?? '') === (string) $itemInstallerId;
                            });
                        if ($tech) {
                            $itemInstallerName = $tech['name'] ?? ($tech['technician_name'] ?? null);
                        }
                    }

                    if (!$itemInstallerName && $itemInstallerId) {
                        $itemInstallerName = 'Installer #' . $itemInstallerId;
                    }

                    $isAssignedToThisInstaller = ((string) $item->assigned_to === $idStr)
                        || (!empty($installerName) && $item->assigned_user_name === $installerName)
                        || (empty($item->assigned_to) && ((string) $order->assigned_to === $idStr || (!empty($installerName) && $order->assigned_user_name === $installerName)));

                    $isInstalled = !empty($afterImage) || in_array($installation?->status, ['completed', 'installed']);

                    $installationPayload = $installation ? [
                        'id' => $installation->id,
                        'order_item_id' => $installation->order_item_id,
                        'installation_type' => $installation->installation_type,
                        'installation_level' => $installation->installation_level,
                        'status' => $isInstalled ? 'installed' : 'in_progress',
                        'notes' => $installation->notes ?? null,
                        'completed_at' => $installation->completed_at?->toIso8601String(),
                        'is_scheduled_assigned' => (bool) $installation->is_scheduled_assigned,
                        'created_at' => $installation->created_at?->toIso8601String(),
                        'updated_at' => $installation->updated_at?->toIso8601String(),
                    ] : null;

                    $itemData = [
                        'id' => $item->id,
                        'order_id' => $item->order_id,
                        'line_item_id' => $item->line_item_id,
                        'product_name' => $item->product_name,
                        'sku' => $sku,
                        'barcode' => $item->barcode,
                        'image' => $item->image,
                        'quantity' => $qty,
                        'unit_price' => $unitPrice,
                        'total_price' => (float) ($unitPrice * $qty),
                        'currency' => 'QAR',
                        'status' => $isInstalled ? 'installed' : 'in_progress',
                        'installation_proof_photos' => [
                            'before_install_image' => $beforeImage,
                            'after_install_image' => $afterImage,
                        ],
                        'is_installed' => $isInstalled,
                    ];

                    if ($installationPayload !== null) {
                        $itemData['installation'] = $installationPayload;
                    }

                    return $itemData;
                })->values();

                $isOrderAssignedToMe = ((string) $order->assigned_to === $idStr)
                    || (!empty($installerName) && $order->assigned_user_name === $installerName)
                    || $allFormattedItems->contains('is_assigned_to_me', true);

                $orderInstallerId = $order->assigned_to
                    ? (is_numeric($order->assigned_to) ? (int) $order->assigned_to : $order->assigned_to)
                    : ($allFormattedItems->firstWhere('assigned_installer_id')['assigned_installer_id'] ?? (is_numeric($idStr) ? (int) $idStr : $idStr));

                $orderInstallerName = $order->assigned_user_name
                    ?? ($allFormattedItems->firstWhere('assigned_installer_name')['assigned_installer_name'] ?? $installerName);

                // Shift text from technician schedule (e.g. "Shift: 9-10 Mar 2026")
                $techSchedule = $order->technicianSchedules?->first();
                $shiftText = null;
                if ($techSchedule) {
                    if (!empty($techSchedule->scheduled_time)) {
                        $shiftText = 'Shift: ' . $techSchedule->scheduled_time;
                    } elseif (!empty($techSchedule->scheduled_date)) {
                        $shiftText = 'Shift: ' . \Carbon\Carbon::parse($techSchedule->scheduled_date)->format('j M Y');
                    }
                }
                if (!$shiftText) {
                    $shiftText = 'Shift: ' . ($order->created_at ? $order->created_at->format('j M Y') : '9-10 Mar 2026');
                }

                // Payment Status
                $rawPaymentStatus = $order->payment_status ?: ($order->payment?->payment_status ?: 'paid');
                $paymentStatusFormatted = ucfirst(strtolower(str_replace(['_', '-'], ' ', (string) $rawPaymentStatus)));
                $isPaid = strtolower($rawPaymentStatus) === 'paid';
                $paymentMethod = $order->payment_method ?: ($order->payment?->payment_method ?: 'Card');

                // Financial calculations
                $subtotal = (float) $allFormattedItems->sum(function ($it) {
                    return (float) ($it['unit_price'] * $it['quantity']);
                });
                $totalAmount = (float) ($order->total_amount ?: $subtotal);
                $currency = $order->payment?->currency ?: 'QAR';

                // Date Formatting: e.g. "Nov 5 | 08:35" and relative "1h 12m ago"
                $orderDateFormatted = $order->created_at ? $order->created_at->format('M j | H:i') : null;
                $timeAgo = $order->created_at ? $order->created_at->diffForHumans() : null;

                // Contact & WhatsApp URL
                $cleanPhone = preg_replace('/[^0-9]/', '', (string) $order->customer_phone);
                $whatsappUrl = $cleanPhone ? "https://wa.me/{$cleanPhone}" : null;

                // Order summary breakdown
                $orderSummary = [
                    'subtotal' => $subtotal,
                    'discount' => 0.00,
                    'shipping' => 0.00,
                    'total' => $totalAmount,
                    'currency' => $currency,
                ];

                $isOrderFlagged = (bool) (
                    $order->status === 'flagged'
                    || $allFormattedItems->contains('status', 'flagged')
                    || $order->items->contains('is_flagged', true)
                );

                $flagReason = null;
                if ($isOrderFlagged) {
                    $latestDisc = $order->discrepancies?->sortByDesc('id')->first()
                        ?? OrderItemDiscrepancy::where('order_id', $order->id)->latest()->first();
                    $flagReason = $latestDisc?->issue_type
                        ?: ($order->items->firstWhere('flag_reason', '!=', null)?->flag_reason
                            ?: ($order->flag_reason ?? 'Customer Unavailable'));
                }

                $orderData = [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                ];

                if ($isOrderFlagged) {
                    $orderData['status'] = 'flagged';
                    $orderData['reason'] = $flagReason;
                }

                $orderData = array_merge($orderData, [
                    // Payment details
                    'payment' => [
                        'payment_status' => $paymentStatusFormatted,
                        'payment_method' => $paymentMethod,
                        'currency' => $currency,
                        'total' => $totalAmount,
                        'paid_amount' => $isPaid ? $totalAmount : (float) ($order->payment?->paid_amount ?? 0),
                    ],

                    // Customer & Contact
                    'customer_name' => $order->customer_name ?? 'Customer',
                    'customer_phone' => $order->customer_phone ?? 'N/A',
                    'delivery_address' => $order->delivery_address ?? 'N/A',
                    'latitude' => 25.286106,
                    'longitude' => 51.534817,

                    // Delivery receipt (Signed slip / Proof of delivery Optional)
                    'delivery_receipt' => null,

                    // Order Summary breakdown (Subtotal, Discount, Shipping, Total)
                    'order_summary' => $orderSummary,

                    'created_at' => $order->created_at?->toIso8601String(),
                    'updated_at' => $order->updated_at?->toIso8601String(),
                    'items' => $allFormattedItems,
                ]);

                return $orderData;
            })->values();

            return response()->json([
                'status' => 'success',
                'message' => 'Started installation orders retrieved successfully.',
                'count' => $formattedOrders->count(),
                'data' => $formattedOrders,
            ], 200);

        } catch (Exception $e) {
            Log::error('Error in getStartedInstallationList: ' . $e->getMessage(), [
                'installer_userid' => $installer_userid,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve started installation list: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Flag an installation order with issue reason, optional comments, and optional attached photo.
     *
     * Supported issue reasons from mobile screen:
     * - Customer Unavailable
     * - Wrong Address / Location
     * - Customer Rescheduled
     * - Site Not Ready for Installation
     * - Item Damaged / Defective
     * - Other Issue
     *
     * Route: POST|GET /api/mobile/scheduled/flag
     * Route: POST|GET /api/mobile/scheduled/flag-order
     * Route: POST|GET /api/mobile/scheduled/flag-installation
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function flagInstallation(Request $request): JsonResponse
    {
        try {
            $payload = array_merge($request->all(), is_array($request->json()?->all()) ? $request->json()->all() : []);
            if ($request->getContent()) {
                $decoded = json_decode($request->getContent(), true);
                if (is_array($decoded)) {
                    $payload = array_merge($payload, $decoded);
                }
            }

            // Extract order identifier
            $orderNumberRaw = $payload['order_number']
                ?? $request->input('order_number')
                ?? $payload['orderNumber']
                ?? ($payload['order_numbers'][0] ?? null);

            if (is_array($orderNumberRaw)) {
                $orderNumberRaw = $orderNumberRaw[0] ?? '';
            }
            $orderNumber = trim((string) $orderNumberRaw);
            $orderId = $payload['order_id'] ?? $request->input('order_id') ?? $payload['orderId'] ?? null;
            $itemId = $payload['item_id'] ?? $payload['order_item_id'] ?? $request->input('item_id') ?? $request->input('order_item_id') ?? null;

            if (empty($orderNumber) && empty($orderId)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'The order_number or order_id parameter is required.',
                ], 422);
            }

            // Resolve Reason / Issue Type
            $reason = trim((string) (
                $payload['reason']
                ?? $payload['issue_reason']
                ?? $payload['issue_type']
                ?? $payload['flag_reason']
                ?? $request->input('reason')
                ?? $request->input('issue_reason')
                ?? $request->input('issue_type')
                ?? $request->input('flag_reason')
                ?? 'Customer Unavailable'
            ));

            if (empty($reason)) {
                $reason = 'Customer Unavailable';
            }

            // Additional Comments (Optional)
            $comment = trim((string) (
                $payload['comment']
                ?? $payload['comments']
                ?? $payload['additional_comments']
                ?? $payload['notes']
                ?? $request->input('comment')
                ?? $request->input('comments')
                ?? $request->input('additional_comments')
                ?? $request->input('notes')
                ?? ''
            ));

            // Find Order
            $order = null;
            if (!empty($orderId)) {
                $order = Order::with('items')->find($orderId);
            }
            if (!$order && !empty($orderNumber)) {
                $cleanNum = ltrim($orderNumber, '#');
                $order = Order::with('items')
                    ->where('order_number', $orderNumber)
                    ->orWhere('order_number', $cleanNum)
                    ->orWhere('order_number', '#' . $cleanNum)
                    ->orWhere('id', $orderNumber)
                    ->first();
            }

            if (!$order) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Order '{$orderNumber}' not found.",
                ], 404);
            }

            // Target Item (if specified or installable item)
            $targetItem = null;
            if ($itemId) {
                $targetItem = $order->items->where('id', $itemId)->first();
            }
            if (!$targetItem) {
                $targetItem = $order->items->where('is_installable', true)->first()
                    ?? $order->items->first();
            }

            // Photo / Attachment handling
            $photoUrl = $payload['photo_url']
                ?? $payload['image_url']
                ?? $request->input('photo_url')
                ?? $request->input('image_url')
                ?? null;

            $fileKey = null;
            if ($request->hasFile('photo')) {
                $fileKey = 'photo';
            } elseif ($request->hasFile('file')) {
                $fileKey = 'file';
            } elseif ($request->hasFile('image')) {
                $fileKey = 'image';
            } elseif ($request->hasFile('attachment')) {
                $fileKey = 'attachment';
            } elseif ($request->hasFile('proof')) {
                $fileKey = 'proof';
            }

            if ($fileKey && $request->file($fileKey)->isValid()) {
                $uploadedFile = $request->file($fileKey);
                $uploadDir = public_path('uploads/installation_flags');
                if (!file_exists($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                $ext = $uploadedFile->getClientOriginalExtension() ?: 'jpg';
                $filename = 'flag_' . preg_replace('/[^A-Za-z0-9_\-]/', '', $order->order_number) . '_' . time() . '_' . substr(md5(uniqid('', true)), 0, 6) . '.' . $ext;
                $uploadedFile->move($uploadDir, $filename);
                $photoUrl = asset('uploads/installation_flags/' . $filename);
            } elseif (!empty($payload['photo']) || !empty($payload['image']) || !empty($payload['attach_photo'])) {
                $rawInputPhoto = $payload['photo'] ?? ($payload['image'] ?? $payload['attach_photo']);
                if (is_string($rawInputPhoto) && preg_match('/^data:image\/(\w+);base64,/', $rawInputPhoto, $matches)) {
                    $uploadDir = public_path('uploads/installation_flags');
                    if (!file_exists($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }
                    $ext = strtolower($matches[1]) ?: 'jpg';
                    $b64 = substr($rawInputPhoto, strpos($rawInputPhoto, ',') + 1);
                    $decoded = base64_decode($b64);
                    if ($decoded !== false) {
                        $filename = 'flag_' . preg_replace('/[^A-Za-z0-9_\-]/', '', $order->order_number) . '_' . time() . '_' . substr(md5(uniqid('', true)), 0, 6) . '.' . $ext;
                        file_put_contents($uploadDir . DIRECTORY_SEPARATOR . $filename, $decoded);
                        $photoUrl = asset('uploads/installation_flags/' . $filename);
                    }
                } elseif (is_string($rawInputPhoto) && filter_var($rawInputPhoto, FILTER_VALIDATE_URL)) {
                    $photoUrl = $rawInputPhoto;
                }
            }

            // Resolve installer user
            $installerUserId = $payload['installer_user_id']
                ?? $payload['installer_id']
                ?? $payload['user_id']
                ?? $request->input('installer_user_id')
                ?? $request->input('installer_id')
                ?? $request->input('user_id')
                ?? Auth::id()
                ?? $order->assigned_to;

            $installerUser = $installerUserId ? User::find($installerUserId) : null;
            $installerUserName = $payload['user_name']
                ?? $payload['installer_name']
                ?? $request->input('user_name')
                ?? $request->input('installer_name')
                ?? ($installerUser?->name ?? (Auth::user()?->name ?? ($order->assigned_user_name ?? 'Mobile Installer')));

            $oldOrderStatus = $order->status;
            $discrepancy = null;

            DB::transaction(function () use ($order, $targetItem, $reason, $comment, $photoUrl, $installerUserId, $installerUserName, $oldOrderStatus, &$discrepancy) {
                // 1. Create OrderItemDiscrepancy record
                $discrepancy = OrderItemDiscrepancy::create([
                    'order_id' => $order->id,
                    'order_item_id' => $targetItem ? $targetItem->id : ($order->items->first()?->id ?? $order->id),
                    'user_id' => $installerUserId,
                    'user_name' => $installerUserName,
                    'issue_type' => $reason,
                    'comment' => $comment,
                    'status' => 'open',
                    'photo_url' => $photoUrl,
                ]);

                // 2. Create FlagImage record if table exists
                if (Schema::hasTable('flag_images')) {
                    try {
                        FlagImage::create([
                            'order_id' => $order->id,
                            'order_number' => $order->order_number,
                            'order_item_id' => $targetItem?->id,
                            'line_item_id' => $targetItem?->line_item_id,
                            'discrepancy_id' => $discrepancy->id,
                            'image_url' => $photoUrl,
                            'photo_url' => $photoUrl,
                            'issue_type' => $reason,
                            'comment' => $comment,
                            'user_id' => $installerUserId,
                            'user_name' => $installerUserName,
                        ]);
                    } catch (\Throwable $e) {
                    }
                }

                // 3. Mark items as flagged in order_items table
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

                // 4. Update order_installations status if table exists
                if (Schema::hasTable('order_installations')) {
                    $instQuery = OrderInstallation::whereIn('order_item_id', $order->items->pluck('id'));
                    if ($targetItem) {
                        $instQuery = OrderInstallation::where('order_item_id', $targetItem->id);
                    }
                    $instQuery->update([
                        'status' => 'flagged',
                    ]);
                }

                // 5. Update technician_scheduled table if exists
                if (Schema::hasTable('technician_scheduled')) {
                    TechnicianScheduled::where('order_id', $order->id)
                        ->update([
                            'status' => 'flagged',
                        ]);
                }

                // 6. Update order status to flagged
                try {
                    $order->update([
                        'status' => 'flagged',
                    ]);
                } catch (\Throwable $e) {
                    Log::warning("Could not set orders.status to flagged: " . $e->getMessage());
                }

                // 7. Audit log in order_status_logs
                OrderStatusLog::create([
                    'order_id' => $order->id,
                    'order_item_id' => $targetItem ? $targetItem->id : null,
                    'user_id' => $installerUserId,
                    'user_name' => $installerUserName,
                    'action' => 'installation_flagged_order',
                    'old_status' => $oldOrderStatus,
                    'new_status' => 'flagged',
                    'notes' => "Order #{$order->order_number} flagged by installer {$installerUserName}. Reason: {$reason}" . ($comment ? ". Details: {$comment}" : ""),
                ]);
            });

            return response()->json([
                'status' => 'success',
                'message' => "Order {$order->order_number} flagged successfully.",
                'data' => [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'order_status' => 'flagged',
                    'status' => 'flagged',
                    'reason' => $reason,
                    'issue_reason' => $reason,
                    'issue_type' => $reason,
                    'comment' => $comment,
                    'photo_url' => $photoUrl,
                    'discrepancy_id' => $discrepancy?->id,
                    'installer_user_id' => $installerUserId ? (is_numeric($installerUserId) ? (int) $installerUserId : $installerUserId) : null,
                    'installer_name' => $installerUserName,
                    'flagged_at' => now()->toIso8601String(),
                ],
            ], 200);

        } catch (Exception $e) {
            Log::error('Error in flagInstallation: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to flag order: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Retrieve all flagged installation orders for a particular installer.
     *
     * Route: GET|POST /api/mobile/scheduled/flagged/{installer_userid?}
     *
     * @param Request $request
     * @param int|string|null $installer_userid
     * @return JsonResponse
     */
    public function getFlaggedInstallations(Request $request, $installer_userid = null): JsonResponse
    {
        try {
            $resolvedId = $installer_userid
                ?? $request->route('installer_userid')
                ?? $request->input('installer_userid')
                ?? $request->input('installer_id')
                ?? $request->input('user_id')
                ?? $request->query('installer_userid')
                ?? $request->query('installer_id')
                ?? $request->query('user_id')
                ?? Auth::id();

            if (empty($resolvedId)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Installer user ID is required.',
                ], 422);
            }

            $idStr = trim((string) $resolvedId);

            $installerUser = User::find($idStr);
            $installerName = $installerUser?->name;

            $ordersQuery = Order::with([
                'items.installation',
                'items.installationImages',
                'assignedUser',
                'technicianSchedules',
                'payment',
                'discrepancies',
                'logs' => function ($q) {
                    $q->latest()->limit(10);
                }
            ]);

            $ordersQuery->whereHas('items', function ($iq) {
                $iq->where('is_installable', true);
            });

            if ($idStr !== 'all' && !filter_var($request->query('all', false), FILTER_VALIDATE_BOOLEAN)) {
                $ordersQuery->where(function ($q) use ($idStr, $installerName) {
                    $q->where('assigned_to', $idStr)
                        ->orWhereHas('items', function ($iq) use ($idStr, $installerName) {
                            $iq->where('assigned_to', $idStr);
                            if (!empty($installerName)) {
                                $iq->orWhere('assigned_user_name', $installerName);
                            }
                        })
                        ->orWhereHas('discrepancies', function ($dq) use ($idStr) {
                            $dq->where('user_id', $idStr);
                        });

                    if (!empty($installerName)) {
                        $q->orWhere('assigned_user_name', $installerName);
                    }
                });
            }

            // Filter for flagged status
            $ordersQuery->where(function ($q) {
                $q->where('status', 'flagged')
                    ->orWhereHas('items', function ($iq) {
                        $iq->where('is_flagged', true)
                            ->orWhereHas('installation', function ($instQ) {
                                $instQ->where('status', 'flagged');
                            });
                    })
                    ->orWhereHas('discrepancies', function ($dq) {
                        $dq->where('status', 'open');
                    });
            });

            $orders = $ordersQuery->orderBy('updated_at', 'desc')->get();

            $formatted = $orders->map(function ($order) {
                $latestDiscrepancy = OrderItemDiscrepancy::where('order_id', $order->id)->latest()->first();

                return [
                    'id' => $order->id,
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'status' => 'flagged',
                    'order_status' => 'flagged',
                    'customer_name' => $order->customer_name,
                    'customer_phone' => $order->customer_phone,
                    'delivery_address' => $order->delivery_address,
                    'total_amount' => (float) $order->total_amount,
                    'is_flagged' => true,
                    'flag_reason' => $latestDiscrepancy?->issue_type ?? 'Customer Unavailable',
                    'flag_comment' => $latestDiscrepancy?->comment ?? '',
                    'photo_url' => $latestDiscrepancy?->photo_url,
                    'flagged_at' => $latestDiscrepancy?->created_at?->toIso8601String() ?? $order->updated_at?->toIso8601String(),
                    'discrepancy' => $latestDiscrepancy,
                ];
            })->values();

            return response()->json([
                'status' => 'success',
                'installer_user_id' => is_numeric($idStr) ? (int) $idStr : $idStr,
                'count' => $formatted->count(),
                'data' => $formatted,
            ], 200);

        } catch (Exception $e) {
            Log::error('Error in getFlaggedInstallations: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve flagged installations: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mark an installed order as delivered with collected amount, payment method, and payment status.
     *
     * Route: GET|POST /api/mobile/scheduled/markas-delivered
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function installationMarkasDelivered(Request $request): JsonResponse
    {
        try {
            $payload = array_merge(
                $request->all(),
                is_array($request->json()?->all()) ? $request->json()->all() : []
            );

            $orderNumber = $payload['order_number']
                ?? $request->input('order_number')
                ?? $payload['order_id']
                ?? $request->input('order_id');

            if (empty($orderNumber)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Order number is required.',
                ], 422);
            }

            $amount = $payload['amount']
                ?? $request->input('amount')
                ?? $payload['collected_amount']
                ?? $request->input('collected_amount')
                ?? $payload['total_amount']
                ?? $request->input('total_amount');

            $paymentMethod = $payload['payment_method']
                ?? $request->input('payment_method')
                ?? $payload['payment_type']
                ?? $request->input('payment_type')
                ?? 'Cash';

            $paymentStatus = $payload['payment_status']
                ?? $request->input('payment_status')
                ?? 'Paid';

            $installerUserId = $payload['installer_user_id']
                ?? $request->input('installer_user_id')
                ?? $payload['user_id']
                ?? $request->input('user_id')
                ?? Auth::id();

            $userName = $payload['user_name']
                ?? $request->input('user_name')
                ?? (Auth::user()?->name ?? 'Mobile Installer');

            $notes = $payload['notes'] ?? $request->input('notes');

            // Find order
            $cleanNum = trim(ltrim((string) $orderNumber, '#'));
            $order = Order::with(['items.installation', 'payment'])->where(function ($q) use ($orderNumber, $cleanNum) {
                $q->where('order_number', $orderNumber)
                    ->orWhere('order_number', '#' . $cleanNum)
                    ->orWhere('order_number', $cleanNum)
                    ->orWhere('id', $orderNumber);
            })->first();

            if (!$order) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Order not found with order_number '{$orderNumber}'.",
                ], 404);
            }

            $numericAmount = !is_null($amount) ? (float) $amount : (float) $order->total_amount;

            return DB::transaction(function () use ($order, $paymentMethod, $paymentStatus, $numericAmount, $installerUserId, $userName, $notes) {
                $oldStatus = $order->status;

                // 1. Update order status and delivery fields
                $orderUpdates = ['status' => 'completed'];
                if (Schema::hasColumn('orders', 'delivered_at')) {
                    $orderUpdates['delivered_at'] = now();
                }
                if (Schema::hasColumn('orders', 'delivered_by') && $installerUserId) {
                    $orderUpdates['delivered_by'] = $installerUserId;
                }
                if (Schema::hasColumn('orders', 'delivered_user_name') && $userName) {
                    $orderUpdates['delivered_user_name'] = $userName;
                }
                if (Schema::hasColumn('orders', 'payment_method') && $paymentMethod) {
                    $orderUpdates['payment_method'] = $paymentMethod;
                }
                if (Schema::hasColumn('orders', 'payment_status') && $paymentStatus) {
                    $orderUpdates['payment_status'] = $paymentStatus;
                }
                if (Schema::hasColumn('orders', 'collected_amount')) {
                    $orderUpdates['collected_amount'] = $numericAmount;
                }

                $order->update($orderUpdates);

                // 2. Update or create OrderPayment
                if (Schema::hasTable('order_payments')) {
                    OrderPayment::updateOrCreate(
                        ['order_id' => $order->id],
                        [
                            'shopify_order_id' => $order->shopify_order_id,
                            'payment_method' => $paymentMethod,
                            'payment_status' => $paymentStatus,
                            'paid_amount' => $numericAmount,
                            'total_price' => (float) $order->total_amount,
                            'currency' => $order->payment?->currency ?: 'QAR',
                            'processed_at' => now(),
                        ]
                    );
                }

                // 3. Mark all installable items as completed
                foreach ($order->items as $item) {
                    if ((bool) ($item->is_installable ?? false)) {
                        OrderInstallation::updateOrCreate(
                            ['order_item_id' => $item->id],
                            [
                                'status' => 'completed',
                                'completed_at' => now(),
                                'notes' => $notes ?: 'Installed and marked as delivered',
                                'is_scheduled_assigned' => true,
                            ]
                        );
                    }

                    // Safe item status update
                    try {
                        $item->update(['status' => 'delivered']);
                    } catch (\Throwable $e) {}
                }

                // 4. Update technician_scheduled if present
                if (Schema::hasTable('technician_scheduled')) {
                    TechnicianScheduled::where('order_id', $order->id)->update([
                        'status' => 'completed',
                        'completed_at' => now(),
                        'notes' => $notes ?: 'Delivered by installer',
                    ]);
                }

                // 5. Update orders_driver_assigned if present
                if (Schema::hasTable('orders_driver_assigned')) {
                    OrderDriverAssigned::where('order_id', $order->id)->update([
                        'driver_status' => 'delivered',
                        'delivered_at' => now(),
                        'payment_method' => $paymentMethod,
                        'payment_status' => $paymentStatus,
                        'collected_amount' => $numericAmount,
                    ]);
                }

                // 6. Audit log in order_status_logs
                OrderStatusLog::create([
                    'order_id' => $order->id,
                    'user_id' => $installerUserId,
                    'user_name' => $userName,
                    'action' => 'installation_marked_as_delivered',
                    'old_status' => $oldStatus,
                    'new_status' => 'completed',
                    'notes' => "Order #{$order->order_number} marked as delivered by installer. Method: {$paymentMethod}, Amount: {$numericAmount}, Status: {$paymentStatus}" . ($notes ? " Notes: {$notes}" : ""),
                ]);

                // 7. Sync Shopify Fulfillment
                DB::afterCommit(function () use ($order, $userName) {
                    try {
                        app(\App\Services\ShopifyService::class)->syncOrderFulfillmentToShopify($order, [
                            'status' => 'completed',
                            'delivered_by' => $userName,
                            'action' => 'installation_marked_as_delivered',
                        ]);
                    } catch (\Throwable $e) {
                        Log::warning("Shopify sync error on mark-as-delivered for order #{$order->order_number}: " . $e->getMessage());
                    }
                });

                return response()->json([
                    'status' => 'success',
                    'message' => "Order {$order->order_number} marked as delivered successfully.",
                    'data' => [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'status' => 'completed',
                        'order_status' => 'completed',
                        'payment' => [
                            'amount' => $numericAmount,
                            'payment_method' => $paymentMethod,
                            'payment_status' => $paymentStatus,
                            'currency' => $order->payment?->currency ?: 'QAR',
                        ],
                        'delivered_at' => now()->toIso8601String(),
                    ],
                ], 200);
            });

        } catch (Exception $e) {
            Log::error('Error in installationMarkasDelivered: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to mark installation as delivered: ' . $e->getMessage(),
            ], 500);
        }
    }
}
