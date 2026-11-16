<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\MisOrderSla;
use App\Models\MisShiftSchedule;
use App\Models\MisSlaConfig;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeliveryScheduleSettingsController extends Controller
{
    private const DAY_NAMES = [
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
    ];

    /**
     * Create or update shift timings in mis_shift_schedules (or fetch them on bare GET).
     */
    public function createDeliveryScheduleSettings(Request $request): JsonResponse
    {
        if ($request->isJson() && trim($request->getContent()) !== '') {
            json_decode($request->getContent(), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid JSON payload: ' . json_last_error_msg(),
                ], 400);
            }
        }

        // Bare GET with no schedule inputs returns existing shift timings
        if (
            $request->isMethod('get') &&
            !$request->hasAny(['schedules', 'shift_schedules', 'days', 'day_of_week', 'day_name', 'id'])
        ) {
            return response()->json([
                'success' => true,
                'message' => 'Shift schedule timings retrieved successfully.',
                'data' => MisShiftSchedule::orderBy('day_of_week')->get(),
            ], 200);
        }

        $rawEntries = $this->extractScheduleEntries($request);
        if (empty($rawEntries)) {
            return response()->json([
                'success' => false,
                'message' => 'Please provide shift schedule data (schedules array or day_of_week/day_name).',
            ], 422);
        }

        $normalizedEntries = [];
        $errors = [];
        $seenDays = [];

        foreach ($rawEntries as $index => $entry) {
            if (!is_array($entry)) {
                $errors["schedules.{$index}"][] = 'Each schedule item must be an object/array.';
                continue;
            }

            $result = $this->normalizeScheduleEntry($entry);
            if (!empty($result['errors'])) {
                foreach ($result['errors'] as $field => $messages) {
                    $errors["schedules.{$index}.{$field}"] = $messages;
                }
                continue;
            }

            $dayOfWeek = $result['data']['day_of_week'];
            if (isset($seenDays[$dayOfWeek])) {
                $errors["schedules.{$index}.day_of_week"][] = "Duplicate entry for {$result['data']['day_name']} (day_of_week: {$dayOfWeek}).";
                continue;
            }

            $seenDays[$dayOfWeek] = true;
            $normalizedEntries[] = $result['data'];
        }

        if (!empty($errors)) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed for shift schedule timings.',
                'errors' => $errors,
            ], 422);
        }

        try {
            $savedSchedules = DB::transaction(function () use ($normalizedEntries) {
                $saved = [];

                foreach ($normalizedEntries as $item) {
                    $saved[] = MisShiftSchedule::updateOrCreate(
                        ['day_of_week' => $item['day_of_week']],
                        [
                            'day_name' => $item['day_name'],
                            'is_working_day' => $item['is_working_day'],
                            'is_half_day' => $item['is_half_day'],
                            'shift_start' => $item['shift_start'],
                            'shift_end' => $item['shift_end'],
                        ]
                    );
                }

                return $saved;
            });

            return response()->json([
                'success' => true,
                'message' => 'Shift schedule timings saved successfully.',
                'data' => $savedSchedules,
                'schedules' => MisShiftSchedule::orderBy('day_of_week')->get(),
            ], 200);
        } catch (Throwable $e) {
            Log::error('Failed to save delivery shift schedule settings: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to save shift schedule timings.',
            ], 500);
        }
    }

    /**
     * Extract either a bulk list of schedule entries or wrap a single-day payload.
     */
    private function extractScheduleEntries(Request $request): array
    {
        $payload = $request->all();

        foreach (['schedules', 'shift_schedules', 'days'] as $listKey) {
            if (isset($payload[$listKey]) && is_array($payload[$listKey])) {
                return array_values($payload[$listKey]);
            }
        }

        // Support raw JSON indexed array payload
        if (array_is_list($payload) && !empty($payload)) {
            return $payload;
        }

        if ($request->hasAny(['id', 'day_of_week', 'day_name'])) {
            return [$payload];
        }

        return [];
    }

    /**
     * Validate and normalize one shift schedule entry against mis_shift_schedules columns.
     */
    private function normalizeScheduleEntry(array $entry): array
    {
        $errors = [];
        $existing = null;

        if (isset($entry['id']) && $entry['id'] !== '') {
            $existing = MisShiftSchedule::find($entry['id']);
            if (!$existing) {
                return ['errors' => ['id' => ["Shift schedule ID {$entry['id']} not found."]]];
            }
        }

        [$dayOfWeek, $dayName, $dayError] = $this->resolveDay($entry, $existing);
        if ($dayError !== null) {
            return ['errors' => ['day_of_week' => [$dayError]]];
        }

        if (!$existing) {
            $existing = MisShiftSchedule::where('day_of_week', $dayOfWeek)->first();
        }

        // Parse boolean flags, preserving existing values on partial updates
        $isWorkingDay = $this->parseBooleanField($entry, 'is_working_day', $existing ? (bool) $existing->is_working_day : true, $errors);
        $isHalfDay = $isWorkingDay
            ? $this->parseBooleanField($entry, 'is_half_day', $existing ? (bool) $existing->is_half_day : false, $errors)
            : false;

        // Still validate is_half_day if explicitly provided as non-boolean when is_working_day is false
        if (!$isWorkingDay && array_key_exists('is_half_day', $entry) && $entry['is_half_day'] !== null && $entry['is_half_day'] !== '') {
            $this->parseBooleanField($entry, 'is_half_day', false, $errors);
        }

        $defaultStart = $isWorkingDay ? ($existing ? (string) $existing->shift_start : '09:00:00') : '00:00:00';
        $defaultEnd = $isWorkingDay ? ($existing ? (string) $existing->shift_end : '18:00:00') : '00:00:00';

        $shiftStart = $this->parseTimeField($entry, 'shift_start', $defaultStart, $errors);
        $shiftEnd = $this->parseTimeField($entry, 'shift_end', $defaultEnd, $errors);

        if ($isWorkingDay && empty($errors) && strcmp($shiftEnd, $shiftStart) <= 0) {
            $errors['shift_end'][] = 'The shift_end must be after shift_start when is_working_day is true.';
        }

        if (!empty($errors)) {
            return ['errors' => $errors];
        }

        return [
            'data' => [
                'day_of_week' => $dayOfWeek,
                'day_name' => $dayName,
                'is_working_day' => $isWorkingDay,
                'is_half_day' => $isHalfDay,
                'shift_start' => $shiftStart,
                'shift_end' => $shiftEnd,
            ],
        ];
    }

    /**
     * Parse an optional boolean field from the entry, recording validation errors if invalid.
     */
    private function parseBooleanField(array $entry, string $field, bool $default, array &$errors): bool
    {
        if (!array_key_exists($field, $entry) || $entry[$field] === null || $entry[$field] === '') {
            return $default;
        }

        $parsed = filter_var($entry[$field], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            $errors[$field][] = "The {$field} field must be a boolean.";
            return $default;
        }

        return $parsed;
    }

    /**
     * Parse an optional time field from the entry into H:i:s, recording validation errors if invalid.
     */
    private function parseTimeField(array $entry, string $field, string $default, array &$errors): string
    {
        if (!array_key_exists($field, $entry) || $entry[$field] === null || $entry[$field] === '') {
            return $default;
        }

        $normalized = $this->normalizeTime($entry[$field]);
        if ($normalized === null) {
            $errors[$field][] = "The {$field} must be a valid time (e.g. 09:00 or 09:00:00).";
            return $default;
        }

        return $normalized;
    }

    /**
     * Resolve day_of_week (0-6) and canonical day_name from input or existing record.
     *
     * @return array{0: ?int, 1: ?string, 2: ?string}
     */
    private function resolveDay(array $entry, ?MisShiftSchedule $existing): array
    {
        if (array_key_exists('day_of_week', $entry) && $entry['day_of_week'] !== null && $entry['day_of_week'] !== '') {
            if (filter_var($entry['day_of_week'], FILTER_VALIDATE_INT) === false) {
                return [null, null, 'The day_of_week must be an integer between 0 (Sunday) and 6 (Saturday).'];
            }

            $dayOfWeek = (int) $entry['day_of_week'];
            if (!isset(self::DAY_NAMES[$dayOfWeek])) {
                return [null, null, 'The day_of_week must be between 0 (Sunday) and 6 (Saturday).'];
            }

            $dayName = !empty($entry['day_name']) ? mb_substr(trim((string) $entry['day_name']), 0, 15) : self::DAY_NAMES[$dayOfWeek];
            return [$dayOfWeek, $dayName, null];
        }

        if (!empty($entry['day_name'])) {
            $inputLower = strtolower(trim((string) $entry['day_name']));
            foreach (self::DAY_NAMES as $index => $canonicalName) {
                $canonicalLower = strtolower($canonicalName);
                if ($inputLower === $canonicalLower || $inputLower === substr($canonicalLower, 0, 3)) {
                    return [$index, $canonicalName, null];
                }
            }

            return [null, null, "Unrecognized day_name '{$entry['day_name']}'. Expected Sunday through Saturday."];
        }

        if ($existing) {
            return [(int) $existing->day_of_week, (string) $existing->day_name, null];
        }

        return [null, null, 'Either day_of_week (0-6) or day_name is required.'];
    }

    /**
     * Strictly normalize common time formats (H:i, H:i:s, g:i A) to H:i:s.
     */
    private function normalizeTime(mixed $value): ?string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }

        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }

        foreach (['H:i:s', 'H:i', 'G:i:s', 'G:i', 'g:i A', 'g:iA', 'g:i:s A', 'g:i a', 'g:ia'] as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $trimmed);
                $lastErrors = Carbon::getLastErrors();
                $hasErrors = is_array($lastErrors)
                    && (($lastErrors['warning_count'] ?? 0) > 0 || ($lastErrors['error_count'] ?? 0) > 0);

                if ($parsed !== false && !$hasErrors) {
                    return $parsed->format('H:i:s');
                }
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }
}
