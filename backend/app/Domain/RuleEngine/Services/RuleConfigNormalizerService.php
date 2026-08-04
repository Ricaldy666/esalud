<?php

namespace App\Domain\RuleEngine\Services;

class RuleConfigNormalizerService
{
    public function normalize(
        string $tipo,
        array $columnasOrigen,
        ?string $columnaDestino,
        ?string $rangoFilas,
        string $letra,
    ): array {
        $scope = 'per_row';

        if ($rangoFilas !== null && preg_match('/^(\d+):(\d+)$/', $rangoFilas, $m)) {
            $from = (int) $m[1];
            $to = (int) $m[2];
            if ($from !== $to) {
                $scope = 'row_range';
            }
        }

        $normalized = [
            'tipo' => $tipo,
            'source_columns' => $columnasOrigen,
            'target_column' => $letra,
            'columna_destino' => $columnaDestino,
            'rango_filas' => $rangoFilas,
            'scope' => $scope,
        ];

        if ($rangoFilas !== null && preg_match('/^(\d+):(\d+)$/', $rangoFilas, $m)) {
            $normalized['row_from'] = (int) $m[1];
            $normalized['row_to'] = (int) $m[2];
        }

        if (isset($columnasOrigen) && is_array($columnasOrigen)) {
            $normalized['source_letters'] = $this->extractColumnLetters($columnasOrigen);
        }

        return $normalized;
    }

    private function extractColumnLetters(array $cellRefs): array
    {
        $letters = [];
        foreach ($cellRefs as $ref) {
            if (preg_match('/^([A-Z]+)/', strtoupper($ref), $m)) {
                $letter = $m[1];
                if (!in_array($letter, $letters, true)) {
                    $letters[] = $letter;
                }
            }
        }
        return $letters;
    }
}
