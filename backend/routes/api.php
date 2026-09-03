<?php

use App\Domain\Auth\Controllers\AuthController;
use App\Domain\Auth\Controllers\TwoFactorController;
use App\Domain\Health\Controllers\HealthController;
use App\Domain\Users\Controllers\UserController;
use App\Domain\HealthCenters\Controllers\HealthCenterController;
use App\Domain\Roles\Controllers\RoleController;
use App\Domain\Audit\Controllers\ActivityLogController;
use App\Domain\REM\Controllers\RemUploadController;
use App\Domain\REM\Controllers\RemTemplateController;
use App\Domain\RemParser\Controllers\RemExplorerController;
use App\Domain\RuleEngine\Controllers\CalibrationViewController;
use App\Domain\RuleEngine\Controllers\CatalogController;
use App\Domain\RuleEngine\Controllers\FeatureFlagController;
use App\Domain\Calibration\Controllers\CalibrationController;
use App\Http\Controllers\RemParserTestController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::prefix('v1')->group(function () {
    Route::get('/health', HealthController::class)->name('health');

    Route::prefix('auth')->group(function () {
        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:login')
            ->name('auth.login');

        // Publica, deliberadamente SIN auth:sanctum -- a diferencia de /me
        // (401 si no hay sesion), esta ruta existe para consultarse SIN
        // sesion (useAuthInit al abrir /login) y siempre responde 200 con
        // authenticated:false/true. Igual pasa por EnsureFrontendRequestsAreStateful/
        // StartSession/EncryptCookies (prepended al grupo api completo), asi
        // que una sesion real SI se detecta, y el listener
        // AuthServiceProvider::preventRememberedReauthentication() sigue
        // aplicando (esta atado al guard, no a la ruta).
        Route::get('/session', [AuthController::class, 'session'])
            ->name('auth.session');

        // logout/me/2fa-verify: deliberadamente SIN el middleware 2fa.verified
        // -- son las 3 unicas rutas que deben seguir siendo alcanzables
        // mientras un challenge de doble factor esta pendiente (logout para
        // poder cancelar, me para que el frontend sepa que debe mostrar el
        // challenge, verify porque es la ruta que lo resuelve).
        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');
            Route::get('/me', [AuthController::class, 'me'])->name('auth.me');

            Route::post('/2fa/verify', [TwoFactorController::class, 'verify'])
                ->middleware('throttle:2fa-verify')
                ->name('auth.2fa.verify');
        });

        // Gestion de 2FA de la propia cuenta: exige sesion COMPLETAMENTE
        // autenticada (2fa.verified) -- nunca alcanzable mientras un
        // challenge esta pendiente.
        Route::middleware(['auth:sanctum', '2fa.verified'])->group(function () {
            Route::post('/2fa/enroll', [TwoFactorController::class, 'enroll'])
                ->middleware('throttle:sensitive-user-write')
                ->name('auth.2fa.enroll');
            Route::post('/2fa/confirm', [TwoFactorController::class, 'confirm'])
                ->middleware('throttle:2fa-verify')
                ->name('auth.2fa.confirm');
            Route::post('/2fa/disable', [TwoFactorController::class, 'disable'])
                ->middleware('throttle:sensitive-user-write')
                ->name('auth.2fa.disable');
            Route::post('/2fa/recovery-codes/regenerate', [TwoFactorController::class, 'regenerateRecoveryCodes'])
                ->middleware('throttle:sensitive-user-write')
                ->name('auth.2fa.recovery-codes.regenerate');
        });
    });

    Route::middleware(['auth:sanctum', '2fa.verified'])->group(function () {
        Route::apiResource('users', UserController::class)
            ->middlewareFor(['store', 'update'], 'throttle:sensitive-user-write');
        Route::apiResource('health-centers', HealthCenterController::class);
        Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
        Route::get('/activity-log', [ActivityLogController::class, 'index'])->name('activity-log.index');

        Route::apiResource('rem-uploads', RemUploadController::class)
            ->only(['index', 'show', 'store', 'destroy'])
            ->parameter('rem-uploads', 'remUpload');

        Route::get('/rem-uploads/{remUpload}/status', [RemUploadController::class, 'status'])
            ->name('rem-uploads.status');

        Route::post('/rem-uploads/preview', [RemUploadController::class, 'preview'])
            ->name('rem-uploads.preview');

        Route::get('/rem-uploads/{remUpload}/validation-results', [RemUploadController::class, 'validationResults'])
            ->name('rem-uploads.validation-results');

        Route::get('/rem-templates', [RemTemplateController::class, 'index'])
            ->name('rem-templates.index');
        Route::get('/rem-templates/{remTemplate}', [RemTemplateController::class, 'show'])
            ->name('rem-templates.show');

        // FASE 1 — Endpoint temporal de prueba para el nuevo Parser REM
        Route::post('/rem-parser/test', [RemParserTestController::class, 'test'])
            ->name('rem-parser.test');

        Route::prefix('rem-explorer')->name('rem-explorer.')->group(function () {
            Route::get('/structures', [RemExplorerController::class, 'index'])->name('structures.index');
            Route::get('/structures/{id}', [RemExplorerController::class, 'show'])->name('structures.show');
            Route::get('/structures/{id}/stats', [RemExplorerController::class, 'stats'])->name('structures.stats');
            Route::get('/structures/{id}/json', [RemExplorerController::class, 'json'])->name('structures.json');
        });

        Route::get('/rem-discovery/latest', function (Request $request) {
            abort_unless($request->user()->hasRole('Administrador'), 403);

            $files = Storage::disk('rem-discovery')->files();
            $jsonFiles = collect($files)->filter(fn($f) => str_ends_with($f, '.json'))->sortDesc();

            if ($jsonFiles->isEmpty()) {
                return response()->json([
                    'data' => null,
                    'message' => 'No hay discoveries generados aun',
                    'errors' => null,
                ]);
            }

            $latest = $jsonFiles->first();
            return response()->json([
                'data' => json_decode(Storage::disk('rem-discovery')->get($latest), true),
                'message' => 'Discovery mas reciente',
                'errors' => null,
            ]);
        })->name('rem-discovery.latest');

        Route::prefix('rule-engine')->name('rule-engine.')->group(function () {
            Route::get('/health', [\App\Domain\RuleEngine\Controllers\ObservabilityController::class, 'health'])
                ->name('health');
            Route::get('/stats', [\App\Domain\RuleEngine\Controllers\ObservabilityController::class, 'stats'])
                ->name('stats');
            Route::get('/rules', [\App\Domain\RuleEngine\Controllers\RuleController::class, 'index'])
                ->name('rules.index');
            Route::get('/rules/{rule}', [\App\Domain\RuleEngine\Controllers\RuleController::class, 'show'])
                ->name('rules.show');
            Route::get('/logs', [\App\Domain\RuleEngine\Controllers\RuleExecutionLogController::class, 'index'])
                ->name('logs.index');
            Route::get('/logs/{log}', [\App\Domain\RuleEngine\Controllers\RuleExecutionLogController::class, 'show'])
                ->name('logs.show');
            Route::get('/structures', [\App\Domain\RuleEngine\Controllers\StructureController::class, 'index'])
                ->name('structures.index');
            Route::get('/structures/{id}', [\App\Domain\RuleEngine\Controllers\StructureController::class, 'show'])
                ->name('structures.show');
            Route::get('/bindings', [\App\Domain\RuleEngine\Controllers\BindingController::class, 'index'])
                ->name('bindings.index');
            Route::get('/bindings/{id}', [\App\Domain\RuleEngine\Controllers\BindingController::class, 'show'])
                ->name('bindings.show');
            Route::get('/compare', [\App\Domain\RuleEngine\Controllers\ComparisonController::class, 'run'])
                ->name('compare.run');
            Route::get('/config', [FeatureFlagController::class, 'show'])
                ->name('config.show');
            Route::put('/config', [FeatureFlagController::class, 'update'])
                ->name('config.update');
            Route::get('/uploads/{upload}/validation-summary', [\App\Domain\RuleEngine\Controllers\ValidationSummaryController::class, 'show'])
                ->name('uploads.validation-summary');
            Route::get('/uploads/{upload}/validation-errors', [\App\Domain\RuleEngine\Controllers\ValidationErrorController::class, 'index'])
                ->name('uploads.validation-errors');

            Route::prefix('calibrations')->name('calibrations.')->group(function () {
                Route::get('/', [CalibrationController::class, 'index'])->name('index');
                Route::post('/', [CalibrationController::class, 'store'])->name('store');
                Route::get('{calibration}', [CalibrationController::class, 'show'])->name('show');
                Route::get('{calibration}/sections/{section}/cells', [CalibrationController::class, 'sectionCells'])->name('section-cells');
                Route::put('{calibration}/cells', [CalibrationController::class, 'saveCell'])->name('save-cell');
            });

            Route::prefix('catalog')->name('catalog.')->group(function () {
                Route::get('/', [CatalogController::class, 'index'])->name('index');
                Route::get('/export', [CatalogController::class, 'export'])->name('export');
                Route::get('/calibration-summary', [CalibrationViewController::class, 'calibrationSummary'])->name('calibration-summary');
                Route::get('/{sheet}/sections/{section}', [CatalogController::class, 'section'])->name('section');
                Route::get('/{sheet}/sections/{section}/export', [CatalogController::class, 'sectionExport'])->name('section-export');
                Route::get('/{sheet}/sections/{section}/matrix', [CatalogController::class, 'matrix'])->name('matrix');
                Route::get('/{sheet}/sections/{section}/patterns', [CalibrationViewController::class, 'matrixData'])->name('patterns');
                Route::get('/{sheet}/sections/{section}/migration-plan', [CalibrationViewController::class, 'migrationPlan'])->name('migration-plan');
                Route::get('/{sheet}/sections/{section}/row-functional-decisions', [CatalogController::class, 'rowFunctionalDecisions'])->name('row-functional-decisions');
                Route::get('/{sheet}/sections/{section}/questions', [CatalogController::class, 'getQuestions'])->name('questions');
                Route::post('/{sheet}/sections/{section}/questions', [CatalogController::class, 'saveQuestions'])->name('questions.save');
                Route::post('/{sheet}/sections/{section}/pattern-questions', [CalibrationViewController::class, 'saveQuestions'])->name('pattern-questions.save');
                Route::post('/{sheet}/sections/{section}/patterns/{patternId}/quick-revalidation', [CalibrationViewController::class, 'confirmQuickRevalidation'])->name('patterns.quick-revalidation');
                Route::get('/{sheet}/sections/{section}/patterns/{patternId}/mismatch-resolution', [CalibrationViewController::class, 'mismatchResolutionDetails'])->name('patterns.mismatch-resolution.details');
                Route::post('/{sheet}/sections/{section}/patterns/{patternId}/mismatch-resolution/confirm', [CalibrationViewController::class, 'confirmMismatchResolution'])->name('patterns.mismatch-resolution.confirm');
                Route::post('/{sheet}/sections/{section}/bulk-functional', [CatalogController::class, 'bulkFunctional'])->name('bulk-functional');
                Route::get('/{sheet}/sections/{section}/export-calibration', [CatalogController::class, 'exportCalibration'])->name('export-calibration');
                Route::get('/{sheet}/sections/{section}/rows/{row}', [CatalogController::class, 'rowDetail'])->name('row-detail');
                Route::post('/{sheet}/sections/{section}/rows/{row}/functional-rules', [CatalogController::class, 'saveRowFunctionalRules'])->name('row-functional-rules.save');
                Route::get('/{sheet}/sections/{section}/rows/{row}/functional-rules/versions', [CatalogController::class, 'getRowFunctionalVersions'])->name('row-functional-rules.versions');
                Route::post('/{sheet}/sections/{section}/scan-cells', [CatalogController::class, 'scanCells'])->name('scan-cells');
                Route::post('/{sheet}/scan-cells', [CatalogController::class, 'scanCells'])->name('scan-cells-sheet');
                Route::get('/{sheet}/sections/{section}/cell-data', [CatalogController::class, 'getCellData'])->name('cell-data');
                Route::get('/{ruleKey}', [CatalogController::class, 'show'])->name('show');
                Route::post('/{ruleKey}/status', [CatalogController::class, 'status'])->name('status');
                Route::get('/{ruleKey}/functional-rules', [CatalogController::class, 'getFunctionalRules'])->name('functional-rules');
                Route::post('/{ruleKey}/functional-rules', [CatalogController::class, 'saveFunctionalRules'])->name('functional-rules.save');
            });
        });
    });
});
