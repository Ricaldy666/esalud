<?php

namespace App\Providers;

use App\Domain\Calibration\Models\Calibration;
use App\Domain\HealthCenters\Models\HealthCenter;
use App\Domain\REM\Models\RemData;
use App\Domain\REM\Models\RemTemplate;
use App\Domain\REM\Models\RemUpload;
use App\Domain\RuleEngine\Models\RuleEngineSetting;
use App\Models\User;
use App\Policies\ActivityLogPolicy;
use App\Policies\CalibrationPolicy;
use App\Policies\FeatureFlagPolicy;
use App\Policies\HealthCenterPolicy;
use App\Policies\RemDataPolicy;
use App\Policies\RemTemplatePolicy;
use App\Policies\RemUploadPolicy;
use App\Policies\UserPolicy;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Spatie\Activitylog\Models\Activity;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        Calibration::class => CalibrationPolicy::class,
        User::class => UserPolicy::class,
        HealthCenter::class => HealthCenterPolicy::class,
        Activity::class => ActivityLogPolicy::class,
        RemUpload::class => RemUploadPolicy::class,
        RemTemplate::class => RemTemplatePolicy::class,
        RemData::class => RemDataPolicy::class,
        RuleEngineSetting::class => FeatureFlagPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
        $this->preventRememberedReauthentication();
    }

    /**
     * Politica de sesion ATHENEA (2026-09-03): "recordar sesion" fue
     * eliminado por completo -- AuthController::login() ya nunca pasa
     * remember=true a Auth::attempt(), asi que ningun login nuevo emite la
     * cookie "recaller" de Laravel. Eso por si solo NO invalida cookies
     * "recaller" que ya existieran en un navegador de antes de este cambio:
     * Illuminate\Auth\SessionGuard::user() sigue dispuesto a resucitar una
     * sesion a partir de esa cookie mientras el remember_token en BD siga
     * coincidiendo -- y esa resurreccion ocurre por completo dentro del
     * guard, sin pasar por AuthController::login(), por lo que nunca marca
     * el gate de 2FA pendiente (TwoFactorSession::markPending()). Es un
     * bypass real de 2FA via remember_token, no solo teorico.
     *
     * La politica no distingue por 2FA: ninguna sesion de este sistema
     * puede originarse en una cookie "recaller", nunca, para ningun
     * usuario. SessionGuard dispara el evento Login con remember=true
     * unicamente en ese escenario de resurreccion (un login real por
     * password ya nunca pasa remember=true) -- basta escucharlo: en cuanto
     * SessionGuard::user() resucita una sesion asi, se revierte de
     * inmediato con logout() (equivalente a cycleRememberToken() + olvidar
     * la cookie/sesion), antes de que el resto del pipeline (auth:sanctum,
     * 2fa.verified, el controller) llegue a tratar la request como
     * autenticada. Efecto practico: cualquier cookie "recaller" previa a
     * este cambio queda invalidada la primera vez que alguien intenta
     * usarla -- no requiere una migracion ni un UPDATE manual masivo sobre
     * la tabla users.
     */
    private function preventRememberedReauthentication(): void
    {
        Event::listen(Login::class, function (Login $event) {
            if ($event->remember) {
                Auth::guard($event->guard)->logout();
            }
        });
    }
}
