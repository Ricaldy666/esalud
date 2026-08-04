<?php

namespace App\Domain\RuleEngine\Controllers;

use App\Domain\RuleEngine\Services\ObservabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class ObservabilityController extends Controller
{
    public function health(ObservabilityService $obs): JsonResponse
    {
        return response()->json([
            'data' => $obs->health(),
            'message' => null,
            'errors' => null,
        ]);
    }

    public function stats(ObservabilityService $obs): JsonResponse
    {
        return response()->json([
            'data' => $obs->stats(),
            'message' => null,
            'errors' => null,
        ]);
    }
}
