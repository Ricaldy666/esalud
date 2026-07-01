<?php
require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$path = 'C:\Users\INFORMATICA\Desktop\documentacion estadistica aps\recursos-rem\2026-05-mayo\102302-cesfam-guzman\102302A05.xlsm';
$spreadsheet = IOFactory::load($path);

foreach (['A01', 'A05'] as $sheetName) {
    $sheet = $spreadsheet->getSheetByName($sheetName);
    if (!$sheet) { echo $sheetName . ' no encontrada' . PHP_EOL; continue; }

    echo PHP_EOL . '=== ' . $sheetName . ' — ESTRUCTURA COMPLETA ===' . PHP_EOL;
    $highestRow = $sheet->getHighestRow();
    $currentSection = null;
    $sectionStart = null;

    for ($row = 1; $row <= $highestRow; $row++) {
        $valA = (string)$sheet->getCell('A' . $row)->getValue();
        $valB = (string)$sheet->getCell('B' . $row)->getValue();

        if (preg_match('/^SECCI[OÓ][NÑ]\s+([A-Z][\.\d]*)/u', $valA, $m) ||
            preg_match('/^SECCI[OÓ][NÑ]\s+([A-Z][\.\d]*)/u', $valB, $m)) {
            if ($currentSection && $sectionStart) {
                echo $currentSection . ': filas ' . $sectionStart . '–' . ($row-1) . PHP_EOL;
            }
            $currentSection = $m[1];
            $sectionStart = $row;
        }
    }
    if ($currentSection) {
        echo $currentSection . ': filas ' . $sectionStart . '–' . $highestRow . PHP_EOL;
    }
}
