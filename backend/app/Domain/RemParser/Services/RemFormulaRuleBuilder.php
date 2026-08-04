<?php

namespace App\Domain\RemParser\Services;

use App\Domain\RemParser\DTOs\ValidationRuleDTO;
use App\Domain\RemParser\Models\RemTemplateStructure;

class RemFormulaRuleBuilder
{
    public function build(RemTemplateStructure $structure): array
    {
        $estructura = $structure->estructura;
        $version = $structure->version_number;
        $rules = [];

        foreach ($estructura['forms'] as $form) {
            $sheet = strtolower($form['sheetName']);

            foreach ($form['sections'] as $section) {
                foreach ($section['fields'] as $field) {
                    $regla = $field['reglaDetectada'] ?? null;

                    if ($regla === null) {
                        continue;
                    }

                    $tipo = $regla['tipo'];

                    if ($tipo === 'control_oculto') {
                        continue;
                    }

                    $columnasOrigen = $this->extractColumnLetters($regla['columnasOrigen'] ?? []);
                    $rangoFilas = $regla['rangoFilas'] ?? null;
                    $targetCol = $field['letra'];
                    $ruleKey = "{$sheet}_v{$version}_{$targetCol}_{$tipo}";

                    if ($rangoFilas !== null && preg_match('/^(\d+):(\d+)$/', $rangoFilas, $m)) {
                        $from = (int) $m[1];
                        $to = (int) $m[2];

                        if ($from === $to) {
                            $rules[] = new ValidationRuleDTO(
                                ruleKey: $ruleKey,
                                ruleType: $tipo,
                                sheet: $form['sheetName'],
                                targetColumn: $targetCol,
                                sourceColumns: $columnasOrigen,
                                scope: 'per_row',
                                rowFrom: $from,
                                rowTo: $to,
                                severity: $tipo === 'sum_equals' ? 'error' : 'warning',
                            );
                        } else {
                            $rules[] = new ValidationRuleDTO(
                                ruleKey: $ruleKey,
                                ruleType: $tipo,
                                sheet: $form['sheetName'],
                                targetColumn: $targetCol,
                                sourceColumns: $columnasOrigen,
                                scope: 'row_range',
                                rowFrom: $from,
                                rowTo: $to,
                                severity: 'error',
                            );
                        }
                    } else {
                        $rules[] = new ValidationRuleDTO(
                            ruleKey: $ruleKey,
                            ruleType: $tipo,
                            sheet: $form['sheetName'],
                            targetColumn: $targetCol,
                            sourceColumns: $columnasOrigen,
                            scope: 'per_row',
                            rowFrom: null,
                            rowTo: null,
                            severity: $tipo === 'sum_equals' ? 'error' : 'warning',
                        );
                    }
                }
            }
        }

        return $rules;
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
