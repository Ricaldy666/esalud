<?php

namespace App\Domain\RuleEngine\Controllers;

use App\Domain\RuleEngine\Services\ValidationSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class ValidationSummaryController extends Controller
{
    public function show(int $upload, ValidationSummaryService $service): JsonResponse
    {
        return response()->json([
            'data' => $service->summary($upload),
            'message' => null,
            'errors' => null,
        ]);
    }
}
