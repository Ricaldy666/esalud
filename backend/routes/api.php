<?php

use App\Domain\Auth\Controllers\AuthController;
use App\Domain\Health\Controllers\HealthController;
use App\Domain\Users\Controllers\UserController;
use App\Domain\HealthCenters\Controllers\HealthCenterController;
use App\Domain\Roles\Controllers\RoleController;
use App\Domain\Audit\Controllers\ActivityLogController;
use App\Domain\REM\Controllers\RemUploadController;
use App\Domain\REM\Controllers\RemTemplateController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::prefix('v1')->group(function () {
    Route::get('/health', HealthController::class)->name('health');

    Route::prefix('auth')->group(function () {
        Route::post('/login', [AuthController::class, 'login'])->name('auth.login');

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');
            Route::get('/me', [AuthController::class, 'me'])->name('auth.me');
        });
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::apiResource('users', UserController::class);
        Route::apiResource('health-centers', HealthCenterController::class);
        Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
        Route::get('/activity-log', [ActivityLogController::class, 'index'])->name('activity-log.index');

        Route::apiResource('rem-uploads', RemUploadController::class)
            ->only(['index', 'show', 'store', 'destroy'])
            ->parameter('rem-uploads', 'remUpload');

        Route::get('/rem-uploads/{remUpload}/status', [RemUploadController::class, 'status'])
            ->name('rem-uploads.status');

        Route::get('/rem-uploads/{remUpload}/validation-results', [RemUploadController::class, 'validationResults'])
            ->name('rem-uploads.validation-results');

        Route::get('/rem-templates', [RemTemplateController::class, 'index'])
            ->name('rem-templates.index');
        Route::get('/rem-templates/{remTemplate}', [RemTemplateController::class, 'show'])
            ->name('rem-templates.show');

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
    });
});
