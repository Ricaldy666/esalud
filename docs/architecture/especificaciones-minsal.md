# Especificaciones Técnicas Oficiales MINSAL

> Documento de referencia basado en las especificaciones técnicas oficiales
> del MINSAL recibidas para el desarrollo del Sistema Esalud.
> Fecha: 2026-06-12 | Versión: 0.1

## 1. Contexto y Alcance

El Ministerio de Salud de Chile (MINSAL), a través del DEIS, define los
formatos y procedimientos para la recolección de datos estadísticos de
Atención Primaria de Salud (APS). Estas especificaciones establecen los
requisitos funcionales que todo sistema de gestión REM debe cumplir.

El sistema Esalud se alinea con estas especificaciones oficiales para
garantizar interoperabilidad, consistencia de datos y cumplimiento
normativo ante la autoridad sanitaria.

## 2. Modelo Conceptual de Datos (Confirmado)

Las especificaciones confirman la estructura jerárquica de datos que ya
implementa Esalud:

`
Establecimiento (DEIS) → Reporte_REM → Registro_Celda
`

| Nivel | Entidad Esalud | Descripción |
|-------|----------------|-------------|
| 1 | health_centers | Establecimiento APS con código DEIS |
| 2 | em_uploads | Archivo REM subido (período, tipo, establecimiento) |
| 3 | em_data | Valor de celda individual (fila, columna, hoja, sección, concepto, profesional, valor) |

El modelo actual coincide con la estructura esperada por MINSAL.

## 3. Series REM Soportadas

### Serie A (Mensual)

- Frecuencia: mensual
- Hojas: A01 a A34 (~30 hojas)
- Estado actual: A01 mapeada y verificada (piloto Fase 04B-2a). A02-A34 pendientes (Fase 04B-2b).
- Archivo de referencia: SA_26_V1.2-2.xlsm (REM A real de 2026)

### Serie P (Semestral)

- Frecuencia: semestral (reporte al 30 de junio y 31 de diciembre)
- Estado actual: No implementada (planeada Fase 04B-2c)
- Manual de referencia: Manual REM-P 2025-2026 v1.0 (MINSAL)

| Formulario | Descripción | Secciones |
|------------|-------------|-----------|
| REM-P1 | Salud de la Mujer | A-J |
| REM-P2 | Salud Infantil | A-J |
| REM-P3 | Población Crónica | A-E |
| REM-P4 | Salud Cardiovascular PSCV | A-C |
| REM-P5 | Adulto Mayor (EMPAM) | A-E |
| REM-P6 | Salud Mental APS y Especialidades | A-B |
| REM-P7 | Familia | — |
| REM-P9 | (sección específica) | — |
| REM-P11 | (sección específica) | — |
| REM-P12 | Tamizajes (PAP, VPH) | — |
| REM-P13 | (sección específica) | — |

## 4. Requisitos Funcionales (RF-01 a RF-05)

| ID | Requisito | Descripción | Estado en Esalud | Fase |
|----|-----------|-------------|------------------|------|
| RF-01 | Carga de archivos REM | Subir archivos .xlsx/.xlsm/.xls con validación de formato, tamaño y estructura | ✅ Implementado | 04A |
| RF-02 | Validación y Consistencia | Motor de reglas de validación cruzada entre secciones y hojas de un mismo reporte | ⚠️ Parcial (validación celda por celda, falta validación cruzada) | 04B-3 |
| RF-03 | Sumatoria y Agregación | Acumulación de datos entre establecimientos, comunas y períodos | 🔴 No implementado | 04B-4 |
| RF-04 | Motor de Cálculo de Metas | Evaluación de metas sanitarias con fórmulas parametrizables que referencian celdas REM | 🔴 No implementado | 05 |
| RF-05 | Exportación | Generación de reportes PDF (formales) y Excel (para R/SPSS/Stata) | 🔴 No implementado | 06 |

## 5. Validación de Consistencia Interna

El RF-02 requiere un motor de reglas que valide la consistencia de datos
dentro de un mismo reporte REM. Ejemplos de reglas definidas en las
especificaciones oficiales:

- **Inclusión de subgrupos:** Los subgrupos NANEAS en REM-P2 deben estar
  incluidos en los totales de Sección A y A.1
- **Consistencia cruzada:** "Total gestantes Sección D == Total gestantes
  Sección B" (REM-P1)
- **Subgrupos diagnósticos:** La suma de subcategorías debe coincidir con
  el total de la categoría padre

El sistema actual valida celda por celda (tipo entero, rango 0-999999,
nulos permitidos). La validación cruzada entre secciones se implementará
en Fase 04B-3.

## 6. Motor de Cálculo de Metas Sanitarias

Las especificaciones definen un motor de fórmulas que evalúa metas
sanitarias usando variables que referencian celdas específicas de los
reportes REM.

### Sintaxis de variables

`
[REM-P4_B_FILA_02_COL_03]
  │      │   │      │
  │      │   │      └── Columna dentro de la hoja
  │      │   └───────── Número de fila (1-indexed)
  │      └───────────── Sección del formulario
  └──────────────────── Formulario REM
`

### Sintaxis de fórmulas

`
[REM-P4_B_FILA_02_COL_03] / [REM-P4_A_FILA_05_COL_02] * 100
`

### Ejemplos de Metas Sanitarias

| Meta | Fórmula base | Fuente |
|------|-------------|--------|
| Cobertura PAP | Población tamizada / Población objetivo * 100 | REM-P12 Sección A.1 |
| Compensación Diabetes Tipo 2 | Pacientes compensados (HbA1c < 7%) / Total diabéticos * 100 | REM-P4 Sección B |

El motor de cálculo se implementará en Fase 05, posterior a la
finalización del mapeo de ambas series (A y P).

## 7. Glosario de Conceptos Críticos

| Término | Significado |
|---------|-------------|
| APS | Atención Primaria de Salud |
| CIE-10 | Clasificación Internacional de Enfermedades, 10ma edición |
| DEIS | Departamento de Estadística e Información en Salud (MINSAL) |
| EMPAM | Examen Medicina Preventiva del Adulto Mayor |
| IAAPS | Índice de Actividad de la Atención Primaria de Salud |
| NANEAS | Niños/as con Necesidades Especiales de Atención en Salud |
| PBC | Población Bajo Control |
| PSCV | Programa de Salud Cardiovascular |

Ver glosario completo en docs/architecture/glosario.md.

## 8. Referencias

- **Manual REM 2025-2026 Serie P v1.0** — MINSAL, División de Atención
  Primaria, Departamento de Estadística e Información en Salud
- **Especificaciones Técnicas REM (institucional)** — Documento de
  requisitos funcionales para sistemas de gestión REM
- **Documentos físicos almacenados en:** ecursos-rem/manuales/
  (gitignored, no versionados)
