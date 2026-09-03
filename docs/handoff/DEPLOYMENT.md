# Despliegue Esalud — Guía operativa (preparado 2026-08-04)

Preparado para el despliegue con Nelson. Este documento asume un servidor
Linux limpio con Nginx + PHP-FPM + MySQL/MariaDB + Supervisor. No usar
`php artisan serve`, no usar `npm run dev`, no depender de una terminal
`queue:listen` abierta manualmente.

> Ver también [`docs/ESTADO_ACTUAL_PROYECTO.md`](../ESTADO_ACTUAL_PROYECTO.md)
> para un resumen de estado más corto y siempre actualizado.

---

## 0. ESTADO GENERAL DEL DESPLIEGUE (actualizado 2026-09-01)

**Último commit estable: `e7cda9c4d144d78777e0468a91b1f5028d78e2a4`
(rama `main`, sincronizado con `origin/main`).** Este commit incluye,
además de todo lo descrito originalmente en este documento (fechado
2026-08-04/05, commit `d02e88e`): el cierre completo de **REM A**
(estructura activa **67/v35**, **798** `rem_rules` — **751** activas,
**474** `SAFE_1_TO_1`, **1655** `rem_rule_bindings` totales, **451**
bindings activos a la estructura 67), **Seguridad/2FA** (TOTP RFC 6238
completo vía `pragmarx/google2fa`) y la campaña **UX/UI** (retiro de la
integración Prime legacy, manejo visual de rutas 404, normalización de
tablas/headers). **Todas las cifras de esta sección fueron reverificadas
en vivo contra la BD local (`esalud_dev`) el 2026-09-01** — donde este
documento aún diga `36`/`764`/`859`/`v15` más abajo, son valores
**obsoletos** de la preparación original de agosto, reemplazados por los
de arriba.

⚠️ **El dump de calibración mencionado en §3 nunca se generó como
archivo real** — quedó solo como plan. No buscar ni reutilizar
`esalud_datos_calibracion_2026-08-04.sql`; hay que generar uno nuevo
contra el estado actual (comando idéntico en §3.1, solo con los nombres
de tabla y una fecha nueva) antes de cualquier despliegue real.

### ✅ RESUELTO

- **Build frontend**: los 19 errores de TypeScript que bloqueaban
  `npm run build` quedaron corregidos (ver detalle en riesgo #1, abajo,
  ahora marcado como resuelto). `npx tsc -b`: 0 errores. `npm run build`:
  exitoso, `dist/` se genera correctamente.
- **Parser REM v2**: mecanismo de jerarquía de overflow con herencia
  vertical, fixes de `total_column`/`professional_column`, certificación
  A01-A09 (las 14 secciones de A09 aprobadas funcionalmente) — verificado
  con `tests/Feature/REM` en verde.
- **RuleEngine (RF-02)**: operativo — 798 `rem_rules` (751 activas, 474
  `SAFE_1_TO_1`), 1655 bindings totales (451 activos a la estructura),
  estructura activa **v35/id=67** (cifras reverificadas 2026-09-01; el
  texto histórico de esta sección, escrito en agosto contra `764`/`859`/
  `v15/id=36`, queda superado — ver §0).
- **Pipeline de carga**: `ProcessRemUploadJob` → `ValidateRemUploadJob` →
  `ValidateWithEngineJob` verificado de punta a punta contra uploads
  reales, sin quedar atascado en `validating`.
- **Worker y configuración de colas en local**: diagnosticado el
  `ProcessTimedOutException` de `queue:listen` (default `--timeout=60`
  insuficiente), confirmado que el worker local ya procesa uploads reales
  hasta el final.
- **Tests REM**: `tests/Feature/REM` **64/64** en verde, reconfirmado
  después de cada fase posterior (TypeScript, permisos, rediseño visual
  de Usuarios) — ninguna de esas fases tocó backend REM/RuleEngine.
- **Módulo Usuarios**: cerrado funcional y visualmente (commit
  `d02e88e`). Listar/crear/editar/eliminar usuarios verificado. Permisos
  de `Superadmin`/`Administrador` corregidos en `UserPolicy`,
  `ActivityLogPolicy`, `HealthCenterPolicy` (antes solo `Administrador`
  pasaba, pese a que el menú ya mostraba la opción a `Superadmin` — ver
  `AGENTS.md`/historial de commits para el detalle). El rol Analista
  ("Estadística APS" en la interfaz) confirmado **sin acceso** a
  Usuarios/Centros de Salud/Auditoría (403 real, no solo oculto en el
  menú).
- **ESLint**: 0 errores/warnings en todos los archivos tocados en las
  fases de permisos y rediseño visual.

### ⏳ PENDIENTE — trabajo operativo de servidor, no de código

- [ ] Confirmar con Nelson si el servidor sirve por HTTP o HTTPS
      — condiciona `SESSION_SECURE_COOKIE` y `APP_URL` (§11).
