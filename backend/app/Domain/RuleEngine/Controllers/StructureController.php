<?php

namespace App\Domain\RuleEngine\Controllers;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Models\RuleBinding;
use App\Http\Resources\RuleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class StructureController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = RemTemplateStructure::withTrashed()
            ->orderBy('anio', 'desc')
            ->orderBy('serie')
            ->orderBy('version_number', 'desc');

        if ($request->filled('anio')) {
            $query->where('anio', $request->anio);
        }
        if ($request->filled('serie')) {
            $query->where('serie', $request->serie);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('version')) {
            $query->where('version_number', $request->version);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('hash_estructura', 'like', "%{$search}%")
                  ->orWhere('source_filename', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) $request->get('per_page', 20), 100);
        $structures = $query->paginate($perPage);

        $items = collect($structures->items())->map(function ($s) {
            $stats = $this->computeStats($s);
            return [
                'id' => $s->id,
                'anio' => $s->anio,
                'serie' => $s->serie,
                'version_number' => $s->version_number,
                'status' => $s->status,
                'hash_short' => substr($s->hash_estructura, 0, 12),
                'source_filename' => $s->source_filename,
                'stats' => $stats,
                'created_at' => $s->created_at,
            ];
        });

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $structures->currentPage(),
                'last_page' => $structures->lastPage(),
                'per_page' => $structures->perPage(),
                'total' => $structures->total(),
            ],
            'message' => null,
            'errors' => null,
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $structure = RemTemplateStructure::withTrashed()->findOrFail($id);
        $est = $structure->estructura;
        $stats = $this->computeStats($structure);

        $formsDetail = [];
        foreach ($est['forms'] ?? [] as $form) {
            $secDetail = [];
            foreach ($form['sections'] ?? [] as $section) {
                $secDetail[] = [
                    'codigo' => $section['codigo'],
                    'titulo' => $section['titulo'],
                    'filaHeader' => $section['filaHeader'] ?? null,
                    'filaInicioDatos' => $section['filaInicioDatos'] ?? null,
                    'filaFinDatos' => $section['filaFinDatos'] ?? null,
                    'campos' => count($section['fields'] ?? []),
                    'reglas' => $this->countSectionRules($section),
                    'fields' => $section['fields'] ?? [],
                ];
            }
            $formsDetail[] = [
                'sheetName' => $form['sheetName'],
                'sections' => $secDetail,
            ];
        }

        $rulesCount = RuleBinding::where('bindable_type', 'structure')
            ->where('bindable_id', $structure->id)
            ->where('active', true)
            ->count();

        $rules = RuleBinding::where('bindable_type', 'structure')
            ->where('bindable_id', $structure->id)
            ->where('active', true)
            ->with('rule')
            ->get()
            ->pluck('rule');

        $uploadsCount = RemTemplateStructure::where('hash_estructura', $structure->hash_estructura)
            ->whereNotNull('rem_upload_id')
            ->count('rem_upload_id');

        $versionHistory = RemTemplateStructure::where('hash_estructura', $structure->hash_estructura)
            ->orderByDesc('version_number')
            ->get(['id', 'version_number', 'status', 'created_at']);

        $template = null;
        if ($structure->rem_template_id) {
            $tpl = $structure->remTemplate;
            if ($tpl) {
                $template = [
                    'id' => $tpl->id,
                    'year' => $tpl->year,
                    'rem_type' => $tpl->rem_type,
                    'version' => $tpl->version,
                    'is_active' => $tpl->is_active,
                ];
            }
        }

        $upload = null;
        if ($structure->remUpload) {
            $u = $structure->remUpload;
            $upload = [
                'id' => $u->id,
                'filename' => $u->original_filename,
                'period' => $u->year . '-' . $u->month,
            ];
        }

        return response()->json([
            'data' => [
                'id' => $structure->id,
                'anio' => $structure->anio,
                'serie' => $structure->serie,
                'version_number' => $structure->version_number,
                'status' => $structure->status,
                'hash_estructura' => $structure->hash_estructura,
                'metadata' => $structure->metadata,
                'source_filename' => $structure->source_filename,
                'notes' => $structure->notes,
                'stats' => $stats,
                'forms_detail' => $formsDetail,
                'template' => $template,
                'upload' => $upload,
                'rules_count' => $rulesCount,
                'rules' => $rules->map(fn ($r) => [
                    'id' => $r->id,
                    'rule_key' => $r->rule_key,
                    'rule_type' => $r->rule_type,
                    'name' => $r->name,
                    'severity' => $r->severity,
                    'status' => $r->status,
                ]),
                'uploads_count' => $uploadsCount,
                'version_history' => $versionHistory,
                'created_at' => $structure->created_at,
                'updated_at' => $structure->updated_at,
            ],
            'message' => null,
            'errors' => null,
        ]);
    }

    private function computeStats(RemTemplateStructure $structure): array
    {
        $est = $structure->estructura;
        $totalForms = 0;
        $totalSections = 0;
        $totalFields = 0;
        $totalSumEquals = 0;
        $totalRequired = 0;

        foreach ($est['forms'] ?? [] as $form) {
            $totalForms++;
            foreach ($form['sections'] ?? [] as $section) {
                $totalSections++;
                foreach ($section['fields'] ?? [] as $field) {
                    $totalFields++;
                    $regla = $field['reglaDetectada'] ?? null;
                    if ($regla === null) continue;
                    $tipo = is_array($regla) ? ($regla['tipo'] ?? '') : $regla;
                    match ($tipo) {
                        'sum_equals' => $totalSumEquals++,
                        'required_and_le_parent' => $totalRequired++,
                        default => null,
                    };
                }
            }
        }

        return [
            'total_forms' => $totalForms,
            'total_sections' => $totalSections,
            'total_fields' => $totalFields,
            'total_rules' => $totalSumEquals + $totalRequired,
            'sum_equals' => $totalSumEquals,
            'required_and_le_parent' => $totalRequired,
        ];
    }

    private function countSectionRules(array $section): int
    {
        $count = 0;
        foreach ($section['fields'] ?? [] as $field) {
            $regla = $field['reglaDetectada'] ?? null;
            if ($regla === null) continue;
            $tipo = is_array($regla) ? ($regla['tipo'] ?? '') : $regla;
            if (in_array($tipo, ['sum_equals', 'required_and_le_parent'])) {
                $count++;
            }
        }
        return $count;
    }
}
