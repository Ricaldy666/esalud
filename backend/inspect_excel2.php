<?php
require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$path = 'C:\Users\INFORMATICA\Desktop\documentacion estadistica aps\recursos-rem\2026-05-mayo\102302-cesfam-guzman\102302A05.xlsm';
$spreadsheet = IOFactory::load($path);

function colLetter($i) {
    $l = '';
    while ($i > 0) {
        $i--;
        $l = chr(65 + ($i % 26)) . $l;
        $i = (int)($i / 26);
    }
    return $l;
}

$a01 = $spreadsheet->getSheetByName('A01');
echo '=== A01 SECCIÓN B (filas 33-39) ===' . PHP_EOL;
for ($row = 33; $row <= 39; $row++) {
    $rowData = [];
    for ($c = 1; $c <= 26; $c++) {
        $val = $a01->getCell(colLetter($c) . $row)->getValue();
        if ($val !== null && $val !== '') {
            $rowData[] = colLetter($c) . '=' . substr((string)$val, 0, 25);
        }
    }
    if (!empty($rowData)) echo 'F' . $row . ': ' . implode(' | ', $rowData) . PHP_EOL;
}

echo PHP_EOL . '=== A01 SECCIÓN D (filas 67-74) ===' . PHP_EOL;
for ($row = 67; $row <= 74; $row++) {
    $rowData = [];
    for ($c = 1; $c <= 26; $c++) {
        $val = $a01->getCell(colLetter($c) . $row)->getValue();
        if ($val !== null && $val !== '') {
            $rowData[] = colLetter($c) . '=' . substr((string)$val, 0, 25);
        }
    }
    if (!empty($rowData)) echo 'F' . $row . ': ' . implode(' | ', $rowData) . PHP_EOL;
}

$a05 = $spreadsheet->getSheetByName('A05');
echo PHP_EOL . '=== A05 SECCIÓN E (filas 87-89) ===' . PHP_EOL;
for ($row = 87; $row <= 89; $row++) {
    $rowData = [];
    for ($c = 1; $c <= 15; $c++) {
        $val = $a05->getCell(colLetter($c) . $row)->getValue();
        if ($val !== null && $val !== '') {
            $rowData[] = colLetter($c) . '=' . substr((string)$val, 0, 25);
        }
    }
    if (!empty($rowData)) echo 'F' . $row . ': ' . implode(' | ', $rowData) . PHP_EOL;
}
