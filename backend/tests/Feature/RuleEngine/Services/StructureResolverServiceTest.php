<?php

namespace Tests\Feature\RuleEngine\Services;

use App\Domain\HealthCenters\Models\HealthCenter;
use App\Domain\REM\Models\RemUpload;
use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Services\StructureResolverService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StructureResolverServiceTest extends TestCase
{
    use RefreshDatabase;

    private StructureResolverService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new StructureResolverService;
    }

    private function createHealthCenter(): int
    {
        return HealthCenter::create([
            'name' => 'Test Center',
            'code_deis' => 'TC001',
            'type' => 'CESFAM',
        ])->id;
    }

    private function createUser(): int
    {
        return User::factory()->create()->id;
    }

    private function createUpload(array $overrides = []): RemUpload
    {
        return RemUpload::create(array_merge([
            'rem_type' => 'A',
            'year' => 2026,
            'month' => 1,
            'status' => 'pending',
            'health_center_id' => $this->createHealthCenter(),
            'user_id' => $this->createUser(),
            'original_filename' => 'test.xlsx',
            'stored_path' => 'rem/2026/01/test.xlsx',
            'file_size' => 1234,
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ], $overrides));
    }

    public function test_resolve_returns_latest_version(): void
    {
        RemTemplateStructure::create([
            'serie' => 'A',
            'anio' => 2026,
            'version_number' => 1,
            'estructura' => ['forms' => []],
            'status' => 'active',
            'hash_estructura' => 'hash_v1',
        ]);

        $structureV2 = RemTemplateStructure::create([
            'serie' => 'A',
            'anio' => 2026,
            'version_number' => 2,
            'estructura' => ['forms' => []],
            'status' => 'active',
            'hash_estructura' => 'hash_v2',
        ]);

        $upload = $this->createUpload(['rem_type' => 'A']);

        $result = $this->service->resolve($upload);

        $this->assertSame($structureV2->id, $result);
    }

    public function test_resolve_returns_null_when_no_match(): void
    {
        $upload = $this->createUpload(['rem_type' => 'X']);

        $result = $this->service->resolve($upload);

        $this->assertNull($result);
    }

    public function test_resolve_uses_rem_type_as_serie(): void
    {
        $structure = RemTemplateStructure::create([
            'serie' => 'B',
            'anio' => 2026,
            'version_number' => 1,
            'estructura' => ['forms' => []],
            'status' => 'active',
            'hash_estructura' => 'hash_b',
        ]);

        $upload = $this->createUpload(['rem_type' => 'B']);

        $result = $this->service->resolve($upload);

        $this->assertSame($structure->id, $result);
    }

    public function test_same_input_returns_same_structure(): void
    {
        RemTemplateStructure::create([
            'serie' => 'A', 'anio' => 2026,
            'version_number' => 2, 'status' => 'active',
            'estructura' => ['forms' => []],
            'hash_estructura' => 'h1',
        ]);
        RemTemplateStructure::create([
            'serie' => 'A', 'anio' => 2026,
            'version_number' => 3, 'status' => 'active',
            'estructura' => ['forms' => []],
            'hash_estructura' => 'h2',
        ]);

        $upload = $this->createUpload(['rem_type' => 'A']);

        $results = [];
        for ($i = 0; $i < 5; $i++) {
            $results[] = $this->service->resolve($upload);
        }

        $this->assertSame(1, count(array_unique($results)));
    }

    public function test_active_structure_has_priority_over_draft(): void
    {
        RemTemplateStructure::create([
            'serie' => 'A', 'anio' => 2026,
            'version_number' => 2, 'status' => 'draft',
            'estructura' => ['forms' => []],
            'hash_estructura' => 'h1',
        ]);
        $active = RemTemplateStructure::create([
            'serie' => 'A', 'anio' => 2026,
            'version_number' => 1, 'status' => 'active',
            'estructura' => ['forms' => []],
            'hash_estructura' => 'h2',
        ]);

        $upload = $this->createUpload(['rem_type' => 'A']);

        $result = $this->service->resolve($upload);

        $this->assertSame($active->id, $result);
    }

    public function test_stable_with_null_version_number(): void
    {
        RemTemplateStructure::create([
            'serie' => 'X', 'anio' => 2025,
            'version_number' => 1, 'status' => 'active',
            'estructura' => ['forms' => []],
            'hash_estructura' => 'h1',
        ]);
        $s2 = RemTemplateStructure::create([
            'serie' => 'X', 'anio' => 2025,
            'version_number' => 2, 'status' => 'active',
            'estructura' => ['forms' => []],
            'hash_estructura' => 'h2',
        ]);

        $upload = $this->createUpload(['rem_type' => 'X', 'year' => 2025]);

        $results = [];
        for ($i = 0; $i < 5; $i++) {
            $results[] = $this->service->resolve($upload);
        }

        $this->assertSame(1, count(array_unique($results)));
        $this->assertSame($s2->id, $results[0]);
    }

    public function test_fallback_to_draft_when_no_active(): void
    {
        $draft = RemTemplateStructure::create([
            'serie' => 'Z', 'anio' => 2025,
            'version_number' => 1, 'status' => 'draft',
            'estructura' => ['forms' => []],
            'hash_estructura' => 'h1',
        ]);

        $upload = $this->createUpload(['rem_type' => 'Z', 'year' => 2025]);

        $result = $this->service->resolve($upload);

        $this->assertSame($draft->id, $result);
    }
}
