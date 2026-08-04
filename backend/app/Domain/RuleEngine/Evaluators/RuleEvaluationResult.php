<?php

namespace App\Domain\RuleEngine\Evaluators;

class RuleEvaluationResult
{
    public function __construct(
        public readonly string $ruleKey,
        public readonly int $totalRows,
        public readonly int $failedRows,
        public readonly array $details,
        public readonly int $skippedRows = 0,
        public readonly string $reason = '',
    ) {}
}
