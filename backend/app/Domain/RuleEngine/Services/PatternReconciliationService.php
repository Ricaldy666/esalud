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

            $withFingerprint = array_values(array_filter($questions, fn ($q) => ! empty($q['pattern_fingerprint'])));
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

            $withRows = array_values(array_filter($questions, fn ($q) => ! empty($q['pattern_rows'])));
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
