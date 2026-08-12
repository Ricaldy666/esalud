# Herramienta de Certificación Funcional del Catálogo REM

## Objetivo

Permitir la revisión y certificación manual, una regla a la vez, del catálogo completo de reglas del Rule Engine para la Serie A 2026.

No modifica reglas, no ejecuta migraciones, no altera el Rule Engine.

---

## Comando

```bash
php artisan rule:certify [opciones]
```

### Opciones

| Opción | Descripción | Default |
|--------|-------------|---------|
| `--sheet=` | Filtrar por hoja (ej: A01, A02...) | A01 |
| `--rule=` | Rule_key específica para certificar | — |
| `--type=` | Filtrar por tipo (`sum_equals`, `required_and_le_parent`) | — |
| `--output=` | Formato de salida: `table`, `json`, `card` | table |
| `--interactive` | Modo interactivo uno por uno (default si no se especifica output) | false |
| `--export` | Exportar todas las fichas a JSON | false |
| `--stats` | Mostrar estadísticas de certificación | false |

---

## Modo Interactivo

```bash
php artisan rule:certify --sheet=A01
```

Navega regla por regla mostrando una ficha completa con:

- rule_key, tipo, severidad
- Hoja, sección, descripción funcional
- Columnas origen y destino
- Rango de filas
- Fórmula interpretada en lenguaje natural
- Evidencia encontrada en el XLSM (estructura parseada)
- Evidencia del Manual REM (para completar manualmente)
- Estado actual

### Comandos disponibles

| Comando | Descripción |
|---------|-------------|
| `n` / `next` | Siguiente regla |
| `p` / `prev` | Regla anterior |
| `c` / `certificar` | Marcar como **Certificada** |
| `r` / `revisar` | Marcar como **Requiere revisión** |
| `o` / `obs` | Agregar/editar observaciones |
| `s` / `stats` | Mostrar estadísticas |
| `q` / `quit` | Salir |
| `?` / `help` | Mostrar ayuda |

### Estados de certificación

- ⏳ **Pendiente** — No revisada aún
- ✅ **Certificada** — Regla verificada y correcta
- 🔍 **Requiere revisión** — Regla con observaciones o dudas

---

## Modo no interactivo

### Tabla resumen

```bash
php artisan rule:certify --sheet=A01 --output=table
```

### Ficha completa

```bash
php artisan rule:certify --rule=a01_a_c_sum_equals --output=card
```

### JSON

```bash
php artisan rule:certify --sheet=A01 --output=json
```

### Exportar catálogo completo

```bash
php artisan rule:certify --export
```

Genera `storage/app/certificacion/serie-a-fichas-completas.json` con las 529 fichas.

### Estadísticas

```bash
php artisan rule:certify --stats
```

---

## Ficha de Certificación (campos)

| Campo | Descripción |
|-------|-------------|
| `rule_key` | Identificador único de la regla |
| `rule_type` | Tipo: `sum_equals` o `required_and_le_parent` |
| `severity` | `error` o `warning` |
| `hoja` | Hoja del XLSM (A01–A34) |
| `seccion` | Código de sección dentro de la hoja |
| `descripcion` | Descripción funcional de la regla |
| `columnas_origen` | Columnas que se suman o comparan |
| `columna_destino` | Columna destino o total |
| `rango_filas` | Filas donde aplica la regla |
| `formula_interpretada` | Fórmula en lenguaje natural |
| `evidencia_xlsm` | Datos extraídos de la estructura parseada del XLSM |
| `evidencia_manual_rem` | Manualmente completado durante certificación |
| `estado` | Pendiente / Certificada / Requiere revisión |
| `observaciones` | Notas del certificador |

---

## Almacenamiento

El estado de certificación se guarda en:

```
storage/app/certificacion/serie-a-catalogo.json
```

Estructura del archivo:

```json
{
    "a01_a_c_sum_equals": {
        "estado": "Certificada",
        "observaciones": "",
        "certificado_por": "",
        "certificado_en": "2026-07-15T12:00:00+00:00"
    },
    "a01_d_i_sum_equals": {
        "estado": "Requiere revisión",
        "observaciones": "Verificar columnas origen en el manual",
        "certificado_por": "",
        "certificado_en": "2026-07-15T12:05:00+00:00"
    }
}
```

No se requieren migraciones ni nuevas tablas en la base de datos.

---

## Reglas por hoja (Serie A)

| Hoja | Reglas | Tipos |
|------|--------|-------|
| A01 | 22 | sum_equals |
| A02 | 4 | sum_equals |
| A03 | 11 | sum_equals |
| A04 | 25 | sum_equals |
| A05 | 27 | sum_equals |
| A06 | 31 | sum_equals |
| A07 | 34 | sum_equals |
| A08 | 10 | sum_equals |
| A09 | 48 | sum_equals |
| A11 | 0 | — |
| A11a | 44 | sum_equals + required_and_le_parent |
| A19a | 5 | sum_equals |
| A19b | 19 | sum_equals |
| A21 | 34 | sum_equals |
| A23 | 17 | sum_equals |
| A24 | 10 | sum_equals |
| A25 | 27 | sum_equals |
| A26 | 26 | sum_equals |
| A27 | 24 | sum_equals |
| A28 | 27 | sum_equals |
| A29 | 5 | sum_equals |
| A30 | 1 | sum_equals |
| A30AR | 7 | sum_equals |
| A31 | 27 | sum_equals |
| A32 | 49 | sum_equals |
| A33 | 2 | sum_equals |
| A34 | 6 | sum_equals |

**Total: 529 reglas** (497 sum_equals + 32 required_and_le_parent)

> Nota: Las reglas `required_and_le_parent` (32) están concentradas en la hoja A11a.

---

## Archivos de la herramienta

| Archivo | Propósito |
|---------|-----------|
| `backend/app/Domain/RuleEngine/Services/CertificationService.php` | Servicio de certificación: carga de reglas, fichas, evidencia, persistencia |
| `backend/app/Console/Commands/RuleCertifyCommand.php` | Comando CLI interactivo y no interactivo |
| `storage/app/certificacion/serie-a-catalogo.json` | Estado de certificación (se crea al certificar) |
| `storage/app/certificacion/serie-a-fichas-completas.json` | Exportación completa de fichas (con --export) |

---

## Flujo de trabajo recomendado

1. **Comenzar con A01**: `php artisan rule:certify --sheet=A01`
2. **Revisar cada regla**: verificar que la fórmula interpretada coincida con el XLSM y el Manual REM
3. **Certificar o marcar**: usar `c` (certificada) o `r` (requiere revisión)
4. **Agregar observaciones**: documentar discrepancias con `o`
5. **Avanzar por hoja**: al terminar A01, continuar con A02, A03...
6. **Exportar progreso**: `php artisan rule:certify --export`
7. **Ver estadísticas**: `php artisan rule:certify --stats`
