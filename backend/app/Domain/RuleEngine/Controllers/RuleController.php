<?php

namespace App\Domain\RuleEngine\Controllers;

use App\Domain\RuleEngine\Models\Rule;
use App\Http\Resources\RuleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RuleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Rule::query();

        if ($request->filled('rule_type')) {
            $query->where('rule_type', $request->rule_type);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('severity')) {
            $query->where('severity', $request->severity);
        }
        if ($request->filled('source')) {
            $query->where('source', $request->source);
        }
        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }
        if ($request->filled('version')) {
            $query->where('version', $request->version);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('rule_key', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%");
            });
        }

        $sortField = $request->get('sort', 'created_at');
        $sortOrder = $request->get('order', 'desc');
        $allowedSorts = ['rule_key', 'rule_type', 'status', 'severity', 'source', 'category', 'version', 'created_at', 'updated_at'];
        if (in_array($sortField, $allowedSorts)) {
            $query->orderBy($sortField, $sortOrder === 'asc' ? 'asc' : 'desc');
        }

        $perPage = min((int) $request->get('per_page', 15), 100);
        $rules = $query->paginate($perPage);

        return RuleResource::collection($rules)
            ->additional(['message' => null, 'errors' => null]);
    }

    public function show(Rule $rule): JsonResponse
    {
        $rule->load([
            'bindings',
            'versions' => fn ($q) => $q->latest(),
            'executionLogs' => fn ($q) => $q->latest()->limit(10),
        ]);

        return response()->json([
            'data' => new RuleResource($rule),
            'message' => null,
            'errors' => null,
        ]);
    }
}
