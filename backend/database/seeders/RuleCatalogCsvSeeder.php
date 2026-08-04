<?php

namespace Database\Seeders;

use App\Domain\RuleEngine\Models\Rule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RuleCatalogCsvSeeder extends Seeder
{
    private const CSV_PATH = 'database/seeders/data/catalogo-reglas-serie-a-2026.csv';

    private const TYPE_MAP = [
        'Suma igual al Total' => 'sum_equals',
        'Requerido y menor o igual al Total' => 'required_and_le_parent',
    ];

    private const SEVERITY_MAP = [
        'Error' => 'error',
        'Advertencia' => 'warning',
        'Info' => 'info',
    ];

    private const STATUS_MAP = [
        'Activa' => 'active',
        'Inactiva' => 'inactive',
        'Desactivada' => 'inactive',
    ];

    public function run(): void
    {
        $path = base_path(self::CSV_PATH);

        if (!file_exists($path)) {
            $this->command->error("CSV not found: {$path}");
            return;
        }

        $handle = fopen($path, 'r');
        $headers = fgetcsv($handle, 0, ';');

        if ($headers === false) {
            $this->command->error('Failed to read CSV headers');
            fclose($handle);
            return;
        }

        $imported = 0;
        $errors = [];
        $skipped = [];
        $rows = [];

        while (($line = fgetcsv($handle, 0, ';')) !== false) {
            if (count($line) < 16) {
                $skipped[] = 'Row with ' . count($line) . ' fields (expected 16)';
                continue;
            }

            $row = array_combine([
                'codigo', 'formulario', 'seccion', 'columna', 'nombre',
                'tipo_regla', 'descripcion', 'logica', 'formula',
                'rango_filas', 'importancia', 'estado', 'fuente',
                'observacion', 'validado_estadistica', 'comentario_estadistica',
            ], $line);

            try {
                $this->processRow($row);
                $imported++;
                $rows[] = $row['codigo'];
            } catch (\Throwable $e) {
                $errors[] = "{$row['codigo']}: {$e->getMessage()}";
            }
        }

        fclose($handle);

        $this->command->info("Imported: {$imported}");
        $this->command->info("Errors: " . count($errors));
        $this->command->info("Skipped: " . count($skipped));

        if (!empty($errors)) {
            $this->command->warn('--- ERRORS ---');
            foreach ($errors as $e) {
                $this->command->warn("  {$e}");
            }
        }

        if (!empty($skipped)) {
            $this->command->warn('--- SKIPPED ---');
            foreach ($skipped as $s) {
                $this->command->warn("  {$s}");
            }
        }
    }

    private function processRow(array $row): void
    {
        $ruleKey = trim($row['codigo']);
        $ruleType = $this->mapType(trim($row['tipo_regla']));
        $severity = $this->mapSeverity(trim($row['importancia']));
        $status = $this->mapStatus(trim($row['estado']));
        $rowRange = $this->parseRowRange(trim($row['rango_filas']));

        $config = [
            'sheet' => trim($row['formulario']),
            'section' => trim($row['seccion']),
            'column' => trim($row['columna']),
            'row_range' => $rowRange,
            'rule_logic' => trim($row['logica']),
            'formula' => trim($row['formula']),
        ];

        $metadata = [
            'source' => trim($row['fuente']),
            'observation' => trim($row['observacion']),
            'validated_by_statistics' => trim($row['validado_estadistica']),
            'statistics_comment' => trim($row['comentario_estadistica']),
        ];

        $config = array_filter($config, fn($v) => $v !== '');
        $metadata = array_filter($metadata, fn($v) => $v !== '');

        Rule::updateOrCreate(
            ['rule_key' => $ruleKey],
            [
                'rule_type' => $ruleType,
                'source' => 'csv_catalog',
                'name' => trim($row['nombre']),
                'description' => trim($row['descripcion']) ?: null,
                'category' => trim($row['formulario']),
                'severity' => $severity,
                'scope' => 'per_row',
                'config' => $config,
                'status' => $status,
                'version' => '1.0.0',
                'metadata' => !empty($metadata) ? $metadata : null,
            ]
        );
    }

    private function mapType(string $type): string
    {
        return self::TYPE_MAP[$type] ?? str_replace(' ', '_', strtolower($type));
    }

    private function mapSeverity(string $severity): string
    {
        return self::SEVERITY_MAP[$severity] ?? strtolower($severity);
    }

    private function mapStatus(string $status): string
    {
        return self::STATUS_MAP[$status] ?? strtolower($status);
    }

    private function parseRowRange(string $range): array
    {
        $range = trim($range);

        if (preg_match('/Fila\s+(\d+)/i', $range, $m)) {
            return ['from' => (int)$m[1], 'to' => (int)$m[1]];
        }

        if (preg_match('/Filas?\s+(\d+)\s*(?:-|–|—)\s*(\d+)/i', $range, $m)) {
            return ['from' => (int)$m[1], 'to' => (int)$m[2]];
        }

        return ['from' => 0, 'to' => 0];
    }
}
