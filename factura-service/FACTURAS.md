# Guía de Facturas

## Descripción

El servicio de facturas permite generar, consultar, actualizar y enviar facturas asociadas a reservas confirmadas. También ofrece una vista imprimible en HTML para exportar la factura como PDF desde el navegador.

**Nota importante:** Las facturas están vinculadas a reservas confirmadas (estado != 0). Solo se pueden generar facturas de reservas que tengan un precio configurado en la cabaña o mesa.

Todas las peticiones a este servicio deben entrar por el gateway usando el prefijo `/facturas`.

### Base URL para el Gateway
```
http://localhost/microservices/gateway/facturas
```

## Permisos de Acceso

- **Administrador**: puede listar facturas, generar facturas de cualquier reserva, cambiar estado y ver detalles.
- **Sistema interno**: puede generar facturas usando `X-Internal-Key`.
- **Usuario autenticado**: puede consultar sus propias facturas y generar factura solo para sus propias reservas confirmadas.

---

## Estructura de Base de Datos

### Tabla `factura`
- `id_factura` (INT, PRIMARY KEY)
- `numero_factura` (INT, UNIQUE) - Número consecutivo de factura
- `fecha_emision` (DATETIME) - Fecha de generación
- `subtotal` (INT) - Subtotal sin IVA
- `impuestos` (INT) - Total de IVA
- `estado` (ENUM: 'paga', 'pendiente') - Estado de pago
- `id_reserva` (INT, FOREIGN KEY) - Referencia a reserva
- `total` (INT) - Total con IVA

### Tabla `detalle_factura`
- `id_detalle` (INT, PRIMARY KEY)
- `descripcion` (VARCHAR) - Descripción del item
- `cantidad` (INT) - Cantidad de horas/unidades
- `precio_unitario` (INT) - Precio por unidad
- `subtotal` (INT) - Cantidad × Precio unitario
- `id_factura` (INT, FOREIGN KEY) - Referencia a factura
- `iva_unitario` (INT) - IVA por unidad
- `total` (INT) - Subtotal + IVA
- `iva_subtotal` (INT) - IVA total del detalle

## Notas Importantes

- La factura se genera a partir de una reserva confirmada (estado != 0).
- Una reserva solo debe tener una factura.
- El número de factura es consecutivo.
- Los precios deben estar configurados en la tabla `cabania` o `mesa`.
- El IVA se calcula automáticamente (19% por defecto).

---

## Endpoints

### 1. Listar Facturas

**GET** `/facturas`

Retorna todas las facturas (admin) o solo las del usuario autenticado.

#### Headers Requeridos
```
Authorization: Bearer <token_jwt>
```

#### Parámetros Query (Opcionales)
- `id_factura`: Obtener una factura específica
- `id_reserva`: Obtener factura de una reserva específica

#### Respuesta Exitosa (200)
```json
{
  "status": 200,
  "data": [
    {
      "id_factura": 1,
      "numero_factura": 1,
      "fecha_emision": "2026-05-07 14:30:00",
      "subtotal": 100000,
      "impuestos": 19000,
      "estado": "paga",
      "id_reserva": 5,
      "total": 119000
    }
  ]
}
```

---

### 2. Generar Factura desde Reserva

**POST** `/facturas/generar`

Genera una factura a partir de una reserva confirmada.

#### Headers Requeridos
```
Authorization: Bearer <token_jwt>
Content-Type: application/json
```

El usuario autenticado puede generar factura solo de una reserva que le pertenezca. El administrador puede generar facturas de cualquier reserva.

#### Body (JSON)
```json
{
  "id_reserva": 5
}
```

#### Respuesta Exitosa (201)
```json
{
  "status": 201,
  "message": "Factura creada exitosamente",
  "data": {
    "factura": {
      "id_factura": 1,
      "numero_factura": 1,
      "fecha_emision": "2026-05-07 14:30:00",
      "subtotal": 100000,
      "impuestos": 19000,
      "estado": "paga",
      "id_reserva": 5,
      "total": 119000,
      "detalles": [
        {
          "id_detalle": 1,
          "descripcion": "Reserva de cabaña: Cabaña La Montaña",
          "cantidad": 2,
          "precio_unitario": 50000,
          "subtotal": 100000,
          "iva_unitario": 9500,
          "iva_subtotal": 19000,
          "total": 119000
        }
      ]
    },
    "tipo_reserva": "cabania",
    "cantidad": 2,
    "precio_base": 50000
  }
}
```

#### Errores Comunes
- **403 - FORBIDDEN**: La reserva no pertenece al usuario autenticado
- **404 - RESERVA_NOT_FOUND**: La reserva no existe
- **409 - RESERVA_INVALIDA**: La reserva está cancelada
- **409 - FACTURA_EXISTENTE**: La reserva ya tiene factura
- **409 - PRECIO_NO_CONFIGURADO**: La cabaña/mesa no tiene precio

---

### 3. Cambiar Estado de Factura

**PUT** `/facturas/estado`

Cambia el estado de pago de una factura (paga o pendiente).

#### Headers Requeridos
```
Authorization: Bearer <token_jwt> (Admin)
Content-Type: application/json
```

#### Body (JSON)
```json
{
  "id_factura": 1,
  "estado": "pendiente"
}
```

#### Respuesta Exitosa (200)
```json
{
  "status": 200,
  "message": "Estado de factura actualizado exitosamente",
  "data": {
    "id_factura": 1,
    "estado": "pendiente"
  }
}
```

---

### 4. Ver Factura en PDF/HTML

**GET** `/facturas/pdf?id_factura=1`

Retorna una vista HTML imprimible de la factura. Se abre el cuadro de impresión automáticamente.

#### Headers Requeridos
```
Authorization: Bearer <token_jwt>
```

#### Respuesta
HTML con estilos CSS listos para imprimir. El navegador abre automáticamente el diálogo de impresión.

---

## Códigos de Error

| Código | Descripción |
|--------|-------------|
| 400 | Bad Request - Parámetros inválidos |
| 401 | Unauthorized - Token inválido |
| 403 | Forbidden - No tiene permisos |
| 404 | Not Found - Recurso no encontrado |
| 409 | Conflict - Conflicto de lógica |
| 500 | Internal Server Error - Error en BD |

---

## Ejemplo Completo

### 1. Crear una reserva
```bash
curl -X POST "http://localhost/microservices/gateway/reservas" \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{
    "fecha_hora_inicio": "2026-05-10 10:00:00",
    "fecha_hora_fin": "2026-05-10 12:00:00",
    "id_usuario": 5,
    "estado": 1,
    "cantidad_personas": 4,
    "nombre_cabania": "Cabaña La Montaña"
  }'
```

### 2. Generar factura
```bash
curl -X POST "http://localhost/microservices/gateway/facturas/generar" \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"id_reserva": 1}'
```

Si la reserva no pertenece al usuario autenticado, el servicio responde `403 FORBIDDEN`.

### 3. Consultar factura
```bash
curl -X GET "http://localhost/microservices/gateway/facturas?id_factura=1" \
  -H "Authorization: Bearer <token>"
```

### 4. Descargar PDF
```
http://localhost/microservices/gateway/facturas/pdf?id_factura=1
(Con token en header Authorization)
```
