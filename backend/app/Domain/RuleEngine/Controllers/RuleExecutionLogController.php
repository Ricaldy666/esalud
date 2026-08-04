<?php

namespace App\Domain\RuleEngine\Controllers;

use App\Domain\RuleEngine\Models\RuleExecutionLog;
use App\Http\Resources\RuleExecutionLogResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class RuleExecutionLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = RuleExecutionLog::query();

        if ($request->filled('upload_id')) {
            $query->where('rem_upload_id', $request->upload_id);
        }
        if ($request->filled('rule_id')) {
            $query->where('rule_id', $request->rule_id);
        }
        if ($request->filled('rule_key')) {
            $query->where('rule_key', 'like', "%{$request->rule_key}%");
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('triggered_by')) {
            $query->where('triggered_by', $request->triggered_by);
        }
        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->to . ' 23:59:59');
        }
        if ($request->filled('structure_id')) {
            $query->where('rem_template_structure_id', $request->structure_id);
        }

        $sortField = $request->get('sort', 'created_at');
        $sortOrder = $request->get('order', 'desc');
        $allowedSorts = ['created_at', 'execution_ms', 'status', 'total_rows', 'failed_rows'];
        if (in_array($sortField, $allowedSorts)) {
            $query->orderBy($sortField, $sortOrder === 'asc' ? 'asc' : 'desc');
        }

        $perPage = min((int) $request->get('per_page', 20), 100);
        $logs = $query->paginate($perPage);

        return RuleExecutionLogResource::collection($logs)
            ->additional(['message' => null, 'errors' => null]);
    }

    public function show(RuleExecutionLog $log): JsonResponse
    {
        $log->load([
            'rule' => fn ($q) => $q->select('id', 'rule_key', 'rule_type', 'name', 'severity', 'status'),
            'upload' => fn ($q) => $q->select('id', 'original_filename', 'year', 'month', 'rem_type'),
        ]);

        $resource = new RuleExecutionLogResource($log);
        $resource->withDetail();

        return response()->json([
            'data' => $resource,
            'message' => null,
            'errors' => null,
        ]);
    }
}
