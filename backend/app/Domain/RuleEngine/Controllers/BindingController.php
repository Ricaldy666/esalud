<?php

namespace App\Domain\RuleEngine\Controllers;

use App\Domain\RuleEngine\Models\RuleBinding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class BindingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = RuleBinding::with(['rule']);

        if ($request->filled('rule_id')) {
            $query->where('rule_id', $request->rule_id);
        }
        if ($request->filled('bindable_type')) {
            $query->where('bindable_type', $request->bindable_type);
        }
        if ($request->filled('serie')) {
            $query->where('serie', $request->serie);
        }
        if ($request->filled('anio')) {
            $query->where('anio', $request->anio);
        }
        if ($request->filled('active')) {
            $query->where('active', $request->boolean('active'));
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('rule', function ($q) use ($search) {
                $q->where('rule_key', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%");
            });
        }

        $sortField = $request->get('sort', 'created_at');
        $sortOrder = $request->get('order', 'desc');
        $allowedSorts = ['created_at', 'serie', 'anio', 'active'];
        if (in_array($sortField, $allowedSorts)) {
            $query->orderBy($sortField, $sortOrder === 'asc' ? 'asc' : 'desc');
        }

        $perPage = min((int) $request->get('per_page', 20), 100);
        $bindings = $query->paginate($perPage);

        $items = $bindings->getCollection()->map(function ($b) {
            return [
                'id' => $b->id,
                'rule_id' => $b->rule_id,
                'bindable_type' => $b->bindable_type,
                'bindable_id' => $b->bindable_id,
                'serie' => $b->serie,
                'anio' => $b->anio,
                'active' => $b->active,
                'conditions' => $b->conditions,
                'created_at' => $b->created_at,
                'rule' => $b->rule ? [
                    'id' => $b->rule->id,
                    'rule_key' => $b->rule->rule_key,
                    'rule_type' => $b->rule->rule_type,
                    'name' => $b->rule->name,
                    'severity' => $b->rule->severity,
                    'status' => $b->rule->status,
                ] : null,
            ];
        });

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $bindings->currentPage(),
                'last_page' => $bindings->lastPage(),
                'per_page' => $bindings->perPage(),
                'total' => $bindings->total(),
            ],
            'message' => null,
            'errors' => null,
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $binding = RuleBinding::with(['rule'])->findOrFail($id);

        $structure = null;
        if ($binding->bindable_type === 'structure' && $binding->bindable_id) {
            $struct = \App\Domain\RemParser\Models\RemTemplateStructure::withTrashed()
                ->find($binding->bindable_id);
            if ($struct) {
                $structure = [
                    'id' => $struct->id,
                    'anio' => $struct->anio,
                    'serie' => $struct->serie,
                    'version_number' => $struct->version_number,
                    'status' => $struct->status,
                    'source_filename' => $struct->source_filename,
                ];
            }
        }

        return response()->json([
            'data' => [
                'id' => $binding->id,
                'rule_id' => $binding->rule_id,
                'bindable_type' => $binding->bindable_type,
                'bindable_id' => $binding->bindable_id,
                'serie' => $binding->serie,
                'anio' => $binding->anio,
                'active' => $binding->active,
                'conditions' => $binding->conditions,
                'created_at' => $binding->created_at,
                'rule' => $binding->rule ? [
                    'id' => $binding->rule->id,
                    'rule_key' => $binding->rule->rule_key,
                    'rule_type' => $binding->rule->rule_type,
                    'name' => $binding->rule->name,
                    'severity' => $binding->rule->severity,
                    'status' => $binding->rule->status,
                ] : null,
                'structure' => $structure,
            ],
            'message' => null,
            'errors' => null,
        ]);
    }
}
