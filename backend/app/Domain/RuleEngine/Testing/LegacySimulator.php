<?php

namespace App\Domain\RuleEngine\Testing;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RemParser\Services\RemFormulaRuleBuilder;
use App\Domain\RemParser\Services\RemFormulaValidationExecutor;

class LegacySimulator
{
    public function simulate(int $structureId, int $uploadId): array
    {
        $structure = RemTemplateStructure::withTrashed()->findOrFail($structureId);
        $builder = new RemFormulaRuleBuilder;
        $dtos = $builder->build($structure);

        $executor = new RemFormulaValidationExecutor;
        $results = $executor->execute($uploadId, $dtos, dryRun: true);

        return [
            'dtos' => $dtos,
            'results' => $results,
        ];
    }
}
