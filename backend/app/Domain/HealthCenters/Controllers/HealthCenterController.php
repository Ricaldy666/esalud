<?php

namespace App\Domain\HealthCenters\Controllers;

use App\Domain\HealthCenters\Models\HealthCenter;
use App\Domain\HealthCenters\Requests\StoreHealthCenterRequest;
use App\Domain\HealthCenters\Requests\UpdateHealthCenterRequest;
use App\Http\Controllers\Controller;
use App\Http\Resources\HealthCenterResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HealthCenterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', HealthCenter::class);

        $query = HealthCenter::query()->withCount('users');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code_deis', 'like', "%{$search}%");
            });
        }

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $perPage = min((int) $request->query('per_page', 20), 100);
        $centers = $query->orderBy('name')->paginate($perPage);

        return response()->json([
            'data' => HealthCenterResource::collection($centers->items()),
            'meta' => [
                'current_page' => $centers->currentPage(),
                'last_page' => $centers->lastPage(),
                'per_page' => $centers->perPage(),
                'total' => $centers->total(),
            ],
            'message' => 'Centros de salud obtenidos exitosamente',
            'errors' => null,
        ]);
    }

    public function show(HealthCenter $healthCenter): JsonResponse
    {
        $this->authorize('view', $healthCenter);
        $healthCenter->loadCount('users');

        return response()->json([
            'data' => new HealthCenterResource($healthCenter),
            'message' => 'Centro de salud obtenido exitosamente',
            'errors' => null,
        ]);
    }

    public function store(StoreHealthCenterRequest $request): JsonResponse
    {
        $this->authorize('create', HealthCenter::class);

        $center = HealthCenter::create($request->validated());
        $center->loadCount('users');

        return response()->json([
            'data' => new HealthCenterResource($center),
            'message' => 'Centro de salud creado exitosamente',
            'errors' => null,
        ], 201);
    }

    public function update(UpdateHealthCenterRequest $request, HealthCenter $healthCenter): JsonResponse
    {
        $this->authorize('update', $healthCenter);

        $healthCenter->update($request->validated());
        $healthCenter->loadCount('users');

        return response()->json([
            'data' => new HealthCenterResource($healthCenter),
            'message' => 'Centro de salud actualizado exitosamente',
            'errors' => null,
        ]);
    }

    public function destroy(HealthCenter $healthCenter): JsonResponse
    {
        $this->authorize('delete', $healthCenter);

        if ($healthCenter->users()->count() > 0) {
            return response()->json([
                'data' => null,
                'message' => 'No se puede eliminar un centro con usuarios asociados',
                'errors' => ['health_center' => ['El centro tiene usuarios asignados. Desasígnelos primero.']],
            ], 422);
        }

        $healthCenter->delete();

        return response()->json([
            'data' => null,
            'message' => 'Centro de salud eliminado exitosamente',
            'errors' => null,
        ]);
    }
}
