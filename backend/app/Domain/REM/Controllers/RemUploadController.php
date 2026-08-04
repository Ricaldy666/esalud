<?php

namespace App\Domain\REM\Controllers;

use App\Domain\REM\Jobs\ProcessRemUploadJob;
use App\Domain\REM\Models\RemTemplate;
use App\Domain\REM\Models\RemUpload;
use App\Domain\REM\Requests\StoreRemUploadRequest;
use App\Domain\REM\Services\RemUploadPreviewService;
use App\Http\Controllers\Controller;
use App\Http\Resources\RemUploadResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RemUploadController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', RemUpload::class);

        $query = RemUpload::query()->with(['healthCenter', 'user', 'remTemplate']);

        $user = $request->user();
        if ($user->hasRole('Revisor')) {
            $centroIds = $user->healthCenters()->pluck('health_centers.id');
            $query->whereIn('health_center_id', $centroIds);
        } elseif ($user->hasRole('Analista') || $user->hasRole('Auditor')) {
        }

        if ($year = $request->query('year')) $query->where('year', $year);
        if ($month = $request->query('month')) $query->where('month', $month);
        if ($type = $request->query('rem_type')) $query->where('rem_type', $type);
        if ($status = $request->query('status')) $query->where('status', $status);
        if ($centerId = $request->query('health_center_id')) {
            $query->where('health_center_id', $centerId);
        }

        $perPage = min((int) $request->query('per_page', 20), 100);
        $uploads = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'data' => RemUploadResource::collection($uploads->items()),
            'meta' => [
                'current_page' => $uploads->currentPage(),
                'last_page' => $uploads->lastPage(),
                'per_page' => $uploads->perPage(),
                'total' => $uploads->total(),
            ],
            'message' => 'Cargas REM obtenidas',
            'errors' => null,
        ]);
    }

    public function show(Request $request, RemUpload $remUpload): JsonResponse
    {
        $this->authorize('view', $remUpload);
        $remUpload->load(['healthCenter', 'user', 'remTemplate', 'remData']);

        return response()->json([
            'data' => new RemUploadResource($remUpload),
            'message' => 'Carga REM obtenida',
            'errors' => null,
        ]);
    }

    public function store(StoreRemUploadRequest $request): JsonResponse
    {
        $this->authorize('create', RemUpload::class);

        $file = $request->file('file');
        $data = $request->validated();

        $template = RemTemplate::active()
            ->forYearAndType($data['year'], $data['rem_type'])
            ->first();

        $monthPadded = str_pad($data['month'], 2, '0', STR_PAD_LEFT);
        $directory = "{$data['year']}/{$monthPadded}/{$data['health_center_id']}";

        $timestamp = now()->format('YmdHis');
        $extension = $file->getClientOriginalExtension();
        $basename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $storedFilename = "{$timestamp}_{$basename}.{$extension}";

        $storedPath = $file->storeAs($directory, $storedFilename, 'rem-uploads');

        $upload = RemUpload::create([
            'uuid' => (string) Str::uuid(),
            'health_center_id' => $data['health_center_id'],
            'user_id' => $request->user()->id,
            'rem_template_id' => $template?->id,
            'year' => $data['year'],
            'month' => $data['month'],
            'rem_type' => $data['rem_type'],
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $storedPath,
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'status' => 'pending',
        ]);

        $upload->load(['healthCenter', 'user', 'remTemplate']);

        ProcessRemUploadJob::dispatch($upload->id);

        return response()->json([
            'data' => new RemUploadResource($upload),
            'message' => 'Archivo REM cargado exitosamente. Procesamiento encolado.',
            'errors' => null,
        ], 201);
    }

    public function preview(Request $request): JsonResponse
    {
        $this->authorize('create', RemUpload::class);

        \Log::info('Preview upload debug', [
            'has_file' => $request->hasFile('file'),
            'file_value' => $request->file('file'),
            'content_type' => $request->header('Content-Type'),
            'all_files' => array_keys($request->allFiles()),
        ]);

        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:xlsx,xlsm,xls'],
        ]);

        $service = app(RemUploadPreviewService::class);
        $result = $service->preview($request->file('file'));

        return response()->json([
            'data' => $result,
            'message' => 'Archivo previsualizado exitosamente',
            'errors' => null,
        ]);
    }

    public function status(Request $request, RemUpload $remUpload): JsonResponse
    {
        $this->authorize('view', $remUpload);

        $status = $remUpload->status;
        $currentStep = match ($status) {
            'pending' => 'upload',
            'processing' => 'parsing',
            'validating' => 'rule_engine',
            'success', 'with_errors', 'rejected' => 'report',
            'failed' => 'report',
            default => 'upload',
        };
        $progress = match ($status) {
            'pending' => 20,
            'processing' => 50,
            'validating' => 75,
            'success', 'with_errors', 'rejected' => 100,
            'failed' => 100,
            default => 0,
        };
        $message = match ($status) {
            'pending' => 'Archivo recibido, esperando procesamiento',
            'processing' => 'Procesando archivo REM',
            'validating' => 'Validando datos y reglas de consistencia',
            'success' => 'Procesamiento completado exitosamente',
            'with_errors' => 'Procesamiento completado con observaciones',
            'rejected' => 'Estructura no disponible para la serie y período seleccionados',
            'failed' => 'Error en el procesamiento del archivo',
            default => 'Estado desconocido',
        };

        $validationResults = $remUpload->validationResults();

        $validationSummary = null;
        if ($validationResults->count() > 0) {
            $total = $validationResults->count();
            $passed = $validationResults->where('passed', true)->count();
            $failed = $validationResults->where('passed', false)->count();
            $applicable = $passed + $failed;
            $compliance = $applicable > 0 ? round(($passed / $applicable) * 100, 2) : null;

            $validationSummary = [
                'total_rules' => $total,
                'applicable' => $applicable,
                'passed' => $passed,
                'failed' => $failed,
                'compliance' => $compliance,
            ];
        }

        return response()->json([
            'data' => [
                'id' => $remUpload->id,
                'uuid' => $remUpload->uuid,
                'original_filename' => $remUpload->original_filename,
                'status' => $status,
                'current_step' => $currentStep,
                'progress' => $progress,
                'message' => $message,
                'rem_type' => $remUpload->rem_type,
                'period' => $remUpload->year . '-' . str_pad($remUpload->month, 2, '0', STR_PAD_LEFT),
                'health_center' => $remUpload->healthCenter ? [
                    'id' => $remUpload->healthCenter->id,
                    'name' => $remUpload->healthCenter->name,
                ] : null,
                'processed_at' => $remUpload->processed_at,
                'has_errors' => !is_null($remUpload->error_report),
                'error_summary' => $remUpload->error_report['summary'] ?? null,
                'validation_summary' => $validationSummary,
            ],
            'message' => 'Estado del upload obtenido',
            'errors' => null,
        ]);
    }

    public function validationResults(RemUpload $remUpload): JsonResponse
    {
        $this->authorize('view', $remUpload);

        $results = $remUpload->validationResults()
            ->orderBy('passed')
            ->orderBy('rule_key')
            ->get();

        $totalRules = $results->count();
        $passed = $results->where('passed', true)->count();
        $failedResults = $results->where('passed', false);

        return response()->json([
            'data' => [
                'rem_upload_id' => $remUpload->id,
                'status' => $remUpload->status,
                'total_rules' => $totalRules,
                'applicable' => $passed + $failedResults->count(),
                'passed' => $passed,
                'failed' => $failedResults->count(),
                'compliance' => $passed + $failedResults->count() > 0 ? round(($passed / ($passed + $failedResults->count())) * 100, 2) : null,
                'results' => $results->map(fn ($r) => [
                    'id' => $r->id,
                    'rule_key' => $r->rule_key,
                    'rule_type' => $r->rule_type,
                    'severity' => $r->severity,
                    'passed' => $r->passed,
                    'message' => $r->message,
                    'context' => $r->context,
                ]),
            ],
            'message' => null,
            'errors' => null,
        ]);
    }

    public function destroy(RemUpload $remUpload): JsonResponse
    {
        $this->authorize('delete', $remUpload);
        $remUpload->delete();

        return response()->json([
            'data' => null,
            'message' => 'Carga REM eliminada exitosamente',
            'errors' => null,
        ]);
    }
}
