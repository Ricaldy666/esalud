<?php

namespace Tests\Feature\RuleEngine\Services;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Models\RemSheetUsageStatus;
use App\Domain\RuleEngine\Services\RemSheetUsageStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Cubre RemSheetUsageStatusService -- registro explicito de que una hoja
 * REM (anio+serie+sheet_name) es 'aplicable' (default, sin fila) o
 * 'no_utilizada' segun determinacion de Estadistica APS. Nunca inferido
 * automaticamente. Ver migracion 2026_08_11_000001 para el diseño
 * completo (VARCHAR + validacion en Laravel, no ENUM SQL; asociado a
 * anio+serie+sheet_name, no a un structure_id puntual).
 */
class RemSheetUsageStatusServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): RemSheetUsageStatusService
    {
        return app(RemSheetUsageStatusService::class);
    }

    public function test_absence_of_row_means_aplicable(): void
    {
        $status = $this->service()->getStatusFor(2026, 'A', 'A21');

        $this->assertSame(RemSheetUsageStatusService::STATUS_APLICABLE, $status);
        $this->assertSame(0, RemSheetUsageStatus::count(), 'no debe crearse ninguna fila solo por consultar el estado');
    }

    public function test_set_status_rejects_invalid_status(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service()->setStatus(2026, 'A', 'A21', 'inventado', 'motivo', 'Estadística APS', null);
    }

    public function test_set_status_to_no_utilizada_creates_row_and_history(): void
    {
        $row = $this->service()->setStatus(
            2026,
            'A',
            'A21',
            RemSheetUsageStatusService::STATUS_NO_UTILIZADA,
            'Determinado por Estadística APS como no utilizada',
            'Estadística APS',
            null,
        );

        $this->assertSame('no_utilizada', $row->status);
        $this->assertSame('A21', $row->sheet_name);
        $this->assertNotNull($row->decided_at);

        $history = $row->history()->get();
        $this->assertCount(1, $history);
        $this->assertSame('aplicable', $history[0]->previous_status);
        $this->assertSame('no_utilizada', $history[0]->new_status);

        $this->assertSame(
            RemSheetUsageStatusService::STATUS_NO_UTILIZADA,
            $this->service()->getStatusFor(2026, 'A', 'A21')
        );
    }

    /**
     * Requisito #4 y #5: no_utilizada -> aplicable reincorpora
     * correctamente, y el historial conserva AMBAS transiciones.
     */
    public function test_reactivating_a_sheet_preserves_full_history(): void
    {
        $svc = $this->service();
        $row = $svc->setStatus(2026, 'A', 'A21', RemSheetUsageStatusService::STATUS_NO_UTILIZADA, 'motivo 1', 'Estadística APS', null);

        $svc->setStatus(2026, 'A', 'A21', RemSheetUsageStatusService::STATUS_APLICABLE, 'motivo 2 -- vuelve a usarse', 'Estadística APS', null);

        $this->assertSame(RemSheetUsageStatusService::STATUS_APLICABLE, $svc->getStatusFor(2026, 'A', 'A21'));

        $history = $row->fresh()->history()->get();
        $this->assertCount(2, $history, 'debe conservar las 2 transiciones, no sobrescribir la primera');
        $this->assertSame('aplicable', $history[0]->previous_status);
        $this->assertSame('no_utilizada', $history[0]->new_status);
        $this->assertSame('no_utilizada', $history[1]->previous_status);
        $this->assertSame('aplicable', $history[1]->new_status);
    }

    public function test_setting_same_status_twice_is_rejected(): void
    {
        $svc = $this->service();
        $svc->setStatus(2026, 'A', 'A21', RemSheetUsageStatusService::STATUS_NO_UTILIZADA, 'motivo', 'Estadística APS', null);

        $this->expectException(InvalidArgumentException::class);
        $svc->setStatus(2026, 'A', 'A21', RemSheetUsageStatusService::STATUS_NO_UTILIZADA, 'motivo repetido', 'Estadística APS', null);
    }

    /**
     * Requisito #6: cambio de estructura activa no pierde el estado de
     * negocio -- la fila esta asociada a (anio,serie,sheet_name), no a un
     * structure_id puntual.
     */
    public function test_usage_status_persists_across_structure_activation(): void
    {
        $structureA = RemTemplateStructure::create([
            'anio' => 2026, 'serie' => 'A', 'rem_template_id' => null, 'version_number' => 1,
            'hash_estructura' => 'hash-usage-a-' . uniqid(),
            'estructura' => ['forms' => []], 'metadata' => null, 'source_filename' => 'test.xlsm', 'status' => 'active',
        ]);

        $svc = $this->service();
        $svc->setStatus(2026, 'A', 'A21', RemSheetUsageStatusService::STATUS_NO_UTILIZADA, 'motivo', 'Estadística APS', $structureA->id);

        // Simula la activacion de una nueva version de estructura para el
        // mismo anio/serie (como hace StructureApprovalService::activate()).
        $structureA->update(['status' => 'superseded']);
        $structureB = RemTemplateStructure::create([
            'anio' => 2026, 'serie' => 'A', 'rem_template_id' => null, 'version_number' => 2,
            'hash_estructura' => 'hash-usage-b-' . uniqid(),
            'estructura' => ['forms' => []], 'metadata' => null, 'source_filename' => 'test.xlsm', 'status' => 'active',
        ]);

        $this->assertSame(
            RemSheetUsageStatusService::STATUS_NO_UTILIZADA,
            $svc->getStatusFor((int) $structureB->anio, $structureB->serie, 'A21'),
            'el estado de negocio debe sobrevivir al cambio de estructura activa'
        );
    }

    public function test_get_non_aplicable_sheets_filters_by_anio_serie(): void
    {
        $svc = $this->service();
        $svc->setStatus(2026, 'A', 'A21', RemSheetUsageStatusService::STATUS_NO_UTILIZADA, 'motivo', 'Estadística APS', null);
        $svc->setStatus(2026, 'A', 'A24', RemSheetUsageStatusService::STATUS_NO_UTILIZADA, 'motivo', 'Estadística APS', null);
        $svc->setStatus(2025, 'A', 'A21', RemSheetUsageStatusService::STATUS_NO_UTILIZADA, 'motivo otro anio', 'Estadística APS', null);

        $sheets = $svc->getNonAplicableSheets(2026, 'A');

        $this->assertCount(2, $sheets);
        $this->assertEqualsCanonicalizing(['A21', 'A24'], $sheets->pluck('sheet_name')->all());
    }
}
