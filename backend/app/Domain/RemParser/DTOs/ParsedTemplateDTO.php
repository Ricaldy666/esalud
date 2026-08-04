<?php

namespace App\Domain\RemParser\DTOs;

class ParsedTemplateDTO
{
    public function __construct(
        public readonly ?int $anio,
        public readonly ?string $serie,
        public readonly string $hashEstructura,
        /** @var ParsedFormDTO[] */
        public readonly array $forms,
    ) {}

    public function toArray(): array
    {
        return [
            'anio' => $this->anio,
            'serie' => $this->serie,
            'hashEstructura' => $this->hashEstructura,
            'forms' => array_map(fn(ParsedFormDTO $f) => $f->toArray(), $this->forms),
        ];
    }
}
