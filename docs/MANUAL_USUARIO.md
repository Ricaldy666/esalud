# Manual de usuario — Esalud

> Sustituye al borrador `docs/manuals/user-manual.md` (pendiente de retirar).
> Dirigido a las personas que usan el sistema día a día: cargar archivos REM,
> revisar resultados de validación y, según el rol, administrar el catálogo
> de reglas. Para tareas de administración de usuarios/centros de salud ver
> también [`docs/manuals/admin-manual.md`](manuals/admin-manual.md).

## Tabla de contenido

1. [Introducción](#1-introducción)
2. [Acceso al sistema](#2-acceso-al-sistema)
3. [Panel principal y menú](#3-panel-principal-y-menú)
4. [Cargar un archivo REM](#4-cargar-un-archivo-rem)
5. [Interpretar el resultado de la validación](#5-interpretar-el-resultado-de-la-validación)
6. [Criterios funcionales](#6-criterios-funcionales)
7. [Catálogo de Reglas y Calibración](#7-catálogo-de-reglas-y-calibración)
8. [Preguntas frecuentes](#8-preguntas-frecuentes)

---

## 1. Introducción

Esalud (mostrado en el sistema como **"Estadística APS"**) permite cargar
los archivos REM (Resumen Estadístico Mensual) que cada establecimiento de
salud reporta mensualmente, y valida automáticamente que los datos sean
consistentes antes de remitirlos al MINSAL. El sistema reemplaza la revisión
manual en Excel por un motor de reglas que detecta errores de sumas,
subtotales y relaciones entre celdas.

## 2. Acceso al sistema

1. Ingresar a la URL del sistema (`http://localhost:5173` en desarrollo, o
   la URL institucional en producción).
2. Iniciar sesión con el correo y contraseña asignados.
3. El menú lateral se ajusta automáticamente según el rol del usuario — no
   todas las personas ven las mismas opciones (ver §3).

**Roles del sistema:**

| Rol | Para qué sirve |
|---|---|
| Superadmin / Administrador | Acceso completo: usuarios, centros de salud, auditoría, motor de reglas |
| Auditor | Revisión y trazabilidad de calibración y criterios funcionales |
| Revisor | Certificación de reglas y criterios funcionales |
| Analista | Carga y seguimiento de archivos REM, calibración |

## 3. Panel principal y menú

| Menú | Qué permite hacer |
|---|---|
| **Dashboard** | Vista general (todos los roles) |
| **Cargas REM** | Subir un archivo REM nuevo y ver el historial de cargas |
| **Calibración REM** | Mapear celda a celda una estructura REM nueva (roles Superadmin/Analista/Revisor/Auditor) |
| **Criterios funcionales** | Revisar y decidir el comportamiento de celdas/filas específicas |
| **Motor de Reglas** | Ver estado, reglas, logs y estructuras del motor de validación |
| **Catálogo de Reglas** | Consultar y certificar las reglas de consistencia |
| **Usuarios / Centros de Salud / Auditoría** | Administración (rol Administrador) |

Los ítems **GES** y **Metas APS** aparecen marcados "Próximamente": son
accesos de demostración visual, todavía sin funcionalidad real.

## 4. Cargar un archivo REM

1. Ir a **Cargas REM** → arrastrar o seleccionar el archivo Excel
   (`.xlsx`/`.xlsm`/`.xls`).
2. El sistema analiza el archivo y muestra una **vista previa**: serie
   detectada, período, establecimiento. Verificar que sean correctos.
3. Confirmar la carga. A partir de aquí el sistema procesa el archivo de
   forma automática, mostrando el progreso en pantalla:

   | Paso | Mensaje en pantalla | Progreso |
   |---|---|---|
   | Recibido | "Archivo recibido, esperando procesamiento" | 20% |
   | Procesando | "Procesando archivo REM" | 50% |
   | Validando | "Validando datos y reglas de consistencia" | 75% |
   | Completado | Resultado final (ver §5) | 100% |

4. No es necesario mantener la pantalla abierta ni recargar — el sistema
   sigue procesando en segundo plano. Si la carga tarda más de lo esperado,
   aparece un aviso indicando que sigue en cola y un botón para consultar
   el estado nuevamente.
5. Al finalizar, el resultado puede ser:
   - **Correcto**: sin errores.
   - **Con observaciones**: se procesó, pero hay reglas que no se
     cumplieron — revisar el detalle (§5).
   - **Rechazado**: no existe una estructura activa compatible con la
     serie/período seleccionados — contactar al administrador.
   - **Error de procesamiento**: falló el procesamiento del archivo —
     contactar al administrador con el nombre del archivo y la hora.

## 5. Interpretar el resultado de la validación

Al terminar una carga, la pantalla de **Validación REM** muestra:

- **% de cumplimiento**: proporción de reglas aplicables que pasaron.
- **Reglas evaluadas**: total, cuántas son aplicables y cuántas no aplican
  a esta carga en particular.
- **Errores por severidad**:
  - 🔴 **Error**: inconsistencia que debe corregirse antes de remitir el
    reporte.
  - 🟡 **Advertencia**: observación a revisar, no necesariamente bloqueante.
- **Desglose por formulario/sección**: qué hojas del REM tienen errores,
  cuáles están completas. Los formularios con errores aparecen primero;
  los correctos quedan colapsados bajo "Mostrar formularios sin errores".
- Desde el desglose se puede entrar a **"Revisar errores"** de un
  formulario específico para ver el detalle celda por celda.

## 6. Criterios funcionales

Algunas celdas del REM no tienen una regla técnica única y dependen de una
decisión funcional (por ejemplo: "esta celda puede quedar vacía si no
aplica" o "debe registrarse como cero, nunca vacía"). La pantalla
**Criterios funcionales** permite:

1. Filtrar por sección (Todas, Pendientes, Revisadas, Aprobadas, Heredadas,
   Excepciones).
2. Abrir una fila y usar el **Asistente de Revisión** para decidir su
   comportamiento (aplica un criterio existente, hereda de una fila
   similar, o define una excepción para un establecimiento/tipo
   específico).
3. El estado de cada criterio avanza: Pendiente → En revisión → Revisado →
   Validado → Aprobado → Publicado. Solo cuando está aprobado/publicado
   afecta la validación real de las cargas.

## 7. Catálogo de Reglas y Calibración

- **Catálogo de Reglas** (roles Administrador/Superadmin/Revisor): permite
  consultar cada regla del motor, su configuración, y certificarla contra
  el manual técnico oficial.
- **Calibración REM** (roles Superadmin/Analista/Revisor/Auditor): flujo
  para mapear celda a celda una estructura REM nueva (una hoja/serie que
  el sistema todavía no conoce), sección por sección, hasta alcanzar el
  100% de avance y poder activarla.

Estas dos pantallas son para quienes administran el contenido del motor de
validación, no para el uso diario de carga de archivos.

## 8. Preguntas frecuentes

**¿Por qué mi carga quedó "Rechazada"?**
No existe una estructura REM activa para la serie y período seleccionados.
Contactar al administrador del sistema.

**¿Por qué la carga tarda tanto?**
El procesamiento y la validación de un archivo REM completo pueden tardar
hasta un par de minutos (parseo de decenas de hojas + evaluación de
cientos de reglas). Si el mensaje "sigue en cola" persiste por mucho más
tiempo, puede indicar que el proceso de fondo (worker) no está corriendo —
avisar al administrador.

**¿Puedo volver a subir el mismo archivo si me equivoqué?**
Sí, desde "Cargas REM" se puede iniciar una nueva carga en cualquier
momento; cada carga queda registrada por separado en el historial.

**¿Qué significa "no aplica" en el resumen de validación?**
Que la regla existe en el catálogo pero no corresponde evaluarla para este
establecimiento, tipo de centro o período específico (por ejemplo, una
regla exclusiva de CESFAM no se evalúa en un SAPU).

**¿Quién puede ver la Auditoría?**
Solo el rol Administrador. Registra creación, edición y eliminación de
usuarios y centros de salud, con el detalle de qué cambió.
