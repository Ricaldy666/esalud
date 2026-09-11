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
 * El fix de config/filesystems.php (permissions.dir/file) por si solo NO
 * bastaba: Flysystem\Local\LocalFilesystemAdapter solo aplica un chmod()
 * explicito (setVisibility(), no sujeto a umask) cuando la operacion recibe
 * Config::OPTION_VISIBILITY. Sin eso, makeDirectory()/put() crean via
 * mkdir()/file_put_contents() con el modo por defecto del sistema, sujeto
 * al umask del proceso -- confirmado en produccion real 2026-09-04:
 * directorios nuevos salian en 0750 (no 0770) y archivos nuevos en 0644
 * (no 0660). El fix real esta en el punto de escritura
 * (CellDataStorageService::saveCellData()): setVisibility() explicito para
 * el directorio + 'private' como visibilidad explicita en put() para el
 * archivo, ambos disparando el chmod() explicito de Flysystem.
 *
 * Minimo privilegio: 0660 archivos / 0770 directorios -- rw/rwx para
 * propietario y grupo (grupo esperado: www-data, el mismo con el que
 * corre PHP-FPM), nada para "otros" (storage/app/private es privado).
 *
 * Los bits de permisos POSIX no aplican en Windows -- estos tests se
 * saltan ahi y corren real en Linux (contenedor/CI/produccion), que es
 * donde importan.
 */
