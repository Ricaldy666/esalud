<?php

namespace App\Console\Commands;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RemParser\Services\MetadataExtractorService;
use App\Domain\RemParser\Services\SectionDetectorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Reemplaza o inserta UNICAMENTE las secciones solicitadas (--sections=F,G)
 * de una hoja dentro de una estructura existente, con el resultado del
 * parser corregido (SectionDetectorService). Todo lo demas -- las otras 26
 * hojas Y las demas secciones de la hoja objetivo -- queda byte-identico.
 *
 * Existe porque rem:rebuild-structure siempre reprocesa el workbook
 * completo (todas las hojas de la serie) en una sola pasada, y porque
 * reemplazar la hoja completa (version anterior de este comando) arrastraba
 * secciones que no tenian relacion con el bug corregido (ej. Seccion K de
 * A09, cuyo filaFinDatos cambiaba sin que filterAggregators() tuviera nada
 * que ver). Este comando aisla el reemplazo al nivel de seccion individual.
 *
 * Cada codigo solicitado se clasifica en REEMPLAZO (ya existe en la
 * estructura base) o INSERCION (no existe en la base pero SI lo detecta el
 * parser corregido -- hallazgo real de A05/A06/A24/A25/A26/A32, donde
 * builds anteriores omitieron secciones completas). La insercion respeta
 * la posicion real del Excel (ver insertSectionInFreshOrder()) y
 * deepDiffOutsideScope() sigue garantizando que ninguna otra seccion,
 * codigo o hoja cambie fuera de lo explicitamente solicitado.
 *
 * No modifica RemParserService ni el pipeline general de rebuild --
 * reutiliza SectionDetectorService directamente, la misma clase que ya usa
 * RemParserService::parse() internamente por hoja.
 */
class RemPatchSheetStructureCommand extends Command
{
    protected $signature = 'rem:patch-sheet-structure
                            {structure_id : ID de la estructura base en rem_template_structures}
                            {--serie= : Serie esperada de la estructura base (ej. A) -- validacion de seguridad}
                            {--year= : Anio esperado de la estructura base (ej. 2026) -- validacion de seguridad}
                            {--sheet= : Nombre de la hoja a parchear (ej. A09)}
                            {--sections= : Lista separada por comas de codigos de seccion a reemplazar o insertar dentro de la hoja (ej. F,G) -- obligatorio}
                            {--source= : Ruta explicita al archivo Excel fuente}
                            {--dry-run : Obligatorio para la primera validacion -- no persiste nada}';

    protected $description = 'Reemplaza o inserta unicamente las secciones indicadas (--sections) de una hoja, con el resultado del parser corregido, dejando el resto de esa hoja y las demas 26 hojas byte-identicas. Requiere --dry-run para validar antes de persistir.';

