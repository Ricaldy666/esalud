<?php

namespace App\Domain\RemParser\Controllers;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RemExplorerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = RemTemplateStructure::withTrashed()->orderBy('anio', 'desc')->orderBy('serie')->orderBy('version_number', 'desc');

        if ($anio = $request->query('anio')) {
            $query->where('anio', $anio);
        }
        if ($serie = $request->query('serie')) {
            $query->where('serie', $serie);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $perPage = min((int) $request->query('per_page', 50), 100);
        $structures = $query->paginate($perPage);

        return response()->json([
            'data' => $structures->items(),
            'meta' => [
                'current_page' => $structures->currentPage(),
                'last_page' => $structures->lastPage(),
                'per_page' => $structures->perPage(),
                'total' => $structures->total(),
            ],
            'message' => 'Estructuras obtenidas',
            'errors' => null,
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $structure = RemTemplateStructure::withTrashed()->findOrFail($id);

        return response()->json([
            'data' => $this->enrich($structure),
            'message' => 'Estructura obtenida',
            'errors' => null,
        ]);
    }

    public function stats(int $id): JsonResponse
    {
        $structure = RemTemplateStructure::withTrashed()->findOrFail($id);
        $est = $structure->estructura;

        $forms = $est['forms'] ?? [];
        $totalSections = 0;
        $totalFields = 0;
        $totalSumEquals = 0;
        $totalRequired = 0;
        $totalControlOculto = 0;

        $formsDetail = [];
        foreach ($forms as $form) {
            $sections = $form['sections'] ?? [];
            $totalSections += count($sections);
            $secDetail = [];

            foreach ($sections as $section) {
                $fields = $section['fields'] ?? [];
                $totalFields += count($fields);
                $secSum = 0;
                $secReq = 0;
                $secCtrl = 0;

                foreach ($fields as $field) {
                    $regla = $field['reglaDetectada'] ?? null;
                    if ($regla === null) continue;
                    $tipo = is_array($regla) ? ($regla['tipo'] ?? '') : $regla;
                    match ($tipo) {
                        'sum_equals' => [$totalSumEquals++, $secSum++],
                        'required_and_le_parent' => [$totalRequired++, $secReq++],
                        'control_oculto' => [$totalControlOculto++, $secCtrl++],
                        default => null,
                    };
                }

                $secDetail[] = [
                    'codigo' => $section['codigo'],
                    'titulo' => $section['titulo'],
                    'filas' => $section['filaFinDatos']
                        ? ($section['filaFinDatos'] - $section['filaInicioDatos'] + 1)
                        : 0,
                    'campos' => count($fields),
                    'sum_equals' => $secSum,
                    'required' => $secReq,
                    'control_oculto' => $secCtrl,
                ];
            }

            $formsDetail[] = [
                'sheetName' => $form['sheetName'],
                'secciones' => count($sections),
                'campos' => array_sum(array_map(fn($s) => $s['campos'], $secDetail)),
                'sections' => $secDetail,
            ];
        }

        return response()->json([
            'data' => [
                'id' => $structure->id,
                'anio' => $structure->anio,
                'serie' => $structure->serie,
                'version' => $structure->version_number,
                'status' => $structure->status,
                'total_forms' => count($forms),
                'total_sections' => $totalSections,
                'total_fields' => $totalFields,
                'total_rules' => $totalSumEquals + $totalRequired,
                'sum_equals' => $totalSumEquals,
                'required_and_le_parent' => $totalRequired,
                'control_oculto' => $totalControlOculto,
                'forms' => $formsDetail,
            ],
            'message' => 'Estadisticas de estructura',
            'errors' => null,
        ]);
    }

    public function json(int $id): JsonResponse
    {
        $structure = RemTemplateStructure::withTrashed()->findOrFail($id);

        return response()->json([
            'data' => [
                'id' => $structure->id,
                'estructura' => $structure->estructura,
                'metadata' => $structure->metadata,
            ],
            'message' => 'JSON original de la estructura',
            'errors' => null,
        ]);
    }

    public function indexWeb(): View
    {
        $structures = RemTemplateStructure::withTrashed()
            ->orderBy('anio', 'desc')
            ->orderBy('serie')
            ->orderBy('version_number', 'desc')
            ->get();

        return view('admin.rem-explorer.index', compact('structures'));
    }

    public function showWeb(mixed $id): View
    {
        $structure = RemTemplateStructure::withTrashed()->findOrFail((int) $id);
        $est = $structure->estructura;

        $totalSumEquals = 0;
        $totalRequired = 0;
        $totalControlOculto = 0;
        $totalFields = 0;
        $totalSections = 0;

        foreach ($est['forms'] ?? [] as $form) {
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
                        'control_oculto' => $totalControlOculto++,
                        default => null,
                    };
                }
            }
        }

        $stats = [
            'forms' => count($est['forms'] ?? []),
            'sections' => $totalSections,
            'fields' => $totalFields,
            'sum_equals' => $totalSumEquals,
            'required' => $totalRequired,
            'control_oculto' => $totalControlOculto,
        ];

        return view('admin.rem-explorer.show', compact('structure', 'est', 'stats'));
    }

    private function enrich(RemTemplateStructure $structure): array
    {
        $data = $structure->toArray();
        $est = $structure->estructura;
        $forms = $est['forms'] ?? [];

        $totalSumEquals = 0;
        $totalRequired = 0;
        $totalControlOculto = 0;
        $totalFields = 0;
        $totalSections = 0;

        $formsDetail = [];
        foreach ($forms as $form) {
            $secDetail = [];
            foreach ($form['sections'] ?? [] as $section) {
                $totalSections++;
                $totalFields += count($section['fields'] ?? []);
                $secSum = 0;
                $secReq = 0;
                $secCtrl = 0;

                foreach ($section['fields'] ?? [] as $field) {
                    $regla = $field['reglaDetectada'] ?? null;
                    if ($regla === null) continue;
                    $tipo = is_array($regla) ? ($regla['tipo'] ?? '') : $regla;
                    match ($tipo) {
                        'sum_equals' => [$totalSumEquals++, $secSum++],
                        'required_and_le_parent' => [$totalRequired++, $secReq++],
                        'control_oculto' => [$totalControlOculto++, $secCtrl++],
                        default => null,
                    };
                }

                $secDetail[] = [
                    'codigo' => $section['codigo'],
                    'titulo' => $section['titulo'],
                    'filaHeader' => $section['filaHeader'],
                    'filaInicioDatos' => $section['filaInicioDatos'],
                    'filaFinDatos' => $section['filaFinDatos'],
                    'campos' => count($section['fields'] ?? []),
                    'reglas' => $secSum + $secReq,
                    'fields' => $section['fields'] ?? [],
                ];
            }

            $formsDetail[] = [
                'sheetName' => $form['sheetName'],
                'sections' => $secDetail,
            ];
        }

        $data['forms_detail'] = $formsDetail;
        $data['stats'] = [
            'total_forms' => count($forms),
            'total_sections' => $totalSections,
            'total_fields' => $totalFields,
            'total_rules' => $totalSumEquals + $totalRequired,
            'sum_equals' => $totalSumEquals,
            'required_and_le_parent' => $totalRequired,
            'control_oculto' => $totalControlOculto,
        ];

        return $data;
    }
}
