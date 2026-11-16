<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MainwareHousePickerManagementController extends Controller
{
        /**
     * Retrieve scheduled installation orders (scheduled on today) for the mobile picker app.
     * Route: ANY /api/mobile/installation-orders/picker
     *
     * @param Request $request
     * @return JsonResponse
     */
 

    public function installationOrdersPicker(Request $request): JsonResponse
    {
        try {
            // Optional silent sync with Shopify
            if (
                $request->boolean('auto_sync', false) &&
                !empty($this->shopifyService)
            ) {
                try {
                    $this->shopifyService->syncOrdersToDatabase();
                } catch (\Throwable $syncEx) {
                    Log::warning(
                        'Silent Shopify sync failed in installationOrdersPicker: ' .
                        $syncEx->getMessage()
                    );
                }
            }

            // Resolve target date; default to today
            $today = Carbon::today()->toDateString();

            $targetDateInput = $request->input('scheduled_date')
                ?? $request->input('date')
                ?? $request->query('scheduled_date')
                ?? $request->query('date')
                ?? $today;

            try {
                $targetDate = Carbon::parse($targetDateInput)->toDateString();
            } catch (\Throwable $e) {
                $targetDate = $today;
            }

            // Base query and required relationships
            $query = Order::query()->with([
                'items' => function ($iq) {
                    $iq->with([
                        'installation',
                        'technicianSchedule',
                        'technicianSchedules',
                        'flagImages',
                    ]);
                },
                'technicianSchedules',
                'assignedUser',
            ]);

            // Find orders scheduled for the target date
            $query->where(function ($q) use ($targetDate) {
                // Order-level technician schedule
                $q->whereHas('technicianSchedules', function ($tq) use ($targetDate) {
                    $tq->where(function ($sub) use ($targetDate) {
                        $sub->whereDate('scheduled_date', $targetDate)
                            ->orWhereDate('scheduled_at', $targetDate);
                    })->where('status', '!=', 'cancelled');
                })

                // Item-level technician schedules
                ->orWhereHas('items', function ($iq) use ($targetDate) {
                    $iq->whereHas('technicianSchedule', function ($tq) use ($targetDate) {
                        $tq->where(function ($sub) use ($targetDate) {
                            $sub->whereDate('scheduled_date', $targetDate)
                                ->orWhereDate('scheduled_at', $targetDate);
                        })->where('status', '!=', 'cancelled');
                    })

                    ->orWhereHas('technicianSchedules', function ($tq) use ($targetDate) {
                        $tq->where(function ($sub) use ($targetDate) {
                            $sub->whereDate('scheduled_date', $targetDate)
                                ->orWhereDate('scheduled_at', $targetDate);
                        })->where('status', '!=', 'cancelled');
                    })

                    ->orWhereHas('installation.technicianSchedule', function ($tq) use ($targetDate) {
                        $tq->where(function ($sub) use ($targetDate) {
                            $sub->whereDate('scheduled_date', $targetDate)
                                ->orWhereDate('scheduled_at', $targetDate);
                        })->where('status', '!=', 'cancelled');
                    });
                });

                // Fallback: orders.scheduled_date
                if (Schema::hasColumn('orders', 'scheduled_date')) {
                    $q->orWhere(function ($oq) use ($targetDate) {
                        $oq->whereDate('scheduled_date', $targetDate)
                            ->where(function ($sub) {
                                $sub->where('status', 'like', '%install%')
                                    ->orWhereHas('items', function ($iq) {
                                        $iq->where('is_installable', true);
                                    });
                            });
                    });
                }

                // Fallback: order_installations.scheduled_date
                if (
                    Schema::hasTable('order_installations') &&
                    Schema::hasColumn('order_installations', 'scheduled_date')
                ) {
                    $q->orWhereHas('items.installation', function ($instQ) use ($targetDate) {
                        $instQ->whereDate('scheduled_date', $targetDate);
                    });
                }
            });

            // Exclude cancelled orders by default
            if (
                !$request->filled('status') ||
                strtolower((string) $request->input('status')) !== 'cancelled'
            ) {
                $query->where(function ($q) {
                    $q->whereNull('cancelled_at')
                        ->whereNotIn('status', [
                            'cancelled',
                            'delivery_cancelled',
                            'failed',
                        ]);
                });
            }

            // Search filter
            if ($request->filled('search') || $request->filled('q')) {
                $search = trim(
                    (string) ($request->input('search') ?? $request->input('q'))
                );

                $cleanSearch = ltrim($search, '#');

                $query->where(function ($q) use ($search, $cleanSearch) {
                    $q->where('order_number', 'like', "%{$search}%")
                        ->orWhere('order_number', 'like', "%{$cleanSearch}%")
                        ->orWhere('customer_name', 'like', "%{$search}%")
                        ->orWhere('customer_phone', 'like', "%{$search}%")
                        ->orWhere('delivery_address', 'like', "%{$search}%")
                        ->orWhereHas('items', function ($iq) use ($search) {
                            $iq->where('product_name', 'like', "%{$search}%")
                                ->orWhere('product_code', 'like', "%{$search}%")
                                ->orWhere('barcode', 'like', "%{$search}%");
                        });
                });
            }

            // Assigned picker filter
            if (
                $request->filled('assigned_to') ||
                $request->filled('picker_id') ||
                $request->filled('user_id')
            ) {
                $assignedTo = $request->input('assigned_to')
                    ?? $request->input('picker_id')
                    ?? $request->input('user_id');

                $query->where(function ($q) use ($assignedTo) {
                    $q->where('assigned_to', $assignedTo)
                        ->orWhereHas('items', function ($iq) use ($assignedTo) {
                            $iq->where('assigned_to', $assignedTo);
                        });
                });
            }

            // Status filter
            if (
                $request->filled('status') &&
                strtolower((string) $request->input('status')) !== 'all'
            ) {
                $query->where('status', $request->input('status'));
            }

            // Flag image URL configuration
            $configuredBase = env(
                'FLAG_IMAGES_BASE_URL',
                env('PUBLIC_BASE_URL')
            );

            if (!$configuredBase) {
                $appUrl = config('app.url', 'http://localhost');

                if (
                    !empty($appUrl) &&
                    !str_contains($appUrl, 'localhost') &&
                    !str_contains($appUrl, '127.0.0.1')
                ) {
                    $configuredBase = rtrim($appUrl, '/') . '/public';
                } else {
                    $configuredBase = 'https://delivery-management.hmws.qatar123.com/public';
                }
            }

            $storageBaseUrl = rtrim($configuredBase, '/');

            $formatFlagImageUrl = function (?string $rawUrl) use ($storageBaseUrl): ?string {
                if (empty($rawUrl)) {
                    return null;
                }

                $rawUrl = trim($rawUrl);

                if (str_starts_with($rawUrl, $storageBaseUrl)) {
                    return $rawUrl;
                }

                if (
                    preg_match(
                        '#(?:https?://[^/]+)?(?:/public)?(/storage/.+)$#i',
                        $rawUrl,
                        $matches
                    )
                ) {
                    return $storageBaseUrl . $matches[1];
                }

                return $rawUrl;
            };

            // Pagination
            $isAll = $request->boolean('all')
                || $request->query('paginate') === 'false';

            $perPage = (int) (
                $request->input('per_page')
                ?? $request->query('per_page', 15)
            );

            $perPage = max(1, min($perPage, 100));

            if ($isAll) {
                $ordersCollection = $query
                    ->orderBy('created_at', 'desc')
                    ->get();

                $paginator = null;
            } else {
                $paginator = $query
                    ->orderBy('created_at', 'desc')
                    ->paginate($perPage);

                $ordersCollection = collect($paginator->items());
            }

            // Format orders and return installation items only
            $formattedOrders = $ordersCollection
                ->map(function (Order $order) use (
                    $targetDate,
                    $formatFlagImageUrl
                ) {
                    // Find the order-level schedule for the target date
                    $orderSchedule = $order->technicianSchedules->first(
                        function ($schedule) use ($targetDate) {
                            return $schedule->scheduled_date &&
                                Carbon::parse(
                                    $schedule->scheduled_date
                                )->toDateString() === $targetDate;
                        }
                    ) ?? $order->technicianSchedules->first();

                    // Format installation items
                    $installationItems = $order->items
                        ->map(function (OrderItem $item) use (
                            $targetDate,
                            $orderSchedule,
                            $formatFlagImageUrl
                        ) {
                            $installation = $item->installation;

                            // Find the item schedule for the target date
                            $itemSchedule = $item->technicianSchedules->first(
                                function ($schedule) use ($targetDate) {
                                    return $schedule->scheduled_date &&
                                        Carbon::parse(
                                            $schedule->scheduled_date
                                        )->toDateString() === $targetDate;
                                }
                            ) ?? $item->technicianSchedule
                                ?? $installation?->technicianSchedule;

                            // An item is installable if it has an installation
                            // flag, installation record, or item schedule
                            $isInstallable = (bool) (
                                $item->is_installable ||
                                $installation ||
                                $item->technicianSchedule ||
                                $item->technicianSchedules->isNotEmpty()
                            );

                            if (!$isInstallable) {
                                return null;
                            }

                            // Use item schedule, falling back to order schedule
                            $displaySchedule = $itemSchedule ?? $orderSchedule;

                            // Format scheduled date
                            $scheduledDate = $displaySchedule?->scheduled_date
                                ? Carbon::parse(
                                    $displaySchedule->scheduled_date
                                )->toDateString()
                                : $targetDate;

                            // Flag image
                            $flagImageUrl = null;

                            if ($item->is_flagged) {
                                $firstFlagImg = $item->flagImages->first();

                                $rawUrl = $firstFlagImg
                                    ? (
                                        $firstFlagImg->image_url
                                        ?? $firstFlagImg->photo_url
                                    )
                                    : null;

                                $flagImageUrl = $formatFlagImageUrl($rawUrl);
                            }

                            // Product tag: return VS only when present
                            $productTag = collect(
                                explode(',', $item->product_tags ?? '')
                            )
                                ->map(fn ($tag) => trim($tag))
                                ->contains(
                                    fn ($tag) => strcasecmp($tag, 'VS') === 0
                                )
                                ? 'VS'
                                : null;

                            // Return only the required item fields
                            return [
                                'line_item_id' => $item->line_item_id,
                                'product_id' => $item->product_id,
                                'product_name' => $item->product_name,
                                'sku' => $item->sku ?? $item->product_code,
                                'product_code' => $item->product_code ?? $item->sku,
                                'barcode' => $item->barcode,
                                'quantity' => (int) $item->quantity,
                                'rack' => $item->rack,
                                'bin' => $item->bin,

                                'imageUrl' => $item->image
                                    ?? $item->imageUrl
                                    ?? $item->image_url,

                                'product_tag' => $productTag,
                                'status' => $item->status,
                                'is_assigned' => !is_null($item->assigned_to),
                                'is_flagged' => (bool) $item->is_flagged,
                                'flag_reason' => $item->flag_reason,
                                'flag_image' => $flagImageUrl,

                                'scheduled_date' => $scheduledDate,
                                'start_time' => $displaySchedule?->start_time,
                                'end_time' => $displaySchedule?->end_time,
                            ];
                        })
                        ->filter()
                        ->values();

                    // Order schedule date and times
                    $scheduledDate = $orderSchedule?->scheduled_date
                        ? Carbon::parse(
                            $orderSchedule->scheduled_date
                        )->toDateString()
                        : $targetDate;

                    // Return only the required order fields
                    return [
                        'order_number' => $order->order_number,
                        'status' => $order->status,
                        'scheduled_date' => $scheduledDate,
                        'start_time' => $orderSchedule?->start_time,
                        'end_time' => $orderSchedule?->end_time,
                        'items_count' => $installationItems->count(),
                        'items' => $installationItems,
                    ];
                })
                // Avoid returning orders with no installation items
                ->filter(fn ($order) => $order['items_count'] > 0)
                ->values();

            // Minimal response structure
            $response = [
                'success' => true,
                'status' => 'success',
                'message' => 'Today scheduled installation orders retrieved successfully.',
                'date' => $targetDate,
                'data' => $formattedOrders,
                'count' => $formattedOrders->count(),
            ];

            // Preserve pagination fields
            if ($paginator) {
                $response['pagination'] = [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                    'has_more_pages' => $paginator->hasMorePages(),
                ];
            }

            return response()->json($response, 200);

        } catch (\Throwable $e) {
            Log::error(
                'Error in PickerManagementController@installationOrdersPicker: ' .
                $e->getMessage(),
                [
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return response()->json([
                'success' => false,
                'status' => 'error',
                'message' => 'An error occurred while retrieving scheduled installation orders: ' .
                    $e->getMessage(),
                'data' => [],
            ], 500);
        }
    }

    public function assignInstallationOrdersToPicker(Request $request): JsonResponse
    {
        $picker = null;

        try {
            /*
            |--------------------------------------------------------------------------
            | Step 1: Get authenticated picker
            |--------------------------------------------------------------------------
            */
            $picker = Auth::guard('sanctum')->user()
                ?? $request->user()
                ?? Auth::user();

            if (!$picker) {
                return response()->json([
                    'success' => false,
                    'status' => 'error',
                    'message' => 'Unauthenticated. Please log in first.',
                    'data' => [],
                ], 401);
            }

            /*
            |--------------------------------------------------------------------------
            | Step 2: Validate request
            |--------------------------------------------------------------------------
            */
            $validated = $request->validate([
                'order_number' => ['required', 'string'],
                'order_line_item_ids' => ['required', 'array', 'min:1'],
                'order_line_item_ids.*' => [
                    'required',
                    'integer',
                    'distinct',
                    'min:1',
                ],
            ]);

            /*
            |--------------------------------------------------------------------------
            | Step 3: Normalize order number
            |--------------------------------------------------------------------------
            | Accepts both 1095 and #1095.
            */
            $inputOrderNumber = trim($validated['order_number']);
            $normalizedOrderNumber = trim(ltrim($inputOrderNumber, '#'));

            if ($normalizedOrderNumber === '') {
                return response()->json([
                    'success' => false,
                    'status' => 'error',
                    'message' => 'A valid order number is required.',
                    'data' => [],
                ], 422);
            }

            $orderNumberVariants = array_values(array_unique([
                $normalizedOrderNumber,
                '#' . $normalizedOrderNumber,
            ]));

            /*
            |--------------------------------------------------------------------------
            | Step 4: Process assignment in a transaction
            |--------------------------------------------------------------------------
            */
            $result = DB::transaction(function () use (
                $validated,
                $picker,
                $orderNumberVariants
            ) {
                /*
                |--------------------------------------------------------------------------
                | Find order
                |--------------------------------------------------------------------------
                */
                $order = Order::query()
                    ->whereIn('order_number', $orderNumberVariants)
                    ->lockForUpdate()
                    ->first();

                if (!$order) {
                    return [
                        'error' => true,
                        'http_code' => 404,
                        'message' => 'Order not found for the supplied order number.',
                        'data' => [
                            'searched_order_numbers' => $orderNumberVariants,
                        ],
                    ];
                }

                /*
                |--------------------------------------------------------------------------
                | Step 5: Normalize requested line item IDs
                |--------------------------------------------------------------------------
                */
                $requestedLineItemIds = collect(
                    $validated['order_line_item_ids']
                )
                    ->map(fn ($id) => (string) $id)
                    ->unique()
                    ->values();

                /*
                |--------------------------------------------------------------------------
                | Step 6: Fetch requested installable items
                |--------------------------------------------------------------------------
                */
                $items = OrderItem::query()
                    ->where('order_id', $order->id)
                    ->whereIn(
                        'line_item_id',
                        $requestedLineItemIds->all()
                    )
                    ->where('is_installable', true)
                    ->lockForUpdate()
                    ->get();

                /*
                |--------------------------------------------------------------------------
                | Step 7: Validate requested line items
                |--------------------------------------------------------------------------
                */
                $foundLineItemIds = $items
                    ->pluck('line_item_id')
                    ->map(fn ($id) => (string) $id)
                    ->unique()
                    ->values();

                $missingLineItemIds = $requestedLineItemIds
                    ->diff($foundLineItemIds)
                    ->values()
                    ->all();

                if (!empty($missingLineItemIds)) {
                    return [
                        'error' => true,
                        'http_code' => 404,
                        'message' => 'Some line item IDs were not found in this order or are not installable.',
                        'data' => [
                            'order_number' => $order->order_number,
                            'missing_line_item_ids' => $missingLineItemIds,
                        ],
                    ];
                }

                /*
                |--------------------------------------------------------------------------
                | Step 8: Prevent reassignment to another picker
                |--------------------------------------------------------------------------
                */
                $alreadyAssigned = $items->first(
                    function ($item) use ($picker) {
                        return $item->assigned_to !== null
                            && (string) $item->assigned_to
                                !== (string) $picker->id;
                    }
                );

                if ($alreadyAssigned) {
                    return [
                        'error' => true,
                        'http_code' => 409,
                        'message' => 'One or more installation items are already assigned to another picker.',
                        'data' => [
                            'order_number' => $order->order_number,
                            'conflicting_line_item_id' =>
                                $alreadyAssigned->line_item_id,
                            'assigned_to' => $alreadyAssigned->assigned_to,
                        ],
                    ];
                }

                /*
                |--------------------------------------------------------------------------
                | Step 9: Assign installation items
                |--------------------------------------------------------------------------
                | Update only order_items.
                | Status is picker_assigned.
                | The orders table is not modified.
                */
                $now = now();
                $itemIds = $items->pluck('id')->all();

                $updatedCount = OrderItem::query()
                    ->whereIn('id', $itemIds)
                    ->where('order_id', $order->id)
                    ->where('is_installable', true)
                    ->where(function ($query) use ($picker) {
                        $query->whereNull('assigned_to')
                            ->orWhere('assigned_to', $picker->id);
                    })
                    ->update([
                        'status' => 'picker_assigned',
                        'assigned_to' => $picker->id,
                        'assigned_user_name' => $picker->name,
                        'assigned_at' => $now,
                        'updated_at' => $now,
                    ]);

                /*
                |--------------------------------------------------------------------------
                | Step 10: Retrieve assigned installation items
                |--------------------------------------------------------------------------
                */
                $assignedItems = OrderItem::query()
                    ->whereIn('id', $itemIds)
                    ->where('order_id', $order->id)
                    ->where('is_installable', true)
                    ->where('assigned_to', $picker->id)
                    ->get([
                        'id',
                        'order_id',
                        'line_item_id',
                        'product_id',
                        'product_name',
                        'product_code',
                        'barcode',
                        'quantity',
                        'is_installable',
                        'status',
                        'assigned_to',
                        'assigned_user_name',
                        'assigned_at',
                    ]);

                /*
                |--------------------------------------------------------------------------
                | Step 11: Return transaction result
                |--------------------------------------------------------------------------
                */
                return [
                    'error' => false,
                    'message' => 'Installation items assigned to picker successfully.',
                    'order_number' => $order->order_number,
                    'updated_count' => $updatedCount,
                    'data' => $assignedItems,
                ];
            }, 3);

            /*
            |--------------------------------------------------------------------------
            | Step 12: Handle business errors
            |--------------------------------------------------------------------------
            */
            if ($result['error']) {
                return response()->json([
                    'success' => false,
                    'status' => 'error',
                    'message' => $result['message'],
                    'data' => $result['data'],
                ], $result['http_code']);
            }

            /*
            |--------------------------------------------------------------------------
            | Step 13: Success response
            |--------------------------------------------------------------------------
            */
            return response()->json([
                'success' => true,
                'status' => 'success',
                'message' => $result['message'],
                'order_number' => $result['order_number'],
                'assigned_count' => $result['data']->count(),
                'updated_count' => $result['updated_count'],
                'data' => $result['data'],
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;

        } catch (\Throwable $e) {
            Log::error(
                'MainwareHousePickerManagementController@assignInstallationOrdersToPicker failed',
                [
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'order_number' => $request->input('order_number'),
                    'order_line_item_ids' => $request->input('order_line_item_ids'),
                    'picker_id' => $picker?->id,
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return response()->json([
                'success' => false,
                'status' => 'error',
                'message' => 'Failed to assign installation items.',
                'debug_message' => config('app.debug')
                    ? $e->getMessage()
                    : null,
                'data' => [],
            ], 500);
        }
    }
    /*
        assign installation orders to picker
        @param  \Illuminate\Http\Request  $request
        @return \Illuminate\Http\JsonResponse
        @desc     Assign installation orders to picker
        @throws \Throwable
        @auth     true
        @public   false
        @middleware   api
        @group    Picker Management
        @tags     Picker Management
        @response 200
        @response 401
        @response 403
        @response 404
        @response 422
        @response 500
    */


    // public function assignInstallationOrdersToPicker(Request $request)
    // {
    //     $picker = null;

    //     try {
    //         /*
    //         |--------------------------------------------------------------------------
    //         | Step 1: Get authenticated picker
    //         |--------------------------------------------------------------------------
    //         */
    //         $picker = Auth::guard('sanctum')->user()
    //             ?? $request->user()
    //             ?? Auth::user();

    //         if (!$picker) {
    //             return response()->json([
    //                 'success' => false,
    //                 'status' => 'error',
    //                 'message' => 'Unauthenticated. Please log in first.',
    //                 'data' => [],
    //             ], 401);
    //         }

    //         /*
    //         |--------------------------------------------------------------------------
    //         | Step 2: Validate request
    //         |--------------------------------------------------------------------------
    //         */
    //         $validated = $request->validate([
    //             'order_number' => ['required', 'string'],
    //             'order_line_item_ids' => ['required', 'array', 'min:1'],
    //             'order_line_item_ids.*' => [
    //                 'required',
    //                 'integer',
    //                 'distinct',
    //                 'min:1',
    //             ],
    //         ]);

    //         /*
    //         |--------------------------------------------------------------------------
    //         | Step 3: Normalize order number
    //         |--------------------------------------------------------------------------
    //         | Request: 1095 or #1095
    //         | Database: #1095
    //         */
    //         $inputOrderNumber = trim($validated['order_number']);

    //         // Remove a leading # and any surrounding whitespace.
    //         $normalizedOrderNumber = ltrim($inputOrderNumber, '#');
    //         $normalizedOrderNumber = trim($normalizedOrderNumber);

    //         if ($normalizedOrderNumber === '') {
    //             return response()->json([
    //                 'success' => false,
    //                 'status' => 'error',
    //                 'message' => 'A valid order number is required.',
    //                 'data' => [],
    //             ], 422);
    //         }

    //         // Support both stored formats: #1095 and 1095.
    //         $orderNumberVariants = array_values(array_unique([
    //             $normalizedOrderNumber,
    //             '#' . $normalizedOrderNumber,
    //         ]));

    //         /*
    //         |--------------------------------------------------------------------------
    //         | Step 4: Process assignment in a transaction
    //         |--------------------------------------------------------------------------
    //         */
    //         $result = DB::transaction(function () use (
    //             $validated,
    //             $picker,
    //             $orderNumberVariants
    //         ) {
    //             /*
    //             |--------------------------------------------------------------------------
    //             | Find order using normalized order number
    //             |--------------------------------------------------------------------------
    //             */
    //             $order = Order::query()
    //                 ->whereIn('order_number', $orderNumberVariants)
    //                 ->lockForUpdate()
    //                 ->first();

    //             if (!$order) {
    //                 return [
    //                     'error' => true,
    //                     'http_code' => 404,
    //                     'message' => 'Order not found for the supplied order number.',
    //                     'data' => [
    //                         'searched_order_numbers' => $orderNumberVariants,
    //                     ],
    //                 ];
    //             }

    //             /*
    //             |--------------------------------------------------------------------------
    //             | Normalize requested line item IDs
    //             |--------------------------------------------------------------------------
    //             */
    //             $requestedLineItemIds = collect(
    //                 $validated['order_line_item_ids']
    //             )
    //                 ->map(fn ($id) => (string) $id)
    //                 ->unique()
    //                 ->values();

    //             /*
    //             |--------------------------------------------------------------------------
    //             | Fetch requested installable items belonging to this order
    //             |--------------------------------------------------------------------------
    //             */
    //             $items = OrderItem::query()
    //                 ->where('order_id', $order->id)
    //                 ->whereIn(
    //                     'line_item_id',
    //                     $requestedLineItemIds->all()
    //                 )
    //                 ->where('is_installable', true)
    //                 ->lockForUpdate()
    //                 ->get();

    //             /*
    //             |--------------------------------------------------------------------------
    //             | Verify every requested line item was found
    //             |--------------------------------------------------------------------------
    //             */
    //             $foundLineItemIds = $items
    //                 ->pluck('line_item_id')
    //                 ->map(fn ($id) => (string) $id)
    //                 ->unique()
    //                 ->values();

    //             $missingLineItemIds = $requestedLineItemIds
    //                 ->diff($foundLineItemIds)
    //                 ->values()
    //                 ->all();

    //             if (!empty($missingLineItemIds)) {
    //                 return [
    //                     'error' => true,
    //                     'http_code' => 404,
    //                     'message' => 'Some line item IDs were not found in this order or are not installable.',
    //                     'data' => [
    //                         'order_number' => $order->order_number,
    //                         'missing_line_item_ids' => $missingLineItemIds,
    //                     ],
    //                 ];
    //             }

    //             /*
    //             |--------------------------------------------------------------------------
    //             | Prevent reassignment from another picker
    //             |--------------------------------------------------------------------------
    //             */
    //             $alreadyAssigned = $items->first(
    //                 function ($item) use ($picker) {
    //                     return $item->assigned_to !== null
    //                         && (string) $item->assigned_to
    //                             !== (string) $picker->id;
    //                 }
    //             );

    //             if ($alreadyAssigned) {
    //                 return [
    //                     'error' => true,
    //                     'http_code' => 409,
    //                     'message' => 'One or more installation items are already assigned to another picker.',
    //                     'data' => [
    //                         'order_number' => $order->order_number,
    //                         'conflicting_line_item_id' =>
    //                             $alreadyAssigned->line_item_id,
    //                         'assigned_to' => $alreadyAssigned->assigned_to,
    //                     ],
    //                 ];
    //             }

    //             /*
    //             |--------------------------------------------------------------------------
    //             | Assign installation items
    //             |--------------------------------------------------------------------------
    //             | Only order_items is updated.
    //             | The orders table is not updated.
    //             */
    //             $now = now();
    //             $itemIds = $items->pluck('id')->all();

    //             $updatedCount = OrderItem::query()
    //                 ->whereIn('id', $itemIds)
    //                 ->where('order_id', $order->id)
    //                 ->where('is_installable', true)
    //                 ->where(function ($query) use ($picker) {
    //                     $query->whereNull('assigned_to')
    //                         ->orWhere('assigned_to', $picker->id);
    //                 })
    //                 ->update([
    //                     'status' => 'picking',
    //                     'assigned_to' => $picker->id,
    //                     'assigned_user_name' => $picker->name,
    //                     'assigned_at' => $now,
    //                     'updated_at' => $now,
    //                 ]);

    //             /*
    //             |--------------------------------------------------------------------------
    //             | Retrieve assigned installation items
    //             |--------------------------------------------------------------------------
    //             */
    //             $assignedItems = OrderItem::query()
    //                 ->whereIn('id', $itemIds)
    //                 ->where('order_id', $order->id)
    //                 ->where('is_installable', true)
    //                 ->where('assigned_to', $picker->id)
    //                 ->get([
    //                     'id',
    //                     'order_id',
    //                     'line_item_id',
    //                     'product_id',
    //                     'product_name',
    //                     'product_code',
    //                     'barcode',
    //                     'quantity',
    //                     'is_installable',
    //                     'status',
    //                     'assigned_to',
    //                     'assigned_user_name',
    //                     'assigned_at',
    //                 ]);

    //             /*
    //             |--------------------------------------------------------------------------
    //             | Transaction result
    //             |--------------------------------------------------------------------------
    //             */
    //             return [
    //                 'error' => false,
    //                 'message' => 'Installation items assigned to picker successfully.',
    //                 'order_number' => $order->order_number,
    //                 'updated_count' => $updatedCount,
    //                 'data' => $assignedItems,
    //             ];
    //         }, 3);

    //         /*
    //         |--------------------------------------------------------------------------
    //         | Handle transaction errors
    //         |--------------------------------------------------------------------------
    //         */
    //         if ($result['error']) {
    //             return response()->json([
    //                 'success' => false,
    //                 'status' => 'error',
    //                 'message' => $result['message'],
    //                 'data' => $result['data'],
    //             ], $result['http_code']);
    //         }

    //         /*
    //         |--------------------------------------------------------------------------
    //         | Success response
    //         |--------------------------------------------------------------------------
    //         */
    //         return response()->json([
    //             'success' => true,
    //             'status' => 'success',
    //             'message' => $result['message'],
    //             'order_number' => $result['order_number'],
    //             'assigned_count' => $result['data']->count(),
    //             'updated_count' => $result['updated_count'],
    //             'data' => $result['data'],
    //         ], 200);

    //     } catch (\Illuminate\Validation\ValidationException $e) {
    //         throw $e;

    //     } catch (\Throwable $e) {
    //         Log::error(
    //             'MainwareHousePickerManagementController@assignInstallationOrdersToPicker failed',
    //             [
    //                 'exception' => get_class($e),
    //                 'message' => $e->getMessage(),
    //                 'file' => $e->getFile(),
    //                 'line' => $e->getLine(),
    //                 'order_number' => $request->input('order_number'),
    //                 'order_line_item_ids' => $request->input('order_line_item_ids'),
    //                 'picker_id' => $picker?->id,
    //                 'trace' => $e->getTraceAsString(),
    //             ]
    //         );

    //         return response()->json([
    //             'success' => false,
    //             'status' => 'error',
    //             'message' => 'Failed to assign installation items.',
    //             'debug_message' => config('app.debug')
    //                 ? $e->getMessage()
    //                 : null,
    //             'data' => [],
    //         ], 500);
    //     }
    // }

    /**
     * update status by mainware house picker 
     */


    public function updateStatus(Request $request): JsonResponse
    {
        try {
            $picker = Auth::guard('sanctum')->user()
                ?? $request->user()
                ?? Auth::user();

            if (!$picker) {
                return response()->json([
                    'success' => false,
                    'status' => 'error',
                    'message' => 'Unauthenticated. Please log in first.',
                    'data' => [],
                ], 401);
            }

            $validated = $request->validate([
                'order_number' => ['required', 'string'],
                'order_line_item_ids' => ['required', 'array', 'min:1'],
                'order_line_item_ids.*' => [
                    'required',
                    'integer',
                    'distinct',
                ],
            ]);

            $result = DB::transaction(function () use ($validated, $picker) {

                /*
                |--------------------------------------------------------------------------
                | Normalize order number
                |--------------------------------------------------------------------------
                | The frontend can send 1095 or #1095.
                | The database order_number is expected to contain #1095.
                */
                $orderNumber = trim((string) $validated['order_number']);
                $orderNumber = '#' . ltrim($orderNumber, '#');

                /*
                |--------------------------------------------------------------------------
                | Find order
                |--------------------------------------------------------------------------
                */
                $order = Order::query()
                    ->where('order_number', $orderNumber)
                    ->first();

                if (!$order) {
                    return [
                        'error' => true,
                        'http_code' => 404,
                        'message' => 'Order not found.',
                        'data' => [],
                    ];
                }

                /*
                |--------------------------------------------------------------------------
                | Get requested line item IDs
                |--------------------------------------------------------------------------
                */
                $requestedIds = collect($validated['order_line_item_ids'])
                    ->map(fn ($id) => (string) $id)
                    ->unique()
                    ->values();

                /*
                |--------------------------------------------------------------------------
                | Find requested installable items belonging to this order
                |--------------------------------------------------------------------------
                */
                $items = OrderItem::query()
                    ->where('order_id', $order->id)
                    ->whereIn('line_item_id', $requestedIds->all())
                    ->where('is_installable', true)
                    ->lockForUpdate()
                    ->get();

                /*
                |--------------------------------------------------------------------------
                | Validate that every requested item exists and is installable
                |--------------------------------------------------------------------------
                */
                $foundIds = $items
                    ->pluck('line_item_id')
                    ->map(fn ($id) => (string) $id)
                    ->unique();

                $missingIds = $requestedIds
                    ->diff($foundIds)
                    ->values()
                    ->all();

                if (!empty($missingIds)) {
                    return [
                        'error' => true,
                        'http_code' => 404,
                        'message' => 'Some line item IDs were not found in this order or are not installable.',
                        'data' => [
                            'missing_line_item_ids' => $missingIds,
                        ],
                    ];
                }

                /*
                |--------------------------------------------------------------------------
                | Validate picker assignment
                |--------------------------------------------------------------------------
                */
                $unassignedOrOtherPicker = $items->first(
                    fn ($item) =>
                        (string) $item->assigned_to !== (string) $picker->id
                );

                if ($unassignedOrOtherPicker) {
                    return [
                        'error' => true,
                        'http_code' => 409,
                        'message' => 'One or more items are not assigned to the authenticated picker.',
                        'data' => [
                            'line_item_id' => $unassignedOrOtherPicker->line_item_id,
                        ],
                    ];
                }

                /*
                |--------------------------------------------------------------------------
                | Update installation items
                |--------------------------------------------------------------------------
                | Update order_items only.
                | Do not update the parent orders table.
                */
                $now = now();
                $itemIds = $items->pluck('id')->all();

                OrderItem::query()
                    ->whereIn('id', $itemIds)
                    ->where('order_id', $order->id)
                    ->where('is_installable', true)
                    ->where('assigned_to', $picker->id)
                    ->update([
                        'status' => 'picked',
                        'picked_by' => $picker->id,
                        'picked_user_name' => $picker->name,
                        'picked_at' => $now,
                        'updated_at' => $now,
                    ]);

                /*
                |--------------------------------------------------------------------------
                | Retrieve updated items
                |--------------------------------------------------------------------------
                */
                $updatedItems = OrderItem::query()
                    ->whereIn('id', $itemIds)
                    ->where('order_id', $order->id)
                    ->get([
                        'id',
                        'order_id',
                        'line_item_id',
                        'product_id',
                        'product_name',
                        'quantity',
                        'is_installable',
                        'status',
                        'assigned_to',
                        'picked_by',
                        'picked_user_name',
                        'picked_at',
                    ]);

                return [
                    'error' => false,
                    'message' => 'Installation items marked as picked successfully.',
                    'order_number' => $order->order_number,
                    'data' => $updatedItems,
                ];
            });

            /*
            |--------------------------------------------------------------------------
            | Handle transaction result
            |--------------------------------------------------------------------------
            */
            if ($result['error']) {
                return response()->json([
                    'success' => false,
                    'status' => 'error',
                    'message' => $result['message'],
                    'data' => $result['data'],
                ], $result['http_code']);
            }

            return response()->json([
                'success' => true,
                'status' => 'success',
                'message' => $result['message'],
                'order_number' => $result['order_number'],
                'updated_count' => $result['data']->count(),
                'data' => $result['data'],
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;

        } catch (\Throwable $th) {
            Log::error('Error in updateStatus: ' . $th->getMessage(), [
                'trace' => $th->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'status' => 'error',
                'message' => 'Failed to update installation item status.',
                'data' => [],
            ], 500);
        }
    }


    /**
     * Get installation orders by picker id
     * 
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function getInstallationOrdersByPickerId(Request $request, $id): JsonResponse
    {
        try {
            if (!is_numeric($id) || (int) $id <= 0) {
                return response()->json([
                    'success' => false,
                    'status' => 'error',
                    'message' => 'Invalid picker ID.',
                    'data' => [],
                ], 422);
            }

            $pickerId = (int) $id;

            $installationItems = OrderItem::query()
                ->where('assigned_to', $pickerId)
                ->where('is_installable', true)
                ->where('status', 'picker_assigned')
                ->orderByDesc('assigned_at')
                ->get();

            $orderIds = $installationItems
                ->pluck('order_id')
                ->unique()
                ->values();

            $orders = Order::query()
                ->whereIn('id', $orderIds)
                ->get(['id', 'order_number'])
                ->keyBy('id');

            $installationOrders = $installationItems
                ->groupBy('order_id')
                ->map(function ($items, $orderId) use ($orders) {
                    $order = $orders->get($orderId);

                    return [
                        'order_number' => $order?->order_number,
                        'installation_items_count' => $items->count(),
                        'installation_items' => $items->map(function ($item) {
                            return [
                                'line_item_id' => $item->line_item_id,
                                'product_id' => $item->product_id,
                                'product_code' => $item->product_code,
                                'barcode' => $item->barcode,
                                'product_name' => $item->product_name,
                                'product_tags' => $item->product_tags,
                                'gift_wrap' => $item->gift_wrap,
                                'gift_message' => $item->gift_message,
                                'image' => $item->image,
                                'quantity' => $item->quantity,
                                'unit_price' => $item->unit_price,
                                'is_installable' => (bool) $item->is_installable,
                                'status' => $item->status,
                                'is_vs_verified' => (bool) $item->is_vs_verified,
                                'is_flagged' => (bool) $item->is_flagged,
                                'flag_reason' => $item->flag_reason,
                                'assigned_at' => $item->assigned_at,
                                'created_at' => $item->created_at,
                                'updated_at' => $item->updated_at,
                            ];
                        })->values(),
                    ];
                })
                ->values();

            return response()->json([
                'success' => true,
                'status' => 'success',
                'message' => 'Picker-assigned installation items retrieved successfully.',
                'total_orders' => $installationOrders->count(),
                'data' => $installationOrders,
            ], 200);

        } catch (\Throwable $th) {
            Log::error('Error in getInstallationOrdersByPickerId', [
                'picker_id' => $id,
                'exception' => get_class($th),
                'message' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'status' => 'error',
                'message' => 'Failed to retrieve installation orders.',
                'data' => [],
            ], 500);
        }
    }

}
