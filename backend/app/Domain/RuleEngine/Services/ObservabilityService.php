<?php

namespace App\Domain\RuleEngine\Services;

use App\Domain\REM\Models\RemUpload;
use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Models\RuleBinding;
use App\Domain\RuleEngine\Models\RuleExecutionLog;
use Illuminate\Support\Facades\DB;

class ObservabilityService
{
    public function __construct(
        private readonly FeatureFlagService $featureFlags,
    ) {
    }
    public function health(): array
    {
        $totalRules = Rule::where('status', 'active')->count();
        $totalBindings = RuleBinding::where('active', true)->count();
        $totalStructures = RemTemplateStructure::count();
        $totalStructuresWithRules = RuleBinding::where('active', true)
            ->where('bindable_type', 'structure')
            ->distinct('bindable_id')
            ->count('bindable_id');

        $totalUploads = RemUpload::count();
        $uploadsWithEngine = RuleExecutionLog::distinct('rem_upload_id')->count('rem_upload_id');
        $totalExecutionLogs = RuleExecutionLog::count();

        $errorLogs = RuleExecutionLog::where('error_message', '!=', null)->count();
        $lastError = RuleExecutionLog::where('error_message', '!=', null)
            ->orderBy('created_at', 'desc')
            ->first();

        $structuresWithoutBindings = $totalStructures - $totalStructuresWithRules;

        $lastExecution = RuleExecutionLog::orderBy('created_at', 'desc')->first();

        $config = $this->featureFlags->getAll();

        return [
            'config_enabled' => $config['enabled'],
            'config_mode' => $config['mode'],
            'config_fail_open' => $config['fail_open'],
            'config_log_mode' => $config['log_mode'],
            'total_rules_active' => $totalRules,
            'total_bindings_active' => $totalBindings,
            'total_structures' => $totalStructures,
            'structures_with_rules' => $totalStructuresWithRules,
            'structures_without_bindings' => $structuresWithoutBindings,
            'total_uploads' => $totalUploads,
            'uploads_with_engine' => $uploadsWithEngine,
            'uploads_without_engine' => $totalUploads - $uploadsWithEngine,
            'total_execution_logs' => $totalExecutionLogs,
            'error_logs' => $errorLogs,
            'last_error' => $lastError ? [
                'id' => $lastError->id,
                'upload_id' => $lastError->rem_upload_id,
                'message' => $lastError->error_message,
                'created_at' => $lastError->created_at->toIso8601String(),
            ] : null,
            'last_execution' => $lastExecution ? [
                'id' => $lastExecution->id,
                'upload_id' => $lastExecution->rem_upload_id,
                'status' => $lastExecution->status,
                'created_at' => $lastExecution->created_at->toIso8601String(),
            ] : null,
        ];
    }

    public function stats(): array
    {
        $byType = array_map('intval', Rule::where('status', 'active')
            ->select('rule_type', DB::raw('count(*) as total'))
            ->groupBy('rule_type')
            ->pluck('total', 'rule_type')
            ->toArray());

        $byStatus = array_map('intval', RuleExecutionLog::select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray());

        $byTriggeredBy = array_map('intval', RuleExecutionLog::select('triggered_by', DB::raw('count(*) as total'))
            ->groupBy('triggered_by')
            ->pluck('total', 'triggered_by')
            ->toArray());

        $avgExecTime = RuleExecutionLog::whereNotNull('execution_ms')
            ->avg('execution_ms');

        $totalRows = (int) RuleExecutionLog::sum('total_rows');
        $totalFailed = (int) RuleExecutionLog::sum('failed_rows');

        $byStructure = collect(RuleExecutionLog::select(
            'rem_template_structure_id',
            DB::raw('count(*) as total_logs'),
            DB::raw('avg(execution_ms) as avg_ms'),
            DB::raw('sum(total_rows) as total_rows'),
            DB::raw('sum(failed_rows) as total_failed'),
        )
            ->whereNotNull('rem_template_structure_id')
            ->groupBy('rem_template_structure_id')
            ->get()
            ->toArray())
            ->keyBy('rem_template_structure_id')
            ->map(fn ($item) => [
                'rem_template_structure_id' => (int) $item['rem_template_structure_id'],
                'total_logs' => (int) $item['total_logs'],
                'avg_ms' => (float) $item['avg_ms'],
                'total_rows' => (int) $item['total_rows'],
                'total_failed' => (int) $item['total_failed'],
            ])
            ->toArray();

        $perUpload = collect(RuleExecutionLog::select(
            'rem_upload_id',
            DB::raw('count(*) as total_rules'),
            DB::raw("sum(case when status = 'passed' then 1 else 0 end) as passed"),
            DB::raw("sum(case when status = 'failed' then 1 else 0 end) as failed"),
            DB::raw("sum(case when status = 'skipped' then 1 else 0 end) as skipped"),
            DB::raw('avg(execution_ms) as avg_ms'),
            DB::raw('sum(total_rows) as total_rows'),
        )
            ->groupBy('rem_upload_id')
            ->orderBy('rem_upload_id', 'desc')
            ->limit(20)
            ->get()
            ->toArray())
            ->map(fn ($item) => [
                'rem_upload_id' => (int) $item['rem_upload_id'],
                'total_rules' => (int) $item['total_rules'],
                'passed' => (int) $item['passed'],
                'failed' => (int) $item['failed'],
                'skipped' => (int) $item['skipped'],
                'avg_ms' => (float) $item['avg_ms'],
                'total_rows' => (int) $item['total_rows'],
            ])
            ->toArray();

        $topSlowest = RuleExecutionLog::whereNotNull('execution_ms')
            ->orderBy('execution_ms', 'desc')
            ->limit(10)
            ->get(['id', 'rule_key', 'execution_ms', 'total_rows', 'rem_upload_id'])
            ->toArray();

        return [
            'rules_by_type' => $byType,
            'executions_by_status' => $byStatus,
            'executions_by_trigger' => $byTriggeredBy,
            'avg_execution_time_ms' => round($avgExecTime ?: 0, 2),
            'total_rows_processed' => $totalRows,
            'total_rows_failed' => $totalFailed,
            'by_structure' => $byStructure,
            'last_20_uploads' => $perUpload,
            'top_10_slowest_rules' => $topSlowest,
        ];
    }