- [ ] Generar y transportar el dump de estructura activa **v35/id=67**,
      `rem_rules` (798), `rem_rule_bindings` (1655), `cell_data`
      (108MB/381 archivos — reconfirmar tamaño actual antes de asumirlo),
      `reglas-funcionales.json` (2.4MB) — plan detallado en §3, **no
      ejecutado todavía** (el dump de agosto contra el estado viejo
      tampoco llegó a generarse, ver §0).
- [ ] Aplicar la configuración de Supervisor/Nginx (§6) en el servidor
      real — la propuesta ya está redactada.
- [ ] Ejecutar la secuencia de despliegue con Nelson (§8) y el checklist
      de prueba REM real post-despliegue
      (`docs/CHECKLIST_DESPLIEGUE_PRODUCCION.md` / `ESTADO_ACTUAL_PROYECTO.md` §7).

### Riesgos bloqueantes restantes

**Ninguno de código, build, permisos o UI.** Todo lo pendiente es
trabajo operativo de transporte de datos y configuración de servidor —
nada de eso se corrige con más cambios de código, se ejecuta como
procedimiento el día del despliegue. El único punto que sigue siendo una
decisión abierta (no un bloqueante técnico) es confirmar HTTP vs. HTTPS
con Nelson antes de fijar `SESSION_SECURE_COOKIE` en el `.env` de
producción.

---

## 0.1 Historial de riesgos (detalle)

1. ~~**`npm run build` fallaba**~~ — **resuelto (2026-08-05)**. Causa:
   19 errores de TypeScript preexistentes — 13 imports/variables sin
   uso, 1 identificador duplicado (`pattern_id` en `criteriaService.ts`),
   1 tipo incompleto (`WorkflowData.instance` sin `current_state` en
   `AsistenteRevision.tsx`), 2 incompatibilidades de tipo en
   `RemUploadForm.tsx` (`compliance: number` vs. el real
   `number | null`). Corregidos en 7 archivos, sin tocar lógica de
   negocio, Parser REM, RuleEngine, flujo de carga REM ni comportamiento
   de UI — solo tipos/imports/duplicados. Verificado:
   - `npx tsc -b`: **0 errores**.
   - `npm run build`: **exitoso** (2332 módulos, build en 648ms).
   - `dist/` **regenerado correctamente** (`index.html` + `assets/*.js`
     `*.css` con timestamp de la build nueva).
   - Único aviso restante: el bundle principal (`index-*.js`, ~1.2MB sin
     comprimir / 328KB gzip) supera el umbral de 500kB que Vite avisa por
     default — **es solo una advertencia de rendimiento, no bloquea el
     build ni el despliegue**. Sugerencia de Vite (code-splitting) no
     aplicada — fuera del alcance autorizado, no se tocó.

2. ~~`backend/.env.example` y `frontend/.env.example` tenían hardcodeada
   una IP de LAN local~~ — **resuelto (2026-08-05)**: ambos archivos
   ahora usan placeholders neutros (`localhost`), con comentarios que
   explican qué valor usar en local vs. producción. El `.env` real de
   este equipo (con la IP `192.168.1.144`, gitignorado, nunca commiteado)
   **no se tocó** — sigue funcionando igual para mostrar avances desde la
   red interna. Ver §11 para el detalle completo de la separación de
   entornos.

3. **`cell_data` (108MB, 381 archivos) y `reglas-funcionales.json`
   (2.4MB) viven únicamente en `storage/app/private/` de este equipo,
   100% excluidos de git** (`storage/app/private/.gitignore` tiene `*`
   sin excepciones). Sin esto, el parser certificado esta sesión
   (jerarquía de overflow, subcategoría/detalle, fallback de
   `total_column`/`professional_column`) pierde toda su evidencia y
   degrada a un comportamiento mucho más pobre — no es un fallo duro, pero
   es una regresión funcional severa frente a lo certificado. Ver §3 para
   el plan de transporte.

4. **La estructura activa (v15/id=36 en agosto, hoy v35/id=67 — ver §0),
   764 `rem_rules` (hoy 798) y 859 `rem_rule_bindings` (hoy 1655)
   existen solo en la base de datos MySQL local.** No
   hay seeder ni migración que los reconstruya desde cero — se armaron a
   lo largo de muchas sesiones con comandos artisan puntuales. La única
   vía reproducible confiable es un dump/restore selectivo de esas tablas
   (§3).

5. **`phpunit.xml` requiere una base MySQL real `esalud_testing`**
   (usuario `root`, sin password) — ya no usa sqlite en memoria. Nelson
   necesita crear esa base con esas credenciales (o ajustar
   `phpunit.xml`/variables de entorno) antes de poder correr
   `tests/Feature/REM`.

6. **No hay ningún worker de colas corriendo de forma persistente** —
   confirmado en la sesión anterior (ni `queue:work` ni `queue:listen` ni
   Horizon activos). Sin Supervisor configurado, cualquier upload real
   quedaría indefinidamente en `validating`.

