<?php

namespace Tests\Unit\RemParser\Services;

use App\Domain\RemParser\Services\MetadataExtractorService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\TestCase;

/**
 * REM BM -- FASE BM-3.1 (2026-09-14): cierra el gap encontrado durante
 * BM-3 -- MetadataExtractorService::extractFromFilename() detectaba
 * `serie="05"` para archivos reales de carga de establecimiento (ej.
 * "102302BM05.xlsm"), porque el fallback `/(\d+)$/` capturaba los digitos
 * finales (el MES) antes de intentar leer la hoja NOMBRE. Confirmado
 * contra archivos reales de storage/app/rem-uploads (no gitignorados como
 * fixture aqui -- las rutas de esos archivos no son portables entre
 * entornos/CI, por eso este test replica los valores REALES ya verificados
 * de esas celdas en vez de leer el .xlsm desde disco; la verificacion
 * directa contra los archivos reales se hizo aparte, ver checkpoint
 * CLAUDE.md).
 *
 * Patron real confirmado en storage/app/rem-uploads para las series con
 * cargas de establecimiento ya existentes: <codigo_deis><SERIE><mes>, sin
 * separador -- "102302BM05" (BM), "102412BM05" (BM), "102302A05"/
 * "102306A05" (A), "102412D05" (D). Sin ejemplos reales de carga de
 * establecimiento para BS/P en este repositorio -- solo existen sus
 * plantillas oficiales en blanco (recursos-rem/SBS_26_V1.1-2.xlsm,
 * recursos-rem/SP_26_V1.2-2.xlsm), que ya funcionaban correctamente ANTES
 * de este fix via SERIES_MAP (prefijo "SBS"/"SP" explicito) -- no
 * dependian del fallback roto, cubiertas igual aqui para no regresionar.
 */
class MetadataExtractorServiceTest extends TestCase
{
    private function service(): MetadataExtractorService
    {
        return new MetadataExtractorService();
    }

    // ── extractFromFilename(): patron real <codigo_deis><SERIE><mes> ────

    public function test_real_bm_filename_cesfam_guzman(): void
    {
        $meta = $this->service()->extractFromFilename('102302BM05.xlsm');
        $this->assertSame('BM', $meta['serie']);
    }

    public function test_real_bm_filename_posta_chanavayita(): void
    {
        $meta = $this->service()->extractFromFilename('102412BM05.xlsm');
        $this->assertSame('BM', $meta['serie']);
    }

    public function test_real_bm_filename_with_upload_timestamp_prefix(): void
    {
        // Nombre real tal cual queda en disco tras subirlo (RemUpload
        // antepone timestamp_ al nombre original) -- confirma que el
        // regex no depende de que el nombre empiece limpio.
        $meta = $this->service()->extractFromFilename('20260714195609_102302BM05.xlsm');
        $this->assertSame('BM', $meta['serie']);
    }

    public function test_real_d_filename(): void
    {
        $meta = $this->service()->extractFromFilename('102412D05.xlsm');
        $this->assertSame('D', $meta['serie']);
    }

    public function test_real_a_filename_does_not_regress(): void
    {
        $meta = $this->service()->extractFromFilename('102302A05.xlsm');
        $this->assertSame('A', $meta['serie']);
    }

    public function test_real_a_filename_second_establishment(): void
    {
        $meta = $this->service()->extractFromFilename('102306A05.xlsm');
        $this->assertSame('A', $meta['serie']);
    }

    // ── extractFromFilename(): plantillas oficiales (SERIES_MAP, ya OK) ──

    public function test_official_template_serie_a(): void
    {
        $meta = $this->service()->extractFromFilename('SA_26_V1.2-2.xlsm');
        $this->assertSame('A', $meta['serie']);
    }

    public function test_official_template_serie_bm(): void
    {
        $meta = $this->service()->extractFromFilename('SBM_26_V1.1-2.xlsm');
        $this->assertSame('BM', $meta['serie']);
    }

    public function test_official_template_serie_bs(): void
    {
        $meta = $this->service()->extractFromFilename('SBS_26_V1.1-2.xlsm');
        $this->assertSame('BS', $meta['serie']);
    }

