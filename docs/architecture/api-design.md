# Diseño de la API

> Fecha: 2026-05-27

## Base URL

```
/api/v1/
```

## Formato de respuesta

```json
{
  "data": { },
  "message": "Operación exitosa",
  "errors": null
}
```

Errores:

```json
{
  "data": null,
  "message": "Error de validación",
  "errors": {
    "email": ["El campo email es obligatorio"]
  }
}
```

## Códigos HTTP

| Código | Uso |
|---|---|
| 200 | Éxito (GET, PUT, PATCH) |
| 201 | Creado (POST) |
| 204 | Sin contenido (DELETE) |
| 400 | Error de validación |
| 401 | No autenticado |
| 403 | No autorizado (permisos) |
| 404 | Recurso no encontrado |
| 422 | Entidad no procesable |
| 429 | Demasiadas solicitudes |
| 500 | Error interno |

## Paginación

```
GET /api/v1/rem-uploads?page=2&per_page=15
```

Respuesta:

```json
{
  "data": [],
  "meta": {
    "current_page": 2,
    "last_page": 10,
    "per_page": 15,
    "total": 145
  }
}
```

## Filtros

```
GET /api/v1/rem-uploads?filter[year]=2026&filter[month]=3&filter[status]=completed
```

## Ordenamiento

```
GET /api/v1/rem-uploads?sort=-created_at&sort=rem_type
```

Prefijo `-` indica descendente.

## Autenticación

Header: `Authorization: Bearer {token}`

Los tokens se obtienen mediante Sanctum:

```
POST /api/v1/login
{ "email": "...", "password": "...", "device_name": "..." }
```

## Rate limiting

- 60 solicitudes por minuto para endpoints autenticados
- 10 solicitudes por minuto para login