7. **Archivos sueltos en el árbol de trabajo que NO deben commitearse**
   — ver §1. Incluye `cookies.txt`/`test_cookies.txt` en la raíz del
   repo (archivos de cookies de `curl`, revisar que no contengan tokens de
   sesión reales antes de descartarlos).

8. **35 tests preexistentes de RuleEngine en rojo** (`FunctionalRuleEngineCertificationTest`,
   `RuleEngineServiceTest`, `RuleEngineIntegrationTest`) — confirmado
   repetidamente esta sesión que son preexistentes, no relacionados con
   los cambios de memoria/caché ni con la certificación de A01-A09. No se
   corrigen en esta tarea (instrucción explícita). Nelson debe saber que
   verá estos 35 fallos en la suite completa y que son esperados —
   `tests/Feature/REM` (64/64) es el criterio real de aceptación para el
   trabajo de esta sesión.

---

## 1. Clasificación de `git status` (146 entradas)

> **Nota (2026-08-05): esta clasificación ya se ejecutó.** El commit
> `1ae35bf` (y los posteriores `3ab026d`, `abe0751`, `d02e88e`) aplicaron
> exactamente esta clasificación — los archivos de §1.2 nunca se
> commitearon, la reubicación de §1.3 se verificó idéntica antes de
> confirmarla. Esta sección queda como referencia histórica de cómo se
> decidió cada caso.

### 1.1 Deben formar parte del despliegue (código real)

- Todo el dominio nuevo: `backend/app/Domain/RuleEngine/`,
  `backend/app/Domain/RemParser/`, `backend/app/Domain/Calibration/`.
- `backend/app/Support/MemoryProbe.php` — **decisión pendiente**: es
  instrumentación de diagnóstico (logging puro, sin efecto funcional)
  agregada esta sesión para el diagnóstico de memoria. Genera bastante
  volumen de log (`storage/logs/laravel.log` creció ~30MB durante las
  pruebas de hoy). Recomendación: mantenerla desactivada por defecto en
  producción (gate por variable de entorno, ej. `MEMORY_PROBE_ENABLED`) o
  quitar las llamadas antes de desplegar — no lo decidí unilateralmente,
  es una llamada de producto/operación.
- Todos los archivos modificados listados con `M` (controllers, jobs,
  models, policies, providers, config, seeders, rutas, tests) —
  corresponden a trabajo real de la sesión (fix de memoria, Hallazgo 1/2,
  mecanismo de jerarquía, etc.), todos verificados con tests.
- Todas las migraciones nuevas (`database/migrations/2026_07_*`,
  `2026_07_21_*`, `2026_07_24_*`) — necesarias para que el esquema del
  servidor tenga las tablas de RuleEngine/Calibration.
- `backend/database/seeders/RuleCatalogCsvSeeder.php`,
  `AdminUserSeeder.php`, `HealthCenterSeeder.php`, `RemTemplateSeeder.php`,
  `RoleSeeder.php`, `DatabaseSeeder.php`.
- `backend/database/seeders/data/*.json` (sí están versionados, no
  gitignorados — se commitean junto con el seeder que los usa).
- Comandos artisan legítimos: `RemActivateStructureCommand`,
  `RemApproveStructureCommand`, `RemDiffStructuresCommand`,
  `RemParseAndPersistCommand`, `RemPersistStructureCommand`,
  `RemRebuildStructureCommand`, `RemScanCellsCommand`,
  `RemTestParserCommand`, `RemValidateStructureCommand`,
  `ResetAdminCommand`, y los `Rule*Command` (catálogo/ingesta/diagnóstico
  de reglas) — son herramientas operativas reales, usadas en esta y
  otras sesiones para administrar estructura/reglas.
- `backend/tests/Feature/REM/`, `tests/Feature/RuleEngine/`,
  `tests/Feature/Calibration/`, `tests/Unit/REM/`, `tests/Unit/RuleEngine/`,
  `tests/Unit/EnvironmentGuardTest.php`, `tests/fixtures/`.
- `backend/config/rule-engine.php` (config nueva, necesaria).
- `backend/resources/views/admin/rem-explorer/`,
  `resources/views/layouts/admin.blade.php`.
- Todo el frontend nuevo: `frontend/src/features/rem/components/RemProcessingSteps.tsx`,
  `RemUploadDropzone.tsx`, `RemUploadPreviewCard.tsx`,
  `RemUploadResultCard.tsx`, `frontend/src/features/rule-engine/`,
  `frontend/src/pages/ComingSoonPage.tsx`,
  `frontend/src/shared/services/apiPrime.ts`, `frontend/src/shared/utils/`.
- Documentación: `AGENTS.md`, `ARQUITECTURA_REM_PARSER.md`,
  `docs/handoff/` (incluye este archivo y `CONTINUE.md`),
  `docs/architecture/adr/` (ADRs reubicados desde `docs/adr/`, que
  aparece como `D` — confirmar que es una reubicación intencional, no
  pérdida: el contenido debería coincidir),
  `docs/arquitectura-modulo-gestion-certificacion-reglas.md`,
  `docs/certificacion-catalogo-serie-a.md`,
  `docs/reconstruccion-estructura-bindings-serie-a.md`,
  `backend/docs/*.md` (informes de auditoría/certificación — valiosos
  como historial, sin datos sensibles).

