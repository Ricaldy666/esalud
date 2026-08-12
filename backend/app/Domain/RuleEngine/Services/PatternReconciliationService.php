<?php

namespace App\Domain\RuleEngine\Services;

/**
 * Reconcilia respuestas de calibracion guardadas contra un conjunto nuevo
 * de patrones, usando row_fingerprint (huella estable por conjunto de
 * filas) en vez de pattern_id (secuencial, se reasigna cuando cambia el
 * cell_data -- ver auditoria de compatibilidad A09/F-G, 2026-08-06).
 *
 * No toca disco ni base de datos -- opera puramente sobre los arrays de
 * patrones/preguntas que le pasa el llamador. El llamador decide de donde
 * salen esos arrays (hoy: comparando la matriz de patrones actual contra
 * una matriz propuesta simulada).
 */
class PatternReconciliationService
{
    public const STATUS_REVIEWED = 'reviewed';

    public const STATUS_PENDING = 'pending';

    public const STATUS_REQUIERE_REVALIDACION = 'requiere_revalidacion';

    public const STATUS_UNRESOLVED = 'unresolved';

    /**
     * Versionado explicito del algoritmo de fingerprint (Fase 2, deuda
     * tecnica #1, 2026-08-12). LEGACY cubre tanto las respuestas sin ningun
     * campo de fingerprint como las que ya traen `pattern_fingerprint` con
     * el algoritmo viejo (solo filas, prefijo "rowset_") pero SIN el campo
     * `fingerprint_version` explicito -- un fingerprint viejo nunca debe
     * interpretarse como canonico solo porque el campo `pattern_fingerprint`
     * existe. CANONICAL es exclusivamente el fingerprint calculado por
     * SectionCalibrationMatrixService::computeCanonicalPatternFingerprint()
     * (prefijo "fpv2_"), marcado con fingerprint_version=2 en el momento en
     * que se guarda.
     */
    public const FINGERPRINT_VERSION_LEGACY = 1;

    public const FINGERPRINT_VERSION_CANONICAL = 2;

    /**
     * Categorias de PLANIFICACION de migracion (Fase 5) -- a nivel de
     * patron. Informativas para dry-run/reporte, nunca alteran
     * reconciliation_status por si solas.
     */
    public const MIGRATION_AUTO_MIGRATE = 'AUTO_MIGRATE';

    public const MIGRATION_QUICK_CONFIRMATION = 'QUICK_CONFIRMATION';

    public const MIGRATION_FULL_REVALIDATION = 'FULL_REVALIDATION';

    public const MIGRATION_MISMATCH = 'MISMATCH';

    public const MIGRATION_NEW_SECTION = 'NEW_SECTION';

    /**
     * @param  array<int, array{id:int, row_fingerprint:string, filas:int[]}>  $beforePatterns  Patrones vigentes hoy.
     * @param  array<int, array<int, array<string,mixed>>>  $beforeQuestionsByPatternId  pattern_id (de $beforePatterns) => preguntas guardadas para ese patron.
     * @param  array<int, array{id:int, row_fingerprint:string, filas:int[]}>  $afterPatterns  Patrones que resultarian tras el cambio propuesto.
     * @return array<int, array<string,mixed>> resultado de reconciliacion por patron "despues", indexado por su id.
     */
    public function reconcile(array $beforePatterns, array $beforeQuestionsByPatternId, array $afterPatterns): array
    {
        $beforeByFingerprint = [];
        foreach ($beforePatterns as $bp) {
            $beforeByFingerprint[$bp['row_fingerprint']][] = $bp;
        }

        $results = [];

        foreach ($afterPatterns as $ap) {
            $fp = $ap['row_fingerprint'];

            // Huella identica y sin ambiguedad: coincidencia exacta e inequivoca.
            // fp_empty nunca cuenta como coincidencia (dos patrones vacios no
            // son "el mismo patron" -- ver computeRowFingerprint()).
            if ($fp !== 'fp_empty' && isset($beforeByFingerprint[$fp]) && count($beforeByFingerprint[$fp]) === 1) {
                $matchedBefore = $beforeByFingerprint[$fp][0];
                $questions = $beforeQuestionsByPatternId[$matchedBefore['id']] ?? [];
                $wasReviewed = ! empty($questions) && $this->allReviewed($questions);

                $results[$ap['id']] = $this->result(
                    $ap,
                    $wasReviewed ? self::STATUS_REVIEWED : self::STATUS_PENDING,
                    null,
                    $wasReviewed ? $this->remapAnswers($questions, $ap['id']) : null,
                    null,
                );

                continue;
            }

            $overlapping = $this->findOverlappingBeforePatterns($ap['filas'], $beforePatterns);

            if (empty($overlapping)) {
                // Patron nuevo: ningun patron "antes" comparte ni una fila con este.
                $results[$ap['id']] = $this->result($ap, self::STATUS_PENDING, null, null, null);

                continue;
            }

            // 1 antecesor con solapamiento parcial = fragmento de un patron
            // dividido. >=2 antecesores = patrones fusionados en uno. Ambos
            // casos reciben el mismo estado (requiere_revalidacion) -- la
            // diferencia queda documentada en derived_from_fingerprint (su
            // longitud revela cual de los dos casos fue).
            $derivedFrom = [];
            $historicalAnswers = [];
            foreach ($overlapping as $bp) {
                $derivedFrom[] = $bp['row_fingerprint'];
                $qs = $beforeQuestionsByPatternId[$bp['id']] ?? [];
                if (! empty($qs)) {
                    $historicalAnswers[] = [
                        'source_fingerprint' => $bp['row_fingerprint'],
                        'source_filas' => $bp['filas'],
                        'questions' => $qs,
                    ];
                }
            }

            $results[$ap['id']] = $this->result(
                $ap,
                self::STATUS_REQUIERE_REVALIDACION,
                $derivedFrom,
                null,
                empty($historicalAnswers) ? null : $historicalAnswers,
            );
        }

        return $results;
    }

