<?php

namespace Tests\Feature\RuleEngine\Services;

use App\Domain\HealthCenters\Models\HealthCenter;
use App\Domain\REM\Models\RemData;
use App\Domain\REM\Models\RemUpload;
use App\Domain\REM\Models\RemValidationResult;
use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Services\ValidationErrorFormatterService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * NO es un test de regresion permanente -- es el arnes de benchmark usado
 * para medir formatErrors() antes/despues de la optimizacion de
 * resolveFunctionalSectionMeta() (hallazgo de rendimiento en produccion,
 * upload 8: 193s / 86.5MB con 1001 errores). Genera un dataset sintetico
 * de escala similar (varios miles de RemData, cientos de errores
 * funcionales) y reporta tiempo + cantidad de queries SQL.
 *
 * Se corre manualmente (no forma parte de la suite de CI): antes de la
 * optimizacion para capturar el baseline, y despues para comparar.
 */
class ValidationErrorFormatterBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    public function test_benchmark_representative_dataset(): void
    {
        $healthCenter = HealthCenter::create([
            'name' => 'CESFAM Benchmark',
            'code_deis' => 'HC_BENCH',
            'type' => 'Cesfam',
        ]);

        $upload = RemUpload::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'health_center_id' => $healthCenter->id,
            'user_id' => User::factory()->create()->id,
            'year' => 2026,
            'month' => 6,
            'rem_type' => 'A',
            'original_filename' => 'benchmark.xlsx',
            'stored_path' => 'benchmark.xlsx',
            'file_size' => 1000,
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'status' => 'with_errors',
        ]);

        RemTemplateStructure::create([
            'serie' => 'A', 'anio' => 2026, 'version_number' => 1,
            'hash_estructura' => 'hash_benchmark',
            'estructura' => ['forms' => [[
                'sheetName' => 'A01',
                'sections' => array_map(fn ($i) => [
                    'codigo' => "SEC{$i}",
                    'titulo' => "Seccion de prueba {$i}",
                    'filaInicioDatos' => $i * 10,
                    'filaFinDatos' => $i * 10 + 9,
                    'fields' => [],
                ], range(1, 10)),
            ]]],
            'status' => 'active',
        ]);

        // ~2200 filas de RemData con row_number MAYORMENTE DISTINTO
        // (fila real = 10..2209), repartidas en 10 secciones -- similar a
        // la escala real de produccion (rem_data_upload_8=2177). Deliberado:
        // fila 10 se duplica dos veces con distinto rem_section_code, para
        // ejercitar la semantica "primer match gana" de Collection::first().
        $remDataRows = [];
        $now = now();
        foreach (range(10, 2209) as $rowNumber) {
            $secIdx = intdiv($rowNumber - 10, 220) + 1;
            $remDataRows[] = [
                'rem_upload_id' => $upload->id,
                'section' => 'A01',
                'data' => json_encode([
                    'section' => 'A01',
                    'rem_section_code' => "SEC{$secIdx}",
                    'row_number' => $rowNumber,
                    'concept' => "Concepto {$rowNumber}",
                    'professional' => 'Profesional Test',
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        // Duplicado deliberado de la fila 10 con OTRA seccion -- debe
        // ignorarse porque no es el primero en insertarse.
        $remDataRows[] = [
            'rem_upload_id' => $upload->id,
            'section' => 'A01',
            'data' => json_encode([
                'section' => 'A01',
                'rem_section_code' => 'SEC_DUPLICADO_NO_DEBE_GANAR',
                'row_number' => 10,
                'concept' => 'Concepto duplicado',
                'professional' => 'Profesional Test',
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now,
        ];
        foreach (array_chunk($remDataRows, 500) as $chunk) {
            DB::table('rem_data')->insert($chunk);
        }
        $this->assertSame(2201, RemData::where('rem_upload_id', $upload->id)->count());

        // ~1000 RemValidationResult functional_rule fallidos (similar a
        // functional_failed=956 en produccion), cada uno referenciando una
        // fila DISTINTA (fila = 10 + i, dentro del rango 10..2209 con
        // RemData real) -- este es el escenario que exponia el problema:
        // cientos de (sheet,row) distintos, cada uno forzando una recarga
        // completa de RemData en el codigo original.
        foreach (range(1, 1000) as $i) {
            $rowNumber = 10 + (($i - 1) % 1000);
            RemValidationResult::create([
                'rem_upload_id' => $upload->id,
                'rule_key' => "f_A01_{$i}",
                'rule_type' => 'functional_rule',
                'severity' => 'error',
                'passed' => false,
                'message' => "Mensaje de prueba {$i}",
                'context' => [
                    'section' => 'A01',
                    'row_number' => $rowNumber,
                    'concept' => "Concepto {$rowNumber}",
                    'profesional' => 'Profesional Test',
                    'pending_cells_count' => 0,
                    'pending_cells' => [],
                    'criterio' => ['empty_behavior' => 'debe_registrar_cero'],
                ],
            ]);
        }

        $formatter = new ValidationErrorFormatterService;

        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        $start = microtime(true);
        $memBefore = memory_get_usage(true);
        $result = $formatter->formatErrors($upload->id);
        $elapsed = microtime(true) - $start;
        $memUsed = (memory_get_usage(true) - $memBefore) / 1024 / 1024;

        $outputJson = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $outputHash = hash('sha256', $outputJson);

        file_put_contents(
            sys_get_temp_dir() . '/vef_benchmark_output.json',
            $outputJson
        );

        fwrite(STDERR, PHP_EOL . '=== BENCHMARK ===' . PHP_EOL);
        fwrite(STDERR, 'errores formateados: ' . count($result) . PHP_EOL);
        fwrite(STDERR, 'queries SQL totales: ' . $queryCount . PHP_EOL);
        fwrite(STDERR, 'tiempo: ' . round($elapsed, 3) . 's' . PHP_EOL);
        fwrite(STDERR, 'memoria: ' . round($memUsed, 2) . 'MB' . PHP_EOL);
        fwrite(STDERR, 'output sha256: ' . $outputHash . PHP_EOL);

        $this->assertGreaterThan(0, count($result));
    }
}
