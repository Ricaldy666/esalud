<?php

namespace App\Domain\RemParser\DTOs;

class ParsedFormDTO
{
    public function __construct(
        public readonly string $sheetName,
        /** @var ParsedSectionDTO[] */
        public readonly array $sections,
    ) {}

    public function toArray(): array
    {
        return [
            'sheetName' => $this->sheetName,
            'sections' => array_map(fn(ParsedSectionDTO $s) => $s->toArray(), $this->sections),
        ];
    }
}
