<?php

namespace App\Domain\RemParser\DTOs;

class ParsedSectionDTO
{
    public function __construct(
        public readonly ?string $codigo,
        public readonly ?string $titulo,
        public readonly int $filaHeader,
        public readonly int $filaInicioDatos,
        public readonly ?int $filaFinDatos,
        /** @var ParsedFieldDTO[] */
        public readonly array $fields,
    ) {}

    public function toArray(): array
    {
        return [
            'codigo' => $this->codigo,
            'titulo' => $this->titulo,
            'filaHeader' => $this->filaHeader,
            'filaInicioDatos' => $this->filaInicioDatos,
            'filaFinDatos' => $this->filaFinDatos,
            'fields' => array_map(fn(ParsedFieldDTO $f) => $f->toArray(), $this->fields),
        ];
    }
}
