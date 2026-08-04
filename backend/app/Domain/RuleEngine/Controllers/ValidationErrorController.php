<?php

namespace App\Domain\RuleEngine\Controllers;

use App\Domain\RuleEngine\Services\ValidationErrorFormatterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ValidationErrorController extends Controller
{
    public function index(int $upload, Request $request, ValidationErrorFormatterService $service): JsonResponse
    {
        $filters = $request->only(['form', 'severidad', 'tipo_regla', 'tipo_error']);

        return response()->json([
            'data' => $service->formatErrors($upload, $filters),
            'message' => null,
            'errors' => null,
        ]);
    }
}
