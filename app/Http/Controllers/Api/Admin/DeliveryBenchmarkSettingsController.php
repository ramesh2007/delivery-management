<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\MisBenchmark;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeliveryBenchmarkSettingsController extends Controller
{
    private const ROLE_TO_STAGE = [
        'picker' => 'picking',
        'packer' => 'packing',
        'packed_to_driver' => 'packed_to_driver',
        'driver' => 'delivery',
    ];

    private const STAGE_TO_ROLE = [
        'picking' => 'picker',
        'packing' => 'packer',
        'packed_to_driver' => 'packed_to_driver',
        'delivery' => 'driver',
    ];

    private const STAGE_DEFAULTS = [
        'picking' => ['display_name' => 'Picking Benchmark (min)', 'sort_order' => 1],
        'packing' => ['display_name' => 'Packing Benchmark (min)', 'sort_order' => 2],
        'packed_to_driver' => ['display_name' => 'Packed → Driver Benchmark (min)', 'sort_order' => 3],
        'delivery' => ['display_name' => 'Delivery Benchmark (min)', 'sort_order' => 4],
    ];

    private const MUTATION_KEYS = [
        'benchmarks',
        'settings',
        'configs',
        'roles',
        'stages',
        'display_name',
        'benchmark_minutes',
        'minutes',
        'is_freeze_active',
        'freeze_start',
        'freeze_end',
        'freeze_windows',
        'sort_order',
        'is_active',
        'picking_benchmark_minutes',
        'packing_benchmark_minutes',
        'packed_to_driver_benchmark_minutes',
        'delivery_benchmark_minutes',
        'picking_is_freeze_active',
        'packing_is_freeze_active',
        'packed_to_driver_is_freeze_active',
        'delivery_is_freeze_active',
        'picking_freeze_start',
        'packing_freeze_start',
        'packed_to_driver_freeze_start',
        'delivery_freeze_start',
        'picking_freeze_end',
        'packing_freeze_end',
        'packed_to_driver_freeze_end',
        'delivery_freeze_end',
        'picking_freeze_windows',
        'packing_freeze_windows',
        'packed_to_driver_freeze_windows',
        'delivery_freeze_windows',
    ];

    /**
     * Retrieve, create, or update benchmark timings and SLA freeze windows in mis_benchmark.
     */
    public function getDeliveryBenchmarkSettings(Request $request): JsonResponse
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

        if ($request->isMethod('get') && !$request->hasAny(self::MUTATION_KEYS)) {
            return $this->fetchBenchmarkSettings($request);
        }

        $rawEntries = $this->extractBenchmarkEntries($request);
        if (empty($rawEntries)) {
            return response()->json([
                'success' => false,
                'message' => 'Please provide benchmark configuration data (benchmarks array or role/stage settings).',
            ], 422);
        }

        $normalizedEntries = [];
        $errors = [];
        $seenRoles = [];
        $seenStages = [];

        foreach ($rawEntries as $index => $entry) {
            if (!is_array($entry)) {
                $errors["benchmarks.{$index}"][] = 'Each benchmark item must be an object/array.';
                continue;
            }

            $result = $this->normalizeBenchmarkEntry($entry);
            if (!empty($result['errors'])) {
                foreach ($result['errors'] as $field => $messages) {
                    $errors["benchmarks.{$index}.{$field}"] = $messages;
                }
                continue;
            }

            $role = $result['data']['role'];
            $stage = $result['data']['stage'];

            if (isset($seenRoles[$role])) {
                $errors["benchmarks.{$index}.role"][] = "Duplicate entry for role '{$role}'.";
                continue;
            }
            if (isset($seenStages[$stage])) {
                $errors["benchmarks.{$index}.stage"][] = "Duplicate entry for stage '{$stage}'.";
                continue;
            }

            $seenRoles[$role] = true;
            $seenStages[$stage] = true;
            $normalizedEntries[] = $result['data'];
        }

        if (!empty($errors)) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed for delivery benchmark settings.',
                'errors' => $errors,
            ], 422);
        }

        try {
            $savedBenchmarks = DB::transaction(function () use ($normalizedEntries) {
                $saved = [];

                foreach ($normalizedEntries as $item) {
                    $attributes = [
                        'role' => $item['role'],
                        'stage' => $item['stage'],
                        'display_name' => $item['display_name'],
                        'benchmark_minutes' => $item['benchmark_minutes'],
                        'is_freeze_active' => $item['is_freeze_active'],
                        'freeze_start' => $item['freeze_start'],
                        'freeze_end' => $item['freeze_end'],
                        'freeze_windows' => $item['freeze_windows'],
                        'sort_order' => $item['sort_order'],
                        'is_active' => $item['is_active'],
                    ];

                    if ($item['existing_id'] !== null) {
                        $benchmark = MisBenchmark::findOrFail($item['existing_id']);
                        $benchmark->update($attributes);
                        $saved[] = $benchmark->fresh();
                    } else {
                        $saved[] = MisBenchmark::create($attributes);
                    }
                }

                return $saved;
            });

            return response()->json([
                'success' => true,
                'message' => 'Delivery benchmark settings saved successfully.',
                'data' => $savedBenchmarks,
                'benchmarks' => MisBenchmark::orderBy('sort_order')->orderBy('id')->get(),
            ], 200);
        } catch (Throwable $e) {
            Log::error('Failed to save delivery benchmark settings: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to save delivery benchmark settings.',
            ], 500);
        }
    }

    /**
     * Fetch existing benchmark and freeze-window settings.
     */
    private function fetchBenchmarkSettings(Request $request): JsonResponse
    {
        $query = MisBenchmark::query()->orderBy('sort_order')->orderBy('id');

        if ($request->filled('id')) {
            $query->where('id', $request->input('id'));
        }

        if ($request->filled('role')) {
            $query->where('role', $this->normalizeSlug($request->input('role')));
        }

        if ($request->filled('stage')) {
            $query->where('stage', $this->normalizeSlug($request->input('stage')));
        }

        if ($request->filled('active_only')) {
            $activeOnly = filter_var($request->input('active_only'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($activeOnly !== null) {
                $query->where('is_active', $activeOnly);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Delivery benchmark settings retrieved successfully.',
            'data' => $query->get(),
        ], 200);
    }

    /**
     * Extract bulk list entries, single role/stage entry, or flat form fields.
     */
    private function extractBenchmarkEntries(Request $request): array
    {
        $payload = $request->all();

        foreach (['benchmarks', 'settings', 'configs', 'roles', 'stages'] as $listKey) {
            if (isset($payload[$listKey]) && is_array($payload[$listKey])) {
                return array_values($payload[$listKey]);
            }
        }

        if (array_is_list($payload) && !empty($payload)) {
            return $payload;
        }

        if ($request->hasAny(['id', 'role', 'stage'])) {
            return [$payload];
        }

        $flatEntries = [];
        $flatFieldSuffixes = ['benchmark_minutes', 'is_freeze_active', 'freeze_start', 'freeze_end', 'freeze_windows', 'is_active'];

        foreach (self::STAGE_TO_ROLE as $stage => $role) {
            $entry = [];
            foreach ($flatFieldSuffixes as $suffix) {
                $flatKey = "{$stage}_{$suffix}";
                if (array_key_exists($flatKey, $payload)) {
                    $entry[$suffix] = $payload[$flatKey];
                }
            }

            if (!empty($entry)) {
                $entry['role'] = $role;
                $entry['stage'] = $stage;
                $flatEntries[] = $entry;
            }
        }

        return $flatEntries;
    }

    /**
     * Validate and normalize one benchmark entry against mis_benchmark columns.
     */
    private function normalizeBenchmarkEntry(array $entry): array
    {
        $errors = [];
        $existing = null;

        if (isset($entry['id']) && $entry['id'] !== '') {
            $existing = MisBenchmark::find($entry['id']);
            if (!$existing) {
                return ['errors' => ['id' => ["Benchmark ID {$entry['id']} not found."]]];
            }
        }

        [$role, $stage] = $this->resolveRoleAndStage($entry, $existing, $errors);

        if (!$existing && empty($errors) && $role !== null && $stage !== null) {
            $existing = MisBenchmark::where('role', $role)->orWhere('stage', $stage)->first();
        }

        if ($existing && empty($errors)) {
            $roleConflict = MisBenchmark::where('role', $role)->where('id', '!=', $existing->id)->exists();
            if ($roleConflict) {
                $errors['role'][] = "Another benchmark already uses role '{$role}'.";
            }

            $stageConflict = MisBenchmark::where('stage', $stage)->where('id', '!=', $existing->id)->exists();
            if ($stageConflict) {
                $errors['stage'][] = "Another benchmark already uses stage '{$stage}'.";
            }
        }

        $benchmarkMinutes = $this->parseBenchmarkMinutes($entry, $existing, $errors);
        $isActive = $this->parseBooleanField($entry, 'is_active', $existing ? (bool) $existing->is_active : true, $errors);
        $isFreezeActive = $this->parseBooleanField($entry, 'is_freeze_active', $existing ? (bool) $existing->is_freeze_active : false, $errors);

        [$freezeStart, $freezeEnd, $freezeWindows] = $this->parseFreezeConfig($entry, $existing, $isFreezeActive, $errors);

        $sortOrder = $existing ? (int) $existing->sort_order : (self::STAGE_DEFAULTS[$stage]['sort_order'] ?? 0);
        if (array_key_exists('sort_order', $entry) && $entry['sort_order'] !== null && $entry['sort_order'] !== '') {
            if (filter_var($entry['sort_order'], FILTER_VALIDATE_INT) === false || (int) $entry['sort_order'] < 0) {
                $errors['sort_order'][] = 'The sort_order must be a non-negative integer.';
            } else {
                $sortOrder = (int) $entry['sort_order'];
            }
        }

        $displayName = $existing ? (string) $existing->display_name : null;
        if (array_key_exists('display_name', $entry) && $entry['display_name'] !== null && trim((string) $entry['display_name']) !== '') {
            $trimmedName = trim((string) $entry['display_name']);
            if (mb_strlen($trimmedName) > 100) {
                $errors['display_name'][] = 'The display_name may not exceed 100 characters.';
            } else {
                $displayName = $trimmedName;
            }
        } elseif ($displayName === null && $stage !== null) {
            $displayName = self::STAGE_DEFAULTS[$stage]['display_name']
                ?? mb_substr(ucwords(str_replace(['_', '-'], ' ', $stage)) . ' Benchmark (min)', 0, 100);
        }

        if (!empty($errors)) {
            return ['errors' => $errors];
        }

        return [
            'data' => [
                'existing_id' => $existing?->id,
                'role' => $role,
                'stage' => $stage,
                'display_name' => $displayName,
                'benchmark_minutes' => $benchmarkMinutes,
                'is_freeze_active' => $isFreezeActive,
                'freeze_start' => $freezeStart,
                'freeze_end' => $freezeEnd,
                'freeze_windows' => $freezeWindows,
                'sort_order' => $sortOrder,
                'is_active' => $isActive,
            ],
        ];
    }

    /**
     * Resolve paired role and stage identifiers.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveRoleAndStage(array $entry, ?MisBenchmark $existing, array &$errors): array
    {
        $hasRole = array_key_exists('role', $entry) && $entry['role'] !== null && trim((string) $entry['role']) !== '';
        $hasStage = array_key_exists('stage', $entry) && $entry['stage'] !== null && trim((string) $entry['stage']) !== '';

        $role = $hasRole ? $this->normalizeSlug($entry['role']) : ($existing ? (string) $existing->role : null);
        $stage = $hasStage ? $this->normalizeSlug($entry['stage']) : ($existing ? (string) $existing->stage : null);

        if ($role === null && $stage !== null) {
            $role = self::STAGE_TO_ROLE[$stage] ?? $stage;
        }
        if ($stage === null && $role !== null) {
            $stage = self::ROLE_TO_STAGE[$role] ?? $role;
        }

        if ($role === null || $stage === null) {
            $errors['role'][] = 'Either role (picker, packer, packed_to_driver, driver) or stage (picking, packing, packed_to_driver, delivery) is required.';
            return [null, null];
        }

        if ($role === '' || strlen($role) > 50 || !preg_match('/^[a-z0-9_-]+$/', $role)) {
            $errors['role'][] = 'The role must be a valid slug up to 50 characters.';
        }

        if ($stage === '' || strlen($stage) > 50 || !preg_match('/^[a-z0-9_-]+$/', $stage)) {
            $errors['stage'][] = 'The stage must be a valid slug up to 50 characters.';
        }

        return [$role, $stage];
    }

    /**
     * Parse benchmark_minutes (non-negative integer), preserving existing value on partial update.
     */
    private function parseBenchmarkMinutes(array $entry, ?MisBenchmark $existing, array &$errors): ?int
    {
        $raw = $entry['benchmark_minutes'] ?? $entry['minutes'] ?? null;
        if ($raw !== null && $raw !== '') {
            if (filter_var($raw, FILTER_VALIDATE_INT) === false || (int) $raw < 0) {
                $errors['benchmark_minutes'][] = 'The benchmark_minutes must be a non-negative integer.';
                return null;
            }
            return (int) $raw;
        }

        if ($existing) {
            return (int) $existing->benchmark_minutes;
        }

        $errors['benchmark_minutes'][] = 'The benchmark_minutes field is required when creating a new benchmark.';
        return null;
    }

    /**
     * Validate and normalize freeze_start, freeze_end, and freeze_windows.
     *
     * @return array{0: ?string, 1: ?string, 2: ?array}
     */
    private function parseFreezeConfig(array $entry, ?MisBenchmark $existing, bool $isFreezeActive, array &$errors): array
    {
        $freezeStart = $existing?->freeze_start ? (string) $existing->freeze_start : null;
        $freezeEnd = $existing?->freeze_end ? (string) $existing->freeze_end : null;
        $freezeWindows = $existing?->freeze_windows;

        $startProvided = array_key_exists('freeze_start', $entry);
        $endProvided = array_key_exists('freeze_end', $entry);
        $windowsProvided = array_key_exists('freeze_windows', $entry);

        if ($startProvided) {
            $freezeStart = $this->parseOptionalTime($entry['freeze_start'], 'freeze_start', $errors);
        }
        if ($endProvided) {
            $freezeEnd = $this->parseOptionalTime($entry['freeze_end'], 'freeze_end', $errors);
        }

        if (($freezeStart !== null) xor ($freezeEnd !== null)) {
            $missingField = $freezeStart === null ? 'freeze_start' : 'freeze_end';
            $errors[$missingField][] = 'Both freeze_start and freeze_end are required when setting a freeze time range.';
        } elseif ($freezeStart !== null && $freezeEnd !== null && strcmp($freezeEnd, $freezeStart) <= 0) {
            $errors['freeze_end'][] = 'The freeze_end must be after freeze_start.';
        }

        if ($windowsProvided) {
            $freezeWindows = $this->parseFreezeWindowsArray($entry['freeze_windows'], $errors);
            if (!$startProvided && !$endProvided && empty($errors['freeze_windows'])) {
                if (!empty($freezeWindows)) {
                    $freezeStart = $freezeWindows[0]['start'];
                    $freezeEnd = $freezeWindows[0]['end'];
                } else {
                    $freezeStart = null;
                    $freezeEnd = null;
                }
            }
        }

        if ($isFreezeActive && empty($errors) && $freezeStart === null && empty($freezeWindows)) {
            $errors['freeze_windows'][] = 'Provide freeze_start/freeze_end or freeze_windows when is_freeze_active is true.';
        }

        return [$freezeStart, $freezeEnd, $freezeWindows];
    }

    /**
     * Validate a nullable time field and normalize to H:i:s.
     */
    private function parseOptionalTime(mixed $value, string $field, array &$errors): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $normalized = $this->normalizeTime($value);
        if ($normalized === null) {
            $errors[$field][] = "The {$field} must be a valid time (e.g. 12:00 or 12:00:00).";
            return null;
        }

        return $normalized;
    }

    /**
     * Validate and normalize an array of freeze window objects [{start, end}].
     */
    private function parseFreezeWindowsArray(mixed $value, array &$errors): ?array
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $value = $decoded;
            }
        }

        if (!is_array($value)) {
            $errors['freeze_windows'][] = 'The freeze_windows field must be an array of {start, end} ranges.';
            return null;
        }

        $normalized = [];
        foreach (array_values($value) as $idx => $window) {
            if (!is_array($window)) {
                $errors["freeze_windows.{$idx}"][] = 'Each freeze window must be an object containing start and end.';
                continue;
            }

            $rawStart = $window['start'] ?? $window['start_time'] ?? $window['freeze_start'] ?? null;
            $rawEnd = $window['end'] ?? $window['end_time'] ?? $window['freeze_end'] ?? null;

            $start = $this->normalizeTime($rawStart);
            $end = $this->normalizeTime($rawEnd);

            if ($start === null || $end === null) {
                $errors["freeze_windows.{$idx}"][] = 'Each freeze window must have valid start and end times.';
                continue;
            }

            if (strcmp($end, $start) <= 0) {
                $errors["freeze_windows.{$idx}"][] = 'The freeze window end time must be after start time.';
                continue;
            }

            $normalized[] = [
                'start' => $start,
                'end' => $end,
            ];
        }

        return !empty($normalized) ? $normalized : null;
    }

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

    private function normalizeSlug(mixed $value): string
    {
        return (string) preg_replace('/\s+/', '_', strtolower(trim((string) $value)));
    }

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
