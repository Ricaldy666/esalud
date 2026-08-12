<?php

namespace App\Domain\RuleEngine\Services;

use Illuminate\Support\Facades\Storage;

class CellDataStorageService
{
    private const DISK = 'local';
    private const BASE_DIR = 'certificacion/cell-data';

    /**
     * Cache de instancia: cada archivo de cell-data (hasta ~7.5MB por
     * seccion, ~108MB en total entre las 379 secciones de la estructura
     * activa de Serie A) se releia y re-decodificaba en cada llamada a
     * loadCellData(), y ValidateRemUploadJob invoca
     * getCellForCoordinate()/getCellsForRow() una vez por columna de cada
     * fila evaluada -- decenas de miles de llamadas en una carga real
     * completa (medido: 81.269 valores de columna en el upload #99). Mismo
     * patron ya aplicado en FunctionalRuleService::loadAll(): el servicio se
     * resuelve nuevo por request/job (no es singleton), asi que cachear a
     * nivel de instancia no arrastra datos obsoletos entre cargas.
     *
     * Sin limite ni eviccion, este cache es tambien la causa raiz confirmada
     * de un OOM real (memory_limit 512M agotado) en
     * RemParserService::buildSectionMaps(): esa rutina invoca loadCellData()
     * una vez por cada una de las 379 secciones de la estructura, sin
     * necesitar el contenido crudo de una seccion una vez calculado su mapa
     * derivado (concept_column, subcategory_column, etc.) -- pero el cache
     * las retenia todas simultaneamente para toda la vida de la instancia.
     * forgetSection()/forgetSheet() (agregados junto con este comentario)
     * permiten a los llamadores liberar una seccion/hoja ya procesada sin
     * perder el beneficio del cache dentro de esa seccion/hoja mientras se
     * usa activamente -- misma semantica de datos (mismo archivo en disco,
     * nunca cambia durante una ejecucion), solo cambia cuanto tiempo se
     * retiene en memoria.
     */
    private array $cache = [];

    /**
     * Secciones pobladas via seedCache() -- nunca tocan disco, ni para leer
     * ni para escribir. hasCellData() las reporta como presentes sin
     * consultar Storage, para que un llamador de solo simulacion (ej.
     * reconciliacion de fingerprints en memoria) no dependa de que el
     * archivo real exista ni lo sobreescriba.
     *
     * @var array<string, true>
     */
    private array $seededSections = [];

    public function saveCellData(string $sheet, string $section, array $cells): void
    {
        $path = $this->getPath($sheet, $section);
        $dir = dirname($path);

        if (!Storage::disk(self::DISK)->exists($dir)) {
            Storage::disk(self::DISK)->makeDirectory($dir);
        }

        $serialized = [];
        foreach ($cells as $coord => $cell) {
            $serialized[$coord] = $cell instanceof \App\Domain\RemParser\DTOs\EnhancedCellDTO
                ? $cell->toArray()
                : $cell;
        }

        Storage::disk(self::DISK)->put($path, json_encode(
            $serialized,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        ));

        $this->cache[$sheet . '|' . $section] = $serialized;
    }

    public function loadCellData(string $sheet, string $section): array
    {
        $key = $sheet . '|' . $section;
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $path = $this->getPath($sheet, $section);
        if (!Storage::disk(self::DISK)->exists($path)) {
            return $this->cache[$key] = [];
        }

        $contents = Storage::disk(self::DISK)->get($path);
        $data = json_decode($contents, true);

        return $this->cache[$key] = is_array($data) ? $data : [];
    }

    public function getCellForCoordinate(string $sheet, string $section, string $coordinate): ?array
    {
        $cells = $this->loadCellData($sheet, $section);
        return $cells[$coordinate] ?? null;
    }

    public function getCellsForRow(string $sheet, string $section, int $row): array
    {
        $cells = $this->loadCellData($sheet, $section);
        $rowCells = [];
        foreach ($cells as $coord => $data) {
            if (($data['fila'] ?? 0) === $row) {
                $rowCells[$coord] = $data;
            }
        }
        return $rowCells;
    }

    public function getAllCellData(string $sheet, string $section): array
    {
        return $this->loadCellData($sheet, $section);
    }

    public function hasCellData(string $sheet, string $section): bool
    {
        if (isset($this->seededSections[$sheet . '|' . $section])) {
            return true;
        }

        return Storage::disk(self::DISK)->exists($this->getPath($sheet, $section));
    }

    /**
     * Puebla el cache de instancia con cell-data propuesto (ej. de un scan
     * en memoria contra un Excel fuente, sin persistir) sin tocar Storage
     * en absoluto -- ni lectura ni escritura. Pensado para simulaciones
     * read-only (reconciliacion de fingerprints contra una estructura
     * propuesta) que necesitan que SectionCalibrationMatrixService vea
     * "hay cell-data para esta seccion" sin que exista en disco real.
     */
    public function seedCache(string $sheet, string $section, array $cells): void
    {
        $serialized = [];
        foreach ($cells as $coord => $cell) {
            $serialized[$coord] = $cell instanceof \App\Domain\RemParser\DTOs\EnhancedCellDTO
                ? $cell->toArray()
                : $cell;
        }

        $key = $sheet . '|' . $section;
        $this->cache[$key] = $serialized;
        $this->seededSections[$key] = true;
    }

    /**
     * Libera del cache de instancia el cell_data ya decodificado de una
     * seccion puntual. Una llamada posterior a loadCellData()/
     * getCellForCoordinate()/etc. para esa misma seccion vuelve a leerla y
     * decodificarla desde disco -- mismo resultado, sin retenerla en memoria
     * mientras no se este usando activamente. No falla si la seccion nunca
     * se cargo (unset() sobre una clave inexistente es un no-op).
     */
    public function forgetSection(string $sheet, string $section): void
    {
        unset($this->cache[$sheet . '|' . $section]);
    }

    /**
     * Igual que forgetSection(), pero libera de una vez todas las secciones
     * ya cacheadas de una hoja completa -- util al terminar de procesar una
     * hoja entera (parseSheet() puede haber recargado varias de sus
     * secciones durante el parseo fila por fila, mas alla de las que ya
     * habia liberado buildSectionMaps() al terminar cada una).
     */
    public function forgetSheet(string $sheet): void
    {
        $prefix = $sheet . '|';
        foreach (array_keys($this->cache) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->cache[$key]);
            }
        }
    }

    /**
     * Cantidad de secciones actualmente retenidas en el cache de instancia
     * -- solo para instrumentacion/diagnostico de memoria, no se usa en
     * ningun camino funcional.
     */
    public function cachedSectionsCount(): int
    {
        return count($this->cache);
    }

    private function getPath(string $sheet, string $section): string
    {
        $safeSheet = preg_replace('/[^A-Za-z0-9_\-]/', '_', $sheet);
        $safeSection = preg_replace('/[^A-Za-z0-9_\-]/', '_', $section);
        return self::BASE_DIR . "/{$safeSheet}-{$safeSection}.json";
    }
}
