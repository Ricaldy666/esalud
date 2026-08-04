<?php

namespace App\Domain\RuleEngine\Services;

class RuleKeyGeneratorService
{
    public function generate(
        string $sheet,
        string $sectionCodigo,
        string $letra,
        string $tipo,
    ): string {
        $sheet = preg_replace('/[^a-z0-9]/', '_', strtolower($sheet));
        $section = preg_replace('/[^a-z0-9.]/', '_', strtolower($sectionCodigo));
        $letra = strtolower($letra);
        $tipo = strtolower($tipo);

        $key = "{$sheet}_{$section}_{$letra}_{$tipo}";

        return $key;
    }
}
