<?php

namespace App\Domain\RemParser\Services;

use App\Domain\REM\Models\RemTemplate;
use App\Domain\RemParser\Exceptions\RemTemplateConfigGenerationException;
use App\Domain\RemParser\Models\RemTemplateStructure;
use Illuminate\Support\Facades\DB;

/**
 * BM-3.5 (2026-09-14): mecanismo OFICIAL, versionado y generico para
 * derivar rem_templates.config (el "gate"/fallback grueso por hoja que
 * consume App\Domain\REM\Services\RemParserService::parseSheet() -- ver
 * auditoria BM-3.4) desde una RemTemplateStructure ya parseada. Reemplaza
 * la dependencia del comando local no versionado
 * temp:create-template-config -- sin copiar sus defectos (ver mas abajo).
 * Generico para cualquier serie (A/BM/BS/D/P), sin hardcodes por serie.
 *
 * DECISION DE DISENO (fusion de columnas entre secciones): el comando
 * viejo fusionaba fields de TODAS las secciones de una hoja en un unico
 * array 'columns' con "primer valor visto gana" -- silenciosamente
 * arbitrario. La auditoria BM-3.4 (y la verificacion real contra BM18/A-D
 * mas abajo) confirmo que en una hoja REM multi-seccion real (ej. BM18,
 * 4 secciones) es NORMAL y ESPERADO que la misma letra de columna
 * represente conceptos distintos en cada seccion (reutilizacion de la
 * misma grilla fisica) -- exigir que "semanticas" coincidan entre TODAS
 * las secciones de la hoja haria el generador inutilizable para cualquier
 * hoja REM real de mas de una seccion, no solo para casos excepcionales.
 * Por eso 'columns'/'concept_column'/'total_column' se derivan
 * EXCLUSIVAMENTE de la PRIMERA seccion de la hoja -- exactamente el mismo
 * criterio que el comando viejo ya aplicaba (sin cambiarlo) para
 * 'header_row'/'data_start_row', extendido aqui de forma consistente en
 * vez de intentar un merge sheet-wide inherentemente ambiguo. Con esto,
 * NO hay merge entre secciones -> NO hay conflicto de columnas posible
 * desde datos reales (cada letra aparece a lo sumo una vez dentro de una
 * misma seccion, por construccion de ColumnDetectorService).
 *
 * El unico conflicto de columnas real que SI se detecta y bloquea (ver
 * assertNoDuplicateLettersWithinSection()) es una letra duplicada DENTRO
 * de la misma seccion con datos incompatibles -- nunca deberia ocurrir
 * con el parser actual, pero es la unica forma de "semantica incompatible
 * de columna" genuina y verificable sin inventar un umbral arbitrario;
 * sirve como guarda defensiva contra un 'estructura' corrupto/editado a
 * mano. Cubierta con fixture sintetica en tests (nunca ocurre con datos
 * reales).
 *
 * 'data_end_row' SI sigue tomandose de la ULTIMA seccion (un limite, no
 * una semantica de columna -- sin ambiguedad posible).
 *
 * total_column: se exige un campo esTotal=true DENTRO de la primera
 * seccion -- si no existe, se falla explicito
 * (RemTemplateConfigGenerationException), nunca se hardcodea 'B' como
 * hacia el comando viejo.
 *
 * concept_column: el modelo actual (ParsedFieldDTO/estructura JSON) NO
 * tiene ninguna marca explicita "es la columna de concepto" -- se
 * documenta aqui el fallback (ver deriveConceptColumn()): la PRIMERA
 * columna, de izquierda a derecha, de la primera seccion que no sea
 * esTotal ni esControlOculto. Mas principled que un hardcode ciego de 'A'
 * (coincide con 'A' en todas las hojas REM reales auditadas hasta ahora
 * porque la columna de concepto SIEMPRE es la mas a la izquierda, pero no
 * asume la letra 'A' en si). Si ninguna columna califica, falla explicito
 * -- nunca asume.
 *
 * professional_column: nunca se deriva aqui (queda null, igual que el
 * comando viejo) -- el modelo tampoco tiene marca explicita para esto, y
 * la resolucion real de esta columna para el parseo fino ya existe en
 * App\Domain\REM\Services\RemParserService::firstProfessionalIdentifierColumn()
 * (heuristica de label "medico"/"matron..." sobre la seccion calibrada) --
 * no se duplica esa logica aqui para el config grueso, que solo actua
 * como fallback previo a que exista una RemTemplateStructure activa.
 */