    public function test_official_template_serie_d(): void
    {
        $meta = $this->service()->extractFromFilename('SD_26_V1.1-2.xlsm');
        $this->assertSame('D', $meta['serie']);
    }

    public function test_official_template_serie_p(): void
    {
        $meta = $this->service()->extractFromFilename('SP_26_V1.2-2.xlsm');
        $this->assertSame('P', $meta['serie']);
    }

    // ── Casos negativos: un sufijo numerico NUNCA es una serie ───────────

    public function test_pure_timestamp_filename_never_yields_a_serie(): void
    {
        $meta = $this->service()->extractFromFilename('20260710185444.xlsm');
        $this->assertNull($meta['serie']);
    }

    public function test_filename_ending_in_year_never_yields_a_serie(): void
    {
        $meta = $this->service()->extractFromFilename('reporte_2026.xlsm');
        $this->assertNull($meta['serie']);
    }

    public function test_filename_ending_in_arbitrary_digits_never_yields_a_serie(): void
    {
        $meta = $this->service()->extractFromFilename('archivo123.xlsm');
        $this->assertNull($meta['serie']);
    }

    public function test_filename_ending_in_month_like_suffix_without_valid_serie_letter(): void
    {
        // Ojo: sin ninguna de las 5 series real (A/BM/BS/D/P) embebida,
        // "05" nunca debe convertirse en serie -- este es exactamente el
        // bug original, reproducido aqui de forma aislada.
        $meta = $this->service()->extractFromFilename('reporte05.xlsm');
        $this->assertNull($meta['serie']);
    }

    public function test_filename_with_test_suffix_after_month_falls_through(): void
    {
        // "102306A05_E2E_TEST.xlsm" -- ejemplo real de storage. No termina
        // en digitos (termina en "TEST"), asi que el fallback de filename
        // no aplica en absoluto (igual antes y despues del fix) -- cae a
        // NOMBRE, sin cambios de comportamiento, no es una regresion.
        $meta = $this->service()->extractFromFilename('102306A05_E2E_TEST.xlsm');
        $this->assertNull($meta['serie']);
    }

    // ── extract(): fallback a NOMBRE cuando el filename no basta ─────────

    private function spreadsheetWithNombre(string $serieCelda, ?int $anio = 2026): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);
        $ws = $spreadsheet->createSheet();
        $ws->setTitle('NOMBRE');
        if ($anio !== null) {
            $ws->setCellValue('B7', $anio);
        }
        $ws->setCellValue('B17', $serieCelda);
        $ws->setCellValue('B3', 'ESTABLECIMIENTO DE PRUEBA');
        $ws->setCellValue('B6', 'MAYO');

        return $spreadsheet;
    }

    public function test_unrecognizable_filename_falls_back_to_nombre_sheet_bm(): void
    {
        // Replica el valor REAL confirmado en NOMBRE!B17 de los 2 archivos
        // BM reales ("SERIE BM ESTABLECIMIENTOS AREA MUNICIPAL").
        $spreadsheet = $this->spreadsheetWithNombre('SERIE BM ESTABLECIMIENTOS AREA MUNICIPAL');

        $meta = $this->service()->extract($spreadsheet, 'archivo_sin_serie_reconocible.xlsm');

        $this->assertSame('BM', $meta['serie']);
        $this->assertSame(2026, $meta['anio']);
    }

    public function test_nombre_sheet_with_unrecognizable_serie_text_never_assigns_garbage(): void
    {
        $spreadsheet = $this->spreadsheetWithNombre('SERIE XX DESCONOCIDA');

        $meta = $this->service()->extract($spreadsheet, 'archivo_sin_serie_reconocible.xlsm');

        $this->assertNull($meta['serie']);
    }

    public function test_valid_filename_serie_takes_priority_over_nombre_sheet(): void
    {
        // Si el filename ya identifica una serie valida, NOMBRE ni se
        // consulta para ese campo (extract() ya lo hace asi -- solo se
        // confirma que el fix no invirtio esa prioridad).
        $spreadsheet = $this->spreadsheetWithNombre('SERIE BS OTRA COSA');

        $meta = $this->service()->extract($spreadsheet, '102302BM05.xlsm');

        $this->assertSame('BM', $meta['serie']);
    }
}
