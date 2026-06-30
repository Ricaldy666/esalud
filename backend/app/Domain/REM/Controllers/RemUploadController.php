<?php

namespace App\Domain\REM\Controllers;

use App\Domain\REM\Jobs\ProcessRemUploadJob;
use App\Domain\REM\Models\RemTemplate;
use App\Domain\REM\Models\RemUpload;
use App\Domain\REM\Requests\StoreRemUploadRequest;
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
        if (!$user->hasRole('Administrador')) {
            $query->where(function ($q) use ($user) {
                $q->where('health_center_id', $user->health_center_id)
                  ->orWhere('user_id', $user->id);
            });
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

    public function status(Request $request, RemUpload $remUpload): JsonResponse
    {
        $this->authorize('view', $remUpload);

        return response()->json([
            'data' => [
                'id' => $remUpload->id,
                'uuid' => $remUpload->uuid,
                'status' => $remUpload->status,
                'processed_at' => $remUpload->processed_at,
                'has_errors' => !is_null($remUpload->error_report),
                'error_summary' => $remUpload->error_report['summary'] ?? null,
                'data_rows_count' => $remUpload->remData()->count(),
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

        return response()->json([
            'data' => [
                'rem_upload_id' => $remUpload->id,
                'status' => $remUpload->status,
                'total_rules' => $results->count(),
                'total_errors' => $results->where('passed', false)->where('severity', 'error')->count(),
                'total_warnings' => $results->where('passed', false)->where('severity', 'warning')->count(),
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
