<?php

namespace App\Domain\RemParser\Services;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Services\CellDataStorageService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

class CellScanOrchestrator
{
    private EnhancedCellScanner $cellScanner;
    private CellDataStorageService $cellDataStorage;

    public function __construct(
        ?EnhancedCellScanner $cellScanner = null,
        ?CellDataStorageService $cellDataStorage = null,
    ) {
        $this->cellScanner = $cellScanner ?? new EnhancedCellScanner();
        $this->cellDataStorage = $cellDataStorage ?? new CellDataStorageService();
    }

    public function scan(
        string $sheet,
        string $section,
        ?int $structureId = null,
    ): array {
        $structureId = $structureId ?? $this->findActiveStructureId();
        $structure = RemTemplateStructure::withTrashed()->findOrFail($structureId);

        $filePath = $this->resolveFilePath($structure);
        if (!$filePath) {
            throw new \RuntimeException("No se pudo resolver la ruta del archivo XLSM para la estructura {$structureId}");
        }

        $spreadsheet = $this->loadSpreadsheet($filePath);
        try {
            $worksheet = $spreadsheet->getSheetByName($sheet);
            if (!$worksheet) {
                throw new \RuntimeException("Hoja '{$sheet}' no encontrada en el archivo XLSM");
            }

            $est = $structure->estructura;
            $sectionData = $this->findSectionInStructure($est, $sheet, $section);
            if (!$sectionData) {
                throw new \RuntimeException("Sección '{$section}' no encontrada en hoja '{$sheet}'");
            }

            $cells = $this->cellScanner->scanForSection($worksheet, $sectionData);

            $this->cellDataStorage->saveCellData($sheet, $section, $cells);

            return $cells;
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    public function scanAllSections(string $sheet): array
    {
        $structureId = $this->findActiveStructureId();
        $structure = RemTemplateStructure::withTrashed()->findOrFail($structureId);

        $filePath = $this->resolveFilePath($structure);
        if (!$filePath) {
            throw new \RuntimeException("No se pudo resolver la ruta del archivo XLSM");
        }

        $spreadsheet = $this->loadSpreadsheet($filePath);
        try {
            $worksheet = $spreadsheet->getSheetByName($sheet);
            if (!$worksheet) {
                throw new \RuntimeException("Hoja '{$sheet}' no encontrada");
            }

            $est = $structure->estructura;
            $form = $this->findFormInStructure($est, $sheet);
            if (!$form) {
                throw new \RuntimeException("Formulario '{$sheet}' no encontrado en estructura");
            }

            $results = [];
            foreach ($form['sections'] ?? [] as $sectionData) {
                $sectionCode = $sectionData['codigo'] ?? 'IMPLICITA';
                $cells = $this->cellScanner->scanForSection($worksheet, $sectionData);
                $this->cellDataStorage->saveCellData($sheet, $sectionCode, $cells);
                $results[$sectionCode] = count($cells);
            }

            return $results;
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    private function findActiveStructureId(): int
    {
        $structure = RemTemplateStructure::where('anio', 2026)
            ->where('serie', 'A')
            ->where('status', 'active')
            ->first();

        if (!$structure) {
            throw new \RuntimeException('No hay estructura activa para 2026/Serie A');
        }

        return $structure->id;
    }

    private function resolveFilePath(RemTemplateStructure $structure): ?string
    {
        if ($structure->rem_upload_id) {
            $upload = $structure->remUpload;
            if ($upload && $upload->stored_path) {
                $path = storage_path("app/rem-uploads/{$upload->stored_path}");
                if (file_exists($path)) {
                    return $path;
                }
            }
        }

        if ($structure->source_filename) {
            $legacyPath = storage_path("app/rem-uploads/{$structure->source_filename}");
            if (file_exists($legacyPath)) {
                return $legacyPath;
            }
        }

        $baseDir = storage_path('app/rem-uploads');
        if (is_dir($baseDir)) {
            $xlsmFiles = [];
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($baseDir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && str_ends_with(strtolower($file->getFilename()), '.xlsm')) {
                    $xlsmFiles[] = $file->getRealPath();
                }
            }
            usort($xlsmFiles, fn($a, $b) => strcmp($a, $b));
            foreach ($xlsmFiles as $path) {
                if (str_contains(strtoupper(basename($path)), 'SA_') || str_contains(strtoupper(basename($path)), 'SERIE A')) {
                    return $path;
                }
            }
            if (!empty($xlsmFiles)) {
                return $xlsmFiles[0];
            }
        }

        return null;
    }

    private function findSectionInStructure(array $est, string $sheet, string $section): ?array
    {
        $form = $this->findFormInStructure($est, $sheet);
        if (!$form) return null;

        foreach ($form['sections'] ?? [] as $sec) {
            $secCode = $sec['codigo'] ?? '';
            if (strtolower(trim($secCode)) === strtolower(trim($section))) {
                return $sec;
            }
        }
        return null;
    }

    private function findFormInStructure(array $est, string $sheet): ?array
    {
        foreach ($est['forms'] ?? [] as $form) {
            if (strtoupper($form['sheetName'] ?? '') === strtoupper($sheet)) {
                return $form;
            }
        }
        return null;
    }

    private function loadSpreadsheet(string $path): Spreadsheet
    {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(false);
        $reader->setIncludeCharts(false);
        return $reader->load($path);
    }
}
