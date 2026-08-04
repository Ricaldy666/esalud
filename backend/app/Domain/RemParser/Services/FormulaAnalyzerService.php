<?php

namespace App\Domain\RemParser\Services;

use App\Domain\RemParser\DTOs\ParsedFormulaRuleDTO;

class FormulaAnalyzerService
{
    public function analyze(string $formula): ?ParsedFormulaRuleDTO
    {
        $formula = trim($formula);

        if (!str_starts_with($formula, '=')) {
            return null;
        }

        $body = ltrim(substr($formula, 1));

        $result = $this->trySumEquals($body);
        if ($result !== null) {
            return $result;
        }

        $result = $this->tryRequiredAndLeParent($body);
        if ($result !== null) {
            return $result;
        }

        $result = $this->tryControlOculto($body);
        if ($result !== null) {
            return $result;
        }

        return null;
    }

    private function trySumEquals(string $body): ?ParsedFormulaRuleDTO
    {
        $normalized = $body;

        if (str_starts_with($normalized, '+')) {
            $normalized = substr($normalized, 1);
        }

        if (preg_match('/^SUM\(([A-Z]+)(\d+):([A-Z]+)(\d+)\)(\s*\+\s*.+)?$/i', $normalized, $m)) {
            $cols = $this->extractColumnRefs($body);
            return new ParsedFormulaRuleDTO(
                tipo: 'sum_equals',
                columnasOrigen: $cols,
                columnaDestino: null,
                rangoFilas: "{$m[2]}:{$m[4]}",
            );
        }

        if (preg_match('/^SUM\(.+\)$/i', $normalized)) {
            $cols = $this->extractColumnRefs($body);
            return new ParsedFormulaRuleDTO(
                tipo: 'sum_equals',
                columnasOrigen: $cols,
                columnaDestino: null,
                rangoFilas: null,
            );
        }

        if (preg_match('/^([A-Z]+\d+(\+[A-Z]+\d+)+)$/i', $normalized)) {
            $cols = $this->extractColumnRefs($body);
            return new ParsedFormulaRuleDTO(
                tipo: 'sum_equals',
                columnasOrigen: $cols,
                columnaDestino: null,
                rangoFilas: null,
            );
        }

        return null;
    }

    private function tryRequiredAndLeParent(string $body): ?ParsedFormulaRuleDTO
    {
        if (preg_match('/^IF\(\s*AND\(/i', $body)) {
            $cols = $this->extractColumnRefs($body);
            return new ParsedFormulaRuleDTO(
                tipo: 'required_and_le_parent',
                columnasOrigen: $cols,
                columnaDestino: null,
                rangoFilas: null,
            );
        }

        return null;
    }

    private function tryControlOculto(string $body): ?ParsedFormulaRuleDTO
    {
        if (preg_match('/^IF\(.+,\s*1\s*,\s*0\s*\)$/i', $body)) {
            $cols = $this->extractColumnRefs($body);
            return new ParsedFormulaRuleDTO(
                tipo: 'control_oculto',
                columnasOrigen: $cols,
                columnaDestino: null,
                rangoFilas: null,
            );
        }

        if (str_contains($body, '&')) {
            $cols = $this->extractColumnRefs($body);
            return new ParsedFormulaRuleDTO(
                tipo: 'control_oculto',
                columnasOrigen: $cols,
                columnaDestino: null,
                rangoFilas: null,
            );
        }

        $stripped = ltrim($body, '+');
        if (preg_match('/^\$?[A-Z]+\$?\d+$/i', $stripped)) {
            $cols = $this->extractColumnRefs($body);
            return new ParsedFormulaRuleDTO(
                tipo: 'control_oculto',
                columnasOrigen: $cols,
                columnaDestino: null,
                rangoFilas: null,
            );
        }

        return null;
    }

    private function extractColumnRefs(string $body): array
    {
        $refs = [];

        $body = preg_replace_callback(
            '/\$?([A-Z]+)(\d+)\s*:\s*\$?([A-Z]+)(\d+)/i',
            function (array $m) use (&$refs): string {
                $colStartIdx = $this->colLetterToIndex($m[1]);
                $colEndIdx = $this->colLetterToIndex($m[3]);
                $rowStart = (int) $m[2];
                $rowEnd = (int) $m[4];

                for ($ci = $colStartIdx; $ci <= $colEndIdx; $ci++) {
                    $colLetter = $this->indexToColLetter($ci);
                    for ($ri = $rowStart; $ri <= $rowEnd; $ri++) {
                        $ref = $colLetter . $ri;
                        if (!in_array($ref, $refs, true)) {
                            $refs[] = $ref;
                        }
                    }
                }

                return '';
            },
            $body,
        );

        if (preg_match_all('/\$?([A-Z]+)\$?(\d+)/', strtoupper($body), $m)) {
            foreach ($m[1] as $i => $col) {
                $ref = $col . $m[2][$i];
                if (!in_array($ref, $refs, true)) {
                    $refs[] = $ref;
                }
            }
        }

        return $refs;
    }

    private function colLetterToIndex(string $letter): int
    {
        $letter = strtoupper($letter);
        $index = 0;
        for ($i = 0; $i < strlen($letter); $i++) {
            $index = $index * 26 + (ord($letter[$i]) - ord('A') + 1);
        }
        return $index;
    }

    private function indexToColLetter(int $index): string
    {
        $letter = '';
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $index = (int) (($index - $mod) / 26);
        }
        return $letter;
    }
}