class RemTemplateConfigGeneratorService
{
    private const ALLOWED_STATUSES = ['draft', 'approved', 'active'];

    /**
     * Construye el config -- 100% puro, sin leer/escribir nada mas alla de
     * $structure (ya cargada), sin efectos secundarios. Lanza
     * RemTemplateConfigGenerationException ante cualquier ambiguedad --
     * nunca hay escritura parcial porque nunca hay escritura aqui. Usable
     * directamente para simulacion/dry-run in-memory.
     */
    public function buildConfig(RemTemplateStructure $structure): array
    {
        $this->assertAllowedStatus($structure);

        $estructura = $structure->estructura ?? [];
        $sheets = [];

        foreach ($estructura['forms'] ?? [] as $form) {
            $sheetName = $form['sheetName'] ?? null;
            $sections = $form['sections'] ?? [];

            if ($sheetName === null || $sheetName === '' || empty($sections)) {
                continue;
            }

            $sheets[] = $this->buildSheetConfig($sheetName, $sections);
        }

        return ['sheets' => $sheets];
    }

    /**
     * Genera el config (buildConfig(), puro) y lo persiste -- unica parte
     * con efectos secundarios, atomica: si algo falla durante la
     * construccion (antes de este metodo, o dentro de el antes de abrir
     * la transaccion) no se escribe nada; si algo falla DENTRO de la
     * transaccion, Laravel revierte ambas escrituras (rem_templates +
     * rem_template_structures.rem_template_id) -- nunca queda config o
     * enlace parcial.
     *
     * Idempotente: mismo structure_id ejecutado 2 veces produce el mismo
     * config (funcion pura de $structure->estructura) y actualiza -- nunca
     * duplica -- el mismo RemTemplate (updateOrCreate por year+rem_type,
     * la misma clave natural ya usada por RemTemplateSeeder/el comando
     * viejo).
     */
    public function generateAndPersist(RemTemplateStructure $structure): RemTemplate
    {
        $config = $this->buildConfig($structure);

        return DB::transaction(function () use ($structure, $config) {
            $template = RemTemplate::where('year', $structure->anio)
                ->where('rem_type', $structure->serie)
                ->first();

            if ($template) {
                // Solo se toca 'config' -- version/is_active de un RemTemplate
                // ya existente (ej. sembrado por RemTemplateSeeder) quedan
                // intactos, nunca se sobreescriben aqui.
                $template->update(['config' => $config]);
            } else {
                $template = RemTemplate::create([
                    'year' => $structure->anio,
                    'rem_type' => $structure->serie,
                    'version' => 'V1.0',
                    'is_active' => true,
                    'config' => $config,
                ]);
            }

            if ($structure->rem_template_id !== $template->id) {
                $structure->rem_template_id = $template->id;
                $structure->save();
            }

            return $template;
        });
    }

    private function buildSheetConfig(string $sheetName, array $sections): array
    {
        $firstSection = $sections[0];
        $lastSection = $sections[array_key_last($sections)];

        $firstFields = $firstSection['fields'] ?? [];
        $this->assertNoDuplicateLettersWithinSection($sheetName, $firstSection['codigo'] ?? null, $firstFields);

        $headerRow = $firstSection['filaHeader'] ?? null;
        $dataStartRow = $firstSection['filaInicioDatos'] ?? null;
        $dataEndRow = $lastSection['filaFinDatos'] ?? null;

        if ($headerRow === null || $dataStartRow === null) {
            throw new RemTemplateConfigGenerationException(
                "Hoja '{$sheetName}': la primera seccion no tiene filaHeader/filaInicioDatos validos -- no se puede derivar header_row/data_start_row de forma segura."
            );
        }

        return [
            'sheet_name' => $sheetName,
            // BM-3.5A (2026-09-14): la auditoria BM-3.4 concluyo -- incompleta --
            // que 'section_code' no era consumido por el parser real. Trazando
            // el flujo completo hasta ProcessRemUploadJob::handle() se confirmo
            // lo contrario: RemParserService::parseSheet() linea ~689 solo
            // asigna $entry['section'] cuando $sheetConfig['section_code'] esta
            // presente, y ProcessRemUploadJob usa EXACTAMENTE ese valor
            // ($entry['section'] ?? 'unknown') para poblar la columna real
            // rem_data.section -- la clave que el resto del pipeline completo
            // (RuleEngineService::execute() via $grouped=$remData->groupBy('section'),
            // SectionCalibrationMatrixService, etc.) usa para agrupar filas por
            // hoja. Omitirlo dejaria TODAS las filas con section='unknown',
            // perdiendo la atribucion de hoja -- no es cosmetico. Se corrige
            // aqui con el valor ya trivialmente disponible (section_code
            // coincide con sheet_name en el 100% de las 27 hojas reales de
            // Serie A verificadas). 'title' SI se reconfirmo sin ningun uso
            // en todo App\Domain\REM -- no se agrega.
            'section_code' => $sheetName,
            'is_required' => true,
            'columns' => array_map(
                fn(array $f) => ['letter' => $f['letra'], 'label' => $f['label']],
                $firstFields
            ),
            'structure' => [
                'header_row' => $headerRow,
                'data_start_row' => $dataStartRow,
                'data_end_row' => $dataEndRow,
                'concept_column' => $this->deriveConceptColumn($sheetName, $firstFields),
                'professional_column' => null,
                'total_column' => $this->deriveTotalColumn($sheetName, $firstFields),
            ],
            'validation_rules' => [
                'data_type' => 'integer',
                'allow_null' => true,
                'min' => 0,
            ],
        ];
    }

