# Estado actual del proyecto — Esalud

> Documento oficial de estado, pensado para leerse en 5 minutos y saber
> exactamente en qué punto está el proyecto ahora mismo. Complementa (no
> reemplaza) [`SISTEMA.md`](../SISTEMA.md), [`docs/MANUAL_TECNICO.md`](MANUAL_TECNICO.md),
> [`docs/handoff/DEPLOYMENT.md`](handoff/DEPLOYMENT.md) y
> [`docs/CHECKLIST_DESPLIEGUE_PRODUCCION.md`](CHECKLIST_DESPLIEGUE_PRODUCCION.md).
>
> **Última actualización:** 2026-08-05.
> **Último commit estable:** `d02e88e1964f5060bedbb3f289e1a24ed127de15`
> (rama `main`, sincronizado con `origin/main`).

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
| **RuleEngine (RF-02)** | ✅ Operativo | 764 reglas activas, 859 bindings, estructura activa **v15/id=36** |
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
| Estructura REM activa (v15/id=36), `rem_rules` (764), `rem_rule_bindings` (859), `rem_templates`, `health_centers` | Solo en MySQL local (`esalud_dev`) | — | `mysqldump` selectivo (`DEPLOYMENT.md` §3.1) |
| `cell-data/` + `reglas-funcionales.json` + `serie-a-catalogo.json` | `storage/app/private/certificacion/` (100% gitignorado) | 108MB / 381 archivos + 2.4MB | `tar` de la carpeta (`DEPLOYMENT.md` §3.2) |
| `rem_uploads` (106) / `rem_data` (260.071 filas) | MySQL local | — | **No llevar** — datos de prueba, producción empieza limpia |
| `users` (3 cuentas de prueba: admin, demo, Francisco Arcos) | MySQL local | — | **No llevar** — Nelson crea el admin real vía `AdminUserSeeder` |

---

## 5. Comandos post-pull (resumen — detalle completo en `DEPLOYMENT.md` §8 y `CHECKLIST_DESPLIEGUE_PRODUCCION.md`)

```bash
cd /var/www/esalud && git pull origin main

cd backend
composer install --no-dev --optimize-autoloader
php artisan migrate --force
# restaurar dump SQL + tar de certificacion/ (ver §4 arriba) antes de seguir
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

**HTTP/HTTPS**: ⏳ **pendiente de confirmación con Nelson.** Mientras sea HTTP, `SESSION_SECURE_COOKIE=false` (con `true` sobre HTTP el navegador descarta la cookie de sesión en silencio — login parece exitoso pero la sesión nunca persiste). Cambiar a `true` solo cuando haya HTTPS realmente activo.

---

## 7. Checklist de prueba REM real en producción (post-despliegue)

Antes de dar el despliegue por cerrado, verificar en este orden:

- [ ] `php artisan tinker` → estructura activa = **36**, `rem_rules` = **764**, `rem_rule_bindings` = **859**.
- [ ] `ls storage/app/private/certificacion/cell-data/ | wc -l` = **381**.
- [ ] Login real con el usuario admin creado en el servidor — la sesión persiste tras recargar (valida HTTP/HTTPS + cookie correctamente configurados).
- [ ] Usuario admin ve Usuarios / Centros de Salud / Auditoría en el menú.
- [ ] `sudo supervisorctl status esalud-queue-worker:*` → `RUNNING`.
- [ ] Subir un **archivo REM real** (no de prueba sintética) desde la interfaz.
- [ ] La carga avanza `pending → processing → validating → success/with_errors` — **nunca queda indefinidamente en `validating`**, nunca queda en `rejected`.
- [ ] El resumen de validación muestra un número de reglas evaluadas coherente con las 764 restauradas (no un número sospechosamente bajo, que indicaría que solo corrió el seeder base del CSV y no se restauró el dump real).
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
