<?php

namespace Tests\Feature\Config;

use App\Domain\RuleEngine\Services\CellDataStorageService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Cubre la causa raiz encontrada en produccion 2026-09-04: sin un
 * 'permissions' explicito en config/filesystems.php (disco 'local'),
 * Flysystem usa su default de fabrica para directorios "privados" -- 0700,
 * solo el propietario -- ver League\Flysystem\UnixVisibility\
 * PortableVisibilityConverter::$directoryPrivate. Eso dejo
 * storage/app/private/certificacion/cell-data ilegible para PHP-FPM
 * (usuario/grupo www-data, distinto del propietario que creo el
 * directorio via Storage::disk('local')->makeDirectory() sin visibilidad
 * explicita), causando que computeStructureCalibrationSummary() calculara
 * "falta evidencia de celdas escaneadas" para secciones ya calibradas
 * cada vez que una request real de PHP-FPM disparaba un cache-miss.
 *
 * Minimo privilegio: 0660 archivos / 0770 directorios -- rw/rwx para
 * propietario y grupo (grupo esperado: www-data, el mismo con el que
 * corre PHP-FPM), nada para "otros" (storage/app/private es privado).
 *
 * Los bits de permisos POSIX no aplican en Windows -- estos tests se
 * saltan ahi y corren real en Linux (CI/produccion), que es donde importan.
 */
class FilesystemDirectoryPermissionsTest extends TestCase
{
    private function skipOnNonPosix(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Permisos POSIX no aplican en Windows; se valida en CI/produccion (Linux).');
        }
    }

    /**
     * Corre en cualquier SO (no depende de bits POSIX reales) -- verifica
     * que la configuracion en si quedo cableada correctamente.
     */
    public function test_local_disk_config_declares_least_privilege_permissions(): void
    {
        $config = config('filesystems.disks.local.permissions');

        $this->assertIsArray($config);
        $this->assertSame(0660, $config['file']['public'] ?? null);
        $this->assertSame(0660, $config['file']['private'] ?? null);
        $this->assertSame(0770, $config['dir']['public'] ?? null);
        $this->assertSame(0770, $config['dir']['private'] ?? null);
    }

    public function test_local_disk_creates_new_directories_with_least_privilege_mode(): void
    {
        $this->skipOnNonPosix();

        $dir = 'certificacion/permtest-dir-' . uniqid();
        Storage::disk('local')->makeDirectory($dir);

        $realPath = Storage::disk('local')->path($dir);
        $mode = fileperms($realPath) & 0777;

        $this->assertSame(
            0770,
            $mode,
            sprintf('Directorio nuevo bajo el disco local: esperado 0770, obtenido %o.', $mode)
        );

        Storage::disk('local')->deleteDirectory($dir);
    }

    public function test_local_disk_creates_new_files_with_least_privilege_mode(): void
    {
        $this->skipOnNonPosix();

        $path = 'certificacion/permtest-file-' . uniqid() . '.txt';
        Storage::disk('local')->put($path, 'diagnostic');

        $realPath = Storage::disk('local')->path($path);
        $mode = fileperms($realPath) & 0777;

        $this->assertSame(
            0660,
            $mode,
            sprintf('Archivo nuevo bajo el disco local: esperado 0660, obtenido %o.', $mode)
        );

        Storage::disk('local')->delete($path);
    }

    /**
     * Reproduce el problema real: usa el mismo flujo de aplicacion que causo
     * el incidente (CellDataStorageService::saveCellData(), el metodo real
     * que crea certificacion/cell-data/ la primera vez que se guarda
     * evidencia de una seccion) y confirma que el directorio resultante NO
     * queda en el 0700 peligroso -- que es exactamente lo que le paso a
     * produccion.
     */
    public function test_cell_data_storage_service_creates_readable_directory_and_file(): void
    {
        $this->skipOnNonPosix();

        $sheet = 'PERMTEST';
        $section = 'X';
        $service = app(CellDataStorageService::class);

        // Asegura estado limpio si un test previo dejo residuos.
        if ($service->hasCellData($sheet, $section)) {
            Storage::disk('local')->delete('certificacion/cell-data/' . $sheet . '-' . $section . '.json');
        }

        $service->saveCellData($sheet, $section, [
            'A1' => ['valor_bruto' => 'diagnostic', 'es_formula' => false],
        ]);

        $this->assertTrue($service->hasCellData($sheet, $section));

        $dirPath = Storage::disk('local')->path('certificacion/cell-data');
        $dirMode = fileperms($dirPath) & 0777;
        $this->assertSame(
            0770,
            $dirMode,
            sprintf('certificacion/cell-data debe quedar en 0770 (no 0700) tras saveCellData(). Obtenido %o.', $dirMode)
        );

        $filePath = Storage::disk('local')->path('certificacion/cell-data/' . $sheet . '-' . $section . '.json');
        $fileMode = fileperms($filePath) & 0777;
        $this->assertSame(
            0660,
            $fileMode,
            sprintf('El archivo de cell-data debe quedar en 0660. Obtenido %o.', $fileMode)
        );

        // Verificacion funcional adicional: el propio servicio, releido
        // desde cero, recupera el contenido guardado -- confirma que el
        // fix no rompe la escritura/lectura normal.
        $reread = $service->getAllCellData($sheet, $section);
        $this->assertSame('diagnostic', $reread['A1']['valor_bruto'] ?? null);

        Storage::disk('local')->delete('certificacion/cell-data/' . $sheet . '-' . $section . '.json');
    }
}
