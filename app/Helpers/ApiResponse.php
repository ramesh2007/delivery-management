<?php

namespace App\Helpers;

use Illuminate\Http\JsonResponse;

trait ApiResponse
{
    /**
     * Return a success JSON response.
     *
     * @param string $message
     * @param mixed $data
     * @param int $statusCode
     * @return JsonResponse
     */
    protected function successResponse(string $message, mixed $data = [], int $statusCode = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $statusCode);
    }

    /**
     * Return an error JSON response.
     *
     * @param string $message
     * @param int $statusCode
     * @return JsonResponse
     */
    protected function errorResponse(string $message, int $statusCode = 400): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $statusCode);
    }
}
