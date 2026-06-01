# Manual de despliegue

> **Fecha:** 2026-05-29
> **Entorno objetivo:** Windows Server 2022
> **Versión del sistema:** v0.4.2-alpha

---

## Índice

1. [Requisitos de infraestructura](#1-requisitos-de-infraestructura)
2. [Preparación del entorno](#2-preparación-del-entorno)
3. [Despliegue del backend](#3-despliegue-del-backend)
4. [Despliegue del frontend](#4-despliegue-del-frontend)
5. [Configuración de base de datos](#5-configuración-de-base-de-datos)
6. [Cola de procesamiento (Queue Worker)](#6-cola-de-procesamiento-queue-worker)
7. [Configuración HTTPS](#7-configuración-https)
8. [Verificación post-despliegue](#8-verificación-post-despliegue)
9. [Mantenimiento](#9-mantenimiento)
10. [Rollback](#10-rollback)

---

## 1. Requisitos de infraestructura

### Software requerido

| Componente | Versión mínima | Descarga |
|------------|---------------|----------|
| Windows Server | 2022 | — |
| PHP | 8.3.x (no threadsafe) | https://windows.php.net/download/ |
| Composer | 2.x | https://getcomposer.org/ |
| MySQL | 8.0.x | https://dev.mysql.com/downloads/installer/ |
| Node.js | 20.x LTS | https://nodejs.org/ |
| Git | 2.x | https://git-scm.com/ |
| IIS o Apache | — | Incluido en Windows Server / XAMPP |

### Extensiones PHP requeridas

Las siguientes extensiones deben estar habilitadas en `php.ini`:

```
extension=bcmath
extension=ctype
extension=fileinfo
extension=json
extension=mbstring
extension=openssl
extension=pdo
extension=pdo_mysql
extension=tokenizer
extension=xml
extension=zip
```

Verificar con:

```cmd
php -m
```

### Puertos necesarios

| Puerto | Servicio | Propósito |
|--------|----------|-----------|
| 80 | HTTP | Tráfico web (con HTTPS: 443) |
| 443 | HTTPS | Tráfico seguro |
| 3306 | MySQL | Base de datos (solo interno) |

---

## 2. Preparación del entorno

### 2.1 Instalar PHP

1. Descargar PHP 8.3 (x64 Non-Thread Safe) desde https://windows.php.net/download/
2. Extraer a `C:\PHP`
3. Agregar `C:\PHP` al `PATH` del sistema (Variables de Entorno → Path)
4. Copiar `C:\PHP\php.ini-development` como `C:\PHP\php.ini`
5. Habilitar extensiones listadas arriba (quitar `;` del inicio)

Verificar:

```cmd
php -v
```

### 2.2 Instalar Composer

```cmd
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php --install-dir=C:\PHP
php -r "unlink('composer-setup.php');"
```

Verificar:

```cmd
composer --version
```

### 2.3 Instalar Node.js

Descargar e instalar Node.js 20.x LTS desde https://nodejs.org/. Verificar:

```cmd
node --version
npm --version
```

### 2.4 Instalar MySQL

1. Descargar MySQL Installer desde https://dev.mysql.com/downloads/installer/
2. Instalar MySQL Server 8.0.x
3. Durante la instalación, configurar contraseña para usuario `root`
4. Anotar el puerto (por defecto 3306)

---

## 3. Despliegue del backend

### 3.1 Clonar el repositorio

```cmd
cd C:\inetpub\wwwroot
git clone <repo-url> esalud
cd esalud\backend
```

### 3.2 Configurar variables de entorno

```cmd
copy .env.example .env
```

Editar `C:\inetpub\wwwroot\esalud\backend\.env` para producción:

```ini
APP_NAME=Esalud
APP_ENV=production
APP_DEBUG=false
APP_URL=https://esalud.cormudesi.cl

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=esalud
DB_USERNAME=root
DB_PASSWORD=********

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax

SANCTUM_STATEFUL_DOMAINS=esalud.cormudesi.cl
SESSION_DOMAIN=.cormudesi.cl

QUEUE_CONNECTION=database

FILESYSTEM_DISK=local
```

### 3.3 Instalar dependencias

```cmd
composer install --optimize-autoloader --no-dev
```

### 3.4 Generar clave de aplicación

```cmd
php artisan key:generate
```

### 3.5 Optimizar Laravel para producción

```cmd
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

---

## 4. Despliegue del frontend

### 4.1 Configurar variables de entorno

```cmd
cd C:\inetpub\wwwroot\esalud\frontend
copy .env.example .env
```

Editar `frontend\.env`:

```ini
VITE_API_URL=/api/v1
```

### 4.2 Instalar dependencias y compilar

```cmd
npm ci
npm run build
```

Esto genera los archivos estáticos en `frontend/dist/`.

### 4.3 Servir el frontend

**Opción A: IIS** — Configurar el sitio web apuntando a `C:\inetpub\wwwroot\esalud\frontend\dist\` con reglas de rewrite para SPA (redirigir todo a `index.html`).

**Opción B: Nginx** — Proxy reverso que sirva `frontend/dist/` como estáticos y derive `/api/*` al backend PHP.

---

## 5. Configuración de base de datos

### 5.1 Crear base de datos

```cmd
mysql -u root -p
```

```sql
CREATE DATABASE esalud CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
EXIT;
```

### 5.2 Ejecutar migraciones

```cmd
cd C:\inetpub\wwwroot\esalud\backend
php artisan migrate --force
```

### 5.3 Poblar datos iniciales

```cmd
php artisan db:seed --class=RemTemplateSeeder --force
```

### 5.4 Usuario administrador inicial

Las credenciales por defecto se crean vía migración o seeder. Por defecto:

- **Email:** `admin@esalud.cl`
- **Contraseña:** `password`

> **⚠️ Cambiar inmediatamente después del primer inicio de sesión.**

---

## 6. Cola de procesamiento (Queue Worker)

El procesamiento de archivos REM es asíncrono. Se requiere un worker corriendo permanentemente.

### Opción A: NSSM (recomendada — servicio Windows)

1. Descargar NSSM desde https://nssm.cc/
2. Instalar como servicio:

```cmd
nssm install EsaludQueueWorker
```

Configurar:
- **Application Path:** `C:\PHP\php.exe`
- **Arguments:** `artisan queue:work --queue=default --tries=3 --timeout=300 --sleep=3`
- **Startup directory:** `C:\inetpub\wwwroot\esalud\backend`
- **Logging:** habilitar stdout/stderr a archivo

Iniciar servicio:

```cmd
nssm start EsaludQueueWorker
```

### Opción B: Task Scheduler (alternativa)

Crear tarea programada:
- **Trigger:** Al iniciar el sistema
- **Action:** Iniciar programa `C:\PHP\php.exe`
- **Arguments:** `C:\inetpub\wwwroot\esalud\backend\artisan queue:work --queue=default --tries=3 --timeout=300 --sleep=3`

### Verificar worker

```cmd
php artisan queue:monitor
```

---

## 7. Configuración HTTPS

> **Requerido por Ley N° 21.663 de Ciberseguridad.** Toda comunicación con el sistema debe ser cifrada.

### Opción 1: Certificado autofirmado (LAN interna)

Adecuado para redes internas. Los usuarios verán advertencia de seguridad.

**Con IIS:**
1. Abrir IIS Manager
2. Seleccionar el servidor → **Server Certificates**
3. **Create Self-Signed Certificate**
4. Ingresar nombre descriptivo (ej: "Esalud Internal")
5. Seleccionar el sitio web → **Bindings** → Agregar `https` con el certificado creado

### Opción 2: mkcert (LAN con confianza local)

mkcert genera certificados reconocidos localmente sin advertencias en los equipos configurados.

```cmd
choco install mkcert  # o descargar desde https://github.com/FiloSottile/mkcert
mkcert -install
mkcert esalud.cormudesi.cl localhost 127.0.0.1 ::1
```

Esto genera `esalud.cormudesi.cl+3.pem` y `esalud.cormudesi.cl+3-key.pem`. Configurar IIS o Nginx para usarlos.

### Opción 3: Let's Encrypt (recomendada — dominio público)

Si el servidor tiene dominio público, usar Certbot o win-acme:

```cmd
# Con win-acme
choco install win-acme
wacs.exe
```

Seleccionar el sitio IIS y seguir las instrucciones. Renovación automática cada 90 días.

### Verificar HTTPS

```cmd
curl -I https://esalud.cormudesi.cl
```

Esperar `HTTP/1.1 200 OK` con protocolo `TLS`.

---

## 8. Verificación post-despliegue

### Smoke tests

```bash
# 1. API responde
curl -I http://localhost:8000/api/v1/health

# 2. Login funciona
curl -X POST http://localhost:8000/api/v1/auth/login ^
  -H "Content-Type: application/json" ^
  -d "{\"email\":\"admin@esalud.cl\",\"password\":\"password\"}"

# 3. Frontend sirve (si está configurado)
curl -I http://localhost:5173

# 4. Queue worker activo
php artisan queue:monitor

# 5. Base de datos conectada
php artisan db:show

# 6. Discovery REM funcional (requiere archivo .xlsm)
php artisan rem:discover "storage/app/rem-uploads/{test-file}" --output=both
```

### Lista de verificación

- [ ] Backend responde en puerto configurado
- [ ] Frontend carga sin errores de consola
- [ ] Login funciona con credenciales de admin
- [ ] HTTPS configurado y accesible
- [ ] Queue worker corriendo como servicio
- [ ] Migraciones ejecutadas sin error
- [ ] Disco `rem-uploads` creado en `storage/app/`
- [ ] Extensión zip habilitada en PHP
- [ ] CORS configurado (Sanctum stateful domains)
- [ ] Logs de Laravel escribiendo en `storage/logs/`

---

## 9. Mantenimiento

### Actualizar el sistema

```cmd
cd C:\inetpub\wwwroot\esalud
git pull origin main
cd backend
composer install --optimize-autoloader --no-dev
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
cd ..\frontend
npm ci
npm run build
```

### Respaldos

- **Base de datos:** `mysqldump -u root -p esalud > backup_%DATE%.sql`
- **Archivos subidos:** `storage/app/rem-uploads/`
- **Logs:** `storage/logs/`

### Monitorear logs

```cmd
type C:\inetpub\wwwroot\esalud\backend\storage\logs\laravel.log
```

---

## 10. Rollback

### Revertir a versión anterior

```cmd
cd C:\inetpub\wwwroot\esalud
git log --oneline -10
git checkout <commit-hash-anterior>
cd backend
composer install --optimize-autoloader --no-dev
php artisan migrate:rollback --force
php artisan config:cache
php artisan route:cache
```

### Restaurar base de datos

```cmd
mysql -u root -p esalud < backup_anterior.sql
```

---

> **Documentación actualizada:** 2026-05-29
> **Siguiente revisión:** Al completar Fase 06 (Frontend)