    public function handle(
        SectionDetectorService $sectionDetector,
        MetadataExtractorService $metadataExtractor,
    ): int {
        $structureId = (int) $this->argument('structure_id');
        $expectedSerie = $this->option('serie');
        $expectedYear = $this->option('year') !== null ? (int) $this->option('year') : null;
        $sheet = $this->option('sheet');
        $sectionsOption = $this->option('sections');
        $source = $this->option('source');
        $dryRun = (bool) $this->option('dry-run');

        if (! $expectedSerie || ! $expectedYear || ! $sheet || ! $source || ! $sectionsOption) {
            $this->error('Debe especificar --serie, --year, --sheet, --sections y --source explicitamente.');

            return self::FAILURE;
        }

        $requestedCodes = array_values(array_filter(array_map('trim', explode(',', $sectionsOption))));
        if (empty($requestedCodes)) {
            $this->error('--sections no puede resolver en una lista vacia de codigos.');

            return self::FAILURE;
        }

        $baseStructure = RemTemplateStructure::find($structureId);
        if (! $baseStructure) {
            $this->error("Estructura base ID {$structureId} no encontrada.");

            return self::FAILURE;
        }

        if ($baseStructure->serie !== $expectedSerie || (int) $baseStructure->anio !== $expectedYear) {
            $this->error(
                "La estructura base ID {$structureId} es {$baseStructure->serie}/{$baseStructure->anio}, ".
                "no coincide con --serie={$expectedSerie} --year={$expectedYear}."
            );

            return self::FAILURE;
        }

        if (! file_exists($source)) {
            $this->error("Archivo fuente no encontrado: {$source}");

            return self::FAILURE;
        }

        $reader = IOFactory::createReaderForFile($source);
        $reader->setReadDataOnly(false);
        $spreadsheet = $reader->load($source);

        try {
            $meta = $metadataExtractor->extract($spreadsheet, $source);
            if ($meta['serie'] !== $expectedSerie || $meta['anio'] !== $expectedYear) {
                $this->error(
                    "El archivo fuente no coincide con Serie {$expectedSerie} {$expectedYear} ".
                    '(detectado en el archivo: serie='.($meta['serie'] ?? 'null').', anio='.($meta['anio'] ?? 'null').').'
                );

                return self::FAILURE;
            }

            $worksheet = $spreadsheet->getSheetByName($sheet);
            if (! $worksheet) {
                $this->error("Hoja '{$sheet}' no encontrada en el archivo fuente.");

                return self::FAILURE;
            }

            $baseEstructura = is_string($baseStructure->estructura)
                ? json_decode($baseStructure->estructura, true)
                : $baseStructure->estructura;

            $baseFormIndex = null;
            foreach ($baseEstructura['forms'] ?? [] as $i => $form) {
                if (($form['sheetName'] ?? '') === $sheet) {
                    $baseFormIndex = $i;
                    break;
                }
            }

            if ($baseFormIndex === null) {
                $this->error("Hoja '{$sheet}' no existe en la estructura base ID {$structureId}.");

                return self::FAILURE;
            }

            $oldSectionsArray = $baseEstructura['forms'][$baseFormIndex]['sections'] ?? [];
            $oldCodesPresent = array_map(fn ($s) => $s['codigo'] ?? null, $oldSectionsArray);

            // --- Parsear la hoja completa con el detector corregido. ---
            $freshSections = $sectionDetector->detect($worksheet, $sheet);
            $freshByCode = [];
            $freshCodesInOrder = [];
            foreach ($freshSections as $s) {
                $freshByCode[$s->codigo] = $s->toArray();
                $freshCodesInOrder[] = $s->codigo;
            }

            // --- Clasificar cada codigo solicitado:
            //       - existe en la base Y el parser lo sigue detectando ->
            //         REEMPLAZO (comportamiento historico);
            //       - no existe en la base pero SI en el parser corregido ->
            //         INSERCION de una seccion ausente (nueva capacidad, ej.
            //         A26/A: omitida por completo en builds anteriores);
            //       - existe en la base pero el parser YA NO la detecta ->
            //         ELIMINACION (hallazgo real de A32, 2026-08-10:
            //         filterAggregators() ahora reconoce D/E/F como
            //         agregadores puros duplicados de D1/E1/F1, pero el
            //         codigo original ya no puede simplemente "reemplazarse"
            //         porque el parser no produce nada para ese codigo --
            //         antes de este fix, intentarlo insertaba una entrada
            //         null en la estructura, corrompiendola);
            //       - no existe en ninguno de los dos -> aborta. ---
            $codesToReplace = [];
            $codesToInsert = [];
            $codesToDelete = [];
            foreach ($requestedCodes as $code) {
                if (in_array($code, $oldCodesPresent, true)) {
                    if (isset($freshByCode[$code])) {
                        $codesToReplace[] = $code;
                    } else {
                        $codesToDelete[] = $code;
                    }
                } elseif (isset($freshByCode[$code])) {
                    $codesToInsert[] = $code;
                } else {
                    $this->error("La seccion '{$code}' solicitada no existe en la estructura base NI fue detectada por el parser corregido en el archivo fuente.");

                    return self::FAILURE;
                }
            }

            // --- Validacion de seguridad para cada ELIMINACION: solo se
            //     permite si otra seccion solicitada EN ESTE MISMO PATCH
            //     (reemplazo o insercion) comparte el filaHeader ORIGINAL de
            //     la seccion a eliminar -- esa es la misma señal que ya usa
            //     filterAggregators() para confirmar que es un duplicado
            //     puro de un agregador, no una eliminacion arbitraria. Sin
            //     esa confirmacion, se aborta -- nunca se elimina una
            //     seccion "porque si". ---
            foreach ($codesToDelete as $deleteCode) {
                $oldSection = null;
                foreach ($oldSectionsArray as $s) {
                    if (($s['codigo'] ?? null) === $deleteCode) {
                        $oldSection = $s;

                        break;
                    }
                }
                $oldFilaHeader = $oldSection['filaHeader'] ?? null;

                $absorbedBy = null;
                foreach (array_merge($codesToReplace, $codesToInsert) as $otherCode) {
                    $freshOther = $freshByCode[$otherCode] ?? null;
                    if ($freshOther !== null && $oldFilaHeader !== null && ($freshOther['filaHeader'] ?? null) === $oldFilaHeader) {
                        $absorbedBy = $otherCode;

                        break;
                    }
                }

                if ($absorbedBy === null) {
                    $this->error(
                        "La seccion '{$deleteCode}' existe en la base pero el parser corregido ya no la detecta, y ninguna otra ".
                        "seccion solicitada en este mismo patch (reemplazo/insercion) comparte su filaHeader original ({$oldFilaHeader}) ".
                        'para confirmar de forma segura que es un duplicado agregador. Abortando por seguridad -- si la eliminacion es '.
                        'intencional, incluya en --sections tambien el codigo que absorbe su rol (ej. E1 para eliminar E).'
                    );

                    return self::FAILURE;
                }
            }

            // --- Splice: las secciones existentes conservan su orden y
            //     contenido original, reemplazando solo las solicitadas para
            //     reemplazo y omitiendo las solicitadas para eliminacion. Las
            //     solicitadas para insercion se agregan en la posicion que
            //     les corresponde segun el orden real detectado en el Excel
            //     (ver insertSectionInFreshOrder()). ---
            $newSectionsArray = [];
            foreach ($oldSectionsArray as $section) {
                $code = $section['codigo'] ?? null;
                if (in_array($code, $codesToDelete, true)) {
                    continue;
                }
                $newSectionsArray[] = in_array($code, $codesToReplace, true)
                    ? $freshByCode[$code]
                    : $section;
            }

            foreach ($codesToInsert as $insertCode) {
                $newSectionsArray = $this->insertSectionInFreshOrder(
                    $newSectionsArray, $insertCode, $freshByCode, $freshCodesInOrder
                );
            }

            $patchedEstructura = $baseEstructura;
            $patchedEstructura['forms'][$baseFormIndex]['sections'] = $newSectionsArray;

            // --- Comparacion profunda: todo fuera de las secciones solicitadas
            //     debe ser identico -- otras hojas Y otras secciones de esta hoja. ---
            $diffOutsideScope = $this->deepDiffOutsideScope(
                $baseEstructura['forms'] ?? [],
                $patchedEstructura['forms'] ?? [],
                $sheet,
                $requestedCodes,
                $codesToDelete,
            );

            if (! empty($diffOutsideScope)) {
                $this->error("ABORTADO: se detectaron cambios fuera del alcance autorizado (hoja '{$sheet}', secciones [".implode(', ', $requestedCodes).']):');
                foreach ($diffOutsideScope as $d) {
                    $this->line("  - {$d}");
                }

                return self::FAILURE;
            }

            $newHash = $this->computeHash($patchedEstructura['forms'] ?? []);
            $newFormulasCount = $this->countFormulas($patchedEstructura['forms'] ?? []);

            $this->printReport(
                sheet: $sheet,
                requestedCodes: $requestedCodes,
                oldSections: $oldSectionsArray,
                newSections: $newSectionsArray,
                allForms: $patchedEstructura['forms'] ?? [],
                baseForms: $baseEstructura['forms'] ?? [],
                oldHash: $baseStructure->hash_estructura,
                newHash: $newHash,
                codesToDelete: $codesToDelete,
            );

            if ($dryRun) {
                $this->newLine();
                $this->warn('DRY-RUN: no se persistio ningun cambio. Estructura base ID '.$structureId.' permanece intacta.');

                return self::SUCCESS;
            }

            // --- Persistencia real (nueva version en draft) ---
            $newStructure = DB::transaction(function () use (
                $baseStructure,
                $patchedEstructura,
                $newHash,
                $newFormulasCount,
                $sheet,
                $requestedCodes,
                $codesToReplace,
                $codesToInsert,
                $codesToDelete,
                $structureId,
            ) {
                $versionNumber = (RemTemplateStructure::where('anio', $baseStructure->anio)
                    ->where('serie', $baseStructure->serie)
                    ->max('version_number') ?? 0) + 1;

                $partes = [];
                if (! empty($codesToReplace)) {
                    $partes[] = 'reemplazadas ['.implode(', ', $codesToReplace).']';
                }
                if (! empty($codesToInsert)) {
                    $partes[] = 'insertadas (ausentes en la estructura base) ['.implode(', ', $codesToInsert).']';
                }
                if (! empty($codesToDelete)) {
                    $partes[] = 'eliminadas (agregadores duplicados, absorbidas por otra seccion del mismo patch) ['.implode(', ', $codesToDelete).']';
                }

                $notes = 'Parche controlado: hoja '.$sheet.', secciones ['.implode(', ', $requestedCodes).'] '.implode(' / ', $partes);
                $notes .= " via rem:patch-sheet-structure. Base: estructura ID {$structureId} v{$baseStructure->version_number}.";

                return RemTemplateStructure::create([
                    'anio' => $baseStructure->anio,
                    'serie' => $baseStructure->serie,
                    'rem_template_id' => $baseStructure->rem_template_id,
                    'version_number' => $versionNumber,
                    'hash_estructura' => $newHash,
                    'estructura' => $patchedEstructura,
                    'metadata' => [
                        'apps' => [],
                        'formulas_count' => $newFormulasCount,
                        'total_rows_classification' => $baseStructure->metadata['total_rows_classification'] ?? [],
                    ],
                    'source_filename' => $baseStructure->source_filename,
                    'status' => 'draft',
                    'notes' => $notes,
                ]);
            });

            $this->newLine();
            $this->info("Nueva version creada: ID {$newStructure->id}, version {$newStructure->version_number}, status draft.");
            $this->line('Pendiente: rem:approve-structure y rem:activate-structure (fuera del alcance de este comando).');

            return self::SUCCESS;
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    /**
     * Compara la estructura base contra la parcheada y devuelve cualquier
     * diferencia fuera del alcance autorizado:
     *   - cualquier hoja distinta a $targetSheet debe ser byte-identica;
     *   - dentro de $targetSheet, la lista de codigos de seccion (y su
     *     orden) debe ser identica a la base -- no se permite agregar ni
     *     quitar secciones, solo reemplazar contenido de las solicitadas;
     *   - dentro de $targetSheet, cualquier seccion cuyo codigo NO este en
     *     $requestedCodes debe ser byte-identica a la base.
     *
     * $codesToDelete: codigos cuya desaparicion respecto a la base esta
     * explicitamente autorizada (ELIMINACION solicitada y ya validada en
     * handle() -- ver comentario de seguridad ahi). Sin esta lista, CUALQUIER
     * desaparicion se trata como una violacion de alcance.
     *
     * @return string[] lista de diferencias encontradas (vacia si todo OK)
     */
    private function deepDiffOutsideScope(array $oldForms, array $newForms, string $targetSheet, array $requestedCodes, array $codesToDelete = []): array
    {
        $diffs = [];

        $oldByName = [];
        foreach ($oldForms as $f) {
            $oldByName[$f['sheetName']] = $f;
        }
        $newByName = [];
        foreach ($newForms as $f) {
            $newByName[$f['sheetName']] = $f;
        }

        $allNames = array_unique(array_merge(array_keys($oldByName), array_keys($newByName)));

        foreach ($allNames as $name) {
            if (! isset($oldByName[$name])) {
                $diffs[] = "Hoja '{$name}' aparece en la nueva estructura pero no existia en la base";

                continue;
            }
            if (! isset($newByName[$name])) {
                $diffs[] = "Hoja '{$name}' desaparecio respecto a la estructura base";

                continue;
            }

            if ($name !== $targetSheet) {
                if (json_encode($oldByName[$name]) !== json_encode($newByName[$name])) {
                    $diffs[] = "Hoja '{$name}' cambio de contenido sin haber sido solicitada";
                }

                continue;
            }

            // Hoja objetivo: se permite que aparezcan codigos NUEVOS
            // unicamente si fueron solicitados explicitamente (insercion de
            // seccion ausente, ej. A26/A). Ningun codigo existente puede
            // desaparecer, y el orden RELATIVO de los codigos que ya
            // existian en la base (ignorando las inserciones) debe
            // mantenerse identico -- solo se permite intercalar, nunca
            // reordenar lo existente.
            $oldCodes = array_map(fn ($s) => $s['codigo'] ?? null, $oldByName[$name]['sections'] ?? []);
            $newCodes = array_map(fn ($s) => $s['codigo'] ?? null, $newByName[$name]['sections'] ?? []);

            $newlyAppeared = array_diff($newCodes, $oldCodes);
            $unauthorizedNew = array_diff($newlyAppeared, $requestedCodes);
            if (! empty($unauthorizedNew)) {
                $diffs[] = "Hoja '{$targetSheet}': aparecieron secciones nuevas no autorizadas: [".implode(', ', $unauthorizedNew).']';
            }

            $disappeared = array_diff($oldCodes, $newCodes);
            $unauthorizedDisappeared = array_diff($disappeared, $codesToDelete);
            if (! empty($unauthorizedDisappeared)) {
                $diffs[] = "Hoja '{$targetSheet}': desaparecieron secciones existentes sin autorizacion: [".implode(', ', $unauthorizedDisappeared).']';
            }

            $expectedOldCodesAfterDeletion = array_values(array_diff($oldCodes, $codesToDelete));
            $oldCodesStillPresentInOrder = array_values(array_intersect($newCodes, $oldCodes));
            if ($oldCodesStillPresentInOrder !== $expectedOldCodesAfterDeletion) {
                $diffs[] = "Hoja '{$targetSheet}': el orden relativo de las secciones existentes cambio (antes, sin las eliminadas: [".implode(', ', $expectedOldCodesAfterDeletion).'], despues (sin inserciones): ['.implode(', ', $oldCodesStillPresentInOrder).'])';
            }

            if (! empty($unauthorizedNew) || ! empty($unauthorizedDisappeared) || $oldCodesStillPresentInOrder !== $expectedOldCodesAfterDeletion) {
                continue;
            }

            $oldSectionsByCode = [];
            foreach ($oldByName[$name]['sections'] ?? [] as $s) {
                $oldSectionsByCode[$s['codigo'] ?? ''] = $s;
            }
            $newSectionsByCode = [];
            foreach ($newByName[$name]['sections'] ?? [] as $s) {
                $newSectionsByCode[$s['codigo'] ?? ''] = $s;
            }

            foreach ($oldSectionsByCode as $code => $oldSection) {
                if (in_array($code, $requestedCodes, true)) {
                    continue; // cambio autorizado
                }

                $newSection = $newSectionsByCode[$code] ?? null;
                if (json_encode($oldSection) !== json_encode($newSection)) {
                    $diffs[] = "Hoja '{$targetSheet}', seccion '{$code}': cambio sin haber sido solicitada (fuera de --sections=".implode(',', $requestedCodes).')';
                }
            }
        }

        $oldOrder = array_column($oldForms, 'sheetName');
        $newOrder = array_column($newForms, 'sheetName');
        if ($oldOrder !== $newOrder) {
            $diffs[] = 'El orden de las hojas cambio respecto a la estructura base';
        }

        return $diffs;
    }

    /**
     * Inserta una seccion nueva ($insertCode, ausente en la estructura base
     * pero detectada por el parser corregido) en la posicion que le
     * corresponde segun el orden REAL del Excel ($freshCodesInOrder) --
     * justo antes de la primera seccion, en ese mismo orden, que ya este
     * presente en $sections. Si ninguna esta presente (la seccion nueva es
     * la ultima del Excel), se agrega al final.
     *
     * Ej. A26: orden real = [A, A.1, B, C, D, E, F, G, H]; $sections trae
     * [A.1, B, C, D, E, F, G, H] (A ausente). Para insertar A: la primera
     * seccion despues de A en el orden real que ya esta en $sections es
     * A.1 -> A se inserta justo antes de A.1.
     */
    private function insertSectionInFreshOrder(array $sections, string $insertCode, array $freshByCode, array $freshCodesInOrder): array
    {
        $freshIndex = array_search($insertCode, $freshCodesInOrder, true);
        $currentCodes = array_column($sections, 'codigo');

        $insertBeforeCode = null;
        for ($i = $freshIndex + 1; $i < count($freshCodesInOrder); $i++) {
            if (in_array($freshCodesInOrder[$i], $currentCodes, true)) {
                $insertBeforeCode = $freshCodesInOrder[$i];

                break;
            }
        }

        $seccionNueva = $freshByCode[$insertCode];

        if ($insertBeforeCode === null) {
            $sections[] = $seccionNueva;

            return $sections;
        }

        $posicion = array_search($insertBeforeCode, $currentCodes, true);
        array_splice($sections, $posicion, 0, [$seccionNueva]);

        return $sections;
    }

    /**
     * Recalcula el hash con el mismo algoritmo y el mismo orden de
     * construccion del buffer que RemParserService::parse() (linea 48-54),
     * para que el resultado sea consistente con el hash que produciria un
     * parseo completo genuino si todas las hojas fueran identicas a las
     * que ya trae la estructura base.
     */
    private function computeHash(array $forms): string
    {
        $buffer = '';
        foreach ($forms as $form) {
            foreach ($form['sections'] ?? [] as $section) {
                $buffer .= $form['sheetName'].'|';
                $buffer .= ($section['codigo'] ?? 'IMPLICITA').'|';
                foreach ($section['fields'] ?? [] as $field) {
                    $buffer .= $field['letra'].':'.$field['label'].';';
                }
                $buffer .= "\n";
            }
        }

        return md5($buffer);
    }

    private function countFormulas(array $forms): int
    {
        $count = 0;
        foreach ($forms as $form) {
            foreach ($form['sections'] ?? [] as $section) {
                foreach ($section['fields'] ?? [] as $field) {
                    if (($field['reglaDetectada'] ?? null) !== null) {
                        $count++;
                    }
                }
            }
        }

        return $count;
    }

    private function printReport(
        string $sheet,
        array $requestedCodes,
        array $oldSections,
        array $newSections,
        array $allForms,
        array $baseForms,
        string $oldHash,
        string $newHash,
        array $codesToDelete = [],
    ): void {
        $this->newLine();
        $this->warn("========== PATCH DE SECCIONES [".implode(', ', $requestedCodes)."] EN HOJA '{$sheet}' — REPORTE ==========");

        $this->info('1. HASH');
        $this->table(['Propiedad', 'Valor'], [
            ['Hash anterior', $oldHash],
            ['Hash propuesto', $newHash],
        ]);

        $oldTotalSections = 0;
        $oldTotalFields = 0;
        foreach ($baseForms as $form) {
            $oldTotalSections += count($form['sections'] ?? []);
            foreach ($form['sections'] ?? [] as $sec) {
                $oldTotalFields += count($sec['fields'] ?? []);
            }
        }

        $newTotalSections = 0;
        $newTotalFields = 0;
        foreach ($allForms as $form) {
            $newTotalSections += count($form['sections'] ?? []);
            foreach ($form['sections'] ?? [] as $sec) {
                $newTotalFields += count($sec['fields'] ?? []);
            }
        }

        $this->info('2. TOTALES — TODA LA ESTRUCTURA (27 hojas)');
        $this->table(['Metrica', 'Antes', 'Despues'], [
            ['Secciones', $oldTotalSections, $newTotalSections],
            ['Campos', $oldTotalFields, $newTotalFields],
        ]);

        $this->info("3. DETALLE DE LA HOJA '{$sheet}' (todas sus secciones, autorizadas y no autorizadas)");
        $oldByCode = [];
        foreach ($oldSections as $s) {
            $oldByCode[$s['codigo'] ?? '(implicita)'] = $s;
        }
        $newByCode = [];
        foreach ($newSections as $s) {
            $newByCode[$s['codigo'] ?? '(implicita)'] = $s;
        }

        $allCodes = array_unique(array_merge(array_keys($oldByCode), array_keys($newByCode)));
        sort($allCodes);

        $rows = [];
        foreach ($allCodes as $code) {
            $old = $oldByCode[$code] ?? null;
            $new = $newByCode[$code] ?? null;

            $oldFields = $old ? count($old['fields'] ?? []) : '—';
            $newFields = $new ? count($new['fields'] ?? []) : '—';
            $oldLast = $old ? (end($old['fields'])['letra'] ?? '—') : '—';
            $newLast = $new ? (end($new['fields'])['letra'] ?? '—') : '—';
            $oldFilaFin = $old['filaFinDatos'] ?? '—';
            $newFilaFin = $new['filaFinDatos'] ?? '—';

            $autorizada = in_array($code, $requestedCodes, true);
            $estado = match (true) {
                ! $autorizada => 'sin cambios (fuera de alcance)',
                in_array($code, $codesToDelete, true) => 'ELIMINADA (autorizada)',
                json_encode($old) === json_encode($new) => 'sin cambios',
                default => 'CAMBIADA (autorizada)',
            };

            $rows[] = [$code, $estado, $oldFields, $newFields, $oldLast, $newLast, $oldFilaFin, $newFilaFin];
        }

        $this->table(
            ['Seccion', 'Estado', 'Campos antes', 'Campos despues', 'Hasta (antes)', 'Hasta (despues)', 'filaFinDatos antes', 'filaFinDatos despues'],
            $rows
        );

        $this->newLine();
        $this->info('4. CONFIRMACION');
        $otherSheetsCount = count($allForms) - 1;
        $otherSectionsCount = count($allCodes) - count($requestedCodes);
        $this->line("Las {$otherSheetsCount} hojas restantes fueron verificadas como byte-identicas a la estructura base.");
        $this->line("Las {$otherSectionsCount} secciones de '{$sheet}' fuera de [".implode(', ', $requestedCodes)."] fueron verificadas como byte-identicas a la estructura base (incluyendo filaInicioDatos/filaFinDatos).");
    }
}
