<?php

namespace App\Domain\RemParser\Services;

use App\Domain\RemParser\DTOs\ParsedTemplateDTO;
use App\Domain\RemParser\DTOs\ParsedFormDTO;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

class RemParserService
{
    private MetadataExtractorService $metadataExtractor;
    private SheetDetectorService $sheetDetector;
    private SectionDetectorService $sectionDetector;

    public function __construct(
        ?MetadataExtractorService $metadataExtractor = null,
        ?SheetDetectorService $sheetDetector = null,
        ?SectionDetectorService $sectionDetector = null,
    ) {
        $this->metadataExtractor = $metadataExtractor ?? new MetadataExtractorService();
        $this->sheetDetector = $sheetDetector ?? new SheetDetectorService();
        $this->sectionDetector = $sectionDetector ?? new SectionDetectorService();
    }

    public function parse(string $filePath): ParsedTemplateDTO
    {
        $spreadsheet = $this->loadSpreadsheet($filePath);

        try {
            $meta = $this->metadataExtractor->extract($spreadsheet, $filePath);

            $validSheetNames = $this->sheetDetector->detect($spreadsheet);

            $forms = [];
            $structureBuffer = '';

            foreach ($validSheetNames as $sheetName) {
                $worksheet = $spreadsheet->getSheetByName($sheetName);

                $sections = $this->sectionDetector->detect($worksheet, $sheetName);

                $forms[] = new ParsedFormDTO(
                    sheetName: $sheetName,
                    sections: $sections,
                );

                foreach ($sections as $section) {
                    $structureBuffer .= $sheetName . '|';
                    $structureBuffer .= ($section->codigo ?? 'IMPLICITA') . '|';
                    foreach ($section->fields as $field) {
                        $structureBuffer .= $field->letra . ':' . $field->label . ';';
                    }
                    $structureBuffer .= "\n";
                }
            }

            $hashEstructura = md5($structureBuffer);

            return new ParsedTemplateDTO(
                anio: $meta['anio'],
                serie: $meta['serie'],
                hashEstructura: $hashEstructura,
                forms: $forms,
            );
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    private function loadSpreadsheet(string $path): Spreadsheet
    {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(false);
        $reader->setIncludeCharts(false);
        return $reader->load($path);
    }
}
