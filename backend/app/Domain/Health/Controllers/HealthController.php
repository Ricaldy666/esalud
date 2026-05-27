<?php

namespace App\Domain\Health\Controllers;

use Illuminate\Http\JsonResponse;

class HealthController
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'status' => 'ok',
                'service' => 'esalud-api',
                'version' => '0.1.0',
                'timestamp' => now()->toIso8601String(),
            ],
            'message' => 'Service is healthy',
            'errors' => null,
        ]);
    }
}