### 1.2 NO deben commitearse (basura de sesiones de trabajo)

Todos son scripts de un solo uso dejados en la raíz del repo o de
`backend/` en vez de un scratchpad — confirmé el contenido de cada uno,
son diagnósticos puntuales sobre `RemTemplateSeeder.php` u otros, sin
valor como código de producto:

- `backend/apply_full_purge.php`
- `backend/check_brackets.php`, `check_brackets2.php`
- `backend/check_depth_detail.php`, `check_depth_shift.php`
- `backend/check_rules_fixed.php`
- `backend/explore_structure.php`
- `backend/tmp_sync_cols.php`, `tmp_sync_analysis.json`
- `backend/lista_64_mujeres.txt`, `backend/problemas_columnas.txt`
- `backend/database/seeders/apply_target_C.php`,
  `database/seeders/verify_keys.php`
- `backend/app/Console/Commands/TempCheckTemplateCommand.php`,
  `TempCreateTemplateConfigCommand.php`, `TempFindUploadsCommand.php`,
  `TempReprocessUploadCommand.php`, `TempValidateFlowCommand.php`
- Raíz del repo: `cookies.txt`, `test_cookies.txt` (**revisar que no
  contengan tokens de sesión reales antes de descartar** — son archivos
  de cookies de `curl`, formato Netscape), `inspect_unclassified.php`,
  `test_explorer.php`, `resultado_parseo.json` (2.5MB, dump de un parseo
  puntual).

**Ninguno de estos está en `.gitignore` actualmente** — si se hace `git
add -A` sin revisar, se colarían al repo. Recomendación: eliminarlos del
working tree (son descartables, no forman parte de ningún flujo real) o
al menos excluirlos explícitamente del `git add`.

### 1.3 Archivos borrados (`D`) a confirmar

`docs/adr/0001..0013*.md` + `README.md` + `template.md` aparecen
borrados de su ubicación original. Coinciden en nombre con los archivos
nuevos en `docs/architecture/adr/` — parece una reubicación intencional
de carpeta. Confirmar que el contenido de cada ADR se preservó antes de
commitear el borrado (no lo verifiqué archivo por archivo).

---

## 2. Qué información de A01-A09 está dónde

| Ubicación | Contenido | ¿Viaja con `git pull`? |
|---|---|---|
| **Código Git** (tras commit) | Lógica del parser (`RemParserService`, `ColumnRoleResolverService`), mecanismo de jerarquía con herencia vertical, fixes de `total_column`/`professional_column`, fix de memoria (`CellDataStorageService`, evicción), toda la lógica de validación y RuleEngine | Sí |
| **Base de datos local `esalud_dev`** | `rem_template_structures` (activa **v35/id=67**, certificada end-to-end en REM A), `rem_rules` (798, 751 activas, 474 `SAFE_1_TO_1`), `rem_rule_bindings` (1655 totales, 451 activos a la estructura 67), `rem_templates` | **No** |
| **Base de datos local `esalud_dev`** (dato de prueba, NO llevar a prod) | `rem_uploads` (106), `rem_data` (260.071 filas) — cargas de prueba de esta y otras sesiones | No (y no debería) |
| **`storage/app/private/certificacion/`** | `cell-data/` (108MB/381 archivos), `reglas-funcionales.json` (2.4MB), `serie-a-catalogo.json` | **No** (100% gitignorado) |
| **`storage/app/`** (fuera de `private/`) | `catalogo-reglas-serie-a-2026.csv` (fuente de `RuleCatalogCsvSeeder`), catálogos xlsx/csv de reglas, `storage/app/certificacion/` (copia vieja, no usada — el disco `local` apunta a `storage/app/private/`) | **No** (100% gitignorado) |
| **Seeders/migraciones** | Solo esquema (tablas vacías) + lo que expliciten los seeders (`RuleCatalogCsvSeeder` puede repoblar `rem_rules` desde el CSV, pero no reproduce `rem_rule_bindings` ni `rem_template_structures`) | Parcial |

---

## 3. Plan para llevar los datos al servidor (NO ejecutado todavía)

**Recomendación: dump/restore selectivo de MySQL + copia de carpeta,
no reconstrucción vía comandos.** Reconstruir vía artisan replicaría
docenas de comandos ad-hoc ejecutados a lo largo de muchas sesiones —
fràgil y lento. Un dump de las tablas ya calibradas es determinista y
verificable.

### 3.1 Base de datos — tablas a exportar

```bash
# En este equipo (NO ejecutar todavía) -- usar la fecha real del dia
# del despliegue en el nombre del archivo, no reutilizar el nombre
# ni el contenido de ningun dump anterior:
mysqldump -u root esalud_dev \
  rem_template_structures \
  rem_rules \
  rem_rule_bindings \
  rem_templates \
  health_centers \
  --no-create-info --complete-insert \
  > esalud_datos_calibracion_$(date +%Y-%m-%d).sql
```

