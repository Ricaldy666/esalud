<?php

namespace App\Domain\RuleEngine\Services;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RemParser\Services\MetadataExtractorService;
use App\Domain\RuleEngine\Exceptions\RuleManifestImportException;
use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Models\RuleBinding;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;

/**
 * BM-6.2. Importador transaccional, idempotente y fail-closed de manifiestos
 * de reglas versionados (JSON en database/seeders/data/), genérico por
 * diseño (no hardcodea BM ni ninguna hoja/serie específica) — el manifiesto
 * describe QUÉ reglas crear; este servicio resuelve DÓNDE (la estructura
 * activa real de la serie/año declarados en el propio manifiesto) y decide
 * SI corresponde crearlas, sin asumir nunca un ID de estructura fijo.
 *
 * Dos operaciones:
 * - plan(): 100% read-only. Valida el manifiesto y el estado del entorno,
 *   clasifica cada regla (create/skip/conflict/invalid) SIN escribir nada.
 *   Fallas estructurales (archivo/JSON/conteo/duplicados dentro del
 *   manifiesto/estructura activa ausente o ambigua/serie o año invalidos)
 *   abortan con excepcion inmediatamente -- no tiene sentido reportar un
 *   plan parcial sobre un manifiesto o un entorno fundamentalmente roto.
 *   Fallas por regla individual (config no normalizable, hoja/seccion
 *   ausente en la estructura, colision con una regla ya existente de
 *   contenido distinto) se acumulan en el plan devuelto, para que el
 *   dry-run muestre el reporte completo.
 * - commit(): reutiliza plan(); si hay CUALQUIER invalida o conflicto,
 *   aborta con excepcion ANTES de abrir la transaccion. Si el plan esta
 *   limpio, crea las reglas 'would_create' + sus bindings dentro de una
 *   unica DB::transaction() -- todo o nada. Las 'would_skip' (idempotentes,
 *   contenido ya identico) nunca se tocan ni se re-escriben.
 */
class RemRuleManifestImporterService
{
    private const SUPPORTED_RULE_TYPES = ['sum_equals', 'required_and_le_parent', 'cross_sheet_equals'];

    public function __construct(private readonly RuleEngineService $engine)
    {
    }

    public function plan(string $manifestPath): array
    {
        if (!is_file($manifestPath)) {
            throw new RuleManifestImportException("Manifiesto no encontrado: {$manifestPath}");
        }

        $raw = file_get_contents($manifestPath);
        $manifest = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuleManifestImportException("Manifiesto no es JSON valido: " . json_last_error_msg());
        }

        foreach (['serie', 'anio', 'rules', 'expected_rule_count'] as $requiredKey) {
            if (!array_key_exists($requiredKey, $manifest)) {
                throw new RuleManifestImportException("Manifiesto incompleto: falta la clave '{$requiredKey}'.");
            }
        }

        $serie = (string) $manifest['serie'];
        $anio = (int) $manifest['anio'];
        $rules = $manifest['rules'];
        $expectedCount = (int) $manifest['expected_rule_count'];

        if (!in_array($serie, MetadataExtractorService::TIPOS_REM, true)) {
            throw new RuleManifestImportException("Serie '{$serie}' declarada en el manifiesto no es una serie REM valida.");
        }

        if ($anio < 2020 || $anio > 2100) {
            throw new RuleManifestImportException("Anio '{$anio}' declarado en el manifiesto no es valido.");
        }

        if (!is_array($rules) || count($rules) !== $expectedCount) {
            $actual = is_array($rules) ? count($rules) : 0;
            throw new RuleManifestImportException(
                "El manifiesto declara expected_rule_count={$expectedCount} pero contiene {$actual} reglas."
            );
        }

