<?php

namespace Tests\Feature\REM;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Models\RemSheetUsageStatus;
use App\Domain\RuleEngine\Services\RemSheetUsageStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Cubre rem:set-sheet-usage-status -- el unico punto de entrada para marcar
 * una hoja REM como 'no_utilizada'/'aplicable'. Nunca determina esto
 * automaticamente: exige --serie, --year, --reason y --by en cada llamada,
 * y soporta --dry-run para previsualizar sin persistir (ver
 * RemSheetUsageStatusService para la logica de negocio subyacente, ya
 * cubierta por su propio test).
 */
class RemSetSheetUsageStatusCommandTest extends TestCase
{
    use RefreshDatabase;

    private function createActiveStructure(): RemTemplateStructure
    {
        return RemTemplateStructure::create([
            'anio' => 2026,
            'serie' => 'A',
            'rem_template_id' => null,
            'version_number' => 1,
            'hash_estructura' => 'hash-usage-command-' . uniqid(),
            'estructura' => [
                'forms' => [
                    ['sheetName' => 'A21', 'sections' => [
                        ['codigo' => 'A', 'titulo' => 'SECCION A', 'filaHeader' => 9, 'filaInicioDatos' => 10, 'filaFinDatos' => 11, 'fields' => []],
                        ['codigo' => 'B', 'titulo' => 'SECCION B', 'filaHeader' => 20, 'filaInicioDatos' => 21, 'filaFinDatos' => 22, 'fields' => []],
                    ]],
                ],
            ],
            'metadata' => null,
            'source_filename' => 'test.xlsm',
            'status' => 'active',
        ]);
    }

    public function test_missing_required_options_fails_without_persisting(): void
    {
        $exit = Artisan::call('rem:set-sheet-usage-status', [
            'sheet' => 'A21',
            'status' => RemSheetUsageStatusService::STATUS_NO_UTILIZADA,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('obligatorios', Artisan::output());
        $this->assertSame(0, RemSheetUsageStatus::count());
    }

    public function test_invalid_status_fails_without_persisting(): void
    {
        $exit = Artisan::call('rem:set-sheet-usage-status', [
            'sheet' => 'A21',
            'status' => 'inventado',
            '--serie' => 'A',
            '--year' => '2026',
            '--reason' => 'motivo',
            '--by' => 'Estadística APS',
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('invalido', Artisan::output());
        $this->assertSame(0, RemSheetUsageStatus::count());
    }

    public function test_dry_run_does_not_persist(): void
    {
        $this->createActiveStructure();

        $exit = Artisan::call('rem:set-sheet-usage-status', [
            'sheet' => 'A21',
            'status' => RemSheetUsageStatusService::STATUS_NO_UTILIZADA,
            '--serie' => 'A',
            '--year' => '2026',
            '--reason' => 'No utilizada por Estadística APS',
            '--by' => 'Estadística APS',
            '--dry-run' => true,
        ]);

        $output = Artisan::output();
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('DRY-RUN', $output);
        $this->assertStringContainsString('aplicable → no_utilizada', $output);
        $this->assertSame(0, RemSheetUsageStatus::count(), 'dry-run nunca debe persistir');
    }

    public function test_real_run_persists_and_creates_history(): void
    {
        $structure = $this->createActiveStructure();

        $exit = Artisan::call('rem:set-sheet-usage-status', [
            'sheet' => 'A21',
            'status' => RemSheetUsageStatusService::STATUS_NO_UTILIZADA,
            '--serie' => 'A',
            '--year' => '2026',
            '--reason' => 'No utilizada por Estadística APS',
            '--by' => 'Estadística APS',
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('registrado', Artisan::output());

        $row = RemSheetUsageStatus::where('sheet_name', 'A21')->first();
        $this->assertNotNull($row);
        $this->assertSame('no_utilizada', $row->status);
        $this->assertSame($structure->id, $row->structure_id);
        $this->assertCount(1, $row->history()->get());
    }

    public function test_re_running_same_status_fails(): void
    {
        $this->createActiveStructure();
        app(RemSheetUsageStatusService::class)->setStatus(2026, 'A', 'A21', RemSheetUsageStatusService::STATUS_NO_UTILIZADA, 'motivo', 'Estadística APS', null);

        $exit = Artisan::call('rem:set-sheet-usage-status', [
            'sheet' => 'A21',
            'status' => RemSheetUsageStatusService::STATUS_NO_UTILIZADA,
            '--serie' => 'A',
            '--year' => '2026',
            '--reason' => 'motivo repetido',
            '--by' => 'Estadística APS',
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('ya esta en estado', Artisan::output());
        $this->assertCount(1, RemSheetUsageStatus::where('sheet_name', 'A21')->first()->history()->get(), 'no debe crear una segunda entrada de historial redundante');
    }
}