- **No incluir** `rem_uploads`, `rem_data`, `rem_validation_results`,
  `rule_execution_logs`, `failed_jobs`, `jobs` — son datos transaccionales
  de prueba, producción debe empezar limpia.
- **`users`**: decisión de Nelson/equipo — probablemente NO copiar (usar
  `AdminUserSeeder` para crear el admin real de producción en vez de
  llevar las 3 cuentas de prueba locales).
- **`health_centers`**: incluido arriba porque `rem_uploads` reales
  necesitan centros de salud válidos — confirmar con Nelson si ya existe
  un maestro de centros en producción o si se debe llevar el local.

En el servidor:
```bash
php artisan migrate --force        # crea el esquema vacío
mysql -u <user> -p esalud_prod < esalud_datos_calibracion_2026-08-04.sql
```

### 3.2 `storage/app/private/certificacion/` — copia directa de carpeta

```bash
# Empaquetar en este equipo (NO ejecutar todavía):
tar -czf certificacion-2026-08-04.tar.gz -C backend/storage/app/private certificacion

# En el servidor, dentro de storage/app/private/:
tar -xzf certificacion-2026-08-04.tar.gz
```
Incluye `cell-data/` (108MB) + `reglas-funcionales.json` +
`serie-a-catalogo.json`. Sin regeneración posible salvo re-escanear cada
hoja con `php artisan rem:scan-cells {hoja} --all` para las 27 hojas de
Serie A — mucho más lento y con riesgo de reintroducir hallazgos ya
descartados; la copia directa es la vía recomendada.

### 3.3 `storage/app/catalogo-reglas-serie-a-2026.csv`

Copiar junto con lo anterior, o mover a una ubicación versionada
(`backend/database/seeders/data/`) antes de commitear, ya que
`RuleCatalogCsvSeeder` depende de él y hoy está fuera del alcance de git.

### 3.4 Verificación post-transferencia

```bash
php artisan tinker --execute="
echo App\Domain\RemParser\Models\RemTemplateStructure::where('status','active')->first()->id; // debe ser 67
echo App\Domain\RemParser\Models\RemTemplateStructure::where('status','active')->first()->version_number; // debe ser 35
echo App\Domain\RuleEngine\Models\Rule::count(); // debe ser 798
echo App\Domain\RuleEngine\Models\RuleBinding::count(); // debe ser 1655
"
ls backend/storage/app/private/certificacion/cell-data/ | wc -l  # debe ser 381 (reconfirmar tamano real antes de asumirlo)
```

---

## 4. Dependencias — versiones confirmadas en este equipo

### Backend
- PHP **8.3.30** (`composer.json` exige `^8.3`)
- Extensiones confirmadas cargadas: `pdo_mysql`, `mbstring`, `xml`,
  `curl`, `zip`, `gd`, `bcmath`, `intl`, `fileinfo`, `tokenizer`,
  `ctype`, `json`, `openssl`, `SimpleXML` — **todas requeridas**
  (PhpSpreadsheet necesita `zip`+`xml`+`gd`; Sanctum/permissions
  necesitan el resto).
- Composer **2.9.5**
- MySQL/MariaDB — **dos bases**: la de producción (nombre a definir) y,
  si se van a correr tests en el servidor, `esalud_testing` (ver
  `phpunit.xml`, credenciales `root`/sin password por defecto — ajustar).
- `QUEUE_CONNECTION=database` (confirmado en `.env` y `.env.example`) —
  no requiere Redis/otro broker.
- Permisos: `storage/` y `bootstrap/cache/` deben ser escribibles por el
  usuario que corre PHP-FPM (`www-data` o el que use Nelson) —
  estructura estándar de Laravel, confirmada existente
  (`storage/app`, `storage/framework`, `storage/logs`,
  `bootstrap/cache/{packages,services}.php`).
- `APP_KEY` debe generarse en el servidor (`php artisan key:generate`),
  no reusar la de este equipo.

### Frontend
- Node **v24.14.0**, npm **11.9.0** en este equipo (sin `engines` en
  `package.json`, no hay restricción de versión declarada — usar
  versiones LTS recientes equivalentes).
- `npm ci` (usa `package-lock.json`, versionado y sin cambios).
- `npm run build` — ✅ resuelto (2026-08-05), ver §0.1 punto 1.

---

## 5. Verificación en limpio — resultados

