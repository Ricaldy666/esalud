<?php

namespace App\Domain\RuleEngine\Services;

use Illuminate\Support\Facades\Storage;

/**
 * Almacen de solo-metadata para clasificar como se debe resolver un patron
 * en estado MISMATCH -- SAFE_RECONFIRM, HUMAN_REVIEW, STRUCTURAL_REVIEW o
 * STRUCTURAL_ROW_EXCLUSION.
 *
 * Deliberadamente SEPARADO de reglas-funcionales.json: nunca escribe
 * response/reviewed_by/reviewed_at/review_status ni ningun campo de
 * fingerprint v2 -- solo guarda la ETIQUETA de clasificacion (quien la puso,
 * cuando, por que, y contra que fingerprint/filas vivas se emitio), para que
 * el endpoint de resolucion pueda revalidarla antes de escribir nada real.
 *
 * Hallazgo 2026-08-21 (auditoria de 41 secciones MISMATCH): ni `mode` ni
 * `total_columns` ni `formula_templates` historicos quedan almacenados en
 * reglas-funcionales.json (solo el hash opaco `pattern_fingerprint`) -- por
 * lo que el motor NUNCA puede derivar automaticamente si una relacion nueva
 * ya era visible para el humano o no. Esta clasificacion requiere evidencia
 * externa (cell-data real, Excel real) -- exactamente el tipo de auditoria
 * ya hecha manualmente esta sesion. Este servicio persiste esa conclusion de
 * forma auditable, en vez de intentar re-derivarla con una heuristica fragil
 * en cada request.
 *
 * IDENTIDAD DE CLAVE (2026-08-24, hallazgo A09/G P3 -- ver auditKeys() para
 * el detalle completo de la causa raiz): la clave de almacenamiento NUNCA
 * debe depender solo de pattern_id (posicional, inestable -- ver el mismo
 * problema ya resuelto para el emparejamiento de preguntas historicas en
 * PatternMigrationScanner::matchLivePatternsToHistorical()). Las 85 entradas
 * existentes al momento de este fix usan el formato LEGACY
 * "{sheet}_{section}_{patternId}" -- se leen sin migrarlas (getTag() cae a
 * ellas SOLO si su audited_rows coincide exactamente con las filas vivas
 * consultadas, nunca a ciegas por posicion). Toda escritura NUEVA usa la
 * clave ESTABLE "{sheet}_{section}_{rowSetFingerprint}", derivada del
 * conjunto de filas auditado -- coexiste sin colision con cualquier entrada
 * legacy, incluso si alguna vez compartieron el mismo pattern_id posicional.
 */
class MismatchResolutionAuditService
{
    public const CATEGORY_SAFE_RECONFIRM = 'safe_reconfirm';
    public const CATEGORY_HUMAN_REVIEW = 'human_review';
    public const CATEGORY_STRUCTURAL_REVIEW = 'structural_review';

    /**
     * 2026-08-24 -- categoria independiente de safe_reconfirm, para patrones
     * cuyo conjunto de filas vivo es un subconjunto ESTRICTO y MECANICAMENTE
     * VERIFICADO del historico: la unica diferencia son filas reconocidas
     * por SectionCalibrationMatrixService::isEmbeddedLeadingTotalRow()
     * (mecanismo #6) como TOTAL lider embebido, nunca una fila agregada,
     * eliminada por otra razon, o modificada. safe_reconfirm exige igualdad
     * EXACTA de filas y nunca se relaja para este caso -- ver gate propio en
     * RuleTagMismatchResolutionCommand.
     */
    public const CATEGORY_STRUCTURAL_ROW_EXCLUSION = 'structural_row_exclusion';

    /**
     * Unico valor valido de 'exclusion_mechanism' para tags
     * structural_row_exclusion -- constante compartida entre
     * RuleTagMismatchResolutionCommand (quien la escribe al crear el tag) y
     * CalibrationViewController::confirmMismatchResolution() (quien la
     * revalida antes de escribir), para no duplicar el string magico.
     */
    public const EXCLUSION_MECHANISM_EMBEDDED_LEADING_TOTAL = 'embedded_leading_total_mecanismo_6';

    public const ALLOWED_CATEGORIES = [
        self::CATEGORY_SAFE_RECONFIRM,
        self::CATEGORY_HUMAN_REVIEW,
        self::CATEGORY_STRUCTURAL_REVIEW,
        self::CATEGORY_STRUCTURAL_ROW_EXCLUSION,
    ];

