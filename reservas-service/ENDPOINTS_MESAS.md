# Endpoints de Reservas de Mesas

Este documento describe todos los endpoints disponibles para gestionar reservas de mesas en el microservicio de reservas.

## Base URL
```
http://localhost/microservices/reservas-service/index.php
```

## Autenticación
Todos los endpoints requieren un token JWT en el header `Authorization`:
```
Authorization: Bearer <token_jwt>
```

## 1. Consultar Mis Reservas de Cabañas

### Endpoint
**GET** `/reservas/mis-reservas`

### Descripción
Devuelve únicamente las reservas de cabañas del usuario autenticado en el front.

### Parámetros
No requiere parámetros de query ni body.

### Ejemplo de Petición
```bash
curl -X GET "http://localhost/microservices/reservas-service/index.php/reservas/mis-reservas" \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json"
```

### Respuesta Exitosa (200)
```json
{
  "status": 200,
  "data": [
    {
      "id_reserva": 10,
      "fecha_hora_inicio": "2026-05-10 10:00:00",
      "fecha_hora_fin": "2026-05-10 12:00:00",
      "id_usuario": 5,
      "estado": 1,
      "cantidad_personas": 4,
      "id_cabania": 3,
      "nombre_cabania": "Cabaña La Montaña"
    }
  ]
}
```

## 2. Consultar Mis Reservas de Mesas

### Endpoint
**GET** `/reservas/mesas/mis-reservas`

### Descripción
Devuelve únicamente las reservas de mesas del usuario autenticado en el front.

### Parámetros
No requiere parámetros de query ni body.

### Ejemplo de Petición
```bash
curl -X GET "http://localhost/microservices/reservas-service/index.php/reservas/mesas/mis-reservas" \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json"
```

### Respuesta Exitosa (200)
```json
{
  "status": 200,
  "data": [
    {
      "id_reserva": 15,
      "fecha_hora_inicio": "2026-05-10 10:00:00",
      "fecha_hora_fin": "2026-05-10 12:00:00",
      "id_usuario": 5,
      "estado": 1,
      "cantidad_personas": 4,
      "id_mesa": 2
    }
  ]
}
```

---

## 3. Consultar Disponibilidad de Mesas

### Endpoint
**GET** `/reservas/mesas/disponibilidad`

### Parámetros Query
- `fecha_hora_inicio` (requerido): Fecha-hora de inicio en formato `Y-m-d H:i:s`
- `fecha_hora_fin` (requerido): Fecha-hora de fin en formato `Y-m-d H:i:s`
- `id_mesa` (opcional): ID de mesa específica. Si no se envía, retorna disponibilidad de todas las mesas.

### Ejemplo de Petición

**Sin ID de mesa (todas):**
```bash
curl -X GET "http://localhost/microservices/reservas-service/index.php/reservas/mesas/disponibilidad?fecha_hora_inicio=2026-05-10%2010:00:00&fecha_hora_fin=2026-05-10%2012:00:00" \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json"
```

**Con ID de mesa específica:**
```bash
curl -X GET "http://localhost/microservices/reservas-service/index.php/reservas/mesas/disponibilidad?id_mesa=1&fecha_hora_inicio=2026-05-10%2010:00:00&fecha_hora_fin=2026-05-10%2012:00:00" \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json"
```

### Respuesta Exitosa (200)

**Todas las mesas:**
```json
{
  "status": 200,
  "data": [
    {
      "id_mesa": 1,
      "fecha_hora_inicio": "2026-05-10 10:00:00",
      "fecha_hora_fin": "2026-05-10 12:00:00",
      "disponible": true
    },
    {
      "id_mesa": 2,
      "fecha_hora_inicio": "2026-05-10 10:00:00",
      "fecha_hora_fin": "2026-05-10 12:00:00",
      "disponible": false
    }
  ]
}
```

**Mesa específica:**
```json
{
  "status": 200,
  "data": {
    "id_mesa": 1,
    "fecha_hora_inicio": "2026-05-10 10:00:00",
    "fecha_hora_fin": "2026-05-10 12:00:00",
    "disponible": true
  }
}
```

---

## 4. Listar Reservas de Mesas

### Endpoint
**GET** `/reservas/mesas`

### Parámetros Query (opcionales)
- `id_usuario`: Listar reservas de un usuario específico
- `id_reserva`: Obtener una reserva específica
- `id_mesa`: Listar reservas de una mesa específica

Sin parámetros retorna todas las reservas de mesas.

### Ejemplo de Petición

**Todas las reservas:**
```bash
curl -X GET "http://localhost/microservices/reservas-service/index.php/reservas/mesas" \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json"
```

**Reservas de un usuario:**
```bash
curl -X GET "http://localhost/microservices/reservas-service/index.php/reservas/mesas?id_usuario=5" \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json"
```

**Una reserva específica:**
```bash
curl -X GET "http://localhost/microservices/reservas-service/index.php/reservas/mesas?id_reserva=10" \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json"
```

**Reservas de una mesa:**
```bash
curl -X GET "http://localhost/microservices/reservas-service/index.php/reservas/mesas?id_mesa=2" \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json"
```

### Respuesta Exitosa (200)

```json
{
  "status": 200,
  "data": [
    {
      "id_reserva": 10,
      "fecha_hora_inicio": "2026-05-10 10:00:00",
      "fecha_hora_fin": "2026-05-10 12:00:00",
      "id_usuario": 5,
      "estado": 1,
      "cantidad_personas": 4,
      "id_mesa": 2
    }
  ]
}
```