| Comando | Resultado |
|---|---|
| `composer install` | ✅ Limpio, sin cambios (lock ya satisfecho) — 2026-08-04 |
| `tests/Feature/REM` | ✅ **64/64** — 2026-08-04, reconfirmado 2026-08-05 tras el fix de TypeScript |
| Suite completa backend | ⚠️ 215/250 — 35 fallos, todos preexistentes de RuleEngine (documentados, no relacionados) — 2026-08-04 |
| `npm ci` | ⚠️ Falló por archivo bloqueado por un `npm run dev` corriendo en este equipo (`lightningcss` nativo) — **problema solo local**, no se replicará en un servidor limpio sin proceso `dev` activo — 2026-08-04 |
| `npm install` (tras liberar el lock) | ✅ 185 instalados, 70 actualizados — 7 vulnerabilidades (1 moderada, 6 altas) reportadas por `npm audit`, no revisadas en detalle — 2026-08-04 |
| `npx tsc -b` | ✅ **0 errores** — 2026-08-05, tras corregir los 19 errores de TypeScript |
| `npm run build` | ✅ **Exitoso** — 2026-08-05. 2332 módulos, build en 648ms, `dist/` regenerado. Único aviso no bloqueante: bundle principal >500kB (sugerencia de code-splitting de Vite, no aplicada) |
| `tests/Feature/REM` (reconfirmado tras permisos + rediseño Usuarios) | ✅ **64/64** — 2026-08-05, commit `d02e88e` |
| Suite completa backend (reconfirmado) | ⚠️ 215/250 — mismos 35 fallos preexistentes de RuleEngine, sin cambios frente a las corridas anteriores |
| ESLint (permisos + rediseño Usuarios, 6 archivos) | ✅ 0 errores/warnings — 2026-08-05 |

---

## 6. Configuración de producción

### 6.1 Nginx + PHP-FPM (backend)

```nginx
server {
    listen 80;
    server_name atenea.cormudesi.cl;

    root /var/www/esalud/backend/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    client_max_body_size 50M;  # cargas REM (.xlsm reales observados >1MB)
}
```

### 6.2 Nginx sirviendo `frontend/dist`

```nginx
server {
    listen 80;
    server_name atenea.cormudesi.cl;  # o subdominio/puerto separado, según defina Nelson

    root /var/www/esalud/frontend/dist;
    index index.html;

    location / {
        try_files $uri $uri/ /index.html;  # SPA fallback
    }

    location /api/ {
        proxy_pass http://127.0.0.1:8080;  # o el puerto real de PHP-FPM/backend
    }
}
```
(`dist/` ya se genera correctamente — ver §0.1 punto 1 y §5. Esta
configuración de Nginx queda lista para usarse.)

### 6.3 Supervisor — worker de colas

```ini
[program:esalud-queue-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/esalud/backend/artisan queue:work database --queue=default --timeout=180 --memory=512 --sleep=1 --tries=2
autostart=true
autorestart=true
numprocs=1
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/esalud/backend/storage/logs/worker.log
stopwaitsecs=200
```

Justificación de cada valor (pedido explícitamente):
- **`--timeout=180`**: cubre con margen los 64s reales medidos de
  `ValidateRemUploadJob` (el más lento de los tres tras
  `ProcessRemUploadJob`), con espacio para cargas más grandes que la de
  prueba. En Linux, a diferencia de este Windows de desarrollo, **`pcntl`
  sí está disponible** — `queue:work --timeout` funcionará de verdad
  (mata el proceso hijo vía señal si excede el límite), a diferencia de
  lo observado en desarrollo.
- **`--memory=512`**: el pico real medido tras el fix de caché de
  `CellDataStorageService` es 226MB. 512MB da margen sin acercarse al
  `memory_limit` de PHP (que debe quedar en 512M en `php.ini`, sin
  tocar — ese es un límite duro distinto de este flag, que solo controla
  cuándo el worker se reinicia solo entre jobs).
- **`--sleep=1`**: pedido explícitamente: reduce la latencia de recogida
  de jobs frente al default de 3s, sin sobrecargar la base con polling
  excesivo.
- **Diferencia de `--tries` entre jobs**: `ProcessRemUploadJob` tiene
  `$tries=1` propio (no debe reintentarse: ya persiste `rem_data`
  parcialmente y dispara side-effects reales como el dispatch de
  `ValidateRemUploadJob`); `ValidateRemUploadJob` y `ValidateWithEngineJob`
  tienen `$tries=2`. El flag `--tries=2` del comando de Supervisor
  **sobreescribiría** el `$tries=1` de `ProcessRemUploadJob` a 2 si se
  pasara igual para todas las colas — **no hay forma de dar un `--tries`
  distinto por job dentro del mismo comando `queue:work`**. Opciones:
  (a) omitir `--tries` del comando y dejar que cada job use su propia
  propiedad (`$tries`) — **recomendado**, ya que Laravel respeta el
  valor del job cuando el flag no se pasa explícitamente; o (b) correr
  dos workers en colas separadas con distinto `--tries` si se necesita
  forzarlo por CLI. Este documento usa la opción (a): **no pasar
  `--tries` en el comando de Supervisor**, dejando que cada job aplique
  su propio límite.
- **Reinicio tras cada despliegue**: `queue:work` (a diferencia de
  `queue:listen`) no recarga código — **cada despliegue debe terminar
  con `php artisan queue:restart`** (señal graceful: los workers
  terminan el job actual y Supervisor los relanza con el código nuevo,
  gracias a `autorestart=true`).

