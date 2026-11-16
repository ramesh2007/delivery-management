<?php

namespace App\Modules\CustomerCare\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'module' => 'Customer Care',
            'status' => 'running',
            'message' => 'Customer Care API is working.',
            'version' => '1.0.0',
            'channels' => [
                'whatsapp' => 'pending',
                'instagram' => 'pending',
                'facebook' => 'pending',
            ],
        ]);
    }
}