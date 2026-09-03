# Checklist de despliegue a producción — Esalud

> Documento operacional para Nelson. Sigue el orden exacto de abajo — cada
> paso depende del anterior. Para contexto adicional (justificación técnica
> de cada valor, plan de datos, rollback) ver
> [`docs/handoff/DEPLOYMENT.md`](handoff/DEPLOYMENT.md) y
> [`docs/MANUAL_TECNICO.md`](MANUAL_TECNICO.md). Este checklist no reemplaza
> esos documentos — es la versión ejecutable, paso a paso, con criterio de
> "hecho/no hecho" para cada punto.
>
> Repositorio: `https://github.com/Ricaldy666/esalud.git` — **commit
> estable actual: `e7cda9c4d144d78777e0468a91b1f5028d78e2a4`** (rama
> `main`, sincronizado con `origin/main`, verificado 2026-09-01). Este
> commit incluye, además de todo lo certificado en agosto: el cierre
> completo de **REM A** (474 reglas `SAFE_1_TO_1`, estructura activa
> **67/v35**), **Seguridad/2FA** (TOTP completo), y la campaña **UX/UI**
> (retiro de la integración Prime legacy, manejo visual de rutas 404,
> normalización de tablas/headers). Las cifras de estructura/reglas/
> bindings de este checklist fueron **reverificadas en vivo contra la
> BD local (`esalud_dev`) el 2026-09-01** — ver §7-8 y §20 abajo.
>
> ⚠️ **Dos condiciones previas siguen sin confirmar con Nelson y bloquean
> continuar más allá del punto 3 y del punto 9 respectivamente — ver esos
> puntos:** (a) si el servidor servirá por HTTP o HTTPS (punto 16), y (b)
> que el dump de datos de calibración (puntos 7-9) **no existe generado
> todavía** — hay que crearlo de nuevo contra el estado actual antes de
> desplegar, el dump histórico de agosto está obsoleto y **no debe
> reutilizarse**.
>
> Convención de cada punto: **Comando(s) exacto(s)** → **⚠️ Si se omite** →
> **✅ Evidencia para marcar como completado**.

---

## ☐ 1. Requisitos previos del servidor

**Comandos de verificación:**
```bash
php -v                          # debe ser >= 8.3
php -m | grep -E "bcmath|ctype|fileinfo|json|mbstring|openssl|pdo_mysql|xml|zip|curl|gd|intl|tokenizer|simplexml"
composer --version              # >= 2.x
mysql --version                 # 8.x o MariaDB compatible
node -v                         # >= 20 LTS
npm -v                          # >= 10.x
nginx -v
php-fpm8.3 -v
supervisord --version
```

**⚠️ Si se omite:** cualquier extensión de PHP faltante hace fallar
`composer install` o produce errores en tiempo de ejecución difíciles de
diagnosticar (ej. sin `zip`/`gd`/`xml`, PhpSpreadsheet no puede leer los
archivos REM `.xlsm` — el parser fallaría en el primer upload real).

**✅ Evidencia:** salida de los comandos de arriba sin errores; las 13
extensiones listadas aparecen en `php -m`.

---

## ☐ 2. Clonado o actualización desde GitHub

**Primera vez (clonado):**
```bash
cd /var/www
git clone https://github.com/Ricaldy666/esalud.git esalud
cd esalud
git log -1 --oneline   # debe mostrar e7cda9c o un commit posterior en main
```

**Actualizaciones posteriores:**
```bash
cd /var/www/esalud
git pull origin main
```

**⚠️ Si se omite / se hace mal:** desplegar una rama o commit distinto de
`main` puede dejar el servidor con código a medio certificar, sin el fix de
memoria ni el fix del rol `Administrador`.

**✅ Evidencia:** `git log -1 --oneline` en el servidor muestra el mismo
hash que `git log -1 --oneline` en el equipo de desarrollo (o uno posterior
en `main`); `git status` sin cambios locales inesperados.

---

## ☐ 3. Configuración de `.env`

```bash
cd /var/www/esalud/backend
cp .env.example .env
php artisan key:generate
```

Editar `backend/.env` con los valores reales de producción (**no dejar el
`.env.example` tal cual**):

