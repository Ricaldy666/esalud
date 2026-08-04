<?php

namespace Tests\Feature\REM;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RemParser\Services\StructurePersistenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StructurePersistenceServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixturePath = base_path('tests/fixtures/rem-certification/a01/A01_UPLOAD53_ORIGINAL.xlsm');
    }

    public function test_persists_structure_as_new_version(): void
    {
        $service = app(StructurePersistenceService::class);
        $result = $service->persistFromFile($this->fixturePath, 'A', 2026, 'test.xlsm', 'draft');

        $this->assertNotNull($result);
        $this->assertEquals(2026, $result->anio);
        $this->assertEquals('A', $result->serie);
        $this->assertEquals(1, $result->version_number); // first version in test DB
        $this->assertEquals('draft', $result->status);
        $this->assertEquals('c53f499b586b8d7fc6e0849cbcd384ad', $result->hash_estructura);
    }

    public function test_increments_version_number(): void
    {
        $service = app(StructurePersistenceService::class);

        $v1 = $service->persistFromFile($this->fixturePath, 'B', 2026, 'v1.xlsm', 'draft');
        $v2 = $service->persistFromFile($this->fixturePath, 'B', 2026, 'v2.xlsm', 'approved');

        $this->assertEquals(1, $v1->version_number);
        $this->assertEquals(2, $v2->version_number);
        $this->assertEquals('draft', $v1->status);
        $this->assertEquals('approved', $v2->status);
    }

    public function test_persisted_structure_contains_12_a01_sections(): void
    {
        $service = app(StructurePersistenceService::class);
        $result = $service->persistFromFile($this->fixturePath, 'A', 2026, 'test.xlsm', 'draft');

        $est = $result->estructura;
        $a01Form = collect($est['forms'])->firstWhere('sheetName', 'A01');
        $this->assertNotNull($a01Form, 'A01 form should exist');

        $sections = $a01Form['sections'] ?? [];
        $this->assertCount(12, $sections, 'A01 should have exactly 12 sections');

        // Check section codes are preserved (including G., G.1, G.2, H., H.1, H.2)
        $codes = array_map(fn ($s) => $s['codigo'] ?? '', $sections);
        $this->assertContains('G.', $codes);
        $this->assertContains('G.1', $codes);
        $this->assertContains('G.2', $codes);
        $this->assertContains('H.', $codes);
        $this->assertContains('H.1', $codes);
        $this->assertContains('H.2', $codes);
    }

    public function test_metadata_is_persisted(): void
    {
        $service = app(StructurePersistenceService::class);
        $result = $service->persistFromFile($this->fixturePath, 'A', 2026, 'test.xlsm', 'draft');

        $est = $result->estructura;
        $this->assertArrayHasKey('_metadata', $est);
        $this->assertEquals('2.0', $est['_metadata']['extractor_version']);
        $this->assertEquals('c53f499b586b8d7fc6e0849cbcd384ad', $est['_metadata']['source_hash']);
        $this->assertArrayHasKey('total_rows', $est['_metadata']);
        $this->assertArrayHasKey('formula_count', $est['_metadata']);
        $this->assertArrayHasKey('highest_row', $est['_metadata']);
        $this->assertArrayHasKey('highest_col', $est['_metadata']);
    }

    public function test_total_rows_classification_has_16_entries(): void
    {
        $service = app(StructurePersistenceService::class);
        $result = $service->persistFromFile($this->fixturePath, 'A', 2026, 'test.xlsm', 'draft');

        $totalRows = $result->estructura['_metadata']['total_rows'];
        $this->assertCount(16, $totalRows);

        // 7 should be total_real (with SUM formulas)
        $totalReales = array_filter($totalRows, fn ($t) => $t['classification'] === 'total_real');
        $this->assertCount(7, $totalReales);

        // Remaining 9 should be header/section/label types
        $headers = array_filter($totalRows, fn ($t) => $t['classification'] !== 'total_real');
        $this->assertCount(9, $headers);
    }

    public function test_section_row_types_are_included(): void
    {
        $service = app(StructurePersistenceService::class);
        $result = $service->persistFromFile($this->fixturePath, 'A', 2026, 'test.xlsm', 'draft');

        $a01Form = collect($result->estructura['forms'])->firstWhere('sheetName', 'A01');
        $nonEmptyCount = 0;
        foreach ($a01Form['sections'] as $section) {
            $this->assertArrayHasKey('_row_types', $section);
            if (! empty($section['_row_types'])) {
                $nonEmptyCount++;
            }
        }
        $this->assertCount(12, $a01Form['sections']);
        $this->assertEquals(12, $nonEmptyCount);
    }

    public function test_h2_section_has_correct_boundaries(): void
    {
        $service = app(StructurePersistenceService::class);
        $result = $service->persistFromFile($this->fixturePath, 'A', 2026, 'test.xlsm', 'draft');

        $a01Form = collect($result->estructura['forms'])->firstWhere('sheetName', 'A01');
        $h2 = collect($a01Form['sections'])->firstWhere('codigo', 'H.2');
        $this->assertNotNull($h2, 'H.2 section should exist');
        $this->assertNotNull($h2['filaFinDatos'], 'H.2 filaFinDatos should not be null');
        $this->assertEquals(187, $h2['filaInicioDatos']);
        $this->assertGreaterThanOrEqual(200, $h2['filaFinDatos']);
        for ($r = 189; $r <= 199; $r++) {
            $this->assertArrayHasKey((string)$r, $h2['_row_types'], "Row $r should be in H.2 row_types");
        }
        $this->assertEquals('total', $h2['_row_types']['200'] ?? null);
    }

    public function test_hash_preserved_when_xlsm_unchanged(): void
    {
        $service = app(StructurePersistenceService::class);
        $result = $service->persistFromFile($this->fixturePath, 'A', 2026, 'test.xlsm', 'draft');
        $this->assertEquals('c53f499b586b8d7fc6e0849cbcd384ad', $result->hash_estructura);
    }

    public function test_v1_unchanged(): void
    {
        $service = app(StructurePersistenceService::class);
        $result = $service->persistFromFile($this->fixturePath, 'E', 2026, 'test.xlsm', 'draft');
        $records = RemTemplateStructure::where('serie', 'E')->where('anio', 2026)->get();
        $this->assertCount(1, $records);
        $this->assertEquals(1, $result->version_number);
    }

    public function test_does_not_overwrite_previous_active_version(): void
    {
        $service = app(StructurePersistenceService::class);

        $active = $service->persistFromFile($this->fixturePath, 'C', 2026, 'active-v1.xlsm', 'active');
        $draft = $service->persistFromFile($this->fixturePath, 'C', 2026, 'draft-v2.xlsm', 'draft');

        // Original active version should remain untouched
        $activeReloaded = RemTemplateStructure::find($active->id);
        $this->assertNotNull($activeReloaded);
        $this->assertEquals('active', $activeReloaded->status);

        // Draft is separate, not superseding
        $draftReloaded = RemTemplateStructure::find($draft->id);
        $this->assertEquals('draft', $draftReloaded->status);
    }

    public function test_persist_is_idempotent(): void
    {
        $service = app(StructurePersistenceService::class);

        $first = $service->persistFromFile($this->fixturePath, 'D', 2026, 'same.xlsm', 'draft');
        $second = $service->persistFromFile($this->fixturePath, 'D', 2026, 'same.xlsm', 'draft');

        $this->assertEquals(1, $first->version_number);
        $this->assertEquals(2, $second->version_number);
        $this->assertEquals($first->hash_estructura, $second->hash_estructura);

        // Both should exist
        $this->assertNotNull(RemTemplateStructure::find($first->id));
        $this->assertNotNull(RemTemplateStructure::find($second->id));
    }

    public function test_metadata_contains_formula_details(): void
    {
        $service = app(StructurePersistenceService::class);
        $result = $service->persistFromFile($this->fixturePath, 'A', 2026, 'test.xlsm', 'draft');

        $est = $result->estructura['_metadata'];

        // Verify some specific total_real rows have formulas
        $totalRows = $est['total_rows'];
        foreach ($totalRows as $tr) {
            if ($tr['classification'] === 'total_real') {
                $this->assertTrue($tr['has_sum_formula']);
                $this->assertNotEmpty($tr['formulas']);
            }
        }

        // Row 111 should have SUM formulas
        $row111 = collect($totalRows)->firstWhere('row', 111);
        $this->assertNotNull($row111);
        $this->assertEquals('total_real', $row111['classification']);
        $this->assertStringStartsWith('=SUM(', $row111['formulas']['C']);
        $this->assertStringStartsWith('=SUM(', $row111['formulas']['D']);
    }
}
