<?php

namespace App\Domain\REM\Controllers;

use App\Domain\REM\Models\RemTemplate;
use App\Http\Controllers\Controller;
use App\Http\Resources\RemTemplateResource;
use Illuminate\Http\JsonResponse;

class RemTemplateController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', RemTemplate::class);

        $templates = RemTemplate::where('is_active', true)
            ->orderBy('rem_type')
            ->get();

        return response()->json([
            'data' => RemTemplateResource::collection($templates),
            'message' => 'Plantillas REM obtenidas',
            'errors' => null,
        ]);
    }

    public function show(RemTemplate $remTemplate): JsonResponse
    {
        $this->authorize('view', $remTemplate);

        return response()->json([
            'data' => new RemTemplateResource($remTemplate),
            'message' => 'Plantilla REM obtenida',
            'errors' => null,
        ]);
    }
}