    private const DISK = 'local';
    private const PATH = 'certificacion/mismatch-resolution-audit.json';

    /**
     * Formato de clave ESTABLE: "{sheet}_{section}_rowset_{16 hex}". El
     * prefijo literal 'rowset_' es lo que distingue una clave estable de una
     * legacy (que termina en un entero puro) -- ver isStableKey().
     */
    private const STABLE_KEY_PREFIX = 'rowset_';

    private function loadAll(): array
    {
        if (!Storage::disk(self::DISK)->exists(self::PATH)) {
            return [];
        }

        return json_decode(Storage::disk(self::DISK)->get(self::PATH), true) ?? [];
    }

    private function persistAll(array $data): void
    {
        $dir = dirname(self::PATH);
        if (!Storage::disk(self::DISK)->exists($dir)) {
            Storage::disk(self::DISK)->makeDirectory($dir);
        }

        Storage::disk(self::DISK)->put(self::PATH, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Huella estable de un conjunto de filas -- MISMO algoritmo que
     * SectionCalibrationMatrixService::computeRowFingerprint() (sort +
     * SHA-256 truncado a 16 hex), duplicado aqui de forma independiente
     * (funcion pura, sin dependencias) en vez de inyectar todo
     * SectionCalibrationMatrixService (que arrastra CertificationService/
     * FunctionalRuleService/CellDataStorageService/ColumnRoleResolverService)
     * solo para esta operacion trivial -- mismo patron de duplicacion
     * deliberada ya usado en el resto de esta campaña (isEmbeddedLeadingTotalRow
     * entre RemParserService y SectionCalibrationMatrixService, etc.). Dos
     * conjuntos de filas identicos SIEMPRE producen la misma huella, sin
     * importar el orden en que se pasaron.
     */
    private function rowSetFingerprint(array $rows): string
    {
        $normalized = array_values(array_unique(array_filter(
            array_map(fn ($r) => (int) $r, $rows),
            fn (int $r) => $r > 0
        )));
        sort($normalized, SORT_NUMERIC);

        if (empty($normalized)) {
            return self::STABLE_KEY_PREFIX . 'empty';
        }

        return self::STABLE_KEY_PREFIX . substr(hash('sha256', implode(',', $normalized)), 0, 16);
    }

    /**
     * Clave ESTABLE (formato nuevo desde 2026-08-24) -- toda escritura nueva
     * usa esta clave exclusivamente. Identifica el patron por CONTENIDO
     * (conjunto de filas auditado), nunca por pattern_id posicional.
     */
    private function stableKey(string $sheet, string $section, array $rows): string
    {
        return "{$sheet}_{$section}_" . $this->rowSetFingerprint($rows);
    }

    /**
     * Clave LEGACY (formato original, usado por las 85 entradas existentes
     * al momento de este fix) -- nunca se escribe de nuevo, solo se lee como
     * fallback, y SOLO si su contenido (audited_rows) coincide exactamente
     * con las filas que se estan buscando (ver getTag()). Sin esa
     * verificacion de contenido, un pattern_id posicional que cambio de
     * significado (hallazgo A09/G P3: paso de significar filas [184-189] a
     * significar [190,191] tras excluir la fila TOTAL lider 183) devolveria
     * el tag equivocado -- exactamente el bug que este diseño evita.
     */
    private function legacyKey(string $sheet, string $section, int $patternId): string
    {
        return "{$sheet}_{$section}_{$patternId}";
    }

    /**
     * Distingue una clave con formato estable de una legacy -- una clave
     * estable siempre termina en "_rowset_<hex>" o "_rowset_empty"; una
     * legacy siempre termina en un entero puro (\d+). Usado por auditKeys()
     * para clasificar las entradas existentes, nunca para decidir logica de
     * lookup (getTag() ya sabe cual formato construir para cada caso).
     */
    private function isStableKeyFormat(string $key): bool
    {
        return (bool) preg_match('/_' . self::STABLE_KEY_PREFIX . '(?:[0-9a-f]{16}|empty)$/', $key);
    }

    /**
     * @return array{category:string,audited_fingerprint:string,audited_rows:int[],reason:string,audited_by:string,audited_at:string}|null
     *
     * Busca primero por clave ESTABLE (derivada de $liveRows) -- si existe,
     * es la fuente de verdad (cualquier tag creado por este servicio desde
     * 2026-08-24 en adelante vive ahi). Si no existe, cae a la clave LEGACY
     * derivada de $patternId -- pero SOLO la acepta si el audited_rows
     * guardado ahi coincide EXACTAMENTE (mismo conjunto, orden
     * indiferente) con $liveRows. Sin esa verificacion de contenido jamas se
     * devuelve una entrada legacy: un patron cuyo pattern_id posicional
     * cambio de significado nunca recibe el tag de otro patron por
     * casualidad de posicion -- simplemente no se encuentra nada (null),
     * igual que si nunca se hubiera auditado.
     */
    public function getTag(string $sheet, string $section, int $patternId, array $liveRows): ?array
    {
        $all = $this->loadAll();

        $stable = $all[$this->stableKey($sheet, $section, $liveRows)] ?? null;
        if ($stable !== null) {
            return $stable;
        }

        $legacy = $all[$this->legacyKey($sheet, $section, $patternId)] ?? null;
        if ($legacy === null) {
            return null;
        }

        $sortedLegacyRows = $legacy['audited_rows'] ?? [];
        sort($sortedLegacyRows, SORT_NUMERIC);
        $sortedLiveRows = $liveRows;
        sort($sortedLiveRows, SORT_NUMERIC);

        return $sortedLegacyRows === $sortedLiveRows ? $legacy : null;
    }

    /**
     * $historicalRows/$excludedTotalRows/$exclusionMechanism son EXCLUSIVOS
     * del flujo structural_row_exclusion (2026-08-24) -- parametros nuevos,
     * opcionales, al final de la firma: las llamadas existentes de
     * safe_reconfirm/human_review/structural_review (8 argumentos
     * posicionales) siguen funcionando exactamente igual, sin ningun campo
     * nuevo en su entrada. Documentan explicitamente, para auditoria futura,
     * que la exclusion NO fue una decision de negocio sino una verificacion
     * mecanica contra el mecanismo #6 -- nunca se sobreescribe el historico
     * real (reglas-funcionales.json no se toca desde este servicio, igual
     * que antes).
     *
     * Escribe SIEMPRE bajo la clave ESTABLE (derivada de $auditedRows) --
     * nunca bajo la clave legacy posicional. $patternId se conserva en la
     * entrada (display/back-reference: "que numero de patron era este en el
     * momento de auditar"), pero deja de ser parte de la identidad de
     * almacenamiento. Esto es lo que permite que un tag legacy antiguo (ej.
     * A09_G_3=[184-189]) y un tag nuevo para un patron vivo distinto que hoy
     * ocupa esa misma posicion (A09/G pattern_id=3 vivo=[190,191]) coexistan
     * sin pisarse -- cada uno vive en su propia clave, derivada de su propio
     * contenido.
     */
    public function setTag(
        string $sheet,
        string $section,
        int $patternId,
        string $category,
        string $auditedFingerprint,
        array $auditedRows,
        string $reason,
        string $auditedBy,
        ?array $historicalRows = null,
        ?array $excludedTotalRows = null,
        ?string $exclusionMechanism = null,
    ): array {
        if (!in_array($category, self::ALLOWED_CATEGORIES, true)) {
            throw new \InvalidArgumentException("Categoria invalida: {$category}. Debe ser una de: " . implode(', ', self::ALLOWED_CATEGORIES));
        }

        $all = $this->loadAll();
        $sortedRows = $auditedRows;
        sort($sortedRows, SORT_NUMERIC);

        $entry = [
            'sheet' => $sheet,
            'section' => $section,
            'pattern_id' => $patternId,
            'category' => $category,
            'audited_fingerprint' => $auditedFingerprint,
            'audited_rows' => $sortedRows,
            'reason' => $reason,
            'audited_by' => $auditedBy,
            'audited_at' => now()->toIso8601String(),
        ];

        if ($historicalRows !== null) {
            $sortedHistorical = $historicalRows;
            sort($sortedHistorical, SORT_NUMERIC);
            $entry['historical_rows'] = $sortedHistorical;
        }
        if ($excludedTotalRows !== null) {
            $sortedExcluded = $excludedTotalRows;
            sort($sortedExcluded, SORT_NUMERIC);
            $entry['excluded_total_rows'] = $sortedExcluded;
        }
        if ($exclusionMechanism !== null) {
            $entry['exclusion_mechanism'] = $exclusionMechanism;
        }

        $all[$this->stableKey($sheet, $section, $sortedRows)] = $entry;
        $this->persistAll($all);

        return $entry;
    }

    /**
     * Mismo criterio de resolucion que getTag() (estable primero, legacy
     * solo si el contenido coincide) -- nunca elimina una entrada legacy
     * cuyo audited_rows ya no coincide con $liveRows (eso seria borrar el
     * tag de OTRO patron por coincidencia posicional).
     */
    public function removeTag(string $sheet, string $section, int $patternId, array $liveRows): void
    {
        $all = $this->loadAll();

        $stableKey = $this->stableKey($sheet, $section, $liveRows);
        if (isset($all[$stableKey])) {
            unset($all[$stableKey]);
            $this->persistAll($all);

            return;
        }

        $legacyKey = $this->legacyKey($sheet, $section, $patternId);
        if (isset($all[$legacyKey])) {
            $sortedLegacyRows = $all[$legacyKey]['audited_rows'] ?? [];
            sort($sortedLegacyRows, SORT_NUMERIC);
            $sortedLiveRows = $liveRows;
            sort($sortedLiveRows, SORT_NUMERIC);

            if ($sortedLegacyRows === $sortedLiveRows) {
                unset($all[$legacyKey]);
                $this->persistAll($all);
            }
        }
    }

    /**
     * Barrido 100% de solo lectura de TODAS las entradas actuales -- nunca
     * escribe nada, nunca migra nada. Clasifica cada clave existente
     * (legacy vs ya-estable) y, para las legacy, calcula cual SERIA su
     * clave estable equivalente (derivada de su propio audited_rows) --
     * permitiendo detectar, sin tocar el archivo real, si dos entradas
     * legacy distintas (mismo sheet/section, distinto pattern_id historico)
     * colisionarian al migrarse porque en algun momento auditaron
     * exactamente el mismo conjunto de filas.
     *
     * @return array{
     *   total: int,
     *   legacy_count: int,
     *   stable_count: int,
     *   collisions: array<int, array{proposed_stable_key: string, legacy_keys: string[]}>,
     *   unmappable: array<int, string>,
     *   entries: array<string, array{format: string, sheet: string, section: string, pattern_id: int, audited_rows: int[], proposed_stable_key: ?string}>,
     * }
     */
    public function auditKeys(): array
    {
        $all = $this->loadAll();

        $entries = [];
        $proposedByKey = []; // proposed_stable_key => [legacy_key, ...]
        $legacyCount = 0;
        $stableCount = 0;
        $unmappable = [];

        foreach ($all as $key => $entry) {
            $isStable = $this->isStableKeyFormat($key);
            $sheet = $entry['sheet'] ?? null;
            $section = $entry['section'] ?? null;
            $auditedRows = $entry['audited_rows'] ?? null;

            $proposedStableKey = null;
            if (! $isStable) {
                $legacyCount++;
                if ($sheet !== null && $section !== null && is_array($auditedRows) && ! empty($auditedRows)) {
                    $proposedStableKey = $this->stableKey($sheet, $section, $auditedRows);
                    $proposedByKey[$proposedStableKey][] = $key;
                } else {
                    $unmappable[] = $key;
                }
            } else {
                $stableCount++;
            }

            $entries[$key] = [
                'format' => $isStable ? 'stable' : 'legacy',
                'sheet' => $sheet,
                'section' => $section,
                'pattern_id' => $entry['pattern_id'] ?? null,
                'audited_rows' => $auditedRows,
                'proposed_stable_key' => $proposedStableKey,
            ];
        }

        $collisions = [];
        foreach ($proposedByKey as $proposedKey => $legacyKeys) {
            if (count($legacyKeys) > 1) {
                $collisions[] = ['proposed_stable_key' => $proposedKey, 'legacy_keys' => $legacyKeys];
            }
        }

        return [
            'total' => count($all),
            'legacy_count' => $legacyCount,
            'stable_count' => $stableCount,
            'collisions' => $collisions,
            'unmappable' => $unmappable,
            'entries' => $entries,
        ];
    }
}
