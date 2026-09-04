<?php

namespace Tests\Feature\Rem;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RemParser\Services\StructureApprovalService;
use App\Domain\RuleEngine\Services\SectionCalibrationMatrixService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cubre la condicion de carrera encontrada 2026-09-04 en
 * StructureApprovalService::activate(): el Cache::forget() del resumen de
 * calibracion corria de forma sincronica, ANTES de que la transaccion
 * envolvente (ej. CertifiedStructurePromotionService::commit(), que llama a
 * activate() dentro de DB::transaction()) confirmara. Una request real
 * concurrente en esa ventana recalculaba contra la estructura activa
 * TODAVIA VIEJA (su propia conexion no ve los cambios sin confirmar de la
 * otra transaccion, bajo el aislamiento REPEATABLE READ por defecto de
 * MySQL/MariaDB) y volvia a cachear ese resultado obsoleto por
 * CALIBRATION_SUMMARY_CACHE_TTL_SECONDS (1 hora) -- reproducido y
 * confirmado en produccion el 2026-09-04 para las hojas A04/A11/A11a/A28.
 *
 * Corregido moviendo el forget() a DB::afterCommit().
 *
 * Deliberadamente SIN RefreshDatabase: estos tests necesitan control real
 * sobre begin/commit/rollBack para verificar el timing exacto de
 * DB::afterCommit(). Bajo RefreshDatabase (que envuelve cada test en su
 * propia transaccion desde el nivel 0) un COMMIT interno del test nunca
 * baja el nivel de transaccion de vuelta a 0 real, y
 * DatabaseTransactionsManager::afterCommitCallbacksShouldBeExecuted()
 * exige exactamente nivel 0 -- los callbacks de afterCommit() nunca
 * llegarian a dispararse y los tests de "despues del commit" darian falso
 * negativo. Limpieza manual en tearDown() en su lugar. Se usa
 * anio=2099/serie='ZZ' (fuera de cualquier combinacion real usada por
 * otros fixtures/tests) para que la logica de "superseder la estructura
 * activa de este anio/serie" de activate() nunca toque una estructura real
 * de otro test que corra en la misma base de datos sin aislamiento
 * transaccional.
 */
class StructureApprovalServiceCacheInvalidationTest extends TestCase
{
    private const TEST_ANIO = 2099;
    private const TEST_SERIE = 'ZZ';
    private const HASH_PREFIX = 'hash-cache-invalidation-test-';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(SectionCalibrationMatrixService::CALIBRATION_SUMMARY_CACHE_KEY);

