<?php

namespace Tests\Unit\RemParser\Services;

use App\Domain\RemParser\Services\SectionDetectorService;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\TestCase;

/**
 * Hallazgo A30 (2026-08-18): un marcador "SECCION ..." guardado por Excel
 * con formato mixto dentro de la celda (parte del texto en negrita/otro
 * color) se lee via PhpSpreadsheet como un objeto RichText, no como string
 * -- el `is_string($valor)` usado en los 4 puntos de deteccion de marcador
 * fallaba silenciosamente, dejando el marcador invisible sin ningun error.
 * Caso real: A30 fila 94, "SECCION D: TELEINTERCONSULTA ODONTOLOGICA",
 * jamas genero una seccion D independiente -- sus filas quedaron
 * absorbidas dentro de la seccion C anterior.
 */
class SectionDetectorServiceRichTextMarkerTest extends TestCase
{
    private function service(): SectionDetectorService
    {
        return new SectionDetectorService();
    }

    private function sheet(): array
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('HOJATEST');

        return [$spreadsheet, $sheet];
    }

    private function richText(string $boldPart, string $restPart): RichText
    {
        $rt = new RichText();
        $bold = $rt->createTextRun($boldPart);
        $bold->getFont()->setBold(true);
        $rt->createText($restPart);

        return $rt;
    }

    /**
     * Caso 1 (control, no regresion): marcador como string normal sigue
     * detectandose exactamente igual que antes del fix.
     */
    public function test_section_marker_as_plain_string_is_detected(): void
    {
        [, $ws] = $this->sheet();
        $ws->setCellValue('A1', 'SECCION A: BLOQUE UNO');
        $ws->setCellValue('A2', 'Concepto');
        $ws->setCellValue('B2', 'Total');
        $ws->setCellValue('A3', 'Fila uno');
        $ws->setCellValue('B3', 1);

        $sections = $this->service()->detect($ws, 'HOJATEST');
        $codigos = array_map(fn ($s) => $s->codigo, $sections);

        $this->assertSame(['A'], $codigos);
    }

    /**
     * Caso 2 (el bug real): marcador guardado como RichText -- antes del
     * fix, esta seccion nunca aparecia. Con el fix, debe detectarse igual
     * que si fuera un string plano.
     */
    public function test_section_marker_stored_as_richtext_is_detected(): void
    {
        [, $ws] = $this->sheet();
        $ws->getCell('A1')->setValue($this->richText('SECCION D: ', 'TELEINTERCONSULTA ODONTOLOGICA'));
        $ws->setCellValue('A2', 'Concepto');
        $ws->setCellValue('B2', 'Total');
        $ws->setCellValue('A3', 'Endodoncia');
        $ws->setCellValue('B3', 2);

        $sections = $this->service()->detect($ws, 'HOJATEST');
        $codigos = array_map(fn ($s) => $s->codigo, $sections);

        $this->assertSame(['D'], $codigos, 'El marcador RichText debe generar una seccion D, igual que un string plano.');
    }

    /**
     * Caso 3: una seccion ya abierta debe cortarse en seco al toparse con
     * un marcador de la SIGUIENTE seccion, incluso si ese marcador esta
     * guardado como RichText -- replica exacta del bug de A30 (C se
     * extendia hasta la fila 108 en vez de cortar en 93 al llegar al
     * marcador RichText de D en la fila 94).
     */
    public function test_open_section_is_hard_cut_by_a_richtext_marker(): void
    {
        [, $ws] = $this->sheet();
        $ws->setCellValue('A1', 'SECCION C: BLOQUE PRIMARIO');
        $ws->setCellValue('A2', 'Concepto');
        $ws->setCellValue('B2', 'Total');
        $ws->setCellValue('A3', 'Fila C uno');
        $ws->setCellValue('B3', 1);
        $ws->setCellValue('A4', 'Fila C dos');
        $ws->setCellValue('B4', 2);
        $ws->getCell('A5')->setValue($this->richText('SECCION D: ', 'BLOQUE SECUNDARIO'));
        $ws->setCellValue('A6', 'Concepto');
        $ws->setCellValue('B6', 'Total');
        $ws->setCellValue('A7', 'Fila D uno');
        $ws->setCellValue('B7', 9);

        $sections = $this->service()->detect($ws, 'HOJATEST');
        $codigos = array_map(fn ($s) => $s->codigo, $sections);

        $this->assertSame(['C', 'D'], $codigos);

        $c = current(array_filter($sections, fn ($s) => $s->codigo === 'C'));
        $this->assertLessThan(
            5,
            $c->filaFinDatos,
            'C no debe extenderse hasta el marcador RichText de D (fila 5) ni mas alla.'
        );

        $d = current(array_filter($sections, fn ($s) => $s->codigo === 'D'));
        $this->assertNotNull($d, 'D debe existir como seccion independiente.');
    }

    /**
     * Caso 4 (falso positivo): texto no relacionado guardado como RichText
     * (formato mixto por cualquier otro motivo de estilo) NO debe generar
     * una seccion nueva solo por ser un objeto RichText -- el regex sigue
     * siendo la unica condicion real, la normalizacion de tipo no debe
     * relajar el criterio de contenido.
     */
    public function test_unrelated_richtext_does_not_produce_false_positive_section(): void
    {
        [, $ws] = $this->sheet();
        $ws->setCellValue('A1', 'SECCION A: BLOQUE UNO');
        $ws->setCellValue('A2', 'Concepto');
        $ws->setCellValue('B2', 'Total');
        $ws->getCell('A3')->setValue($this->richText('Fila con ', 'formato mixto, no es un marcador'));
        $ws->setCellValue('B3', 5);
        $ws->setCellValue('A4', 'Fila normal');
        $ws->setCellValue('B4', 3);

        $sections = $this->service()->detect($ws, 'HOJATEST');
        $codigos = array_map(fn ($s) => $s->codigo, $sections);

        $this->assertSame(['A'], $codigos, 'Texto RichText no relacionado con "SECCION" no debe generar una seccion nueva.');
    }
}