Corrección sobre el comando de arriba: quitar `--tries=2` del
`command=` según el punto anterior. Comando final recomendado:
```
command=php /var/www/esalud/backend/artisan queue:work database --queue=default --timeout=180 --memory=512 --sleep=1
```

---

## 7. Checklist PRE-DEPLOY

- [x] ~~Resolver `npm run build`~~ — hecho 2026-08-05 (§0.1 punto 1).
- [x] ~~Corregir `.env.example`~~ — hecho 2026-08-05, placeholders
      neutros con comentarios (§0.1 punto 2, §11).
- [ ] Limpiar del working tree los archivos de §1.2 (no commitear).
- [ ] Confirmar reubicación de `docs/adr/` → `docs/architecture/adr/`
      (contenido preservado).
- [ ] Generar dump de `rem_template_structures`, `rem_rules`,
      `rem_rule_bindings`, `rem_templates`, `health_centers` (§3.1).
- [ ] Empaquetar `storage/app/private/certificacion/` (§3.2).
- [ ] Copiar `storage/app/catalogo-reglas-serie-a-2026.csv` o moverlo a
      ubicación versionada.
- [ ] Confirmar credenciales MySQL de producción con Nelson (usuario,
      password, nombre de base — no usar `root` sin password).
- [ ] Decidir si `MemoryProbe` queda activo en producción o se
      desactiva/retira.
- [ ] Respaldo de la base de datos de producción si ya existe algo ahí
      (§9).
- [x] ~~`git add` selectivo~~ — hecho, en cada campaña posterior por
      separado (nunca `git add .`/`-A`), siempre con `tsc`/`lint`/`build`
      limpios antes de cada commit. Historial relevante en `main`,
      sincronizado con `origin/main`, hasta el commit vigente
      `e7cda9c`: los 4 originales de agosto (`1ae35bf`, `3ab026d`,
      `abe0751`, `d02e88e`), el cierre de **REM A** (`b659a5e`), el
      cierre de **Seguridad/2FA** (`624519f`), y el cierre de la
      campaña **UX/UI** (`54dc5cd` + `e7cda9c` documental). Detalle
      punto por punto de cada campaña en `CLAUDE.md`.

## 8. Checklist POST-DEPLOY (comandos exactos que ejecuta Nelson)

```bash
cd /var/www/esalud
git pull origin main

# Backend
cd backend
composer install --no-dev --optimize-autoloader
cp .env.example .env   # y editar con valores reales de producción — NO usar tal cual
php artisan key:generate
php artisan migrate --force
# el dump y el tar de abajo deben generarse de nuevo, contra el estado
# actual (ver ⚠️ en §0) -- NO existe todavia un archivo real listo, y
# el nombre "2026-08-04" es solo un ejemplo del dump viejo ya obsoleto:
mysql -u <user> -p <db_prod> < esalud_datos_calibracion_<fecha_real>.sql
tar -xzf certificacion-<fecha_real>.tar.gz -C storage/app/private/
php artisan config:cache
php artisan route:cache
php artisan view:cache
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

# Frontend
cd ../frontend
npm ci
npm run build   # debe generar dist/ sin error — verificado en local 2026-08-05 (§0.1)

# Servicios
sudo nginx -t && sudo systemctl reload nginx
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start esalud-queue-worker:*
php artisan queue:restart   # por si ya existía un worker corriendo con código viejo
```

### Verificación post-deploy
```bash
php artisan tinker --execute="
echo App\Domain\RemParser\Models\RemTemplateStructure::where('status','active')->first()->id;
echo App\Domain\RemParser\Models\RemTemplateStructure::where('status','active')->first()->version_number;
"
# debe imprimir 67 y 35

php artisan test --filter=Rem
# debe dar 64/64 (requiere esalud_testing configurada si se corre en el servidor)

sudo supervisorctl status esalud-queue-worker:*
# debe mostrar RUNNING

curl -I https://atenea.cormudesi.cl
curl -I https://atenea.cormudesi.cl/api/v1/health   # o el endpoint de salud que exista
```
Subir un archivo REM real de prueba desde la interfaz y confirmar que
termina en `success`/`with_errors` (no se queda en `validating`).

**Adicional, cubriendo lo cerrado desde agosto (2FA + campaña UX/UI) —
ver el checklist detallado en `docs/CHECKLIST_DESPLIEGUE_PRODUCCION.md`
punto 19:** login con persistencia de sesión, challenge 2FA/TOTP cuando
el usuario lo tenga activo, Dashboard/Cargas REM/Calibración
REM/Matriz/Patrones y fórmulas/Motor de Reglas/Catálogo de
Reglas/Comparación navegables sin error, una ruta inexistente muestra el
404 propio de ATHENEA (nunca el error técnico de React Router), y
ausencia de la entrada "Criterios funcionales"/cualquier rastro
funcional de la integración Prime legacy retirada.