    /**
     * true solo si TODOS los patrones reconciliados quedaron 'reviewed'.
     * Un solo patron pending/requiere_revalidacion/unresolved invalida el
     * estado efectivo de la seccion, sin importar cuantos otros si esten
     * reviewed. La marca historica 'section_reviewed' guardada en el JSON
     * no se toca -- esto calcula el estado VIGENTE, no reescribe el pasado.
     */
    /**
     * Reconciliacion "en vivo": a diferencia de reconcile() (que compara un
     * "antes" y un "despues" simulados para una migracion puntual), esta
     * variante evalua los patrones VIGENTES en la matriz actual contra las
     * respuestas ya guardadas, sin necesitar un snapshot "antes" separado.
     * Se usa en cada carga de SectionCalibrationMatrixService::buildPatternMatrix(),
     * para cualquier hoja-seccion, no solo A09/F-G.
     *
     * Prioridad de emparejamiento por patron vigente:
     *   1. Si alguna pregunta guardada trae pattern_fingerprint: se compara
     *      contra el row_fingerprint del patron vigente. Coincide -> se
     *      confia (reviewed/pending segun review_status). No coincide ->
     *      requiere_revalidacion (el patron cambio de filas bajo el mismo id).
     *   2. Si ninguna trae pattern_fingerprint pero si pattern_rows (guardado
     *      por una migracion previa sin fingerprint): se compara el conjunto
     *      de filas. No coincide -> requiere_revalidacion.
     *   3. Si no hay ninguna de las dos (caso de casi todas las respuestas
     *      existentes hoy, guardadas antes de que este campo existiera): se
     *      confia en el emparejamiento historico por pattern_id -- valido
     *      mientras la estructura activa no haya cambiado desde que se
     *      respondio, que es el caso de todo REM hoy salvo que se ejecute la
     *      migracion real de A09/F-G (todavia no autorizada).
     *
     * @param  array<int, array{id:int, row_fingerprint:string, filas:int[]}>  $currentPatterns  Patrones de la matriz vigente.
     * @param  array<int, array<int, array<string,mixed>>>  $questionsByPatternId  pattern_id => preguntas guardadas para ese id.
     * @return array<int, array{reconciliation_status:string, backfill_status:?string, derived_from_fingerprint:?array, historical_reference:?array}> indexado por id de patron vigente.
     */
    public function reconcileLive(array $currentPatterns, array $questionsByPatternId): array
    {
        $results = [];

        foreach ($currentPatterns as $pattern) {
            $questions = $questionsByPatternId[$pattern['id']] ?? [];

            if (empty($questions)) {
                $results[$pattern['id']] = $this->liveResult(self::STATUS_PENDING, null, null, null);

                continue;
            }

            // Compatibilidad transitoria (2026-08-12, hallazgo A01/A): mientras
            // reconcileLiveCanonical() no se active, esta funcion (v1) debe
            // seguir comportandose exactamente igual que antes de que
            // existiera el fingerprint canonico v2. Las preguntas ya
            // migradas/revalidadas a v2 (fingerprint_version===2) escriben
            // pattern_fingerprint en formato "fpv2_..." -- un formato
            // distinto al v1 "rowset_..." que row_fingerprint usa. Sin este
            // filtro, la comparacion de abajo nunca podia coincidir y
            // degradaba a requiere_revalidacion cualquier patron apenas se
            // le aplicaba una quick-revalidation, aunque reconcileLiveCanonical
            // siguiera sin activarse. Las preguntas v2 se excluyen por
            // completo de la heuristica legacy (ni fingerprint ni rows) --
            // review_status se sigue evaluando sobre $questions completo,
            // sin cambios.
            $legacyQuestions = array_values(array_filter(
                $questions,
                fn ($q) => $this->resolveFingerprintVersion($q) !== self::FINGERPRINT_VERSION_CANONICAL
            ));

            if (empty($legacyQuestions)) {
                $results[$pattern['id']] = $this->liveResult(
                    $this->allReviewed($questions) ? self::STATUS_REVIEWED : self::STATUS_PENDING,
                    'fingerprint_canonical_only',
                    null,
                    null,
                );

                continue;
            }

            $withFingerprint = array_values(array_filter($legacyQuestions, fn ($q) => ! empty($q['pattern_fingerprint'])));
            if (! empty($withFingerprint)) {
                $recorded = $withFingerprint[0]['pattern_fingerprint'];
                $agrees = $recorded === $pattern['row_fingerprint'];

                $results[$pattern['id']] = $this->liveResult(
                    $agrees
                        ? ($this->allReviewed($questions) ? self::STATUS_REVIEWED : self::STATUS_PENDING)
                        : self::STATUS_REQUIERE_REVALIDACION,
                    'fingerprint_native',
                    $agrees ? null : [$recorded],
                    $agrees ? null : $this->historicalReferenceFrom($withFingerprint[0]),
                );

                continue;
            }

            $withRows = array_values(array_filter($legacyQuestions, fn ($q) => ! empty($q['pattern_rows'])));
            if (! empty($withRows)) {
                $recordedRows = $this->sortedUnique($withRows[0]['pattern_rows']);
                $liveRows = $this->sortedUnique($pattern['filas']);
                $agrees = $recordedRows === $liveRows;

                $results[$pattern['id']] = $this->liveResult(
                    $agrees
                        ? ($this->allReviewed($questions) ? self::STATUS_REVIEWED : self::STATUS_PENDING)
                        : self::STATUS_REQUIERE_REVALIDACION,
                    'legacy_partial',
                    null,
                    $agrees ? null : $this->historicalReferenceFrom($withRows[0]),
                );

                continue;
            }

            $results[$pattern['id']] = $this->liveResult(
                $this->allReviewed($questions) ? self::STATUS_REVIEWED : self::STATUS_PENDING,
                'legacy_unmigrated',
                null,
                null,
            );
        }

        return $results;
    }