        $ruleKeys = array_map(fn (array $r) => $r['rule_key'] ?? null, $rules);
        if (in_array(null, $ruleKeys, true)) {
            throw new RuleManifestImportException('Alguna regla del manifiesto no declara rule_key.');
        }
        $distinctKeys = array_unique($ruleKeys);
        if (count($distinctKeys) !== count($ruleKeys)) {
            $dupes = array_unique(array_diff_assoc($ruleKeys, $distinctKeys));
            throw new RuleManifestImportException(
                'rule_key duplicada dentro del propio manifiesto: ' . implode(', ', $dupes)
            );
        }

        // --- Resolver la (unica) estructura activa de esta serie/anio -- nunca un ID fijo. ---
        $activeStructures = RemTemplateStructure::where('serie', $serie)
            ->where('anio', $anio)
            ->where('status', 'active')
            ->get();

        if ($activeStructures->count() !== 1) {
            throw new RuleManifestImportException(
                "Se esperaba exactamente 1 estructura activa para serie={$serie} anio={$anio}, se encontraron {$activeStructures->count()}."
            );
        }
        $structure = $activeStructures->first();

        $estructura = is_string($structure->estructura) ? json_decode($structure->estructura, true) : $structure->estructura;
        $sectionsBySheet = [];
        foreach ($estructura['forms'] ?? [] as $form) {
            $sheetName = $form['sheetName'] ?? null;
            if ($sheetName === null) {
                continue;
            }
            $sectionsBySheet[$sheetName] = array_map(fn ($s) => $s['codigo'] ?? null, $form['sections'] ?? []);
        }

        $normalizeConfig = new ReflectionMethod($this->engine, 'normalizeConfig');
        $normalizeConfig->setAccessible(true);

        $wouldCreate = [];
        $wouldSkip = [];
        $conflicts = [];
        $invalid = [];

        foreach ($rules as $ruleEntry) {
            $key = $ruleEntry['rule_key'];
            $ruleType = $ruleEntry['rule_type'] ?? null;
            $config = $ruleEntry['config'] ?? [];
            $sheet = $config['sheet'] ?? null;
            $section = $config['section'] ?? null;

            if (!in_array($ruleType, self::SUPPORTED_RULE_TYPES, true)) {
                $invalid[] = ['rule_key' => $key, 'reason' => "rule_type '{$ruleType}' no soportado"];
                continue;
            }

            if ($sheet === null || $section === null || !array_key_exists($sheet, $sectionsBySheet)) {
                $invalid[] = ['rule_key' => $key, 'reason' => "hoja '{$sheet}' no existe en la estructura activa {$structure->id}/v{$structure->version_number}"];
                continue;
            }
            if (!in_array($section, $sectionsBySheet[$sheet], true)) {
                $invalid[] = ['rule_key' => $key, 'reason' => "seccion '{$section}' no existe en hoja '{$sheet}' de la estructura activa {$structure->id}/v{$structure->version_number}"];
                continue;
            }

            $normalized = $normalizeConfig->invoke($this->engine, $config);
            $sourceLetters = $normalized['source_letters'] ?? [];
            $targetColumn = $normalized['target_column'] ?? '';
            if (empty($sourceLetters) || $targetColumn === '') {
                $invalid[] = ['rule_key' => $key, 'reason' => 'config no normalizable (source_letters/target_column vacios tras normalizeConfig())'];
                continue;
            }

            $existing = Rule::withTrashed()->where('rule_key', $key)->first();
            if ($existing === null) {
                $wouldCreate[] = $ruleEntry;
                continue;
            }

            if ($this->contentMatches($existing, $ruleEntry)) {
                $wouldSkip[] = $key;
                continue;
            }

            $existingSheet = $existing->config['sheet'] ?? null;
            $crossSerieHint = ($existingSheet !== null && !str_starts_with((string) $existingSheet, $sheet[0] ?? '')) ? ' (posible colision entre series distintas)' : '';
            $conflicts[] = [
                'rule_key' => $key,
                'reason' => "ya existe rem_rules.id={$existing->id} con contenido distinto{$crossSerieHint}",
                'existing_rule_id' => $existing->id,
            ];
        }