| Variable | Valor en producción |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://atenea.cormudesi.cl` (o `http://` si HTTPS aún no está activo — ver punto 16) |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | credenciales reales de producción, nunca `root` sin password |
| `SESSION_SECURE_COOKIE` | `false` mientras sea HTTP; `true` solo con HTTPS activo (ver punto 16) |
| `SANCTUM_STATEFUL_DOMAINS` | `atenea.cormudesi.cl` |
| `QUEUE_CONNECTION` | `database` (ya es el default, no cambiar) |
| `MEMORY_PROBE_ENABLED` | `false` (dejar así salvo diagnóstico puntual de memoria) |

```bash
cd /var/www/esalud/frontend
cp .env.example .env
# VITE_API_URL=/api/v1 ya es correcto tal cual, no requiere edición
```

**⚠️ Si se omite:** con `APP_DEBUG=true` en producción se exponen trazas de
error y rutas del servidor a cualquier usuario. Con `APP_URL`/
`SANCTUM_STATEFUL_DOMAINS` mal configurados, el login falla silenciosamente
(CSRF/CORS rechazado) o las cookies de sesión no se guardan.

**✅ Evidencia:** `cat backend/.env | grep -E "APP_ENV|APP_DEBUG|APP_URL"`
muestra `production`/`false`/el dominio real; `php artisan config:show app.env`
confirma `production`.

---

## ☐ 4. Creación de base de datos

```sql
CREATE DATABASE esalud_prod CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```
(nombre exacto a confirmar con Nelson; debe coincidir con `DB_DATABASE` del
`.env`)

**⚠️ Si se omite:** `php artisan migrate` falla de inmediato con error de
conexión — bloqueante, no se puede continuar.

**✅ Evidencia:** `mysql -u <user> -p -e "SHOW DATABASES;"` lista la base
creada con el charset `utf8mb4`.

---

## ☐ 5. Migraciones

```bash
cd /var/www/esalud/backend
php artisan migrate --force
```

**⚠️ Si se omite:** ninguna tabla existe — todo el sistema falla al primer
request que toque la base de datos.

**✅ Evidencia:**
```bash
php artisan migrate:status
```
Todas las migraciones (incluidas las de `rem_template_structures`,
`rem_rules`, `rem_rule_bindings`, `rem_calibrations`, `rem_calibration_cells`,
`rule_engine_settings`) deben aparecer como `Ran`.

---

## ☐ 6. Seeders

```bash
php artisan db:seed --force
```

Puebla: roles (`Superadmin`, `Administrador`, `Auditor`, `Revisor`,
`Analista`), usuario admin (`admin@esalud.cl`), centros de salud, plantilla
REM base, y 529 reglas base desde el CSV versionado
(`RuleCatalogCsvSeeder`).

**⚠️ Si se omite:** nadie puede iniciar sesión (no existe el usuario admin
ni los roles). Si solo se omite `RoleSeeder`/`AdminUserSeeder` pero se
corren los demás: el usuario admin queda sin el rol `Administrador`,
bloqueado de Usuarios/Centros de Salud/Auditoría/Motor de Reglas (bug ya
corregido en el código — pero si el seeder no se ejecuta, el efecto es el
mismo).

**✅ Evidencia:**
```bash
php artisan tinker --execute="echo App\Models\User::where('email','admin@esalud.cl')->first()->roles->pluck('name')->implode(', ');"
# debe imprimir: Superadmin, Administrador
```
Login real en la interfaz con `admin@esalud.cl` y acceso visible a
Usuarios/Centros de Salud/Auditoría/Motor de Reglas en el menú.

---

## ⚠️ Dump y respaldo — leer antes de los puntos 7-9

**El dump de datos de calibración mencionado en versiones anteriores de
este checklist (`esalud_datos_calibracion_2026-08-04.sql`) corresponde a
un estado ya superado (estructura id=36, 764 reglas, 859 bindings) y
NUNCA se generó como archivo real** — quedó solo como plan. **No debe
reutilizarse ni buscarse ese archivo.** Antes de este paso hay que generar
un dump **nuevo**, contra el estado actual verificado (§7-8 abajo: 798
reglas, 1655 bindings, estructura 67/v35), con el mismo comando de
`DEPLOYMENT.md` §3.1 pero fechado al día real del despliegue.

**Además, antes de restaurar nada sobre `esalud_prod` (si ya tuviera
datos de un intento previo) o de tocar `storage/app/private` en el
servidor, respaldar primero** (ver `DEPLOYMENT.md` §9):
```bash
mysqldump -u <user> -p esalud_prod > backup_pre_deploy_$(date +%Y%m%d_%H%M%S).sql
tar -czf backup_storage_$(date +%Y%m%d_%H%M%S).tar.gz storage/app/private
```

---

