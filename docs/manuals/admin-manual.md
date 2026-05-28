# Manual de administración

> Fecha: 2026-05-28

## Acceso

Solo usuarios con rol **Administrador** pueden acceder a las páginas de gestión. Los roles Analista y Lector solo ven el Dashboard.

URL: `http://localhost:5173/login`

Credenciales por defecto: `admin@esalud.cl` / `password`

## 1. Gestión de usuarios

### Listar usuarios
- Navegar a **Usuarios** en el sidebar
- Buscar por nombre, email o RUT usando el campo de búsqueda
- Filtrar por rol con el selector desplegable

### Crear usuario
1. Click en **Nuevo Usuario**
2. Completar: nombre, RUT (formato chileno: `12345678-5`), email, contraseña, confirmación
3. Seleccionar rol: Administrador, Analista o Lector
4. Seleccionar centro de salud (opcional)
5. Click en **Crear Usuario**

### Editar usuario
1. Click en **Editar** en la fila correspondiente
2. Modificar los campos necesarios
3. La contraseña es opcional en edición (solo se actualiza si se completa)
4. Click en **Actualizar**

### Eliminar usuario
1. Click en **Eliminar** en la fila correspondiente
2. Confirmar en el diálogo de confirmación
3. La eliminación es **soft delete** (el usuario se oculta pero no se elimina físicamente)
4. No es posible auto-eliminarse

## 2. Roles y permisos

| Rol | Acceso |
|---|---|
| Administrador | CRUD usuarios, centros, auditoría, dashboard |
| Analista | Solo dashboard |
| Lector | Solo dashboard |

Los permisos granulares se expandirán en fases futuras.

## 3. Centros de salud

Misma interfaz que usuarios:

- **Listar**: nombres, código DEIS, tipo, dirección, comuna, usuarios asociados
- **Crear**: nombre, código DEIS (único), tipo (CESFAM/CECOSF/PSR/SAPU/SAR/OTRO), dirección, comuna
- **Editar**: cualquier campo
- **Eliminar**: soft delete. No se puede eliminar si tiene usuarios asignados.

## 4. Auditoría

- Navegar a **Auditoría** en el sidebar
- Filtrar por: evento (creado/actualizado/eliminado), entidad (Usuario/Centro), rango de fechas
- Click en una fila para ver los cambios detallados (valores antes/después en JSON)
- Solo visible para Administradores

## 5. Plantillas REM

[Pendiente — Fase 04+]

## 6. Configuración de metas sanitarias

[Pendiente — Fase 05+]

## 7. Mantenimiento del sistema

[Pendiente — Fase 09+]

## 8. Respaldo y recuperación

[Pendiente — Fase 09+]
