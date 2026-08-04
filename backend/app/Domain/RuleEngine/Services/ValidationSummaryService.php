<?php

namespace App\Domain\RuleEngine\Services;

use App\Domain\REM\Models\RemUpload;
use App\Domain\REM\Models\RemValidationResult;
use App\Domain\RuleEngine\Models\RuleExecutionLog;
use Illuminate\Support\Facades\DB;

class ValidationSummaryService
{
    public function summary(int $uploadId): array
    {
        $upload = RemUpload::with('healthCenter')->findOrFail($uploadId);

        // Legacy/functional source (RemValidationService + ValidateRemUploadJob).
        // rule_id is only ever set by RuleEngineService::execute() (RuleEngineService.php
        // ~line 313), and every row it writes there is created in the very same call
        // that writes the matching rem_rule_execution_logs row for that rule_id+upload.
        // So whereNull('rule_id') here is what keeps this source disjoint from $logs
        // below -- no row can appear in both, and nothing needs to be deduplicated by
        // message/coordinate/etc.
        $results = RemValidationResult::where('rem_upload_id', $uploadId)
            ->whereNull('rule_id')
            ->with('rule')
            ->get();

        // Engine source: one row per rule resolved for this upload's structure via
        // RuleEngineService::resolveRules().
        $logs = RuleExecutionLog::where('rem_upload_id', $uploadId)
            ->with('rule')
            ->get();

        return $this->buildCombined($results, $logs, $upload);
    }

    private function buildCombined($results, $logs, RemUpload $upload): array
    {
        $legacy = $this->summarizeResults($results);
        $engine = $this->summarizeLogs($logs);

        $totalRules = $legacy['total'] + $engine['total'];
        $passed = $legacy['passed'] + $engine['passed'];
        $failed = $legacy['failed'] + $engine['failed'];
        $skipped = $legacy['skipped'] + $engine['skipped'];
        $applicable = $passed + $failed;
        $cumplimiento = $applicable > 0 ? round($passed / $applicable * 100, 2) : null;

        $ultimaEjecucion = $engine['ultima_ejecucion'] ?? $upload->processed_at?->toIso8601String();

        return [
            'upload' => [
                'id' => $upload->id,
                'filename' => $upload->original_filename,
                'serie' => $upload->rem_type,
                'periodo' => "{$upload->year}-{$upload->month}",
                'centro' => $upload->healthCenter?->name ?? 'Sin centro',
                'estado_legacy' => $upload->status,
            ],
            'total_rules' => $totalRules,
            'applicable' => $applicable,
            'not_applicable' => $skipped,
            'passed' => $passed,
            'failed' => $failed,
            'cumplimiento_porcentaje' => $cumplimiento,
            'ultima_ejecucion' => $ultimaEjecucion,
            'por_formulario' => $this->mergeByForm($legacy['por_formulario'], $engine['por_formulario']),
            'por_severidad' => $this->mergeBySeverity($legacy['por_severidad'], $engine['por_severidad']),
        ];
    }

    /**
     * Mirrors the old buildFromResults() grouping: legacy/functional rule_keys are
     * grouped so a rule failing on any of its rows counts once, matching how this
     * source behaved before the engine source existed.
     */
    private function summarizeResults($results): array
    {
        $byRule = $results->groupBy('rule_key');

        $passedRules = 0;
        $failedRules = 0;
        foreach ($byRule as $ruleResults) {
            if ($ruleResults->contains(fn ($r) => !$r->passed)) {
                $failedRules++;
            } else {
                $passedRules++;
            }
        }

        $byForm = $results->groupBy(fn ($r) => $r->context['section'] ?? $r->rule?->config['sheet'] ?? $r->rule?->metadata['sheet'] ?? 'desconocido')
            ->map(fn ($group, $form) => [
                'form' => $form,
                'total' => $group->count(),
                'passed' => $group->where('passed', true)->count(),
                'failed' => $group->where('passed', false)->count(),
                'skipped' => 0,
            ])
            ->values();

        $bySeverity = $results->where('passed', false)
            ->groupBy('severity')
            ->map(fn ($group, $severity) => [
                'severidad' => $severity,
                'total' => $group->count(),
                'failed' => $group->count(),
            ])
            ->values();

        return [
            'total' => $passedRules + $failedRules,
            'passed' => $passedRules,
            'failed' => $failedRules,
            'skipped' => 0,
            'por_formulario' => $byForm,
            'por_severidad' => $bySeverity,
        ];
    }

    private function summarizeLogs($logs): array
    {
        $passed = $logs->where('status', 'passed')->count();
        $failed = $logs->where('status', 'failed')->count();
        $skipped = $logs->where('status', 'skipped')->count();

        $byForm = $logs->groupBy(fn ($l) => $l->rule?->config['sheet'] ?? $l->rule?->metadata['sheet'] ?? 'desconocido')
            ->map(fn ($group, $form) => [
                'form' => $form,
                'total' => $group->count(),
                'passed' => $group->where('status', 'passed')->count(),
                'failed' => $group->where('status', 'failed')->count(),
                'skipped' => $group->where('status', 'skipped')->count(),
            ])
            ->values();

        $bySeverity = $logs->where('status', 'failed')
            ->groupBy(fn ($l) => $l->rule?->severity ?? 'error')
            ->map(fn ($group, $severity) => [
                'severidad' => $severity,
                'total' => $group->count(),
                'failed' => $group->count(),
            ])
            ->values();

        $lastExec = $logs->sortByDesc('created_at')->first()?->created_at?->toIso8601String();

        return [
            'total' => $logs->count(),
            'passed' => $passed,
            'failed' => $failed,
            'skipped' => $skipped,
            'por_formulario' => $byForm,
            'por_severidad' => $bySeverity,
            'ultima_ejecucion' => $lastExec,
        ];
    }

    private function mergeByForm($legacyForms, $engineForms): array
    {
        $merged = [];
        foreach ($legacyForms->concat($engineForms) as $row) {
            $form = $row['form'];
            if (!isset($merged[$form])) {
                $merged[$form] = ['form' => $form, 'total' => 0, 'passed' => 0, 'failed' => 0, 'skipped' => 0];
            }
            $merged[$form]['total'] += $row['total'];
            $merged[$form]['passed'] += $row['passed'];
            $merged[$form]['failed'] += $row['failed'];
            $merged[$form]['skipped'] += $row['skipped'];
        }

        return collect($merged)
            ->map(function ($row) {
                $row['evaluated'] = $row['passed'] + $row['failed'];
                $row['cumplimiento'] = $row['evaluated'] > 0
                    ? round($row['passed'] / $row['evaluated'] * 100, 2)
                    : null;
                return $row;
            })
            ->values()
            ->toArray();
    }

    private function mergeBySeverity($legacySeverity, $engineSeverity): array
    {
        $merged = [];
        foreach ($legacySeverity->concat($engineSeverity) as $row) {
            $severity = $row['severidad'];
            if (!isset($merged[$severity])) {
                $merged[$severity] = ['severidad' => $severity, 'total' => 0, 'failed' => 0];
            }
            $merged[$severity]['total'] += $row['total'];
            $merged[$severity]['failed'] += $row['failed'];
        }

        return array_values($merged);
    }
}
