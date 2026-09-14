<?php

namespace Tests\Unit\REM\Services;

use App\Domain\REM\Services\RemParserService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * BM-3.6 (2026-09-14): cubre el bug real encontrado en BM-3.5A --
 * RemParserService::serieFromRemType() truncaba series de 2 letras a su
 * primer caracter ('BM'->'B', 'BS'->'B', colisionando entre si), porque el
 * fallback `/^([a-z])/i` nunca comparaba primero contra la lista real de
 * series validas. Metodo privado -- se invoca via Reflection, mismo
 * patron ya usado en este proyecto (ver RemValidationServiceTest).
 */
class RemParserServiceSerieFromRemTypeTest extends TestCase
{
    private function invoke(string $remType): string
    {
        $service = new RemParserService();
        $method = new ReflectionMethod($service, 'serieFromRemType');
        $method->setAccessible(true);

        return $method->invoke($service, $remType);
    }

    public function test_a_maps_to_a(): void
    {
        $this->assertSame('A', $this->invoke('A'));
    }

    public function test_bm_maps_to_bm(): void
    {
        $this->assertSame('BM', $this->invoke('BM'));
    }

    public function test_bs_maps_to_bs(): void
    {
        $this->assertSame('BS', $this->invoke('BS'));
    }

    public function test_d_maps_to_d(): void
    {
        $this->assertSame('D', $this->invoke('D'));
    }

    public function test_p_maps_to_p(): void
    {
        $this->assertSame('P', $this->invoke('P'));
    }

    // ── El bug exacto encontrado en BM-3.5A, reproducido como regresion ──

    public function test_bm_is_never_truncated_to_b(): void
    {
        $this->assertNotSame('B', $this->invoke('BM'));
        $this->assertSame('BM', $this->invoke('BM'));
    }

    public function test_bs_is_never_truncated_to_b(): void
    {
        $this->assertNotSame('B', $this->invoke('BS'));
        $this->assertSame('BS', $this->invoke('BS'));
    }

    public function test_bm_and_bs_never_collide(): void
    {
        $this->assertNotSame($this->invoke('BM'), $this->invoke('BS'));
    }

    // ── Compatibilidad: el fallback legado para valores fuera de las 5
    // series reales sigue intacto (ej. fixtures de otros tests, como
    // RemParserServiceEmptyRowPersistenceTest::REM_TYPE = 'T'). ──────────

    public function test_legacy_fallback_preserved_for_non_canonical_value(): void
    {
        $this->assertSame('T', $this->invoke('T'));
    }

    public function test_case_insensitive(): void
    {
        $this->assertSame('BM', $this->invoke('bm'));
        $this->assertSame('BS', $this->invoke('bs'));
    }
}
