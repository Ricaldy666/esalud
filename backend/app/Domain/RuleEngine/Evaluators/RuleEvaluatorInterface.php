<?php

namespace App\Domain\RuleEngine\Evaluators;

use Illuminate\Support\Collection;

interface RuleEvaluatorInterface
{
    public function supports(string $ruleType): bool;
    public function evaluate(array $config, Collection $rows): RuleEvaluationResult;
}
