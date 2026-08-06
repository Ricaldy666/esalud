<?php

namespace Database\Seeders;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Siembra rem_template_structures desde database/seeders/data/rem-template-structures.json
 * (generado localmente por `php artisan rem:export-seed-data`). Idempotente
 * por clave natural (anio, serie, version_number) -- correr varias veces
 * actualiza en vez de duplicar.
 *
 * Dos pasadas porque superseded_by_id es auto-referencial: la primera crea
 * o actualiza todas las filas sin ese campo; la segunda lo completa
 * resolviendo la clave natural del fixture contra los IDs ya asignados en
 * ESTE entorno (que no tienen por que coincidir con los IDs locales).
 *
 * approved_by tambien se resuelve por email contra la tabla users de este
 * mismo entorno -- si no hay un usuario con ese email, queda null en vez de
 * fallar (un approved_by huerfano es preferible a bloquear todo el seeder).
 */
class RemTemplateStructureSeeder extends Seeder
{
    // Comprimido: sin comprimir pesa ~62 MB (17 versiones con estructuras de
    // 27 hojas casi identicas entre si); gzip lo reduce a ~2 MB.
    private const FIXTURE_PATH = 'database/seeders/data/rem-template-structures.json.gz';

    public function run(): void
    {
        $path = base_path(self::FIXTURE_PATH);
        if (! file_exists($path)) {
            $this->command?->error("Fixture no encontrado: {$path}. Corra 'php artisan rem:export-seed-data' en el entorno local primero.");

            return;
        }

        $rows = json_decode(gzdecode(file_get_contents($path)), true);
        if (! is_array($rows)) {
            $this->command?->error("Fixture invalido: {$path}");

            return;
        }

        $created = 0;
        $updated = 0;

        // --- Pasada 1: crear/actualizar todo excepto superseded_by_id ---
        foreach ($rows as $row) {
            $existing = RemTemplateStructure::where([
                'anio' => $row['anio'],
                'serie' => $row['serie'],
                'version_number' => $row['version_number'],
            ])->exists();

            $approvedBy = $row['approved_by_email']
                ? User::where('email', $row['approved_by_email'])->first()?->id
                : null;

            RemTemplateStructure::updateOrCreate(
                [
                    'anio' => $row['anio'],
                    'serie' => $row['serie'],
                    'version_number' => $row['version_number'],
                ],
                [
                    'hash_estructura' => $row['hash_estructura'],
                    'estructura' => $row['estructura'],
                    'metadata' => $row['metadata'] ?? null,
                    'source_filename' => $row['source_filename'] ?? null,
                    'status' => $row['status'],
                    'approved_at' => $row['approved_at'] ?? null,
                    'approved_by' => $approvedBy,
                    'notes' => $row['notes'] ?? null,
                    'rem_upload_id' => null,
                    'rem_template_id' => null,
                ]
            );

            $existing ? $updated++ : $created++;
        }

        // --- Pasada 2: resolver superseded_by_id via clave natural ---
        $linked = 0;
        foreach ($rows as $row) {
            if (empty($row['superseded_by'])) {
                continue;
            }

            $current = RemTemplateStructure::where([
                'anio' => $row['anio'],
                'serie' => $row['serie'],
                'version_number' => $row['version_number'],
            ])->first();

            $target = RemTemplateStructure::where([
                'anio' => $row['superseded_by']['anio'],
                'serie' => $row['superseded_by']['serie'],
                'version_number' => $row['superseded_by']['version_number'],
            ])->first();

            if ($current && $target) {
                $current->update(['superseded_by_id' => $target->id]);
                $linked++;
            }
        }

        $this->command?->info("rem_template_structures: {$created} creadas, {$updated} actualizadas, {$linked} enlaces superseded_by resueltos.");
    }
}
