<?php

use App\Domain\Auth\Controllers\AuthController;
use App\Domain\Health\Controllers\HealthController;
use App\Domain\Users\Controllers\UserController;
use App\Domain\HealthCenters\Controllers\HealthCenterController;
use App\Domain\Roles\Controllers\RoleController;
use App\Domain\Audit\Controllers\ActivityLogController;
use App\Domain\REM\Controllers\RemUploadController;
use App\Domain\REM\Controllers\RemTemplateController;
use Illuminate\Support\Facades\Route;

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

        Route::get('/rem-templates', [RemTemplateController::class, 'index'])
            ->name('rem-templates.index');
        Route::get('/rem-templates/{remTemplate}', [RemTemplateController::class, 'show'])
            ->name('rem-templates.show');
    });
});
