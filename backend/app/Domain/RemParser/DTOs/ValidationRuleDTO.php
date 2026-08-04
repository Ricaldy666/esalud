<?php

namespace App\Domain\RemParser\DTOs;

class ValidationRuleDTO
{
    public function __construct(
        public readonly string $ruleKey,
        public readonly string $ruleType,
        public readonly string $sheet,
        public readonly string $targetColumn,
        public readonly array $sourceColumns,
        public readonly string $scope,
        public readonly ?int $rowFrom,
        public readonly ?int $rowTo,
        public readonly string $severity,
    ) {}
}
