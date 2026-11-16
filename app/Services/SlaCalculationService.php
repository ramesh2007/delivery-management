<?php

namespace App\Services;

use App\Models\MisOrderSla;
use App\Models\MisShiftSchedule;
use App\Models\MisSlaConfig;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class SlaCalculationService
{
    /**
     * Cache weekly shift schedules in memory during calculation cycle.
     */
    protected ?Collection $schedules = null;

    /**
     * Cache SLA configs in memory.
     */
    protected ?Collection $slaConfigs = null;

    public function __construct()
    {
        $this->loadConfigs();
    }

    /**
     * Load or reload shift schedules & SLA configs.
     */
    public function loadConfigs(): void
    {
        try {
            $this->schedules = MisShiftSchedule::all()->keyBy('day_of_week');
            $this->slaConfigs = MisSlaConfig::where('is_active', true)->get()->keyBy('sla_type');
        } catch (\Throwable $e) {
            $this->schedules = collect();
            $this->slaConfigs = collect();
        }
    }

    /**
     * Calculate SLA Start & Due Date based on working shift windows.
     *
     * @param Carbon $orderPlacedAt
     * @param string $slaType ('normal' or 'express')
     * @return array [ 'sla_type', 'duration_minutes', 'sla_start_at', 'sla_due_at' ]
     */
    public function calculateSla(Carbon $orderPlacedAt, string $slaType = 'normal'): array
    {
        $slaType = strtolower(trim($slaType));
        if ($slaType !== 'express') {
            $slaType = 'normal';
        }

        // 1. Resolve duration in minutes
        $config = $this->slaConfigs ? $this->slaConfigs->get($slaType) : null;
        $durationMinutes = $config ? (int) $config->duration_minutes : ($slaType === 'express' ? 60 : 240);
        $remainingMinutes = $durationMinutes;

        // Fallback default schedules if table not yet migrated/seeded
        $schedules = $this->getSchedules();

        $cursor = $orderPlacedAt->copy();
        $slaStartAt = null;

        // 2. Find exact SLA Start Time (accounting for after-hours, before-hours, and holidays)
        $maxSafetyLoop = 14; // Prevent infinite loop if all days are marked off
        $loopCount = 0;

        while ($slaStartAt === null && $loopCount < $maxSafetyLoop) {
            $loopCount++;
            $dayOfWeek = $cursor->dayOfWeek; // 0 = Sunday, 6 = Saturday
            $schedule = $schedules->get($dayOfWeek);

            if ($schedule && $schedule['is_working_day']) {
                $shiftStart = $cursor->copy()->setTimeFromTimeString($schedule['shift_start']);
                $shiftEnd = $cursor->copy()->setTimeFromTimeString($schedule['shift_end']);

                if ($cursor->lt($shiftStart)) {
                    // Before shift starts today -> SLA starts at shift_start (e.g. 09:00 AM)
                    $slaStartAt = $shiftStart;
                    $cursor = $shiftStart->copy();
                } elseif ($cursor->gte($shiftStart) && $cursor->lt($shiftEnd)) {
                    // Inside working window -> SLA starts immediately
                    $slaStartAt = $cursor->copy();
                } else {
                    // After shift end (e.g. 18:01) -> roll to next calendar day at 00:00
                    $cursor = $cursor->addDay()->startOfDay();
                }
            } else {
                // Holiday (e.g. Friday) -> roll to next calendar day at 00:00
                $cursor = $cursor->addDay()->startOfDay();
            }
        }

        if (!$slaStartAt) {
            $slaStartAt = $orderPlacedAt->copy();
        }

        // 3. Consume SLA duration across working windows
        $calculationCursor = $slaStartAt->copy();
        $loopCount = 0;

        while ($remainingMinutes > 0 && $loopCount < $maxSafetyLoop) {
            $loopCount++;
            $dayOfWeek = $calculationCursor->dayOfWeek;
            $schedule = $schedules->get($dayOfWeek);

            if ($schedule && $schedule['is_working_day']) {
                $shiftEnd = $calculationCursor->copy()->setTimeFromTimeString($schedule['shift_end']);

                // Minutes available in today's remaining shift
                $minutesAvailableToday = max(0, $calculationCursor->diffInMinutes($shiftEnd, false));

                if ($remainingMinutes <= $minutesAvailableToday) {
                    // Fits within today's remaining shift!
                    $calculationCursor = $calculationCursor->addMinutes($remainingMinutes);
                    $remainingMinutes = 0;
                } else {
                    // Exceeds today's shift -> use all remaining minutes today and roll over
                    $remainingMinutes -= $minutesAvailableToday;
                    $calculationCursor = $calculationCursor->addDay()->startOfDay();

                    // Advance cursor to next active working day's shift start
                    $nextDayOfWeek = $calculationCursor->dayOfWeek;
                    $nextSchedule = $schedules->get($nextDayOfWeek);

                    $findNextLoop = 0;
                    while ((!$nextSchedule || !$nextSchedule['is_working_day']) && $findNextLoop < 7) {
                        $findNextLoop++;
                        $calculationCursor = $calculationCursor->addDay()->startOfDay();
                        $nextDayOfWeek = $calculationCursor->dayOfWeek;
                        $nextSchedule = $schedules->get($nextDayOfWeek);
                    }

                    if ($nextSchedule) {
                        $calculationCursor->setTimeFromTimeString($nextSchedule['shift_start']);
                    }
                }
            } else {
                // Skip holiday
                $calculationCursor = $calculationCursor->addDay()->startOfDay();
            }
        }

        return [
            'sla_type' => $slaType,
            'duration_minutes' => $durationMinutes,
            'sla_start_at' => $slaStartAt,
            'sla_due_at' => $calculationCursor,
        ];
    }

    /**
     * Create or update the MIS SLA tracking record for an order.
     */
    public function createOrUpdateOrderSla(Order $order, string $slaType = 'normal'): MisOrderSla
    {
        $placedAt = $order->created_at ? Carbon::parse($order->created_at) : now();

        $calc = $this->calculateSla($placedAt, $slaType);

        $deliveredAt = $order->delivered_at ? Carbon::parse($order->delivered_at) : null;
        $status = 'within_sla';
        $isBreached = false;
        $breachedByMinutes = null;
        $totalTatMinutes = null;

        if ($deliveredAt) {
            $totalTatMinutes = max(0, (int) $calc['sla_start_at']->diffInMinutes($deliveredAt, false));

            if ($deliveredAt->gt($calc['sla_due_at'])) {
                $status = 'completed_breached';
                $isBreached = true;
                $breachedByMinutes = (int) $calc['sla_due_at']->diffInMinutes($deliveredAt);
            } else {
                $status = 'completed_within_sla';
                $isBreached = false;
            }
        } else {
            $now = now();
            if ($now->gt($calc['sla_due_at'])) {
                $status = 'breached';
                $isBreached = true;
                $breachedByMinutes = (int) $calc['sla_due_at']->diffInMinutes($now);
            } elseif ($now->diffInMinutes($calc['sla_due_at'], false) <= 30) {
                $status = 'near_breach';
            } else {
                $status = 'within_sla';
            }
        }

        return MisOrderSla::updateOrCreate(
            ['order_id' => $order->id],
            [
                'order_number' => $order->order_number,
                'sla_type' => $calc['sla_type'],
                'sla_duration_minutes' => $calc['duration_minutes'],
                'order_placed_at' => $placedAt,
                'sla_start_at' => $calc['sla_start_at'],
                'sla_due_at' => $calc['sla_due_at'],
                'sla_status' => $status,
                'is_breached' => $isBreached,
                'breached_by_minutes' => $breachedByMinutes,
                'total_tat_minutes' => $totalTatMinutes,
                'completed_at' => $deliveredAt,
            ]
        );
    }

    /**
     * Mark an order delivered and lock in final SLA compliance.
     */
    public function markOrderDelivered(Order $order, ?Carbon $deliveredAt = null): ?MisOrderSla
    {
        $orderSla = MisOrderSla::where('order_id', $order->id)->first();
        if (!$orderSla) {
            return $this->createOrUpdateOrderSla($order);
        }

        $deliveredAt = $deliveredAt ?: ($order->delivered_at ? Carbon::parse($order->delivered_at) : now());
        $dueAt = $orderSla->sla_due_at ? Carbon::parse($orderSla->sla_due_at) : null;
        $startAt = $orderSla->sla_start_at ? Carbon::parse($orderSla->sla_start_at) : Carbon::parse($order->created_at);

        $totalTatMinutes = max(0, (int) $startAt->diffInMinutes($deliveredAt, false));
        $isBreached = false;
        $breachedByMinutes = null;

        if ($dueAt && $deliveredAt->gt($dueAt)) {
            $status = 'completed_breached';
            $isBreached = true;
            $breachedByMinutes = (int) $dueAt->diffInMinutes($deliveredAt);
        } else {
            $status = 'completed_within_sla';
            $isBreached = false;
        }

        $orderSla->update([
            'completed_at' => $deliveredAt,
            'sla_status' => $status,
            'is_breached' => $isBreached,
            'breached_by_minutes' => $breachedByMinutes,
            'total_tat_minutes' => $totalTatMinutes,
        ]);

        return $orderSla->fresh();
    }

    /**
     * Default shift schedules dictionary (Sun-Thu 9-18, Fri off, Sat 9-13).
     */
    protected function getSchedules(): Collection
    {
        if ($this->schedules && $this->schedules->isNotEmpty()) {
            return $this->schedules->map(function ($s) {
                return is_array($s) ? $s : [
                    'day_of_week' => (int) $s->day_of_week,
                    'is_working_day' => (bool) $s->is_working_day,
                    'is_half_day' => (bool) $s->is_half_day,
                    'shift_start' => (string) $s->shift_start,
                    'shift_end' => (string) $s->shift_end,
                ];
            });
        }

        return collect([
            0 => ['day_of_week' => 0, 'is_working_day' => true,  'is_half_day' => false, 'shift_start' => '09:00:00', 'shift_end' => '18:00:00'],
            1 => ['day_of_week' => 1, 'is_working_day' => true,  'is_half_day' => false, 'shift_start' => '09:00:00', 'shift_end' => '18:00:00'],
            2 => ['day_of_week' => 2, 'is_working_day' => true,  'is_half_day' => false, 'shift_start' => '09:00:00', 'shift_end' => '18:00:00'],
            3 => ['day_of_week' => 3, 'is_working_day' => true,  'is_half_day' => false, 'shift_start' => '09:00:00', 'shift_end' => '18:00:00'],
            4 => ['day_of_week' => 4, 'is_working_day' => true,  'is_half_day' => false, 'shift_start' => '09:00:00', 'shift_end' => '18:00:00'],
            5 => ['day_of_week' => 5, 'is_working_day' => false, 'is_half_day' => false, 'shift_start' => '00:00:00', 'shift_end' => '00:00:00'], // Friday
            6 => ['day_of_week' => 6, 'is_working_day' => true,  'is_half_day' => true,  'shift_start' => '09:00:00', 'shift_end' => '13:00:00'], // Saturday
        ]);
    }
}

