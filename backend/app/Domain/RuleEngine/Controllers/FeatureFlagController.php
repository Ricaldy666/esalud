<?php

namespace App\Domain\RuleEngine\Controllers;

use App\Domain\RuleEngine\Models\RuleEngineSetting;
use App\Domain\RuleEngine\Services\FeatureFlagService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeatureFlagController extends Controller
{
    public function __construct(
        private readonly FeatureFlagService $featureFlagService,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $this->authorize('view', RuleEngineSetting::class);

        return response()->json([
            'data' => $this->featureFlagService->getAll(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->authorize('update', RuleEngineSetting::class);

        $validated = $request->validate([
            'enabled' => 'sometimes|boolean',
            'mode' => 'sometimes|string|in:disabled,parallel,parallel_write,replace',
            'fail_open' => 'sometimes|boolean',
            'log_mode' => 'sometimes|string|in:off,diff,all',
        ]);

        $config = $this->featureFlagService->update($validated, $request->user());

        return response()->json([
            'data' => $config,
            'message' => 'Configuración actualizada correctamente',
            'errors' => null,
        ]);
    }
}
