<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        $this->guardAgainstProductionDatabase();
        parent::setUp();
        $this->verifyEffectiveConnection();

        // EnsureFrontendRequestsAreStateful::fromFrontend() exige un header
        // Referer u Origin que matchee SANCTUM_STATEFUL_DOMAINS para aplicar
        // su pipeline de sesion/cookies -- un navegador real siempre envia
        // Origin en peticiones fetch/XHR, pero el cliente de test de Laravel
        // no lo hace por defecto. Sin esto, ningun test ejerceria el camino
        // real que usa el SPA (quedaria silenciosamente igual de "stateless"
        // que antes de corregir bootstrap/app.php).
        $this->withHeaders([
            'Referer' => 'http://localhost:5173',
            'Origin' => 'http://localhost:5173',
        ]);
    }

    private function guardAgainstProductionDatabase(): void
    {
        $env = getenv('APP_ENV') ?: '';
        $database = getenv('DB_DATABASE') ?: '';

        $errors = [];

        if ($env !== 'testing') {
            $errors[] = "APP_ENV debe ser 'testing', actual: '{$env}'";
        }

        if (empty($database)) {
            $errors[] = 'DB_DATABASE está vacío';
        }

        if (in_array($database, ['esalud_dev', 'esalud'], true)) {
            $errors[] = "DB_DATABASE es '{$database}' — PROHIBIDO para testing";
        }

        if (!str_contains(strtolower($database), 'test')) {
            $errors[] = "DB_DATABASE '{$database}' no contiene 'test' ni 'testing'";
        }

        if (!empty($errors)) {
            throw new \RuntimeException(
                "\n" .
                '╔══════════════════════════════════════════════════╗' . "\n" .
                '║      GUARD DE SEGURIDAD — ENTORNO DE TESTING     ║' . "\n" .
                '╚══════════════════════════════════════════════════╝' . "\n" .
                'Ejecución bloqueada por configuración insegura:' . "\n" .
                implode("\n", $errors) . "\n"
            );
        }
    }

    private function verifyEffectiveConnection(): void
    {
        $connection = config('database.default');
        $databaseConfig = config("database.connections.{$connection}.database");
        $envDatabase = getenv('DB_DATABASE');

        if ($databaseConfig !== $envDatabase) {
            throw new \RuntimeException(
                "\n" .
                '╔══════════════════════════════════════════════════╗' . "\n" .
                '║      GUARD DE SEGURIDAD — CONEXIÓN EFECTIVA      ║' . "\n" .
                '╚══════════════════════════════════════════════════╝' . "\n" .
                "Discrepancia: la conexión efectiva apunta a '{$databaseConfig}' " .
                "pero la variable de entorno es '{$envDatabase}'.\n"
            );
        }

        if (!str_contains(strtolower($databaseConfig), 'test')) {
            throw new \RuntimeException(
                "\n" .
                '╔══════════════════════════════════════════════════╗' . "\n" .
                '║      GUARD DE SEGURIDAD — CONEXIÓN EFECTIVA      ║' . "\n" .
                '╚══════════════════════════════════════════════════╝' . "\n" .
                "La conexión efectiva '{$databaseConfig}' no es una base de testing.\n"
            );
        }
    }
}