---

## 5. Crear Reserva de Mesa

### Endpoint
**POST** `/reservas/mesas`

### Body (JSON)
```json
{
  "fecha_hora_inicio": "2026-05-10 10:00:00",
  "fecha_hora_fin": "2026-05-10 12:00:00",
  "id_usuario": 5,
  "estado": 1,
  "cantidad_personas": 4,
  "descripcion": "Cumpleaños familiar",
  "id_mesa": 2
}
```

### Campos Requeridos
- `fecha_hora_inicio`: Formato `Y-m-d H:i:s`
- `fecha_hora_fin`: Formato `Y-m-d H:i:s` (debe ser mayor a inicio)
- `id_usuario`: ID de usuario (número entero)
- `estado`: Estado de reserva (número entero)
- `cantidad_personas`: Número de personas (entero > 0)
- `id_mesa`: ID de mesa (número entero)

### Campos Opcionales
- `descripcion`: Descripción libre de la reserva (`TEXT`)

### Ejemplo de Petición

```bash
curl -X POST "http://localhost/microservices/reservas-service/index.php/reservas/mesas" \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{
    "fecha_hora_inicio": "2026-05-10 10:00:00",
    "fecha_hora_fin": "2026-05-10 12:00:00",
    "id_usuario": 5,
    "estado": 1,
    "cantidad_personas": 4,
    "descripcion": "Cumpleaños familiar",
    "id_mesa": 2
  }'
```

### Respuesta Exitosa (201)

```json
{
  "status": 201,
  "message": "Reserva de mesa creada exitosamente",
  "data": {
    "id_reserva": 15,
    "fecha_hora_inicio": "2026-05-10 10:00:00",
    "fecha_hora_fin": "2026-05-10 12:00:00",
    "id_usuario": 5,
    "estado": 1,
    "cantidad_personas": 4,
    "id_mesa": 2
  }
}
```

### Errores Comunes

- **409 - MESA_NO_DISPONIBLE**: La mesa ya tiene otra reserva en ese rango de fechas
- **409 - RESERVA_DUPLICADA**: El usuario ya tiene la misma reserva
- **404 - MESA_NOT_FOUND**: La mesa no existe

---

## 6. Actualizar Reserva de Mesa

### Endpoint
**PUT** `/reservas/mesas`

### Body (JSON)
```json
{
  "id_reserva": 15,
  "fecha_hora_inicio": "2026-05-10 14:00:00",
  "fecha_hora_fin": "2026-05-10 16:00:00",
  "id_usuario": 5,
  "estado": 1,
  "cantidad_personas": 6,
  "descripcion": "Reserva reprogramada",
  "id_mesa": 3
}
```

### Campos Requeridos
Todos los campos del POST + `id_reserva`

### Campos Opcionales
- `descripcion`: Descripción libre de la reserva

### Ejemplo de Petición

```bash
curl -X PUT "http://localhost/microservices/reservas-service/index.php/reservas/mesas" \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{
    "id_reserva": 15,
    "fecha_hora_inicio": "2026-05-10 14:00:00",
    "fecha_hora_fin": "2026-05-10 16:00:00",
    "id_usuario": 5,
    "estado": 1,
    "cantidad_personas": 6,
    "descripcion": "Reserva reprogramada",
    "id_mesa": 3
  }'
```

### Respuesta Exitosa (200)

```json
{
  "status": 200,
  "message": "Reserva de mesa actualizada exitosamente",
  "data": {
    "id_reserva": 15,
    "fecha_hora_inicio": "2026-05-10 14:00:00",
    "fecha_hora_fin": "2026-05-10 16:00:00",
    "id_usuario": 5,
    "estado": 1,
    "cantidad_personas": 6,
    "id_mesa": 3
  }
}
```

---

## 7. Eliminar Reserva de Mesa

### Endpoint
**DELETE** `/reservas/mesas`

### Parámetros
- `id_reserva` (requerido): Puede venir como query parameter o en el body JSON

### Ejemplo de Petición (Query Parameter)

```bash
curl -X DELETE "http://localhost/microservices/reservas-service/index.php/reservas/mesas?id_reserva=15" \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json"
```

### Ejemplo de Petición (Body JSON)

```bash
curl -X DELETE "http://localhost/microservices/reservas-service/index.php/reservas/mesas" \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{
    "id_reserva": 15
  }'
```

### Respuesta Exitosa (200)

```json
{
  "status": 200,
  "message": "Reserva de mesa eliminada exitosamente",
  "id_reserva": 15
}
```

---

## Códigos de Error Comunes

| Código | Descripción |
|--------|-------------|
| 400 | Bad Request - Parámetros inválidos o faltantes |
| 401 | Unauthorized - Token inválido o expirado |
| 404 | Not Found - Recurso no encontrado |
| 409 | Conflict - Conflicto (mesa no disponible, reserva duplicada) |
| 500 | Internal Server Error - Error en la BD |

---

## Notas Importantes

1. **Tabla de BD requerida:** `mesa` (con al menos `id_mesa`)
2. **Tabla pivote requerida:** `reserva_mesa` (con `id_reserva` e `id_mesa`)
3. **Tabla base:** `reserva` (reutilizada para cabañas y mesas)
4. El estado `0` se considera "cancelada" y no se cuenta en disponibilidad
5. Las fechas deben estar en formato `Y-m-d H:i:s`
6. Todas las operaciones requieren autenticación JWT válida
7. El campo `descripcion` es **opcional** en todas las operaciones de reserva