## ☐ 7. Restauración de estructura REM certificada

Los seeders **no** reconstruyen la estructura REM activa — se transporta
por dump/restore desde el equipo de desarrollo (dump a generar antes del
despliegue, ver recuadro de arriba):

```bash
# En el servidor, después de los seeders:
mysql -u <user> -p esalud_prod < esalud_datos_calibracion_<fecha>.sql
```
(el dump incluye `rem_template_structures`, `rem_rules`,
`rem_rule_bindings`, `rem_templates`, `health_centers` — ver también puntos
8 y siguiente sobre el mismo archivo)

**⚠️ Si se omite:** no hay ninguna estructura REM `active` — todo upload
real queda en estado `rejected` ("Estructura no disponible para la serie y
período seleccionados"). El sistema es inutilizable para carga real.

**✅ Evidencia:**
```bash
php artisan tinker --execute="echo App\Domain\RemParser\Models\RemTemplateStructure::where('status','active')->first()->id . ' / v' . App\Domain\RemParser\Models\RemTemplateStructure::where('status','active')->first()->version_number;"
# debe imprimir: 67 / v35
```

---

## ☐ 8. Restauración de `rem_rules` y `rem_rule_bindings`

Viene en el **mismo dump** del punto 7 — se verifica por separado porque es
un requisito funcional distinto (sin esto el motor de reglas no tiene nada
que evaluar, aunque la estructura sí esté activa).

**⚠️ Si se omite:** la carga REM se procesa y valida estructuralmente, pero
el Motor de Reglas (RF-02) no encuentra reglas de consistencia que evaluar
— compliance siempre 100% de forma falsa, sin detectar errores reales de
sumas/subtotales. `RuleCatalogCsvSeeder` (punto 6) solo aporta 529 reglas
base sin `bindings`; no reemplaza las 798 reglas + 1655 bindings
certificadas.

**✅ Evidencia:**
```bash
php artisan tinker --execute="echo App\Domain\RuleEngine\Models\Rule::count() . ' reglas (' . App\Domain\RuleEngine\Models\Rule::where('status','active')->count() . ' activas), ' . App\Domain\RuleEngine\Models\RuleBinding::count() . ' bindings totales, ' . App\Domain\RuleEngine\Models\RuleBinding::where('bindable_type','structure')->where('bindable_id',67)->count() . ' bindings activos a la estructura 67';"
# debe imprimir: 798 reglas (751 activas), 1655 bindings totales, 451 bindings activos a la estructura 67
```
No todas las 751 reglas activas evalúan directamente contra la estructura
67. Clasificación completa (recalculada en vivo, no un valor cacheado):
**474 `SAFE_1_TO_1`** (candidatas seguras para bindear a 67), **198
`ALREADY_STRUCTURE_AGNOSTIC`** (no dependen de la estructura), **65
`BLOCKED_BY_ENGINE_GAP`**, **14 `DUPLICATE`**. Los **451 bindings activos
a la estructura 67** son los ya materializados (creados en la fase
`rule:rebind-safe-to-structure` documentada en `CLAUDE.md`) — no es
necesariamente el mismo número que las 474 `SAFE_1_TO_1` (esa cifra es
una clasificación recalculable en cualquier momento, no la cuenta de
bindings ya creados); la diferencia entre ambas cifras no es un error a
corregir en este checklist. Detalle completo en `CLAUDE.md`.

---

## ☐ 9. Restauración de `cell_data` y archivos de certificación

```bash
# Copiar el .tar.gz generado en el equipo de desarrollo al servidor, luego:
cd /var/www/esalud/backend
tar -xzf certificacion-<fecha>.tar.gz -C storage/app/private/
ls storage/app/private/certificacion/cell-data/ | wc -l
# debe dar 381
```

**⚠️ Si se omite:** el parser sigue funcionando pero pierde precisión en el
mecanismo de jerarquía/overflow con herencia vertical certificado — es una
regresión funcional real (subcategorías/detalles mal resueltos), aunque no
un error duro visible de inmediato.

**✅ Evidencia:**
```bash
ls storage/app/private/certificacion/cell-data/ | wc -l   # 381
ls -la storage/app/private/certificacion/reglas-funcionales.json   # ~2.4MB
```

---

## ☐ 10. Build del frontend

```bash
cd /var/www/esalud/frontend
npm ci
npm run build
```

**⚠️ Si se omite (o falla silenciosamente):** `dist/` queda desactualizado
o inexistente — Nginx sirve una versión vieja del sitio o un error 404/500.

**✅ Evidencia:** el comando termina sin error; `ls frontend/dist/` muestra
`index.html` y una carpeta `assets/` con timestamp reciente (coincide con
la hora del build, no con una build anterior).

---

## ☐ 11. Configuración Nginx

**Backend** (`/etc/nginx/sites-available/esalud-backend`):
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

    client_max_body_size 50M;
}
```

**Frontend** (`dist/`, mismo dominio o subdominio según defina Nelson):
```nginx
server {
    listen 80;
    server_name atenea.cormudesi.cl;

    root /var/www/esalud/frontend/dist;
    index index.html;

    location / {
        try_files $uri $uri/ /index.html;
    }

    location /api/ {
        proxy_pass http://127.0.0.1:8080;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/esalud-backend /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

**⚠️ Si se omite / se configura mal:** sitio inaccesible (502/404), o el
`fastcgi_pass` apuntando a un socket PHP-FPM inexistente hace que todo
request PHP falle.

**✅ Evidencia:** `sudo nginx -t` reporta `syntax is ok` / `test is
successful`; `curl -I http://atenea.cormudesi.cl` devuelve `200`.

---

## ☐ 12. Configuración PHP-FPM

```bash
sudo systemctl status php8.3-fpm
```
Confirmar que el pool corre como el mismo usuario que tendrá permisos sobre
`storage/`/`bootstrap/cache/` (típicamente `www-data`), y que el socket
(`/run/php/php8.3-fpm.sock`) coincide exactamente con el `fastcgi_pass` de
Nginx del punto 11.

**⚠️ Si se omite:** Nginx devuelve `502 Bad Gateway` en cualquier request
PHP.

**✅ Evidencia:** `sudo systemctl status php8.3-fpm` muestra `active
(running)`; `curl -I https://atenea.cormudesi.cl/api/v1/health` devuelve
`200`.

---

## ☐ 13. Configuración Supervisor

`/etc/supervisor/conf.d/esalud-queue-worker.conf`:
```ini
[program:esalud-queue-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/esalud/backend/artisan queue:work database --queue=default --timeout=180 --memory=512 --sleep=1
autostart=true
autorestart=true
numprocs=1
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/esalud/backend/storage/logs/worker.log
stopwaitsecs=200
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start esalud-queue-worker:*
```

Notas obligatorias:
- **No agregar `--tries`** al comando — cada Job define el suyo propio
  (`ProcessRemUploadJob`=1, los otros dos=2); un `--tries` global lo
  sobrescribiría.
- **No usar `queue:listen`** ni una terminal manual — solo Supervisor con
  `queue:work`.

**⚠️ Si se omite:** ninguna carga REM avanza más allá de `pending`/
`validating` — el worker que procesa los Jobs no existe. Es el fallo más
común y menos visible (el usuario ve "procesando" indefinidamente, sin
error explícito).

**✅ Evidencia:**
```bash
sudo supervisorctl status esalud-queue-worker:*
# debe mostrar: esalud-queue-worker_00   RUNNING
```

---

## ☐ 14. Reinicio de colas

Después de **cada** actualización de código (`git pull` + `composer
install`), aunque Supervisor ya esté corriendo:

```bash
php artisan queue:restart
```

**⚠️ Si se omite:** `queue:work` no recarga código por sí solo — el worker
sigue ejecutando la versión de código anterior al deploy, aunque los
archivos en disco ya sean los nuevos. Puede causar comportamiento
inconsistente (bugs "ya corregidos" que siguen apareciendo).

**✅ Evidencia:** `sudo supervisorctl status esalud-queue-worker:*` muestra
el proceso con un `uptime` reciente (recién reiniciado, no el mismo de
antes del deploy).

---

## ☐ 15. Verificación de permisos

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

**⚠️ Si se omite:** errores 500 al escribir logs, cachear config/rutas/
vistas, o guardar archivos REM subidos (`storage/app/rem-uploads/`) —
típicamente `file_put_contents(): Permission denied` en
`storage/logs/laravel.log` (si es que el propio log pudo escribirse).

**✅ Evidencia:**
```bash
ls -la storage bootstrap/cache
# owner:group = www-data:www-data en ambos
```
Subir un archivo de prueba desde la interfaz sin error 500.

---

## ☐ 16. Validación HTTP/HTTPS

**Condición previa de despliegue, no una suposición** — sigue sin
confirmar con Nelson si `atenea.cormudesi.cl` tendrá HTTPS activo el día
del despliegue. No fijar `SESSION_SECURE_COOKIE=true` "por si acaso" ni
asumir HTTP por defecto — preguntar explícitamente antes de este paso,
condiciona una variable crítica del `.env`:

| Escenario | `SESSION_SECURE_COOKIE` | `APP_URL` |
|---|---|---|
| Servidor sirve por HTTP (sin TLS todavía) | `false` | `http://atenea.cormudesi.cl` |
| Servidor sirve por HTTPS | `true` | `https://atenea.cormudesi.cl` |

**⚠️ Si se configura mal (`true` sobre HTTP):** el navegador descarta la
cookie de sesión en silencio — el login parece exitoso (responde 200) pero
la sesión nunca persiste, y el usuario queda deslogueado en el siguiente
request. Es un fallo confuso de diagnosticar porque no hay error visible.

**✅ Evidencia:** login real desde el navegador, cerrar y recargar la
página — la sesión debe seguir activa (no vuelve al login). Si hay HTTPS,
`curl -I https://...` responde sin advertencias de certificado.

---

## ☐ 17. Pruebas funcionales posteriores al despliegue

```bash
curl -I https://atenea.cormudesi.cl
curl -I https://atenea.cormudesi.cl/api/v1/health

php artisan test --filter=Rem
# requiere esalud_testing configurada (phpunit.xml usa root/sin password
# por defecto -- ajustar credenciales o crear esa base también)
```

**⚠️ Si se omite:** el despliegue queda "hecho" sin confirmar que el
backend, el frontend y el pipeline realmente funcionan de punta a punta —
cualquier error solo aparecerá cuando un usuario real lo reporte.

**✅ Evidencia:** ambos `curl -I` devuelven `200`; `php artisan
test --filter=Rem` da `64 passed` (si se corre en el servidor).

---

## ☐ 18. Carga REM real de prueba

Desde la interfaz (no por API/curl): iniciar sesión, ir a **Cargas REM**,
subir un archivo Excel REM real, y seguir el progreso hasta el final.

**⚠️ Si se omite:** es el único paso que verifica el pipeline completo
(`ProcessRemUploadJob` → `ValidateRemUploadJob` → `ValidateWithEngineJob`)
end-to-end contra infraestructura real de producción — sin esto, todos los
puntos anteriores pueden estar "verdes" individualmente y el sistema
igual no funcionar en conjunto.

**✅ Evidencia:** la carga termina en `success` o `with_errors` (nunca
`rejected` ni se queda indefinidamente en `validating`); el resumen de
validación muestra reglas evaluadas coherentes con las 798 reglas / 751
activas restauradas (no un número sospechosamente bajo como 529, que
indicaría que solo corrió el seeder del CSV y no se restauró el dump del
punto 7-8).

---

## ☐ 19. Validación funcional ampliada — 2FA y campaña UX/UI

Este punto es nuevo respecto al checklist original de agosto — cubre todo
lo cerrado desde entonces (Seguridad/2FA y la campaña UX/UI completa,
incluido el retiro de la integración Prime legacy). Hacerlo **desde la
interfaz real**, con el usuario admin de producción:

- [ ] **Login**: `admin@esalud.cl` (o el admin real creado en el
      servidor) inicia sesión con password correcta.
- [ ] **Persistencia de sesión**: recargar la página tras login — la
      sesión sigue activa (no vuelve a `/login`). Si falla, revisar punto
      16 (HTTP/HTTPS).
- [ ] **2FA/TOTP**: si el usuario tiene 2FA activo, el login exige el
      challenge (código TOTP o recovery code) antes de entrar; si no lo
      tiene activo, entra directo — ambos casos son correctos según el
      estado real de ese usuario (2FA es opcional salvo recomendación no
      bloqueante para `Superadmin`/`Administrador`).
- [ ] **Dashboard**: carga con KPIs reales (cargas REM, resumen del motor
      de reglas si el rol tiene acceso), sin errores en consola.
- [ ] **Cargas REM**: historial visible, filtros funcionando, subida real
      (ver punto 18).
- [ ] **Calibración REM**: entrar a una hoja/sección real — **Matriz** es
      la pestaña inicial (ya no existe "Revisión funcional").
- [ ] **Patrones y fórmulas**: carga correctamente dentro de la misma
      sección.
- [ ] **Motor de Reglas**: dashboard con métricas reales (`/rule-engine`).
- [ ] **Catálogo de Reglas**: lista y filtra reglas, navega al detalle de
      una regla sin error.
- [ ] **Comparación**: ejecuta una comparación real (estructura + upload
      conocidos), tabla de diferencias sin errores.
- [ ] **Manejo 404**: navegar a una URL inexistente (o a
      `/criterios-funcionales`, ruta retirada) muestra la pantalla
      "Página no encontrada" de ATHENEA — **nunca** el error técnico por
      defecto de React Router.
- [ ] **Ausencia de Criterios funcionales/Prime legacy**: confirmar que
      no existe la entrada "Criterios funcionales" en el menú lateral ni
      en el Dashboard, y que la ruta `/criterios-funcionales` no resuelve
      a ninguna pantalla funcional (cae en el 404 de arriba).

**⚠️ Si se omite:** el despliegue podría darse por completo verificando
solo el flujo REM clásico (puntos 1-18) sin confirmar que 2FA y toda la
campaña UX/UI — que representan la mayoría del código en el commit
`e7cda9c` — realmente funcionan en el servidor real.

---

## ☐ 20. Criterios de aceptación final

El despliegue se considera **completo y aceptado** solo si TODOS estos
puntos son verdaderos simultáneamente:

| # | Criterio | Cómo se verifica |
|---|---|---|
| 1 | `git log -1` en el servidor coincide con `main` del repositorio (≥ `e7cda9c`) | `git log -1 --oneline` |
| 2 | Estructura REM activa = id **67** (v**35**) | tinker (punto 7) |
| 3 | **798** reglas (**751** activas), **1655** bindings totales, **451** bindings activos a la estructura 67 | tinker (punto 8) |
| 4 | 381 archivos en `cell-data/` | `ls ... | wc -l` (punto 9) |
| 5 | `admin@esalud.cl` tiene roles `Superadmin, Administrador` | tinker (punto 6) |
| 6 | `dist/` del frontend con timestamp del build actual | `ls -la frontend/dist` (punto 10) |
| 7 | Nginx activo, `nginx -t` sin errores | punto 11 |
| 8 | PHP-FPM activo, socket correcto | punto 12 |
| 9 | Worker de colas `RUNNING` en Supervisor | punto 13 |
| 10 | Permisos `www-data` correctos en `storage/`/`bootstrap/cache/` | punto 15 |
| 11 | `SESSION_SECURE_COOKIE` coherente con HTTP/HTTPS real | punto 16 |
| 12 | Login real persiste la sesión | punto 16-17 |
| 13 | Carga REM real de prueba termina en `success`/`with_errors` | punto 18 |
| 14 | 2FA/TOTP funciona (challenge cuando corresponde, login directo cuando no) | punto 19 |
| 15 | Dashboard, Cargas REM, Calibración REM, Matriz, Patrones y fórmulas, Motor de Reglas, Catálogo de Reglas, Comparación — todas navegables sin error | punto 19 |
| 16 | Ruta inexistente (o `/criterios-funcionales`) muestra el 404 propio de ATHENEA, nunca el error técnico de React Router | punto 19 |
| 17 | Sin entrada "Criterios funcionales" en menú/Dashboard, sin referencia funcional a Prime legacy | punto 19 |

**Si cualquiera de los 17 falla, el despliegue NO está listo** — no cerrar
el ticket/tarea con Nelson hasta que los 13 estén en verde.

---

## Referencia rápida — problema → causa más probable

| Síntoma | Punto del checklist a revisar primero |
|---|---|
| Carga REM se queda en `validating` para siempre | 13 (worker no corriendo) |
| Carga REM queda en `rejected` | 7 (estructura no restaurada) |
| Compliance siempre 100%, ningún error detectado | 8 (reglas/bindings no restaurados) |
| Login "funciona" pero la sesión no persiste | 16 (HTTP/HTTPS mal configurado) |
| Error 500 al subir archivos o ver logs | 15 (permisos) |
| Error 502 | 11 o 12 (Nginx/PHP-FPM) |
| Sitio muestra versión vieja del frontend | 10 (build no corrido o `dist/` no actualizado) |
| Usuario admin no ve Usuarios/Auditoría/Motor de Reglas | 6 (rol `Administrador` no asignado) |
| Ruta eliminada o inexistente muestra error técnico de React Router | 19 (frontend no incluye el commit `e7cda9c`, revisar punto 10) |
| Login no pide 2FA para un usuario que sí lo tiene activo | 5/8 (migración `add_two_factor_columns_to_users_table` no aplicada — revisar `php artisan migrate:status`) |
