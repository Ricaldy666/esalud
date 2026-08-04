<?php

namespace App\Domain\RemParser\DTOs;

use App\Domain\RemParser\Models\RemTemplateStructure;

class PersistResult
{
    public function __construct(
        public readonly RemTemplateStructure $model,
        public readonly bool $wasCreated,
    ) {}
}