    public function recentExecutions(int $limit = 20, ?int $uploadId = null, ?string $status = null): array
    {
        $q = RuleExecutionLog::query()
            ->with('rule:id,rule_key,rule_type')
            ->orderBy('created_at', 'desc')
            ->limit($limit);

        if ($uploadId !== null) {
            $q->where('rem_upload_id', $uploadId);
        }

        if ($status !== null) {
            $q->where('status', $status);
        }

        $logs = $q->get();

        $uploadIds = $logs->pluck('rem_upload_id')->unique()->filter()->values()->toArray();
        $uploads = RemUpload::whereIn('id', $uploadIds)
            ->get(['id', 'original_filename', 'rem_type', 'year', 'month', 'status as upload_status'])
            ->keyBy('id');

        $structureIds = $logs->pluck('rem_template_structure_id')->unique()->filter()->values()->toArray();
        $structures = RemTemplateStructure::whereIn('id', $structureIds)
            ->get(['id', 'serie', 'anio', 'version_number'])
            ->keyBy('id');

        return $logs->map(function ($log) use ($uploads, $structures) {
            $upload = $uploads->get($log->rem_upload_id);
            $structure = $structures->get($log->rem_template_structure_id);
            return [
                'id' => $log->id,
                'rule_key' => $log->rule_key,
                'rule_type' => $log->rule?->rule_type,
                'upload_id' => $log->rem_upload_id,
                'upload_filename' => $upload?->original_filename,
                'upload_type' => $upload?->rem_type,
                'upload_period' => $upload ? "{$upload->year}-{$upload->month}" : null,
                'structure_id' => $log->rem_template_structure_id,
                'structure_serie' => $structure?->serie,
                'structure_anio' => $structure?->anio,
                'structure_version' => $structure?->version_number,
                'status' => $log->status,
                'total_rows' => $log->total_rows,
                'passed_rows' => $log->passed_rows,
                'failed_rows' => $log->failed_rows,
                'execution_ms' => $log->execution_ms,
                'triggered_by' => $log->triggered_by,
                'error_message' => $log->error_message,
                'created_at' => $log->created_at->toIso8601String(),
            ];
        })->toArray();
    }

    public function diffSummary(): array
    {
        $uploadsWithEngine = RuleExecutionLog::select('rem_upload_id')
            ->distinct()
            ->pluck('rem_upload_id')
            ->toArray();

        $summary = [];

        foreach ($uploadsWithEngine as $uploadId) {
            $upload = RemUpload::find($uploadId);
            if (!$upload) {
                continue;
            }

            $engineStats = RuleExecutionLog::where('rem_upload_id', $uploadId)
                ->select(
                    DB::raw('count(*) as total'),
                    DB::raw("sum(case when status = 'passed' then 1 else 0 end) as passed"),
                    DB::raw("sum(case when status = 'failed' then 1 else 0 end) as failed"),
                    DB::raw("sum(case when status = 'skipped' then 1 else 0 end) as skipped"),
                    DB::raw('avg(execution_ms) as avg_ms'),
                )
                ->first();

            $totalEngine = (int) $engineStats->total;
            if ($totalEngine === 0) {
                continue;
            }

            $summary[] = [
                'upload_id' => $uploadId,
                'filename' => $upload->original_filename,
                'type' => $upload->rem_type,
                'period' => "{$upload->year}-{$upload->month}",
                'upload_status' => $upload->status,
                'engine' => [
                    'total_rules' => $totalEngine,
                    'passed' => (int) $engineStats->passed,
                    'failed' => (int) $engineStats->failed,
                    'skipped' => (int) $engineStats->skipped,
                    'avg_execution_ms' => round((float) $engineStats->avg_ms ?: 0, 2),
                ],
            ];
        }

        $totalEngineUploads = count($summary);
        $totalRules = array_sum(array_column(array_column($summary, 'engine'), 'total_rules'));
        $totalPassed = array_sum(array_column(array_column($summary, 'engine'), 'passed'));
        $totalFailed = array_sum(array_column(array_column($summary, 'engine'), 'failed'));
        $totalSkipped = array_sum(array_column(array_column($summary, 'engine'), 'skipped'));

        return [
            'total_uploads_with_engine' => $totalEngineUploads,
            'total_rules_executed' => $totalRules,
            'total_passed' => $totalPassed,
            'total_failed' => $totalFailed,
            'total_skipped' => $totalSkipped,
            'pass_rate' => $totalRules > 0 ? round($totalPassed / $totalRules * 100, 2) : 0,
            'uploads' => $summary,
        ];
    }
}