    /**
     * Unico conflicto de columnas real y verificable: la misma letra
     * aparece MAS DE UNA VEZ dentro de la MISMA seccion con datos
     * incompatibles (label distinto o esTotal distinto). Nunca ocurre con
     * el parser actual (ColumnDetectorService produce como maximo 1 field
     * por letra por seccion) -- guarda defensiva contra 'estructura'
     * corrupta/editada a mano, nunca elige silenciosamente una de las dos.
     */
    private function assertNoDuplicateLettersWithinSection(string $sheetName, ?string $sectionCode, array $fields): void
    {
        $seen = [];
        foreach ($fields as $f) {
            $letra = $f['letra'];
            $huella = ($f['label'] ?? '') . '|' . (!empty($f['esTotal']) ? '1' : '0');

            if (isset($seen[$letra]) && $seen[$letra] !== $huella) {
                $seccionLabel = $sectionCode ?? 'IMPLICITA';
                throw new RemTemplateConfigGenerationException(
                    "Hoja '{$sheetName}' seccion '{$seccionLabel}': la columna '{$letra}' aparece mas de una vez con datos incompatibles (conflicto de columna) -- estructura corrupta, no se genera config parcial."
                );
            }

            $seen[$letra] = $huella;
        }
    }

    /**
     * Ver docblock de clase -- primera columna (izquierda a derecha) que
     * no sea esTotal ni esControlOculto. Falla explicito si ninguna
     * califica -- nunca asume 'A'.
     */
    private function deriveConceptColumn(string $sheetName, array $fields): string
    {
        foreach ($fields as $f) {
            if (empty($f['esTotal']) && empty($f['esControlOculto'])) {
                return $f['letra'];
            }
        }

        throw new RemTemplateConfigGenerationException(
            "Hoja '{$sheetName}': ninguna columna de la primera seccion califica como columna de concepto (todas son esTotal/esControlOculto) -- no se puede derivar concept_column de forma segura."
        );
    }

    /**
     * Ver docblock de clase -- primer campo esTotal=true dentro de la
     * primera seccion. Falla explicito si no existe ninguno -- nunca
     * hardcodea 'B'.
     */
    private function deriveTotalColumn(string $sheetName, array $fields): string
    {
        foreach ($fields as $f) {
            if (!empty($f['esTotal'])) {
                return $f['letra'];
            }
        }

        throw new RemTemplateConfigGenerationException(
            "Hoja '{$sheetName}': la primera seccion no tiene ninguna columna marcada esTotal -- no se puede derivar total_column de forma segura sin inventar un valor."
        );
    }

    private function assertAllowedStatus(RemTemplateStructure $structure): void
    {
        if (!in_array($structure->status, self::ALLOWED_STATUSES, true)) {
            throw new RemTemplateConfigGenerationException(
                "Estructura ID {$structure->id}: status '{$structure->status}' no permitido para generar config (permitidos: " . implode(', ', self::ALLOWED_STATUSES) . "). Una estructura 'superseded' generaria config obsoleto sobre el RemTemplate compartido por year+serie."
            );
        }
    }
}
