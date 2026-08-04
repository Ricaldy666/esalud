<?php

namespace Tests\Feature\REM;

use App\Domain\REM\Models\RemTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RemUploadPreviewVersionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $fixturePath;
    private string $fixtureOriginalName;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'Analista']);
        $this->user = User::factory()->create();
        $this->user->assignRole('Analista');
        Sanctum::actingAs($this->user);

        $this->fixturePath = base_path('tests/fixtures/rem-certification/a01/A01_UPLOAD53_ORIGINAL.xlsm');
        $this->fixtureOriginalName = 'fixture_upload.xlsm';
    }

    private function uploadPreview(): \Illuminate\Testing\TestResponse
    {
        $file = new UploadedFile(
            $this->fixturePath,
            $this->fixtureOriginalName,
            'application/vnd.ms-excel.sheet.macroEnabled.12',
            null,
            true // test mode
        );

        return $this->postJson('/api/v1/rem-uploads/preview', [
            'file' => $file,
        ]);
    }

    public function test_response_has_version_fields(): void
    {
        RemTemplate::create([
            'year' => 2026,
            'rem_type' => 'A',
            'version' => 'V1.0',
            'config' => ['sheets' => []],
            'is_active' => true,
        ]);

        $response = $this->uploadPreview();

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'version_detected',
                'version_active',
                'version_status',
            ],
        ]);
    }

    public function test_version_status_current_when_matches_active_template(): void
    {
        RemTemplate::create([
            'year' => 2026,
            'rem_type' => 'A',
            'version' => 'V1.0',
            'config' => ['sheets' => []],
            'is_active' => true,
        ]);

        $response = $this->uploadPreview();
        $data = $response->json('data');

        $this->assertSame('REM 2026', $data['version_detected']);
        $this->assertSame('REM 2026', $data['version_active']);
        $this->assertSame('current', $data['version_status']);
    }

    public function test_version_status_outdated_when_file_is_older_than_active_template(): void
    {
        RemTemplate::create([
            'year' => 2026,
            'rem_type' => 'A',
            'version' => 'V2.0',
            'config' => ['sheets' => []],
            'is_active' => true,
        ]);

        // The fixture file has year 2026. We need a scenario where
        // the active template year is NEWER than the file's year.
        // Since the fixture is 2026, we change strategy:
        // Create a template with year 2027 so it becomes "newer"
        RemTemplate::create([
            'year' => 2027,
            'rem_type' => 'A',
            'version' => 'V1.0',
            'config' => ['sheets' => []],
            'is_active' => true,
        ]);

        $response = $this->uploadPreview();
        $data = $response->json('data');

        $this->assertSame('REM 2026', $data['version_detected']);
        $this->assertSame('REM 2027', $data['version_active']);
        $this->assertSame('outdated', $data['version_status']);

        $errors = $data['errors'] ?? [];
        $hasVersionError = collect($errors)->contains(
            fn ($e) => str_contains($e, 'no corresponde a la plantilla vigente')
        );
        $this->assertTrue($hasVersionError, 'Expected version mismatch error');
    }

    public function test_version_status_no_template_when_no_active_template_exists(): void
    {
        $response = $this->uploadPreview();
        $data = $response->json('data');

        $this->assertSame('REM 2026', $data['version_detected']);
        $this->assertNull($data['version_active']);
        $this->assertSame('no_template', $data['version_status']);

        $errors = $data['errors'] ?? [];
        $hasError = collect($errors)->contains(
            fn ($e) => str_contains($e, 'No hay una plantilla activa')
        );
        $this->assertTrue($hasError, 'Expected no template error');
    }

    public function test_version_status_unknown_when_year_not_detectable(): void
    {
        // Create a minimal xlsx without NOMBRE sheet
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'test');
        $sheet->setTitle('DATA');

        $tempPath = tempnam(sys_get_temp_dir(), 'preview_test_no_year_') . '.xlsx';
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($tempPath);

        $file = new UploadedFile(
            $tempPath,
            'no_year_file.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );

        $response = $this->postJson('/api/v1/rem-uploads/preview', [
            'file' => $file,
        ]);

        $data = $response->json('data');
        $this->assertSame('No identificada', $data['version_detected']);
        $this->assertNull($data['version_active']);
        $this->assertSame('unknown', $data['version_status']);

        $errors = $data['errors'] ?? [];
        $hasError = collect($errors)->contains(
            fn ($e) => str_contains($e, 'No se pudo identificar la versión')
        );
        $this->assertTrue($hasError, 'Expected unknown version error');

        unlink($tempPath);
    }
}
