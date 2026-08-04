<?php

namespace App\Domain\RemParser\DTOs;

class ParsedFormulaRuleDTO
{
    public function __construct(
        public readonly string $tipo,
        /** @var string[] */
        public readonly array $columnasOrigen,
        public readonly ?string $columnaDestino,
        public readonly ?string $rangoFilas,
    ) {}

    public function toArray(): array
    {
        return [
            'tipo' => $this->tipo,
            'columnasOrigen' => $this->columnasOrigen,
            'columnaDestino' => $this->columnaDestino,
            'rangoFilas' => $this->rangoFilas,
        ];
    }
}
