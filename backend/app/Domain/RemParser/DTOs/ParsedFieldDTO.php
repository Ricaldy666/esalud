<?php

namespace App\Domain\RemParser\DTOs;

class ParsedFieldDTO
{
    public function __construct(
        public readonly string $letra,
        public readonly string $label,
        public readonly bool $esTotal,
        public readonly bool $esControlOculto,
        public readonly null|array $reglaDetectada,
    ) {}

    public function toArray(): array
    {
        return [
            'letra' => $this->letra,
            'label' => $this->label,
            'esTotal' => $this->esTotal,
            'esControlOculto' => $this->esControlOculto,
            'reglaDetectada' => $this->reglaDetectada,
        ];
    }
}
