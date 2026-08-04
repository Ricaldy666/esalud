<?php

namespace App\Domain\RemParser\DTOs;

class EnhancedCellDTO
{
    public function __construct(
        public readonly string $coordenada,
        public readonly string $columna,
        public readonly int $fila,
        public readonly mixed $valorBruto,
        public readonly bool $esFormula,
        public readonly ?string $formula,
        public readonly array $dependencias,
        public readonly bool $esEditable,
        public readonly bool $estaBloqueada,
        public readonly bool $proteccionHojaActiva,
        public readonly ?array $colorFondo,
        public readonly ?array $colorFuente,
        public readonly ?array $bordes,
        public readonly bool $esCombinada,
        public readonly ?string $rangoCombinado,
        public readonly ?array $validacionDatos,
        public readonly ?array $comentarios,
        public readonly ?string $formatoNumero,
        public readonly string $tipoCelda,
        public readonly string $zona,
    ) {}

    public function toArray(): array
    {
        return [
            'coordenada' => $this->coordenada,
            'columna' => $this->columna,
            'fila' => $this->fila,
            'valor_bruto' => $this->valorBruto,
            'es_formula' => $this->esFormula,
            'formula' => $this->formula,
            'dependencias' => $this->dependencias,
            'es_editable' => $this->esEditable,
            'esta_bloqueada' => $this->estaBloqueada,
            'proteccion_hoja_activa' => $this->proteccionHojaActiva,
            'color_fondo' => $this->colorFondo,
            'color_fuente' => $this->colorFuente,
            'bordes' => $this->bordes,
            'es_combinada' => $this->esCombinada,
            'rango_combinado' => $this->rangoCombinado,
            'validacion_datos' => $this->validacionDatos,
            'comentarios' => $this->comentarios,
            'formato_numero' => $this->formatoNumero,
            'tipo_celda' => $this->tipoCelda,
            'zona' => $this->zona,
        ];
    }
}