    private function liveResult(string $status, ?string $backfillStatus, ?array $derivedFrom, ?array $historicalReference): array
    {
        return [
            'reconciliation_status' => $status,
            'backfill_status' => $backfillStatus,
            'derived_from_fingerprint' => $derivedFrom,
            'historical_reference' => $historicalReference,
        ];
    }

    private function historicalReferenceFrom(array $question): array
    {
        return [
            'response' => $question['response'] ?? null,
            'reviewed_by' => $question['reviewed_by'] ?? null,
            'reviewed_at' => $question['reviewed_at'] ?? null,
            'pattern_fingerprint' => $question['pattern_fingerprint'] ?? null,
            'pattern_rows' => $question['pattern_rows'] ?? null,
        ];
    }

    private function sortedUnique(array $filas): array
    {
        $filas = array_values(array_unique(array_map('intval', $filas)));
        sort($filas, SORT_NUMERIC);

        return $filas;
    }

    public function computeEffectiveSectionReviewed(array $reconciliationResults): bool
    {
        if (empty($reconciliationResults)) {
            return false;
        }

        foreach ($reconciliationResults as $r) {
            if (($r['reconciliation_status'] ?? null) !== self::STATUS_REVIEWED) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determina la version de fingerprint de una pregunta guardada. Sin
     * `fingerprint_version` explicito, SIEMPRE se trata como LEGACY --
     * nunca se infiere v2 solo porque `pattern_fingerprint` este presente
     * (ver deuda tecnica #1: todo fingerprint anterior a esta version del
     * mecanismo, aunque ya se llame `pattern_fingerprint`, se calculo con
     * el algoritmo viejo de solo-filas).
     */
    public function resolveFingerprintVersion(array $question): int
    {
        if (isset($question['fingerprint_version'])) {
            return (int) $question['fingerprint_version'];
        }

        return self::FINGERPRINT_VERSION_LEGACY;
    }

    /**
     * Reconciliacion ESTRICTA v2 (Fase 3, deuda tecnica #1, disenada y
     * autorizada 2026-08-12). NO esta conectada a buildPatternMatrix() ni a
     * ningun flujo de produccion -- mientras no se autorice explicitamente
     * la migracion real de las 301 secciones ya calibradas, el sistema en
     * vivo sigue usando reconcileLive() (v1, sin cambios) para no pasar de
     * golpe de "100% calibrado" a "multiples secciones pendientes" antes de
     * tener las 235 seguras migradas y las 66 restantes clasificadas. Este
     * metodo es la implementacion del mecanismo nuevo, consumida hoy
     * unicamente por la simulacion de migracion (Fase 5) -- activarlo en
     * produccion es un paso posterior, separado y explicito (reemplazar la
     * llamada a reconcileLive() por esta en
     * SectionCalibrationMatrixService::applyPatternReconciliation()).
     *
     * Principios (autorizados 2026-08-12), en orden de evaluacion:
     *   1. Fingerprint v2 guardado coincide con el canonico vigente ->
     *      puede conservar 'reviewed' (si ademas review_status='reviewed').
     *   2. Fingerprint v2 guardado NO coincide -> 'requiere_revalidacion'.
     *   3. Respuesta v1/legacy -> NUNCA 'reviewed' solo por coincidir el
     *      pattern_id -- sin importar cuanta evidencia indirecta exista.
     *   4. (Ver classifyLegacyPatternForMigration() para la clasificacion
     *      informativa de que tan buena es esa evidencia legacy -- no
     *      cambia el resultado aqui: sigue siendo 'requiere_revalidacion'
     *      hasta que una migracion real escriba un fingerprint v2.)
     *   5. Sin ninguna evidencia -> 'requiere_revalidacion' (mismo
     *      resultado que el 3/4, la diferencia es solo informativa).
     *   6. Patron sin ninguna pregunta guardada -> 'pending' (patron
     *      nuevo dentro de una seccion ya revisada).
     *
     * @param  array<int, array{id:int, canonical_fingerprint:string, filas:int[]}>  $currentPatterns  Patrones vigentes (deben incluir 'canonical_fingerprint', ver computeCanonicalPatternFingerprint()).
     * @param  array<int, array<int, array<string,mixed>>>  $questionsByPatternId  pattern_id vigente => preguntas guardadas para ese id.
     * @return array<int, array{reconciliation_status:string, fingerprint_version_checked:int, mismatch_detail:?array}>
     */
    public function reconcileLiveCanonical(array $currentPatterns, array $questionsByPatternId): array
    {
        $results = [];

        foreach ($currentPatterns as $pattern) {
            $questions = $questionsByPatternId[$pattern['id']] ?? [];

            if (empty($questions)) {
                $results[$pattern['id']] = $this->canonicalResult(self::STATUS_PENDING, null);

                continue;
            }

            $v2Question = null;
            foreach ($questions as $q) {
                if ($this->resolveFingerprintVersion($q) === self::FINGERPRINT_VERSION_CANONICAL && ! empty($q['pattern_fingerprint'])) {
                    $v2Question = $q;

                    break;
                }
            }

            if ($v2Question !== null) {
                $agrees = $v2Question['pattern_fingerprint'] === ($pattern['canonical_fingerprint'] ?? null);

                $results[$pattern['id']] = $this->canonicalResult(
                    $agrees
                        ? ($this->allReviewed($questions) ? self::STATUS_REVIEWED : self::STATUS_PENDING)
                        : self::STATUS_REQUIERE_REVALIDACION,
                    $agrees ? null : ['stored' => $v2Question['pattern_fingerprint'], 'live' => $pattern['canonical_fingerprint'] ?? null],
                );

                continue;
            }

            // v1/legacy (con o sin pattern_fingerprint viejo): nunca 'reviewed'
            // solo porque el pattern_id coincide -- principio 3.
            $results[$pattern['id']] = $this->canonicalResult(self::STATUS_REQUIERE_REVALIDACION, null);
        }

        return $results;
    }

    private function canonicalResult(string $status, ?array $mismatchDetail): array
    {
        return [
            'reconciliation_status' => $status,
            'fingerprint_version_checked' => self::FINGERPRINT_VERSION_CANONICAL,
            'mismatch_detail' => $mismatchDetail,
        ];
    }

    /**
     * Clasificacion INFORMATIVA de planificacion de migracion (Fase 5) para
     * un patron legacy -- nunca otorga 'reviewed', solo prioriza cuanto
     * trabajo humano hace falta antes de una migracion real. Generico: no
     * depende de hoja/seccion, solo de la evidencia recibida.
     *
     * @param  ?int[]  $historicalRows  Filas recuperadas de evidencia historica (ej. texto libre de la pregunta), o null si no se pudo recuperar nada.
     * @param  int[]  $liveRows  Filas del patron vigente hoy.
     * @param  ?bool  $structureIdenticalSinceHistoricalVersion  true si la seccion declarada en la version de estructura historica es byte-identica a la vigente; null si no se pudo determinar.
     */
    public function classifyLegacyPatternForMigration(
        ?array $historicalRows,
        array $liveRows,
        ?bool $structureIdenticalSinceHistoricalVersion,
    ): string {
        if ($historicalRows === null) {
            return self::MIGRATION_FULL_REVALIDATION;
        }

        $rowsMatch = $this->sortedUnique($historicalRows) === $this->sortedUnique($liveRows);

        if (! $rowsMatch) {
            return self::MIGRATION_MISMATCH;
        }

        return $structureIdenticalSinceHistoricalVersion === true
            ? self::MIGRATION_AUTO_MIGRATE
            : self::MIGRATION_QUICK_CONFIRMATION;
    }

    /**
     * @param  int[]  $afterFilas
     * @param  array<int, array{id:int, row_fingerprint:string, filas:int[]}>  $beforePatterns
     * @return array<int, array{id:int, row_fingerprint:string, filas:int[]}>
     */
    private function findOverlappingBeforePatterns(array $afterFilas, array $beforePatterns): array
    {
        $overlapping = [];
        foreach ($beforePatterns as $bp) {
            if (count(array_intersect($afterFilas, $bp['filas'])) > 0) {
                $overlapping[] = $bp;
            }
        }

        return $overlapping;
    }

    private function allReviewed(array $questions): bool
    {
        foreach ($questions as $q) {
            if (($q['review_status'] ?? null) !== 'reviewed') {
                return false;
            }
        }

        return true;
    }

    /**
     * Copia las preguntas de un patron "antes" hacia el nuevo pattern_id --
     * regenera id/pattern_id/pattern_key, conserva response, review_status,
     * reviewed_by, reviewed_at, technical_signature, structure_version,
     * updated_at y cualquier otro campo tal cual, sin perder nada.
     */
    private function remapAnswers(array $questions, int $newPatternId): array
    {
        return array_map(function ($q) use ($newPatternId) {
            $suffix = $this->suffixFromId($q['id'] ?? '');
            $remapped = $q;
            $remapped['pattern_id'] = $newPatternId;
            $remapped['pattern_key'] = "pattern_{$newPatternId}";
            if ($suffix !== null) {
                $remapped['id'] = "patron_{$newPatternId}_{$suffix}";
            }

            return $remapped;
        }, $questions);
    }

    private function suffixFromId(string $id): ?string
    {
        if (preg_match('/^patron_\d+_(.+)$/', $id, $m)) {
            return $m[1];
        }

        return null;
    }

    private function result(array $afterPattern, string $status, ?array $derivedFrom, ?array $carriedOver, ?array $historical): array
    {
        return [
            'after_pattern_id' => $afterPattern['id'],
            'row_fingerprint' => $afterPattern['row_fingerprint'],
            'filas' => $afterPattern['filas'],
            'reconciliation_status' => $status,
            'derived_from_fingerprint' => $derivedFrom,
            'carried_over_answers' => $carriedOver,
            'historical_answers' => $historical,
        ];
    }
}
