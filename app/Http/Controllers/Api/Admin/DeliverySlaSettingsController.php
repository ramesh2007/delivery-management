<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\MisSlaConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeliverySlaSettingsController extends Controller
{
    /**
     * Retrieve, create, or update SLA timings in mis_sla_configs.
     */
    public function getDeliverySlaSettings(Request $request): JsonResponse
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

        // Handle GET retrieval when no mutation fields are present
        if (
            $request->isMethod('get') &&
            !$request->hasAny([
                'configs',
                'sla_configs',
                'slas',
                'settings',
                'display_name',
                'duration_minutes',
                'minutes',
                'duration_hours',
                'hours',
                'is_active',
            ])
        ) {
            return $this->fetchSlaSettings($request);
        }

        $rawEntries = $this->extractConfigEntries($request);
        if (empty($rawEntries)) {
            return response()->json([
                'success' => false,
                'message' => 'Please provide SLA configuration data (configs array or sla_type with duration).',
            ], 422);
        }

        $normalizedEntries = [];
        $errors = [];
        $seenTypes = [];

        foreach ($rawEntries as $index => $entry) {
            if (!is_array($entry)) {
                $errors["configs.{$index}"][] = 'Each SLA config item must be an object/array.';
                continue;
            }

            $result = $this->normalizeConfigEntry($entry);
            if (!empty($result['errors'])) {
                foreach ($result['errors'] as $field => $messages) {
                    $errors["configs.{$index}.{$field}"] = $messages;
                }
                continue;
            }

            $slaType = $result['data']['sla_type'];
            if (isset($seenTypes[$slaType])) {
                $errors["configs.{$index}.sla_type"][] = "Duplicate entry for sla_type '{$slaType}'.";
                continue;
            }

            $seenTypes[$slaType] = true;
            $normalizedEntries[] = $result['data'];
        }

        if (!empty($errors)) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed for delivery SLA settings.',
                'errors' => $errors,
            ], 422);
        }

        try {
            $savedConfigs = DB::transaction(function () use ($normalizedEntries) {
                $saved = [];

                foreach ($normalizedEntries as $item) {
                    if ($item['existing_id'] !== null) {
                        $config = MisSlaConfig::findOrFail($item['existing_id']);
                        $config->update([
                            'sla_type' => $item['sla_type'],
                            'display_name' => $item['display_name'],
                            'duration_minutes' => $item['duration_minutes'],
                            'is_active' => $item['is_active'],
                        ]);
                        $saved[] = $config->fresh();
                    } else {
                        $saved[] = MisSlaConfig::create([
                            'sla_type' => $item['sla_type'],
                            'display_name' => $item['display_name'],
                            'duration_minutes' => $item['duration_minutes'],
                            'is_active' => $item['is_active'],
                        ]);
                    }
                }

                return $saved;
            });

            return response()->json([
                'success' => true,
                'message' => 'Delivery SLA settings saved successfully.',
                'data' => $savedConfigs,
                'configs' => MisSlaConfig::orderBy('id')->get(),
            ], 200);
        } catch (Throwable $e) {
            Log::error('Failed to save delivery SLA settings: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to save delivery SLA settings.',
            ], 500);
        }
    }

    /**
     * Fetch existing SLA configurations, optionally filtered by id or sla_type.
     */
    private function fetchSlaSettings(Request $request): JsonResponse
    {
        $query = MisSlaConfig::query()->orderBy('id');

        if ($request->filled('id')) {
            $query->where('id', $request->input('id'));
        }

        if ($request->filled('sla_type')) {
            $normalizedType = $this->normalizeSlaType($request->input('sla_type'));
            $query->where('sla_type', $normalizedType);
        }

        if ($request->filled('active_only')) {
            $activeOnly = filter_var($request->input('active_only'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($activeOnly !== null) {
                $query->where('is_active', $activeOnly);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Delivery SLA settings retrieved successfully.',
            'data' => $query->get(),
        ], 200);
    }

    /**
     * Extract either a bulk list of SLA config entries or wrap a single-config payload.
     */
    private function extractConfigEntries(Request $request): array
    {
        $payload = $request->all();

        foreach (['configs', 'sla_configs', 'slas', 'settings'] as $listKey) {
            if (isset($payload[$listKey]) && is_array($payload[$listKey])) {
                return array_values($payload[$listKey]);
            }
        }

        if (array_is_list($payload) && !empty($payload)) {
            return $payload;
        }

        if ($request->hasAny(['id', 'sla_type', 'display_name', 'duration_minutes', 'minutes', 'duration_hours', 'hours', 'is_active'])) {
            return [$payload];
        }

        return [];
    }

    /**
     * Validate and normalize one SLA config entry against mis_sla_configs columns.
     */
    private function normalizeConfigEntry(array $entry): array
    {
        $errors = [];
        $existing = null;

        if (isset($entry['id']) && $entry['id'] !== '') {
            $existing = MisSlaConfig::find($entry['id']);
            if (!$existing) {
                return ['errors' => ['id' => ["SLA config ID {$entry['id']} not found."]]];
            }
        }

        $slaType = $existing ? (string) $existing->sla_type : null;
        if (array_key_exists('sla_type', $entry) && $entry['sla_type'] !== null && trim((string) $entry['sla_type']) !== '') {
            $slaType = $this->normalizeSlaType($entry['sla_type']);
            if ($slaType === '' || strlen($slaType) > 50 || !preg_match('/^[a-z0-9_-]+$/', $slaType)) {
                $errors['sla_type'][] = 'The sla_type must be a valid slug up to 50 characters (e.g. normal, express).';
            }
        } elseif ($slaType === null) {
            $errors['sla_type'][] = 'The sla_type field is required (e.g. normal, express).';
        }

        if (!$existing && !empty($slaType) && empty($errors['sla_type'])) {
            $existing = MisSlaConfig::where('sla_type', $slaType)->first();
        } elseif ($existing && !empty($slaType) && $slaType !== $existing->sla_type && empty($errors['sla_type'])) {
            $conflict = MisSlaConfig::where('sla_type', $slaType)->where('id', '!=', $existing->id)->exists();
            if ($conflict) {
                $errors['sla_type'][] = "Another SLA config already uses sla_type '{$slaType}'.";
            }
        }

        $durationMinutes = $this->parseDurationMinutes($entry, $existing, $errors);

        $isActive = $existing ? (bool) $existing->is_active : true;
        if (array_key_exists('is_active', $entry) && $entry['is_active'] !== null && $entry['is_active'] !== '') {
            $parsedActive = filter_var($entry['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($parsedActive === null) {
                $errors['is_active'][] = 'The is_active field must be a boolean.';
            } else {
                $isActive = $parsedActive;
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
        } elseif ($displayName === null && empty($errors) && $slaType !== null && $durationMinutes !== null) {
            $displayName = $this->formatDefaultDisplayName($slaType, $durationMinutes);
        }

        if (!empty($errors)) {
            return ['errors' => $errors];
        }

        return [
            'data' => [
                'existing_id' => $existing?->id,
                'sla_type' => $slaType,
                'display_name' => $displayName,
                'duration_minutes' => $durationMinutes,
                'is_active' => $isActive,
            ],
        ];
    }

    /**
     * Resolve duration_minutes from minutes or hours input, preserving existing value on partial updates.
     */
    private function parseDurationMinutes(array $entry, ?MisSlaConfig $existing, array &$errors): ?int
    {
        $rawMinutes = $entry['duration_minutes'] ?? $entry['minutes'] ?? null;
        if ($rawMinutes !== null && $rawMinutes !== '') {
            if (filter_var($rawMinutes, FILTER_VALIDATE_INT) === false || (int) $rawMinutes <= 0) {
                $errors['duration_minutes'][] = 'The duration_minutes must be a positive integer (minutes).';
                return null;
            }
            return (int) $rawMinutes;
        }

        $rawHours = $entry['duration_hours'] ?? $entry['hours'] ?? null;
        if ($rawHours !== null && $rawHours !== '') {
            if (!is_numeric($rawHours) || (float) $rawHours <= 0) {
                $errors['duration_hours'][] = 'The duration_hours must be a positive number.';
                return null;
            }
            $minutes = (int) round(((float) $rawHours) * 60);
            if ($minutes <= 0) {
                $errors['duration_hours'][] = 'The duration_hours must convert to at least 1 minute.';
                return null;
            }
            return $minutes;
        }

        if ($existing) {
            return (int) $existing->duration_minutes;
        }

        $errors['duration_minutes'][] = 'The duration_minutes (or duration_hours) field is required.';
        return null;
    }

    /**
     * Normalize sla_type string into lowercase slug format.
     */
    private function normalizeSlaType(mixed $value): string
    {
        return (string) preg_replace('/\s+/', '_', strtolower(trim((string) $value)));
    }

    /**
     * Build default display name like "Normal Delivery (4 hrs)" or "Express Delivery (1 hr)".
     */
    private function formatDefaultDisplayName(string $slaType, int $durationMinutes): string
    {
        $label = ucwords(str_replace(['_', '-'], ' ', $slaType));

        if ($durationMinutes % 60 === 0) {
            $hours = intdiv($durationMinutes, 60);
            $unit = $hours === 1 ? 'hr' : 'hrs';
            $durationLabel = "{$hours} {$unit}";
        } else {
            $unit = $durationMinutes === 1 ? 'min' : 'mins';
            $durationLabel = "{$durationMinutes} {$unit}";
        }

        return mb_substr("{$label} Delivery ({$durationLabel})", 0, 100);
    }
}
