# Estado actual del proyecto — Esalud

> Documento oficial de estado, pensado para leerse en 5 minutos y saber
> exactamente en qué punto está el proyecto ahora mismo. Complementa (no
> reemplaza) [`SISTEMA.md`](../SISTEMA.md), [`docs/MANUAL_TECNICO.md`](MANUAL_TECNICO.md),
> [`docs/handoff/DEPLOYMENT.md`](handoff/DEPLOYMENT.md) y
> [`docs/CHECKLIST_DESPLIEGUE_PRODUCCION.md`](CHECKLIST_DESPLIEGUE_PRODUCCION.md).
>
> **Última actualización:** 2026-09-01.
> **Último commit estable:** `e7cda9c4d144d78777e0468a91b1f5028d78e2a4`
> (rama `main`, sincronizado con `origin/main`). Incluye, además de lo
> descrito originalmente en este documento (agosto, commit `d02e88e`):
> el cierre completo de **REM A**, **Seguridad/2FA** y la campaña
> **UX/UI** (retiro de Prime legacy + manejo visual 404). Ver `CLAUDE.md`
> para el detalle punto por punto de cada campaña.

---

## 1. Resumen de una línea

El backend (parser REM, motor de reglas, pipeline de carga) y el módulo de
Usuarios están funcional y visualmente cerrados y verificados en local.
Queda pendiente únicamente el despliegue operativo en el servidor con
Nelson — sin bloqueantes de código.

---

## 2. Qué está operativo hoy

| Componente | Estado | Evidencia |
|---|---|---|
| **A09 (Serie A)** | ✅ Certificada funcionalmente | 14 secciones (A, B, C, D, E, F, F.1, F.2, G, G.1, H, I, J, K) aprobadas; catálogo técnico e ingesta completados en sesiones previas |
| **Parser REM v2** | ✅ Operativo | Jerarquía de overflow con herencia vertical, fixes de `total_column`/`professional_column`, fix de memoria (evicción de `cell_data`) |
| **RuleEngine (RF-02)** | ✅ Operativo — REM A certificado end-to-end | 798 `rem_rules` (751 activas, 474 `SAFE_1_TO_1`), 1655 bindings totales (451 activos a la estructura), estructura activa **v35/id=67** (reverificado en vivo 2026-09-01) |
| **Seguridad / 2FA** | ✅ Cerrado | `auth:reset-admin` hardening + rate limiting + TOTP RFC 6238 completo, commit `624519f` |
| **UX/UI** | ✅ Cerrado | Normalización visual, `DataTable`, retiro de integración Prime legacy, manejo visual de rutas 404, commit `54dc5cd` |
| **Pipeline Process → Validate → Engine** | ✅ Operativo de punta a punta | `ProcessRemUploadJob` → `ValidateRemUploadJob` → `ValidateWithEngineJob`, verificado contra uploads reales sin quedar atascado en `validating` |
| **Módulo Usuarios** | ✅ Cerrado (funcional y visual) | Listar/crear/editar/eliminar verificados; rediseño visual completo (commit `d02e88e`) |
| **Permisos Superadmin/Administrador** | ✅ Corregidos | `UserPolicy`, `ActivityLogPolicy`, `HealthCenterPolicy` aceptan ambos roles; antes solo `Administrador` pasaba pese a que el menú ya mostraba la opción a `Superadmin` |
| **Estadística APS (rol Analista)** | ✅ Sin acceso administrativo | Verificado: recibe 403 en `/api/v1/users`, no ve Usuarios/Centros de Salud/Auditoría en el menú; sí ve Dashboard, Cargas REM, Calibración REM, Criterios funcionales, GES y Metas APS (estos 2 últimos como "Próximamente") |
| **Build frontend** | ✅ En verde | `npx tsc -b`: 0 errores. `npm run build`: exitoso, `dist/` se genera correctamente |
| **ESLint** | ✅ En verde | 0 errores/warnings en todos los archivos tocados en esta fase |
| **Tests REM** | ✅ `tests/Feature/REM` 64/64 | Criterio de aceptación real del trabajo de esta fase |
| **Suite completa backend** | ⚠️ 215/250 | **35 fallos preexistentes de RuleEngine**, documentados, no relacionados con ningún cambio de esta fase (confirmado repetidamente, mismos nombres y mensajes en cada corrida) |

---

## 3. Qué falta — despliegue pendiente con Nelson

**No hay bloqueantes de código.** Lo que resta es trabajo operativo de servidor:

1. Confirmar con Nelson si el servidor sirve por **HTTP o HTTPS** — condiciona `SESSION_SECURE_COOKIE` y el esquema de `APP_URL` (ver §6 abajo).
2. Transportar los datos que **no viajan por `git pull`** (ver §4).
3. Configurar Nginx + PHP-FPM + Supervisor en el servidor real (configs ya redactadas, ver `DEPLOYMENT.md` §6).
4. Ejecutar la secuencia de despliegue (`DEPLOYMENT.md` §8 / `CHECKLIST_DESPLIEGUE_PRODUCCION.md`).
5. Ejecutar la carga REM real de prueba post-despliegue (ver §7 abajo).

---

## 4. Datos que NO viajan por Git

