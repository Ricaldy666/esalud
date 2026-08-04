<?php

namespace App\Console\Commands;

use App\Domain\REM\Models\RemTemplate;
use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RemParser\Services\RemParserService;
use App\Domain\RemParser\Services\RemTemplateStructurePersistenceService;
use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Models\RuleBinding;
use App\Domain\RuleEngine\Services\StructureResolverService;
use App\Domain\RuleEngine\Services\RuleEngineService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RemRebuildStructureCommand extends Command
{
    protected $signature = 'rem:rebuild-structure
                            {--dry-run : Show what would be done without persisting}
                            {--year=2026 : Year for the structure}
                            {--serie=A : Serie for the structure}
                            {--source= : Explicit path to XLSM file (optional)}';

    protected $description = 'Reconstruye la estructura REM desde el XLSM original y vincula las reglas del catálogo';

    public function handle(
        RemParserService $parser,
        RemTemplateStructurePersistenceService $persistence,
        StructureResolverService $structureResolver,
        RuleEngineService $ruleEngine,
    ): int {
        $dryRun = $this->option('dry-run');
        $year = (int) $this->option('year');
        $serie = strtoupper($this->option('serie'));
        $source = $this->option('source');

        // 1. Find source XLSM
        $xlsmPath = $this->resolveSource($year, $serie, $source);
        if (!$xlsmPath) {
            $this->warn("No XLSM found. Fallback: checking if structure already exists in DB.");
            return $this->handleFallback($year, $serie, $dryRun);
        }

        $this->info("Source: {$xlsmPath}");
        $this->line("  Size: " . number_format(filesize($xlsmPath)) . " bytes");

        // 2. Detect RemTemplate
        $template = RemTemplate::where('year', $year)->where('rem_type', $serie)->first();
        $templateId = $template?->id;

        // 3. Parse
        $this->line('Parsing template...');
        try {
            $dto = $parser->parse($xlsmPath);
        } catch (\Throwable $e) {
            $this->error("Parse error: {$e->getMessage()}");
            return self::FAILURE;
        }

        // 4. Analyze structure details
        $totalSections = 0;
        $totalFields = 0;
        $totalFormulas = 0;
        $formulaTypes = [];
        $sheetNames = [];
        $sheetDetails = [];

        foreach ($dto->forms as $form) {
            $sheetNames[] = $form->sheetName;
            $sectionsArr = $form->sections ?? [];
            $secCount = count($sectionsArr);
            $fldCount = 0;
            $formulaCount = 0;

            foreach ($sectionsArr as $sec) {
                $secFields = $sec->fields ?? [];
                $fldCount += count($secFields);
                foreach ($secFields as $field) {
                    $regla = $field->reglaDetectada ?? null;
                    if ($regla !== null) {
                        $formulaCount++;
                        $tipo = is_array($regla) ? ($regla['tipo'] ?? 'unknown') : $regla;
                        $formulaTypes[$tipo] = ($formulaTypes[$tipo] ?? 0) + 1;
                    }
                }
            }

            $totalSections += $secCount;
            $totalFields += $fldCount;
            $totalFormulas += $formulaCount;

            $sheetDetails[] = [
                'sheet' => $form->sheetName,
                'sections' => $secCount,
                'fields' => $fldCount,
                'formulas' => $formulaCount,
            ];
        }

        // 5. Check if structure already exists
        $existing = RemTemplateStructure::where('anio', $year)
            ->where('serie', $serie)
            ->where('hash_estructura', $dto->hashEstructura)
            ->first();

        // 6. Count rules to bind
        $rulesCount = Rule::where('status', 'active')->count();
        $rulesByType = Rule::where('status', 'active')
            ->select('rule_type', DB::raw('count(*) as cnt'))
            ->groupBy('rule_type')
            ->pluck('cnt', 'rule_type')
            ->toArray();

        // 7. Count existing bindings for this structure/serie
        $existingBindingsTotal = RuleBinding::where('active', true)->count();
        if ($existing) {
            $existingBindingsForStructure = RuleBinding::where('bindable_type', 'structure')
                ->where('bindable_id', $existing->id)
                ->where('active', true)
                ->count();
        } else {
            $existingBindingsForStructure = 0;
        }

        // 8. Display dry-run details
        $this->newLine();
        $this->warn('========== DRY RUN — No changes made ==========');
        $this->line('');

        $this->info('1. SOURCE');
        $this->table(
            ['Property', 'Value'],
            [
                ['Source file', basename($xlsmPath)],
                ['Size', number_format(filesize($xlsmPath)) . ' bytes'],
                ['RemTemplate ID', $templateId ?? 'NOT FOUND'],
                ['RemTemplate type', $template ? "{$template->rem_type} v{$template->version}" : 'N/A'],
            ]
        );

        $this->info('2. STRUCTURE OVERVIEW');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Year', $dto->anio],
                ['Serie', $dto->serie],
                ['Hash', $dto->hashEstructura],
                ['Sheets (forms)', count($dto->forms)],
                ['Total sections', $totalSections],
                ['Total fields', $totalFields],
                ['Total formulas', $totalFormulas],
            ]
        );

        $this->info('3. SHEETS DETECTED');
        $sheetRows = [];
        foreach ($sheetDetails as $sd) {
            $sheetRows[] = [$sd['sheet'], $sd['sections'], $sd['fields'], $sd['formulas']];
        }
        $this->table(
            ['Sheet', 'Sections', 'Fields', 'Formulas'],
            $sheetRows
        );

        if (!empty($formulaTypes)) {
            $this->info('4. FORMULA TYPES');
            $formulaRows = [];
            foreach ($formulaTypes as $tipo => $cnt) {
                $formulaRows[] = [$tipo, $cnt];
            }
            $this->table(['Type', 'Count'], $formulaRows);
        }

        $this->info('5. RULES & BINDINGS');
        $ruleTypeRows = [];
        foreach ($rulesByType as $type => $cnt) {
            $ruleTypeRows[] = [$type, $cnt];
        }
        $this->table(
            ['Metric', 'Value'],
            array_merge(
                [['Total active rules', $rulesCount]],
                $ruleTypeRows,
                [
                    ['Existing total bindings', $existingBindingsTotal],
                    ['Existing bindings for this structure', $existingBindingsForStructure],
                ]
            )
        );

        if ($existing) {
            $this->info('6. EXISTING STRUCTURE (will be reused)');
            $this->table(
                ['Property', 'Value'],
                [
                    ['ID', $existing->id],
                    ['Status', $existing->status],
                    ['Version', $existing->version_number],
                    ['Hash', $existing->hash_estructura],
                    ['TemplateID', $existing->rem_template_id ?? 'NULL'],
                    ['Source', $existing->source_filename ?? 'N/A'],
                ]
            );
        } else {
            $this->info('6. NEW STRUCTURE (will be created)');
        }

        // 7. — Summary action
        $action = $existing ? 'REUSE existing + UPDATE bindings' : 'CREATE new + BIND rules';
        $this->info("7. ACTION: {$action}");

        // 8. Possible errors / warnings
        $issues = [];
        if (!$templateId) {
            $issues[] = "WARNING: No RemTemplate found for {$serie}/{$year}";
        }
        if ($existing && !$existing->rem_template_id && $templateId) {
            $issues[] = "NOTE: Structure {$existing->id} has no template_id, will be updated to {$templateId}";
        }
        if ($existing && $existing->status !== 'active') {
            $issues[] = "NOTE: Structure {$existing->id} status is '{$existing->status}', will be activated";
        }
        if (count($dto->forms) < 16) {
            $issues[] = "WARNING: Only " . count($dto->forms) . " sheets detected (expected >= 16 for Serie A)";
        }

        if (!empty($issues)) {
            $this->newLine();
            $this->warn('OBSERVATIONS');
            foreach ($issues as $issue) {
                $this->line("  • {$issue}");
            }
        }

        $this->newLine();
        $this->warn('===============================================');

        if ($dryRun) {
            return self::SUCCESS;
        }

        // 9. Persist structure (idempotent)
        $filename = basename($xlsmPath);
        $result = $persistence->persist(
            dto: $dto,
            remTemplateId: $templateId,
            sourceFilename: $filename,
        );

        $structure = $result->model;
        if ($result->wasCreated) {
            $this->info("Structure created (ID: {$structure->id}, Version: {$structure->version_number})");
        } else {
            $this->info("Structure already exists (ID: {$structure->id}, Version: {$structure->version_number})");
        }

        // 10. Associate template ID if missing
        if ($templateId && !$structure->rem_template_id) {
            $structure->rem_template_id = $templateId;
            $structure->save();
            $this->info("Associated with RemTemplate ID: {$templateId}");
        } elseif ($templateId && $structure->rem_template_id != $templateId) {
            $structure->rem_template_id = $templateId;
            $structure->save();
            $this->info("Updated RemTemplate association to ID: {$templateId}");
        }

        // 11. Activate the structure
        if ($structure->status !== 'active') {
            $structure->status = 'active';
            $structure->save();
            $this->info("Structure activated (was: '{$structure->status}')");
        }

        // 12. Bind rules (idempotent via updateOrCreate)
        $this->line('Binding rules to structure...');
        $bindableType = 'structure';
        $bindableId = $structure->id;
        $bound = 0;
        $skipped = 0;

        $rules = Rule::where('status', 'active')->get();
        foreach ($rules as $rule) {
            RuleBinding::updateOrCreate(
                [
                    'rule_id' => $rule->id,
                    'bindable_type' => $bindableType,
                    'bindable_id' => $bindableId,
                ],
                [
                    'serie' => $serie,
                    'anio' => $year,
                    'active' => true,
                ]
            );
            $bound++;
        }

        $this->info("Bindings created/confirmed: {$bound}");

        // 13. Final verification
        $finalBindings = RuleBinding::where('bindable_type', $bindableType)
            ->where('bindable_id', $bindableId)
            ->where('active', true)
            ->count();

        $resolvedRules = $ruleEngine->resolveRules($structure->id, $structure);

        $this->newLine();
        $this->info('========== RECONSTRUCTION COMPLETE ==========');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Structure ID', $structure->id],
                ['Year/Serie', "{$structure->anio}/{$structure->serie}"],
                ['Version', $structure->version_number],
                ['Hash', $structure->hash_estructura],
                ['Sheets', count($dto->forms)],
                ['Status', $structure->status],
                ['Template ID', $structure->rem_template_id ?? 'NULL'],
                ['Bindings on structure', $finalBindings],
                ['Rules resolvable by engine', $resolvedRules->count()],
            ]
        );

        return self::SUCCESS;
    }

    public function handleFallback(int $year, string $serie, bool $dryRun): int
    {
        $structure = RemTemplateStructure::where('anio', $year)
            ->where('serie', $serie)
            ->where('status', 'active')
            ->orderBy('version_number', 'desc')
            ->first();

        if (!$structure) {
            $this->error("No existing structure found for {$serie}/{$year} and no XLSM source available.");
            $this->error("Use --source to specify the path to an XLSM file.");
            return self::FAILURE;
        }

        $template = RemTemplate::where('year', $year)->where('rem_type', $serie)->first();

        $this->info("Using existing structure ID:{$structure->id} from DB (hash: {$structure->hash_estructura})");
        $this->line("  Anio: {$structure->anio}, Serie: {$structure->serie}, Version: {$structure->version_number}");
        $this->line("  Status: {$structure->status}, TemplateID: {$structure->rem_template_id}");

        $est = is_string($structure->estructura) ? json_decode($structure->estructura, true) : $structure->estructura;
        $forms = $est['forms'] ?? [];
        $this->line("  Sheets: " . count($forms));

        $rulesCount = Rule::where('status', 'active')->count();

        $existingBindings = RuleBinding::where('bindable_type', 'structure')
            ->where('bindable_id', $structure->id)
            ->where('active', true)
            ->count();

        if ($dryRun) {
            $this->newLine();
            $this->warn('=== DRY RUN (fallback — no XLSM) ===');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Source', 'DB (existing structure)'],
                    ['Structure ID', $structure->id],
                    ['Year/Serie', "{$structure->anio}/{$structure->serie}"],
                    ['Sheets', count($forms)],
                    ['Active rules', $rulesCount],
                    ['Existing bindings', $existingBindings],
                    ['Operation', 'REUSE existing'],
                ]
            );
            return self::SUCCESS;
        }

        // Update template association if missing
        $updated = false;
        if ($template && !$structure->rem_template_id) {
            $structure->rem_template_id = $template->id;
            $structure->save();
            $updated = true;
            $this->info("Associated with RemTemplate ID: {$template->id}");
        }

        // Ensure active
        if ($structure->status !== 'active') {
            $structure->status = 'active';
            $structure->save();
            $updated = true;
            $this->info("Structure activated");
        }

        // Bind any missing rules
        $bound = 0;
        $rules = Rule::where('status', 'active')->get();
        foreach ($rules as $rule) {
            $b = RuleBinding::updateOrCreate(
                [
                    'rule_id' => $rule->id,
                    'bindable_type' => 'structure',
                    'bindable_id' => $structure->id,
                ],
                [
                    'serie' => $serie,
                    'anio' => $year,
                    'active' => true,
                ]
            );
            $bound++;
        }

        $finalBindings = RuleBinding::where('bindable_type', 'structure')
            ->where('bindable_id', $structure->id)
            ->where('active', true)
            ->count();

        $this->newLine();
        $this->info('========== RECONSTRUCTION (fallback) COMPLETE ==========');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Structure ID', $structure->id],
                ['Year/Serie', "{$structure->anio}/{$structure->serie}"],
                ['Version', $structure->version_number],
                ['Hash', $structure->hash_estructura],
                ['Status', $structure->status],
                ['Template ID', $structure->rem_template_id ?? 'NULL'],
                ['Bindings', $finalBindings],
                ['Rules processed', $bound],
            ]
        );

        return self::SUCCESS;
    }

    private function resolveSource(int $year, string $serie, ?string $source): ?string
    {
        if ($source) {
            if (file_exists($source)) return $source;
            $alt = base_path($source);
            if (file_exists($alt)) return $alt;
            $this->error("Source file not found: {$source}");
            return null;
        }

        // Search in recursos-rem/ (official templates)
        $recursosPattern = base_path("../recursos-rem/*{$serie}*{$year}*.xlsm");
        $files = glob($recursosPattern);
        if (!empty($files)) {
            return $files[0];
        }

        $recursosPattern2 = base_path("../recursos-rem/S{$serie}_{$year}*.xlsm");
        $files = glob($recursosPattern2);
        if (!empty($files)) {
            return $files[0];
        }

        // Search in rem-uploads
        $uploadsPattern = base_path("storage/app/rem-uploads/{$year}/*/*/*{$serie}*V*.xlsm");
        $files = glob($uploadsPattern);
        if (!empty($files)) {
            return $files[0];
        }

        // Search in test fixtures
        $fixturesPattern = base_path("tests/fixtures/**/*{$serie}*{$year}*.xlsm");
        $files = glob($fixturesPattern);
        if (!empty($files)) {
            return $files[0];
        }

        return null;
    }
}