## 9. Respaldo previo y rollback

**Antes de tocar la base de producción** (si ya tiene datos):
```bash
mysqldump -u <user> -p <db_prod> > backup_pre_deploy_$(date +%Y%m%d_%H%M%S).sql
tar -czf backup_storage_$(date +%Y%m%d_%H%M%S).tar.gz storage/app/private
```

**Rollback** si algo falla después de desplegar:
```bash
sudo supervisorctl stop esalud-queue-worker:*
git log --oneline -5          # identificar el commit anterior estable
git checkout <commit-anterior>
composer install --no-dev
mysql -u <user> -p <db_prod> < backup_pre_deploy_<timestamp>.sql
php artisan config:clear
sudo supervisorctl start esalud-queue-worker:*
sudo systemctl reload nginx
```
No usar `git reset --hard` contra el remoto sin confirmar antes con el
equipo — preferir `checkout` a un commit conocido, revisable.

---

## 10. Explícitamente fuera de alcance de esta preparación

- No se tocó RuleEngine (`RuleEngineService`, evaluadores) — según
  instrucción.
- No se corrigieron los 35 tests históricos de RuleEngine (siguen igual,
  documentados, no bloqueantes).
- Commit/push: **sí se ejecutaron**, con autorización explícita en cada
  caso — ver §0 para los 4 hashes. Lo que sigue sin ejecutar es
  únicamente el trabajo operativo en el servidor real (transporte de
  datos, Supervisor/Nginx, secuencia de despliegue con Nelson).

---

## 11. Configuración de entornos: local (LAN) vs. producción

Ambos entornos deben convivir sin interferirse — el entorno local (IP de
red interna, usado para mostrar avances) sigue funcionando exactamente
igual, y el de producción es independiente. Mecanismo:

1. **`.env` real de cada máquina nunca se comparte ni se commitea.**
   `backend/.env` y `frontend/.env` están en `.gitignore` (confirmado,
   nunca se commitearon históricamente). El `.env` de este equipo
   conserva su IP de LAN sin cambios. El servidor de producción tiene su
   **propio** `.env`, creado ahí directamente — nunca copiado del local.
2. **`.env.example` (sí versionado) es ahora un template neutro**
   (`localhost` como placeholder, con comentarios inline indicando qué
   poner en cada entorno) — antes tenía la IP de LAN de este equipo
   hardcodeada como si fuera el default de cualquiera. Corregido en
   `backend/.env.example` y `frontend/.env.example`.
3. **`config/cors.php` es código compartido y no se modificó** — ya
   listaba correctamente los orígenes de ambos entornos a la vez
   (`localhost`, `127.0.0.1`, `192.168.1.144` explícito, más un patrón
   regex `#^http://192\.168\.1\.\d+:5173$#` que cubre toda la subred
   aunque la IP cambie por DHCP) junto con `atenea.cormudesi.cl`
   (http y https). No hace falta separarlo por entorno: tener orígenes
   de LAN en el código no es un riesgo para producción, son direcciones
   de red privada inalcanzables desde fuera.

### Variables por entorno

| Variable | Local (este equipo, sin cambios) | Producción (a configurar en el `.env` del servidor) |
|---|---|---|
| `APP_URL` | `http://192.168.1.144:8080` | `https://atenea.cormudesi.cl` (o `http://` si no hay TLS aún — ver nota) |
| `VITE_API_URL` | `/api/v1` (relativa — el proxy de Vite la resuelve) | `/api/v1` (misma lógica: Nginx hace proxy de `/api` bajo el mismo dominio) |
| `SESSION_DOMAIN` | *(vacío)* | *(vacío)* |
| `SESSION_SECURE_COOKIE` | `false` (HTTP en LAN, correcto) | `false` mientras sea HTTP; **cambiar a `true` solo cuando haya HTTPS activo** — con `true` sobre HTTP el navegador descarta la cookie de sesión en silencio (login parece funcionar pero la sesión nunca persiste) |
| `SESSION_SAME_SITE` | `lax` | `lax` |
| `SANCTUM_STATEFUL_DOMAINS` | `192.168.1.144:5173,localhost:5173,127.0.0.1:5173` | `atenea.cormudesi.cl` |

**Condición previa de despliegue, no una suposición — sigue sin
confirmar con Nelson (2026-09-01):** si el servidor tendrá HTTPS activo
el día del despliegue. No fijar `SESSION_SECURE_COOKIE` sin preguntar
explícitamente antes. `config/cors.php` lista ambos esquemas (`http://` y
`https://`) para `atenea.cormudesi.cl`, lo que sugiere que todavía no
está decidido.

`FRONTEND_URL` (presente en el `.env` real local,
`http://192.168.1.144:5173`) no se referencia en ningún punto del código
actual (`app/`, `config/`) — no se agregó a `.env.example` por no ser una
variable en uso; si en el futuro se usa, agregar entonces con el mismo
criterio de placeholder neutro.
