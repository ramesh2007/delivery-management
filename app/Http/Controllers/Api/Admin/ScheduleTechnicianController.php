<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderInstallation;
use App\Models\OrderStatusLog;
use App\Models\TechnicianScheduled;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class ScheduleTechnicianController extends Controller
{
    /**
     * Check if a technician is available or engaged for a given date and time.
     *
     * Route: ANY /api/admin/check-technician-avaliablity
     * Route: ANY /api/check-technician-avaliablity
     *
     * Request Parameters:
     * - technician_id (required): ID of technician user
     * - scheduled_date (required): Date format YYYY-MM-DD
     * - start_time (optional/recommended): e.g. "10:00", "10:00:00", "10:00 AM"
     * - end_time (optional): e.g. "11:30"
     * - taken_time_in_mins (optional): Estimated duration in minutes (default 60 if end_time omitted)
     * - exclude_id (optional): Schedule ID to exclude when rescheduling an existing appointment
     */
    public function checkTechnicianAvaliable(Request $request): JsonResponse
    {
        try {
            $payload = array_merge(
                $request->all(),
                is_array($request->json()?->all()) ? $request->json()->all() : []
            );

            // 1. Resolve technician_id
            $technicianId = $payload['technician_id']
                ?? $payload['technician_userid']
                ?? $payload['installer_id']
                ?? $payload['installer_userid']
                ?? $payload['user_id']
                ?? $payload['id']
                ?? null;

            // 2. Resolve scheduled_date
            $scheduledDate = $payload['scheduled_date']
                ?? $payload['date']
                ?? $payload['schedule_date']
                ?? null;

            // 3. Resolve start_time & end_time
            $startTimeInput = $payload['start_time']
                ?? $payload['time']
                ?? $payload['scheduled_time']
                ?? $payload['start']
                ?? null;

            $endTimeInput = $payload['end_time']
                ?? $payload['end']
                ?? null;

            $takenTimeMins = isset($payload['taken_time_in_mins'])
                ? (int) $payload['taken_time_in_mins']
                : (isset($payload['duration']) ? (int) $payload['duration'] : null);

            $excludeId = $payload['exclude_id'] ?? $payload['schedule_id'] ?? null;

            // Validate primary parameters
            if (empty($technicianId)) {
                return response()->json([
                    'success' => false,
                    'status' => 'error',
                    'message' => 'The technician_id parameter is required.',
                ], 422);
            }

            if (empty($scheduledDate)) {
                return response()->json([
                    'success' => false,
                    'status' => 'error',
                    'message' => 'The scheduled_date parameter is required (format: YYYY-MM-DD).',
                ], 422);
            }

            try {
                $parsedDate = Carbon::parse($scheduledDate)->toDateString();
            } catch (Throwable) {
                return response()->json([
                    'success' => false,
                    'status' => 'error',
                    'message' => 'Invalid scheduled_date format. Please provide a valid date like YYYY-MM-DD.',
                ], 422);
            }

            // Resolve technician display name
            $technicianUser = User::find($technicianId);
            $technicianName = $technicianUser?->name;
            if (!$technicianName && class_exists(InstallationLevelController::class)) {
                $techStatic = collect(InstallationLevelController::TECHNICIANS)->first(function ($t) use ($technicianId) {
                    return (string) ($t['id'] ?? '') === (string) $technicianId
                        || (string) ($t['technician_id'] ?? '') === (string) $technicianId;
                });
                if ($techStatic) {
                    $technicianName = $techStatic['name'] ?? ($techStatic['technician_name'] ?? null);
                }
            }

            // Query existing non-cancelled schedules for this technician on that date
            $query = TechnicianScheduled::with(['order:id,order_number,customer_name', 'orderItem:id,product_name'])
                ->where(function ($q) use ($technicianId, $technicianName) {
                    $q->where('technician_id', $technicianId);
                    if (!empty($technicianName)) {
                        $q->orWhere('technician_name', $technicianName);
                    }
                })
                ->whereDate('scheduled_date', $parsedDate)
                ->whereNotIn('status', ['cancelled', 'rejected']);

            if (!empty($excludeId)) {
                $query->where('id', '!=', $excludeId);
            }

            $existingSchedules = $query->orderBy('start_time', 'asc')->get();

            // Format all booked slots for the date
            $bookedSlots = $existingSchedules->map(function ($sch) {
                return [
                    'id' => $sch->id,
                    'order_id' => $sch->order_id,
                    'order_number' => $sch->order_number ?? $sch->order?->order_number,
                    'order_item_id' => $sch->order_item_id,
                    'product_name' => $sch->orderItem?->product_name,
                    'customer_name' => $sch->customer_name,
                    'zone' => $sch->zone,
                    'building' => $sch->building,
                    'unit' => $sch->unit,
                    'scheduled_date' => $sch->scheduled_date?->toDateString() ?? (string) $sch->scheduled_date,
                    'start_time' => $sch->start_time,
                    'end_time' => $sch->end_time,
                    'taken_time_in_mins' => $sch->taken_time_in_mins,
                    'status' => $sch->status,
                ];
            })->values();

            // Case A: No specific start_time requested - return full day booked slots and daily status
            if (empty($startTimeInput)) {
                $isEngaged = $existingSchedules->count() > 0;
                return response()->json([
                    'success' => true,
                    'available' => !$isEngaged,
                    'response' => !$isEngaged ? 'avaliable' : 'not avaliable',
                    'status' => !$isEngaged ? 'available' : 'engaged',
                    'message' => !$isEngaged
                        ? "Technician is available with no appointments on {$parsedDate}."
                        : "Technician has {$existingSchedules->count()} scheduled appointment(s) on {$parsedDate}.",
                    'technician' => [
                        'id' => (int) $technicianId,
                        'name' => $technicianName ?? "Technician #{$technicianId}",
                    ],
                    'scheduled_date' => $parsedDate,
                    'total_booked_slots' => $existingSchedules->count(),
                    'booked_slots' => $bookedSlots,
                ], 200);
            }

            // Case B: start_time requested - check for exact and overlapping collisions
            $timeRange = $this->resolveNormalizedTimes($parsedDate, $startTimeInput, $endTimeInput, $takenTimeMins);
            $reqStart = $timeRange['start_dt'];
            $reqEnd = $timeRange['end_dt'];
            $reqStartStr = $timeRange['start_str'];
            $reqEndStr = $timeRange['end_str'];
            $reqDuration = $timeRange['duration_mins'];

            $conflicts = [];

            foreach ($existingSchedules as $existing) {
                $exTimeRange = $this->resolveNormalizedTimes(
                    $parsedDate,
                    $existing->start_time,
                    $existing->end_time,
                    $existing->taken_time_in_mins
                );

                $exStart = $exTimeRange['start_dt'];
                $exEnd = $exTimeRange['end_dt'];

                // Overlap condition: startA < endB && endA > startB
                if ($reqStart < $exEnd && $reqEnd > $exStart) {
                    $conflicts[] = [
                        'schedule_id' => $existing->id,
                        'order_id' => $existing->order_id,
                        'order_number' => $existing->order_number ?? $existing->order?->order_number,
                        'order_item_id' => $existing->order_item_id,
                        'customer_name' => $existing->customer_name,
                        'zone' => $existing->zone,
                        'building' => $existing->building,
                        'unit' => $existing->unit,
                        'scheduled_date' => $parsedDate,
                        'start_time' => $existing->start_time,
                        'end_time' => $existing->end_time ?? $exTimeRange['end_str'],
                        'taken_time_in_mins' => $existing->taken_time_in_mins ?? $exTimeRange['duration_mins'],
                        'status' => $existing->status,
                    ];
                }
            }

            $isAvailable = count($conflicts) === 0;

            if ($isAvailable) {
                return response()->json([
                    'success' => true,
                    'available' => true,
                    'response' => 'avaliable',
                    'status' => 'available',
                    'message' => "Technician is available on {$parsedDate} from {$reqStartStr} to {$reqEndStr}.",
                    'technician' => [
                        'id' => (int) $technicianId,
                        'name' => $technicianName ?? "Technician #{$technicianId}",
                    ],
                    'scheduled_date' => $parsedDate,
                    'start_time' => $reqStartStr,
                    'end_time' => $reqEndStr,
                    'taken_time_in_mins' => $reqDuration,
                    'booked_slots' => $bookedSlots,
                ], 200);
            }

            return response()->json([
                'success' => true,
                'available' => false,
                'response' => 'not avaliable',
                'status' => 'engaged',
                'message' => "Technician is engaged (not available) on {$parsedDate} during {$reqStartStr} - {$reqEndStr}.",
                'technician' => [
                    'id' => (int) $technicianId,
                    'name' => $technicianName ?? "Technician #{$technicianId}",
                ],
                'scheduled_date' => $parsedDate,
                'start_time' => $reqStartStr,
                'end_time' => $reqEndStr,
                'taken_time_in_mins' => $reqDuration,
                'conflicts' => $conflicts,
                'booked_slots' => $bookedSlots,
            ], 200);

        } catch (Throwable $e) {
            Log::error('Error in ScheduleTechnicianController@checkTechnicianAvaliable: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'status' => 'error',
                'message' => 'An error occurred while checking technician availability: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a new technician schedule record.
     *
     * Required Fields:
     * - order_id
     * - order_item_id
     * - order_installation_id
     * - technician_id
     * - scheduled_date
     * - start_time
     *
     * All other fields are optional:
     * - end_time, taken_time_in_mins, zone, building, unit, street, address,
     *   customer_name, customer_phone, technician_name, status, notes, images
     *
     * Route: POST /api/admin/technician-schedule/create
     * Route: ANY  /api/admin/technician-schedule/store
     */
    public function createSchedule(Request $request): JsonResponse
    {
        try {
            $payload = array_merge(
                $request->all(),
                is_array($request->json()?->all()) ? $request->json()->all() : []
            );

            // Enforce strictly required parameters for new schedule
            $validator = Validator::make($payload, [
                'order_id' => 'required',
                'order_item_id' => 'required',
                'order_installation_id' => 'required',
                'technician_id' => 'required',
                'scheduled_date' => 'required',
                'start_time' => 'required',

                // All rest are optional
                'end_time' => 'nullable',
                'taken_time_in_mins' => 'nullable|integer',
                'zone' => 'nullable|string',
                'building' => 'nullable|string',
                'unit' => 'nullable|string',
                'street' => 'nullable|string',
                'address' => 'nullable|string',
                'customer_name' => 'nullable|string',
                'customer_phone' => 'nullable|string',
                'technician_name' => 'nullable|string',
                'status' => 'nullable|string',
                'notes' => 'nullable|string',
                'images' => 'nullable',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'status' => 'error',
                    'message' => 'Validation error. Please provide all required fields.',
                    'errors' => $validator->errors(),
                    'required_fields' => [
                        'order_id',
                        'order_item_id',
                        'order_installation_id',
                        'technician_id',
                        'scheduled_date',
                        'start_time'
                    ],
                ], 422);
            }

            // 1. Resolve Order
            $orderId = $payload['order_id'];
            $order = Order::find($orderId)
                ?? Order::where('order_number', $orderId)
                ?? Order::where('order_number', '#' . ltrim((string) $orderId, '#'))->first();

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'status' => 'error',
                    'message' => "Order with ID/Number '{$orderId}' not found.",
                ], 404);
            }

            // 2. Resolve Order Item
            $orderItemId = $payload['order_item_id'];
            $orderItem = OrderItem::where('order_id', $order->id)
                ->where(function ($q) use ($orderItemId) {
                    $q->where('id', $orderItemId)
                        ->orWhere('line_item_id', (string) $orderItemId);
                })
                ->first();

            if (!$orderItem) {
                // Fallback check item globally
                $orderItem = OrderItem::find($orderItemId);
            }

            if (!$orderItem) {
                return response()->json([
                    'success' => false,
                    'status' => 'error',
                    'message' => "Order Item with ID '{$orderItemId}' not found.",
                ], 404);
            }

            // 3. Resolve or verify OrderInstallation
            $orderInstallationId = $payload['order_installation_id'];
            $orderInstallation = OrderInstallation::find($orderInstallationId);

            if (!$orderInstallation) {
                // Check if installation exists for this order item
                $orderInstallation = OrderInstallation::where('order_item_id', $orderItem->id)->first();

                if (!$orderInstallation) {
                    // Create installation record linked to this order item
                    $installData = [
                        'order_item_id' => $orderItem->id,
                        'installation_type' => $payload['installation_type'] ?? 'standard',
                        'is_scheduled_assigned' => true,
                    ];
                    if (\Illuminate\Support\Facades\Schema::hasColumn('order_installations', 'status')) {
                        $installData['status'] = 'scheduled';
                    }
                    $orderInstallation = OrderInstallation::create($installData);
                }
            }

            // 4. Resolve Technician
            $technicianId = $payload['technician_id'];
            $technicianUser = User::find($technicianId);
            $technicianName = $payload['technician_name'] ?? $technicianUser?->name;

            if (!$technicianName && class_exists(InstallationLevelController::class)) {
                $techStatic = collect(InstallationLevelController::TECHNICIANS)->first(function ($t) use ($technicianId) {
                    return (string) ($t['id'] ?? '') === (string) $technicianId
                        || (string) ($t['technician_id'] ?? '') === (string) $technicianId;
                });
                if ($techStatic) {
                    $technicianName = $techStatic['name'] ?? ($techStatic['technician_name'] ?? null);
                }
            }

            // 5. Parse date and normalized times
            $parsedDate = Carbon::parse($payload['scheduled_date'])->toDateString();
            $startTime = trim((string) $payload['start_time']);
            $endTime = !empty($payload['end_time']) ? trim((string) $payload['end_time']) : null;
            $takenTimeMins = !empty($payload['taken_time_in_mins']) ? (int) $payload['taken_time_in_mins'] : null;

            $timeRange = $this->resolveNormalizedTimes($parsedDate, $startTime, $endTime, $takenTimeMins);

            // Optional: check if technician is engaged unless force = true
            $force = filter_var($payload['force'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if (!$force) {
                $conflict = TechnicianScheduled::where('technician_id', $technicianId)
                    ->whereDate('scheduled_date', $parsedDate)
                    ->whereNotIn('status', ['cancelled', 'rejected'])
                    ->where(function ($q) use ($timeRange) {
                        $q->where(function ($sub) use ($timeRange) {
                            $sub->where('start_time', '<', $timeRange['end_str'])
                                ->where('end_time', '>', $timeRange['start_str']);
                        })->orWhere('start_time', $timeRange['start_str']);
                    })
                    ->first();

                if ($conflict) {
                    return response()->json([
                        'success' => false,
                        'status' => 'engaged',
                        'response' => 'not avaliable',
                        'message' => "Technician is engaged at this time with another schedule (#{$conflict->id}). Pass 'force': true to override.",
                        'conflict' => $conflict,
                    ], 409);
                }
            }

            // 6. Autofill optional location/customer fields from Order if not provided
            $zone = $payload['zone'] ?? $order->driverAssignment?->zone ?? null;
            $building = $payload['building'] ?? null;
            $unit = $payload['unit'] ?? null;
            $street = $payload['street'] ?? null;
            $address = $payload['address'] ?? $order->delivery_address ?? null;
            $customerName = $payload['customer_name'] ?? $order->customer_name ?? null;
            $customerPhone = $payload['customer_phone'] ?? $order->customer_phone ?? null;

            // 7. Create Schedule Record
            $schedule = DB::transaction(function () use (
                $order,
                $orderItem,
                $orderInstallation,
                $technicianId,
                $technicianName,
                $parsedDate,
                $timeRange,
                $zone,
                $building,
                $unit,
                $street,
                $address,
                $customerName,
                $customerPhone,
                $payload
            ) {
                $record = TechnicianScheduled::create([
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'order_item_id' => $orderItem->id,
                    'order_installation_id' => $orderInstallation->id,
                    'technician_id' => $technicianId,
                    'technician_name' => $technicianName,
                    'scheduled_date' => $parsedDate,
                    'start_time' => $timeRange['start_str'],
                    'end_time' => $timeRange['end_str'],
                    'taken_time_in_mins' => $timeRange['duration_mins'],
                    'scheduled_at' => $timeRange['start_dt'],
                    'zone' => $zone,
                    'building' => $building,
                    'unit' => $unit,
                    'street' => $street,
                    'address' => $address,
                    'customer_name' => $customerName,
                    'customer_phone' => $customerPhone,
                    'status' => $payload['status'] ?? 'scheduled',
                    'notes' => $payload['notes'] ?? null,
                    'images' => $payload['images'] ?? null,
                ]);

                // Sync status with order_installations
                $installationUpdates = ['is_scheduled_assigned' => true];
                if (\Illuminate\Support\Facades\Schema::hasColumn('order_installations', 'status')) {
                    $installationUpdates['status'] = 'scheduled';
                }
                $orderInstallation->update($installationUpdates);

                // Also update order item assignment if not set
                if (empty($orderItem->assigned_to)) {
                    $orderItem->update([
                        'assigned_to' => $technicianId,
                        'assigned_user_name' => $technicianName,
                    ]);
                }

                // Audit logging
                try {
                    if (class_exists(OrderStatusLog::class)) {
                        OrderStatusLog::create([
                            'order_id' => $order->id,
                            'order_item_id' => $orderItem->id,
                            'user_id' => Auth::id() ?? $technicianId,
                            'user_name' => Auth::user()?->name ?? $technicianName ?? 'Admin',
                            'action' => 'technician_scheduled',
                            'new_status' => 'scheduled',
                            'notes' => "Scheduled with technician {$technicianName} on {$parsedDate} at {$timeRange['start_str']}",
                        ]);
                    }
                } catch (Throwable $logEx) {
                    Log::warning('OrderStatusLog error in createSchedule: ' . $logEx->getMessage());
                }

                return $record;
            });

            return response()->json([
                'success' => true,
                'status' => 'success',
                'message' => 'Technician schedule created successfully.',
                'data' => $schedule->fresh([
                    'order:id,order_number,customer_name,status',
                    'orderItem:id,product_name,product_code,status',
                    'installation',
                    'technician:id,name,email,phone',
                ]),
            ], 201);

        } catch (Throwable $e) {
            Log::error('Error in ScheduleTechnicianController@createSchedule: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'status' => 'error',
                'message' => 'An error occurred while creating technician schedule: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * List all technician schedules with filtering and pagination.
     *
     * Route: GET /api/admin/technician-schedule/list
     */
    public function listSchedules(Request $request): JsonResponse
    {
        try {
            $query = TechnicianScheduled::with([
                'order:id,order_number,customer_name,customer_phone,status',
                'orderItem:id,product_name,product_code,status',
                'installation',
                'technician:id,name,email,phone',
            ]);

            if ($request->has('technician_id')) {
                $query->where('technician_id', $request->input('technician_id'));
            }

            if ($request->has('scheduled_date')) {
                $query->whereDate('scheduled_date', $request->input('scheduled_date'));
            }

            if ($request->has('status') && $request->input('status') !== 'all') {
                $query->where('status', $request->input('status'));
            }

            if ($request->has('order_id')) {
                $query->where('order_id', $request->input('order_id'));
            }

            $perPage = max(1, min((int) $request->input('per_page', 15), 100));
            $paginated = $query->orderBy('scheduled_date', 'desc')
                ->orderBy('start_time', 'asc')
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'status' => 'success',
                'message' => 'Technician schedules retrieved successfully.',
                'pagination' => [
                    'current_page' => $paginated->currentPage(),
                    'per_page' => $paginated->perPage(),
                    'total' => $paginated->total(),
                    'last_page' => $paginated->lastPage(),
                ],
                'data' => $paginated->items(),
            ], 200);

        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'status' => 'error',
                'message' => 'Error retrieving schedules: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Helper to normalize start time, end time, and duration in minutes into Carbon instances and strings.
     *
     * @param string $dateStr (YYYY-MM-DD)
     * @param string|null $startTimeStr
     * @param string|null $endTimeStr
     * @param int|null $takenTimeMins
     * @return array{start_dt: Carbon, end_dt: Carbon, start_str: string, end_str: string, duration_mins: int}
     */
    protected function resolveNormalizedTimes(
        string $dateStr,
        ?string $startTimeStr,
        ?string $endTimeStr,
        ?int $takenTimeMins
    ): array {
        $cleanStart = !empty($startTimeStr) ? trim($startTimeStr) : '09:00:00';
        $startDt = Carbon::parse("{$dateStr} {$cleanStart}");

        if (!empty($endTimeStr)) {
            $cleanEnd = trim($endTimeStr);
            $endDt = Carbon::parse("{$dateStr} {$cleanEnd}");

            if ($endDt->lessThanOrEqualTo($startDt)) {
                // If end time is earlier than start time, treat as cross-midnight or add default 60 mins
                $endDt = (clone $startDt)->addMinutes(60);
            }

            $duration = (int) $startDt->diffInMinutes($endDt);
        } elseif (!empty($takenTimeMins) && $takenTimeMins > 0) {
            $duration = $takenTimeMins;
            $endDt = (clone $startDt)->addMinutes($takenTimeMins);
        } else {
            // Default slot duration: 60 minutes
            $duration = 60;
            $endDt = (clone $startDt)->addMinutes(60);
        }

        return [
            'start_dt' => $startDt,
            'end_dt' => $endDt,
            'start_str' => $startDt->format('H:i:s'),
            'end_str' => $endDt->format('H:i:s'),
            'duration_mins' => $duration,
        ];
    }
}