        // Red de seguridad: sin RefreshDatabase, una corrida anterior
        // interrumpida podria haber dejado una fila residual con el mismo
        // anio/serie/version_number de prueba.
        // forceDelete(): RemTemplateStructure usa SoftDeletes -- un
        // ->delete() normal deja la fila fisicamente presente (solo marca
        // deleted_at), y sigue ocupando el UNIQUE(anio,serie,version_number)
        // para cualquier corrida futura de estos tests.
        RemTemplateStructure::withTrashed()
            ->where('anio', self::TEST_ANIO)
            ->where('serie', self::TEST_SERIE)
            ->forceDelete();
    }

    protected function tearDown(): void
    {
        // Por si un assert fallido dejo una transaccion sin cerrar.
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        // forceDelete(): RemTemplateStructure usa SoftDeletes -- un
        // ->delete() normal deja la fila fisicamente presente (solo marca
        // deleted_at), y sigue ocupando el UNIQUE(anio,serie,version_number)
        // para cualquier corrida futura de estos tests.
        RemTemplateStructure::withTrashed()
            ->where('anio', self::TEST_ANIO)
            ->where('serie', self::TEST_SERIE)
            ->forceDelete();

        Cache::forget(SectionCalibrationMatrixService::CALIBRATION_SUMMARY_CACHE_KEY);

        parent::tearDown();
    }

    private function createApprovedStructure(string $suffix, int $versionNumber): RemTemplateStructure
    {
        // version_number es unsignedTinyInteger (max 255) en este esquema,
        // con unique(anio,serie,version_number) -- cada test usa un numero
        // distinto para no depender de que la limpieza entre tests haya
        // corrido (p.ej. si un test previo dejo una transaccion sin
        // resolver por un assert fallido a mitad de camino).
        return RemTemplateStructure::create([
            'anio' => self::TEST_ANIO,
            'serie' => self::TEST_SERIE,
            'version_number' => $versionNumber,
            'hash_estructura' => self::HASH_PREFIX . $suffix . '-' . random_int(1000, 9999),
            'estructura' => ['forms' => []],
            'status' => 'approved',
        ]);
    }

    public function test_cache_is_not_invalidated_before_commit_inside_a_transaction(): void
    {
        Cache::put(SectionCalibrationMatrixService::CALIBRATION_SUMMARY_CACHE_KEY, ['sentinel' => true], 60);

        // Creacion Y activacion dentro de la MISMA transaccion -- asi es
        // como corre realmente CertifiedStructurePromotionService::commit()
        // (ver createAndActivateStructure(), linea 401-418, invocada dentro
        // de DB::transaction() en commit(), linea 128-129).
        DB::beginTransaction();
        $structure = $this->createApprovedStructure('before-commit', 1);
        app(StructureApprovalService::class)->activate($structure);

        $this->assertTrue(
            Cache::has(SectionCalibrationMatrixService::CALIBRATION_SUMMARY_CACHE_KEY),
            'La cache no debe invalidarse mientras la transaccion sigue abierta -- exactamente el defecto corregido.'
        );

        DB::rollBack();
    }

    public function test_cache_is_invalidated_after_commit(): void
    {
        Cache::put(SectionCalibrationMatrixService::CALIBRATION_SUMMARY_CACHE_KEY, ['sentinel' => true], 60);

        DB::beginTransaction();
        $structure = $this->createApprovedStructure('after-commit', 2);
        app(StructureApprovalService::class)->activate($structure);
        DB::commit();

        $this->assertFalse(
            Cache::has(SectionCalibrationMatrixService::CALIBRATION_SUMMARY_CACHE_KEY),
            'Tras el COMMIT real, la cache debe quedar invalidada.'
        );
    }

    public function test_rollback_does_not_invalidate_cache(): void
    {
        Cache::put(SectionCalibrationMatrixService::CALIBRATION_SUMMARY_CACHE_KEY, ['sentinel' => true], 60);

        DB::beginTransaction();
        $structure = $this->createApprovedStructure('rollback', 3);
        app(StructureApprovalService::class)->activate($structure);
        DB::rollBack();

        $this->assertTrue(
            Cache::has(SectionCalibrationMatrixService::CALIBRATION_SUMMARY_CACHE_KEY),
            'Un rollback no debe disparar la invalidacion -- el callback de afterCommit() nunca debe ejecutarse si la transaccion no confirma.'
        );

        // La creacion misma de la estructura tambien estaba dentro de la
        // transaccion (igual que en el flujo real de promocion) -- un
        // rollback real debe deshacerla por completo, no solo el UPDATE de
        // activacion.
        $this->assertNull(
            RemTemplateStructure::find($structure->id),
            'El rollback real tambien debe deshacer la creacion/activacion de la estructura misma.'
        );
    }

    public function test_activate_outside_a_transaction_invalidates_cache_immediately(): void
    {
        // Documenta el comportamiento real de DB::afterCommit() en Laravel
        // 13.12 fuera de una transaccion: DatabaseTransactionsManager::
        // addCallback() (vendor/laravel/framework/.../DatabaseTransactionsManager.php:205-212)
        // ejecuta el callback de inmediato si no hay transaccion pendiente
        // ($current = $this->callbackApplicableTransactions()->last())
        // resulta null -> $callback() se llama directo). Mismo
        // comportamiento observable que el Cache::forget() sincrono
        // anterior para el caso normal (activate() llamado fuera de una
        // promocion) -- sin regresion.
        Cache::put(SectionCalibrationMatrixService::CALIBRATION_SUMMARY_CACHE_KEY, ['sentinel' => true], 60);
        $structure = $this->createApprovedStructure('no-transaction', 4);

        app(StructureApprovalService::class)->activate($structure);

        $this->assertFalse(
            Cache::has(SectionCalibrationMatrixService::CALIBRATION_SUMMARY_CACHE_KEY),
            'Fuera de una transaccion, DB::afterCommit() debe ejecutar el callback de inmediato -- sin cambio de comportamiento respecto a antes del fix.'
        );
    }
}
