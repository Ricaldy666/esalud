<?php

namespace Tests\Unit;

use Tests\TestCase;

class EnvironmentGuardTest extends TestCase
{
    public function test_environment_is_safe_for_testing(): void
    {
        $env = app()->environment();
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        $this->assertEquals('testing', $env, 'APP_ENV debe ser testing');
        $this->assertStringContainsString('test', strtolower($database), 'DB_DATABASE debe contener "test"');
        $this->assertNotEquals('esalud_dev', $database, 'DB_DATABASE no debe ser esalud_dev');
        $this->assertNotEquals('esalud', $database, 'DB_DATABASE no debe ser esalud');
    }
}