        return [
            'manifest_id' => $manifest['manifest_id'] ?? null,
            'serie' => $serie,
            'anio' => $anio,
            'manifest_rule_count' => count($rules),
            'expected_rule_count' => $expectedCount,
            'structure' => ['id' => $structure->id, 'version_number' => $structure->version_number],
            'valid' => count($wouldCreate) + count($wouldSkip),
            'invalid' => $invalid,
            'would_create' => $wouldCreate,
            'would_skip' => $wouldSkip,
            'conflicts' => $conflicts,
            'bindings_would_create' => count($wouldCreate),
        ];
    }

    public function commit(string $manifestPath): array
    {
        $plan = $this->plan($manifestPath);

        if (!empty($plan['invalid'])) {
            throw new RuleManifestImportException(
                'Commit abortado: ' . count($plan['invalid']) . ' regla(s) invalida(s) en el manifiesto. Ninguna escritura realizada.'
            );
        }
        if (!empty($plan['conflicts'])) {
            throw new RuleManifestImportException(
                'Commit abortado: ' . count($plan['conflicts']) . ' conflicto(s) con reglas ya existentes de contenido distinto. Ninguna escritura realizada.'
            );
        }

        $structureId = $plan['structure']['id'];
        $serie = $plan['serie'];
        $anio = $plan['anio'];

        return DB::transaction(function () use ($plan, $structureId, $serie, $anio) {
            $createdRuleIds = [];
            $createdBindingIds = [];

            foreach ($plan['would_create'] as $ruleEntry) {
                $rule = Rule::create([
                    'rule_key' => $ruleEntry['rule_key'],
                    'rule_type' => $ruleEntry['rule_type'],
                    'source' => $ruleEntry['source'] ?? null,
                    'name' => $ruleEntry['name'] ?? null,
                    'description' => $ruleEntry['description'] ?? null,
                    'category' => $ruleEntry['category'] ?? null,
                    'severity' => $ruleEntry['severity'] ?? 'error',
                    'scope' => $ruleEntry['scope'] ?? null,
                    'config' => $ruleEntry['config'],
                    'status' => $ruleEntry['status'] ?? 'active',
                    'version' => $ruleEntry['version'] ?? '1.0.0',
                    'metadata' => $ruleEntry['metadata'] ?? null,
                ]);
                $createdRuleIds[] = $rule->id;

                $binding = RuleBinding::create([
                    'rule_id' => $rule->id,
                    'bindable_type' => 'structure',
                    'bindable_id' => $structureId,
                    'serie' => $serie,
                    'anio' => $anio,
                    'conditions' => null,
                    'active' => true,
                ]);
                $createdBindingIds[] = $binding->id;
            }

            return [
                'created_rule_ids' => $createdRuleIds,
                'created_binding_ids' => $createdBindingIds,
                'skipped' => $plan['would_skip'],
            ];
        });
    }

    /**
     * Compara el contenido "sustancial" de una regla ya persistida contra la
     * entrada correspondiente del manifiesto -- version canonica (mismas
     * claves, mismo orden) de los campos que definen su comportamiento real
     * (nunca id/timestamps). Identico -> idempotente (skip). Cualquier
     * diferencia -> conflicto, nunca se sobreescribe silenciosamente.
     */
    private function contentMatches(Rule $existing, array $manifestEntry): bool
    {
        $canonical = fn (array $r) => json_encode([
            'rule_type' => $r['rule_type'] ?? null,
            'source' => $r['source'] ?? null,
            'name' => $r['name'] ?? null,
            'description' => $r['description'] ?? null,
            'category' => $r['category'] ?? null,
            'severity' => $r['severity'] ?? null,
            'status' => $r['status'] ?? null,
            'scope' => $r['scope'] ?? null,
            'version' => $r['version'] ?? null,
            'config' => $r['config'] ?? null,
            'metadata' => $r['metadata'] ?? null,
        ]);

        return $canonical($existing->toArray()) === $canonical($manifestEntry);
    }
}