class FilesystemDirectoryPermissionsTest extends TestCase
{
    private function skipOnNonPosix(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Permisos POSIX no aplican en Windows; se valida en contenedor Linux/produccion.');
        }
    }

    private function assertNoOthersAccess(int $mode, string $context): void
    {
        $this->assertSame(
            0,
            $mode & 0007,
            sprintf('%s: no debe haber ningun bit de permiso para "others". Obtenido %o.', $context, $mode)
        );
    }

    /**
     * Corre en cualquier SO (no depende de bits POSIX reales) -- verifica
     * que la configuracion en si quedo cableada correctamente. Esto define
     * QUE significa "private" para este disco (0660/0770); no prueba por
     * si sola que una escritura real la aplique -- eso lo cubren los tests
     * POSIX de abajo.
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

    /**
     * Confirma el mecanismo exacto que usa el fix: makeDirectory() crea el
     * directorio (via mkdir(), sujeto a umask), y setVisibility() explicito
     * lo corrige a la moda exacta configurada (chmod(), no sujeto a umask).
     * Sin el setVisibility() explicito, el resultado quedaria recortado por
     * el umask del proceso (ej. 0750 en vez de 0770 con umask 022) -- el
     * gap real encontrado en produccion.
     */
    public function test_explicit_visibility_produces_exact_directory_mode_regardless_of_umask(): void
    {
        $this->skipOnNonPosix();

        $dir = 'certificacion/permtest-dir-' . uniqid();
        Storage::disk('local')->makeDirectory($dir);
        Storage::disk('local')->setVisibility($dir, 'private');

        $realPath = Storage::disk('local')->path($dir);
        $mode = fileperms($realPath) & 0777;

        $this->assertSame(
            0770,
            $mode,
            sprintf('Directorio con setVisibility() explicito: esperado 0770, obtenido %o.', $mode)
        );
        $this->assertNoOthersAccess($mode, 'Directorio nuevo');

        Storage::disk('local')->deleteDirectory($dir);
    }

    /**
     * Mismo mecanismo para archivos: put() con visibilidad explicita
     * ('private' como tercer argumento) dispara el chmod() explicito de
     * Flysystem tras escribir. Sin esto, file_put_contents() deja el
     * archivo en su modo por defecto (0666 menos umask, tipicamente 0644)
     * -- exactamente lo que se encontro en produccion.
     */
    public function test_explicit_visibility_produces_exact_file_mode_regardless_of_umask(): void
    {
        $this->skipOnNonPosix();

        $path = 'certificacion/permtest-file-' . uniqid() . '.txt';
        Storage::disk('local')->put($path, 'diagnostic', 'private');

        $realPath = Storage::disk('local')->path($path);
        $mode = fileperms($realPath) & 0777;

        $this->assertSame(
            0660,
            $mode,
            sprintf('Archivo con visibilidad explicita: esperado 0660, obtenido %o.', $mode)
        );
        $this->assertNoOthersAccess($mode, 'Archivo nuevo');

        Storage::disk('local')->delete($path);
    }

    /**
     * Reproduce el problema real end-to-end: usa el mismo flujo de
     * aplicacion que causo el incidente (CellDataStorageService::
     * saveCellData(), el metodo real que crea certificacion/cell-data/ la
     * primera vez que se guarda evidencia de una seccion) y confirma que
     * tanto el directorio como el archivo resultante quedan EXACTAMENTE en
     * el modo de minimo privilegio -- no solo "mejor que 0700", sino el
     * valor exacto configurado, sin depender del umask del proceso que
     * ejecuta PHP-FPM/el worker.
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
            sprintf('certificacion/cell-data debe quedar exactamente en 0770 tras saveCellData(). Obtenido %o.', $dirMode)
        );
        $this->assertNoOthersAccess($dirMode, 'certificacion/cell-data');

        $filePath = Storage::disk('local')->path('certificacion/cell-data/' . $sheet . '-' . $section . '.json');
        $fileMode = fileperms($filePath) & 0777;
        $this->assertSame(
            0660,
            $fileMode,
            sprintf('El archivo de cell-data debe quedar exactamente en 0660. Obtenido %o.', $fileMode)
        );
        $this->assertNoOthersAccess($fileMode, 'Archivo de cell-data');

        // Verificacion funcional adicional: el propio servicio, releido
        // desde cero, recupera el contenido guardado -- confirma que el
        // fix no rompe la escritura/lectura normal.
        $reread = $service->getAllCellData($sheet, $section);
        $this->assertSame('diagnostic', $reread['A1']['valor_bruto'] ?? null);

        Storage::disk('local')->delete('certificacion/cell-data/' . $sheet . '-' . $section . '.json');
    }

    /**
     * Un directorio ya existente pero con permisos incorrectos (ej. 0700
     * heredado de una creacion anterior al fix, exactamente el escenario
     * real de produccion) debe autocorregirse a 0770 la proxima vez que se
     * guarda una seccion -- setVisibility() se llama siempre en
     * saveCellData(), exista o no el directorio previamente.
     */
    public function test_cell_data_storage_service_self_heals_preexisting_directory_with_wrong_permissions(): void
    {
        $this->skipOnNonPosix();

        $service = app(CellDataStorageService::class);
        $dirPath = Storage::disk('local')->path('certificacion/cell-data');

        if (!Storage::disk('local')->exists('certificacion/cell-data')) {
            Storage::disk('local')->makeDirectory('certificacion/cell-data');
        }

        // Simula el estado real encontrado en el incidente: directorio
        // preexistente con el 0700 de fabrica de Flysystem (o cualquier
        // modo incorrecto heredado).
        chmod($dirPath, 0700);
        $this->assertSame(0700, fileperms($dirPath) & 0777, 'Precondicion: el directorio debe arrancar en 0700 para esta prueba.');

        $sheet = 'PERMHEAL';
        $section = 'Y';
        if ($service->hasCellData($sheet, $section)) {
            Storage::disk('local')->delete('certificacion/cell-data/' . $sheet . '-' . $section . '.json');
        }

        $service->saveCellData($sheet, $section, [
            'A1' => ['valor_bruto' => 'heal-test', 'es_formula' => false],
        ]);

        $dirMode = fileperms($dirPath) & 0777;
        $this->assertSame(
            0770,
            $dirMode,
            sprintf('El directorio preexistente en 0700 debe autocorregirse a 0770. Obtenido %o.', $dirMode)
        );

        Storage::disk('local')->delete('certificacion/cell-data/' . $sheet . '-' . $section . '.json');
    }

    /**
     * Requisito explicito: el fix no debe tocar archivos historicos ya
     * existentes -- ni su contenido, ni su modo, ni su fecha de
     * modificacion. Crea un archivo "historico" simulado (con un modo
     * distinto al nuevo estandar, como lo tendria un archivo real escrito
     * antes de este fix) y confirma que guardar una seccion DISTINTA no lo
     * altera de ninguna forma.
     */
    public function test_cell_data_storage_service_does_not_touch_other_existing_files(): void
    {
        $this->skipOnNonPosix();

        $service = app(CellDataStorageService::class);

        $historicRelativePath = 'certificacion/cell-data/PERMHIST-Z.json';
        if (Storage::disk('local')->exists($historicRelativePath)) {
            Storage::disk('local')->delete($historicRelativePath);
        }

        // Escribe el archivo "historico" SIN pasar por el fix (visibilidad
        // por defecto), para modelar un archivo real creado antes de este
        // cambio -- tipicamente 0644.
        Storage::disk('local')->put($historicRelativePath, '{"historico":true}');
        $historicRealPath = Storage::disk('local')->path($historicRelativePath);
        $originalMode = fileperms($historicRealPath) & 0777;
        $originalContent = file_get_contents($historicRealPath);
        clearstatcache(true, $historicRealPath);
        $originalMtime = filemtime($historicRealPath);

        // Guardar una seccion DISTINTA ejercita saveCellData() (incluido el
        // setVisibility() del directorio compartido) sin tocar el archivo
        // historico.
        usleep(1_100_000); // margen > 1s: filemtime() tiene resolucion de segundo.
        $service->saveCellData('PERMTOUCH', 'W', [
            'A1' => ['valor_bruto' => 'otro', 'es_formula' => false],
        ]);

        clearstatcache(true, $historicRealPath);
        $this->assertSame($originalMode, fileperms($historicRealPath) & 0777, 'El modo del archivo historico no debe cambiar.');
        $this->assertSame($originalContent, file_get_contents($historicRealPath), 'El contenido del archivo historico no debe cambiar.');
        $this->assertSame($originalMtime, filemtime($historicRealPath), 'La fecha de modificacion del archivo historico no debe cambiar.');
        $this->assertTrue(
            Storage::disk('local')->exists($historicRelativePath),
            'El archivo historico debe seguir existiendo, sin ser eliminado ni movido.'
        );

        // Confirma tambien que el archivo historico sigue siendo legible
        // por el propietario/grupo del proceso (no quedo bloqueado por
        // ningun efecto colateral del setVisibility() del directorio).
        $this->assertNotFalse(file_get_contents($historicRealPath), 'El archivo historico debe seguir siendo legible.');

        Storage::disk('local')->delete($historicRelativePath);
        Storage::disk('local')->delete('certificacion/cell-data/PERMTOUCH-W.json');
    }
}