| Qué | Dónde vive hoy | Tamaño | Plan de transporte |
|---|---|---|---|
| Estructura REM activa (**v35/id=67**), `rem_rules` (**798**, 751 activas, 474 `SAFE_1_TO_1`), `rem_rule_bindings` (**1655** totales, 451 a la estructura 67), `rem_templates`, `health_centers` | Solo en MySQL local (`esalud_dev`) | — | `mysqldump` selectivo (`DEPLOYMENT.md` §3.1) — **el dump aún no se ha generado, ver §5 nota abajo** |
| `cell-data/` + `reglas-funcionales.json` + `serie-a-catalogo.json` | `storage/app/private/certificacion/` (100% gitignorado) | 108MB / 381 archivos + 2.4MB | `tar` de la carpeta (`DEPLOYMENT.md` §3.2) |
| `rem_uploads` (106) / `rem_data` (260.071 filas) | MySQL local | — | **No llevar** — datos de prueba, producción empieza limpia |
| `users` (3 cuentas de prueba: admin, demo, Francisco Arcos) | MySQL local | — | **No llevar** — Nelson crea el admin real vía `AdminUserSeeder` |

---

## 5. Comandos post-pull (resumen — detalle completo en `DEPLOYMENT.md` §8 y `CHECKLIST_DESPLIEGUE_PRODUCCION.md`)

⚠️ **El dump SQL de calibración referido en §4 no existe generado
todavía** (el de agosto, contra el estado viejo id=36/764/859, tampoco
llegó a crearse — quedó solo como plan). Hay que generarlo de nuevo
contra el estado actual (`DEPLOYMENT.md` §3.1) antes de este paso, y
respaldar `storage/app/private` antes de cualquier restauración.

```bash
cd /var/www/esalud && git pull origin main

cd backend
composer install --no-dev --optimize-autoloader
php artisan migrate --force
# restaurar dump SQL + tar de certificacion/ (ver §4 arriba y la nota
# de arriba) antes de seguir
php artisan db:seed --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

cd ../frontend
npm ci && npm run build

sudo nginx -t && sudo systemctl reload nginx
sudo supervisorctl reread && sudo supervisorctl update
php artisan queue:restart
```

---

## 6. Supervisor / Nginx / HTTP-HTTPS

**Supervisor** (worker de colas — obligatorio, sin esto ninguna carga REM termina de procesarse):
```ini
command=php /var/www/esalud/backend/artisan queue:work database --queue=default --timeout=180 --memory=512 --sleep=1
```
Sin `--tries` (cada Job define el suyo: `ProcessRemUploadJob`=1, los otros dos=2).

**Nginx**: dos server blocks — backend (PHP-FPM, `client_max_body_size 50M` para los `.xlsm`) y frontend (`dist/` con fallback SPA + proxy de `/api/` al backend). Configuración completa en `DEPLOYMENT.md` §6.

**HTTP/HTTPS**: ⏳ **condición previa de despliegue, sigue sin confirmar con Nelson (2026-09-01) — no asumir, preguntar explícitamente.** Mientras sea HTTP, `SESSION_SECURE_COOKIE=false` (con `true` sobre HTTP el navegador descarta la cookie de sesión en silencio — login parece exitoso pero la sesión nunca persiste). Cambiar a `true` solo cuando haya HTTPS realmente activo.

---

## 7. Checklist de prueba REM real en producción (post-despliegue)

Antes de dar el despliegue por cerrado, verificar en este orden:

- [ ] `php artisan tinker` → estructura activa = **67** (v**35**), `rem_rules` = **798** (751 activas), `rem_rule_bindings` = **1655** totales (451 activos a la estructura 67).
- [ ] `ls storage/app/private/certificacion/cell-data/ | wc -l` = **381** (reconfirmar tamaño real, no asumir el valor de agosto).
- [ ] Login real con el usuario admin creado en el servidor — la sesión persiste tras recargar (valida HTTP/HTTPS + cookie correctamente configurados).
- [ ] Si el usuario tiene 2FA activo, el login exige el challenge TOTP/recovery code antes de entrar.
- [ ] Usuario admin ve Usuarios / Centros de Salud / Auditoría en el menú.
- [ ] Dashboard, Cargas REM, Calibración REM (tab **Matriz** inicial), Patrones y fórmulas, Motor de Reglas, Catálogo de Reglas y Comparación navegables sin error.
- [ ] Una ruta inexistente (o `/criterios-funcionales`) muestra la pantalla "Página no encontrada" propia de ATHENEA — nunca el error técnico de React Router.
- [ ] No existe la entrada "Criterios funcionales" en el menú ni en el Dashboard.
- [ ] `sudo supervisorctl status esalud-queue-worker:*` → `RUNNING`.
- [ ] Subir un **archivo REM real** (no de prueba sintética) desde la interfaz.
- [ ] La carga avanza `pending → processing → validating → success/with_errors` — **nunca queda indefinidamente en `validating`**, nunca queda en `rejected`.
- [ ] El resumen de validación muestra un número de reglas evaluadas coherente con las 798 restauradas / 751 activas (no un número sospechosamente bajo como 529, que indicaría que solo corrió el seeder base del CSV y no se restauró el dump real).
- [ ] `curl -I https://<dominio>` y `curl -I https://<dominio>/api/v1/health` → `200`.

Checklist detallado, con "qué pasa si se omite" y "evidencia" por cada paso: `docs/CHECKLIST_DESPLIEGUE_PRODUCCION.md`.

---

## 8. Documentación relacionada

| Documento | Para qué |
|---|---|
| `SISTEMA.md` (raíz) | Qué es el sistema, módulos, flujo, tablas, menú |
| `docs/MANUAL_TECNICO.md` | Arquitectura, requisitos, troubleshooting, referencia API |
| `docs/MANUAL_USUARIO.md` | Uso diario del sistema |
| `docs/handoff/DEPLOYMENT.md` | Guía operativa de despliegue completa, con justificación técnica de cada valor |
| `docs/CHECKLIST_DESPLIEGUE_PRODUCCION.md` | Checklist ejecutable paso a paso para Nelson |
| `docs/ESTADO_ACTUAL_PROYECTO.md` (este archivo) | Estado consolidado, punto de partida rápido |
