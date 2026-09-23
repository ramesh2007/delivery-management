<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class InstallationLevelController extends Controller
{
    /**
     * Temporary constant locations data.
     */
    public const LOCATIONS = [
        [
            'id' => 1,
            'name' => 'Doha central',
            'location_id' => 1,
            'location_name' => 'Doha central',
            'city' => 'Doha',
        ],
        [
            'id' => 2,
            'name' => 'Al Wakrah Hall',
            'location_id' => 2,
            'location_name' => 'Al Wakrah Hall',
            'city' => 'Al Wakrah',
        ],
        [
            'id' => 3,
            'name' => 'Al Khor Zone',
            'location_id' => 3,
            'location_name' => 'Al Khor Zone',
            'city' => 'Al Khor',
        ],
    ];

    /**
     * Temporary constant technicians / team members data for admin.
     */
    public const TECHNICIANS = [
        [
            'id' => 1,
            'name' => 'Farshad',
            'technician_id' => 1,
            'technician_name' => 'Farshad',
            'role' => 'Technician',
        ],
        [
            'id' => 2,
            'name' => 'Naveed',
            'technician_id' => 2,
            'technician_name' => 'Naveed',
            'role' => 'Technician',
        ],
        [
            'id' => 3,
            'name' => 'Shambu',
            'technician_id' => 3,
            'technician_name' => 'Shambu',
            'role' => 'Technician',
        ],
        [
            'id' => 4,
            'name' => 'Irshad',
            'technician_id' => 4,
            'technician_name' => 'Irshad',
            'role' => 'Technician',
        ],
        [
            'id' => 5,
            'name' => 'Waseem',
            'technician_id' => 5,
            'technician_name' => 'Waseem',
            'role' => 'Technician',
        ],
        [
            'id' => 6,
            'name' => 'Minhal',
            'technician_id' => 6,
            'technician_name' => 'Minhal',
            'role' => 'Technician',
        ],
        [
            'id' => 7,
            'name' => 'Rizwan',
            'technician_id' => 7,
            'technician_name' => 'Rizwan',
            'role' => 'Technician',
        ],
    ];

    /**
     * Get temporary constant locations list.
     * Route: GET /api/admin/installation-level/locations
     * Route: GET /api/admin/installation/locations
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function locations(Request $request): JsonResponse
    {
        try {
            $locations = self::LOCATIONS;

            return response()->json([
                'status' => 'success',
                'message' => 'Locations retrieved successfully.',
                'count' => count($locations),
                'data' => $locations,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch locations: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Temporary location → technician mapping.
     */
    public const LOCATION_TECHNICIANS = [
        1 => [1, 2, 3], // Doha central → Farshad, Naveed, Shambu
        2 => [4, 5],    // Al Wakrah Hall → Irshad, Waseem
        3 => [6, 7],    // Al Khor Zone → Minhal, Rizwan
    ];

    /**
     * Get team / technicians with ID and name for admin.
     * Checks database for users with technician role; falls back to temporary constant data.
     * Route: GET /api/admin/installation-level/teams
     * Route: GET /api/admin/installation/teams
     * Route: GET /api/admin/installation-level/technicians
     *
     * @param Request $request
     * @return JsonResponse
     */

public function team(Request $request, $LocationId = null): JsonResponse
{
    try {
        $rawLoc = $LocationId ?? $request->query('location_id') ?? $request->query('LocationId');

        // If no location specified, return all technicians
        if ($rawLoc === null || $rawLoc === '') {
            return response()->json([
                'status' => 'success',
                'message' => 'Team technicians retrieved successfully.',
                'count' => count(self::TECHNICIANS),
                'data' => self::TECHNICIANS,
            ], 200);
        }

        $locationId = (int) $rawLoc;

        // Find location from temporary locations
        $location = collect(self::LOCATIONS)
            ->first(function ($loc) use ($locationId, $rawLoc) {
                return (int)$loc['location_id'] === $locationId ||
                       (int)$loc['id'] === $locationId ||
                       strcasecmp($loc['name'], (string)$rawLoc) === 0;
            });

        if (!$location) {
            return response()->json([
                'status' => 'error',
                'message' => 'Location not found.',
                'data' => [],
            ], 404);
        }

        $actualLocationId = (int) $location['location_id'];

        // Get technician IDs assigned to this location
        $technicianIds = self::LOCATION_TECHNICIANS[$actualLocationId] ?? [];

        // Get technicians from temporary technician list
        $technicians = collect(self::TECHNICIANS)
            ->whereIn('id', $technicianIds)
            ->values()
            ->all();

        return response()->json([
            'status' => 'success',
            'message' => 'Team technicians retrieved successfully.',
            'location' => [
                'id' => $location['location_id'],
                'name' => $location['location_name'],
                'city' => $location['city'],
            ],
            'count' => count($technicians),
            'data' => $technicians,
        ], 200);

    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => 'Failed to fetch team technicians: ' . $e->getMessage(),
        ], 500);
    }
}
}
