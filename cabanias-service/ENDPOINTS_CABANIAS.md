# Endpoints de Cabanias

Este documento describe todos los endpoints disponibles para gestionar cabanias en el microservicio de cabanias.

## Base URL
```
http://localhost/microservices/cabanias-service/index.php
```

## Autenticación
Todos los endpoints requieren un token JWT en el header `Authorization`:
```
Authorization: Bearer <token_jwt>
```

---

## 1. Obtener Todas las Cabanias

### Endpoint
**GET** `/cabanias`

### Descripción
Devuelve la lista completa de todas las cabanias disponibles en el sistema.

### Parámetros
No requiere parámetros (para obtener todas).

### Ejemplo de Petición
```bash
curl -X GET "http://localhost/microservices/cabanias-service/index.php/cabanias" \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json"
```

### Respuesta Exitosa (200)
```json
{
  "status": 200,
  "data": [
    {
      "id_cabania": 1,
      "nombre": "Cabaña del Bosque",
      "descripcion": "Hermosa cabaña rodeada de naturaleza",
      "ubicacion": "Sierra de la Macarena",
      "capacidad_maxima": 6,
      "precio_noche": 250000,
      "url_imagen": "http://localhost/microservices/cabanias-service/uploads/cabania_1.jpg"
    },
    {
      "id_cabania": 2,
      "nombre": "Cabaña La Montaña",
      "descripcion": "Vistas espectaculares a la montaña",
      "ubicacion": "Cordillera Central",
      "capacidad_maxima": 8,
      "precio_noche": 350000,
      "url_imagen": "http://localhost/microservices/cabanias-service/uploads/cabania_2.jpg"
    }
  ]
}
```

---

## 2. Buscar Cabaña por Nombre

### Endpoint
**GET** `/cabanias?nombre=<nombre>`

### Descripción
Busca una cabaña específica por su nombre (búsqueda parcial).

### Parámetros Query
- `nombre` (requerido): Nombre de la cabaña a buscar. Acepta búsqueda parcial.

### Validaciones
- El nombre debe contener solo letras y espacios
- No puede estar vacío

### Ejemplo de Petición
```bash
curl -X GET "http://localhost/microservices/cabanias-service/index.php/cabanias?nombre=Bosque" \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json"
```

### Respuesta Exitosa (200)
```json
{
  "status": 200,
  "id_cabania": 1,
  "nombre": "Cabaña del Bosque",
  "descripcion": "Hermosa cabaña rodeada de naturaleza",
  "ubicacion": "Sierra de la Macarena",
  "capacidad_maxima": 6,
  "precio_noche": 250000,
  "url_imagen": "http://localhost/microservices/cabanias-service/uploads/cabania_1.jpg"
}
```

### Errores
- **400** - INVALID_NAME: El nombre contiene caracteres inválidos
- **404** - CABANIA_NOT_FOUND: No se encontró cabaña con ese nombre

---

## 3. Obtener Cabaña por ID

### Endpoint
**GET** `/cabanias?id_cabania=<id>`

### Descripción
Obtiene los detalles de una cabaña específica por su ID.

### Parámetros Query
- `id_cabania` (requerido): ID numérico de la cabaña

### Validaciones
- El ID debe ser un número entero válido

### Ejemplo de Petición
```bash
curl -X GET "http://localhost/microservices/cabanias-service/index.php/cabanias?id_cabania=1" \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json"
```

### Respuesta Exitosa (200)
```json
{
  "status": 200,
  "id_cabania": 1,
  "nombre": "Cabaña del Bosque",
  "descripcion": "Hermosa cabaña rodeada de naturaleza",
  "ubicacion": "Sierra de la Macarena",
  "capacidad_maxima": 6,
  "precio_noche": 250000,
  "url_imagen": "http://localhost/microservices/cabanias-service/uploads/cabania_1.jpg"
}
```

### Errores
- **400** - INVALID_ID_CABANIA: El ID no es un número entero válido
- **404** - CABANIA_NOT_FOUND: No existe cabaña con ese ID

---

## 4. Crear una Nueva Cabaña

### Endpoint
**POST** `/cabanias`

### Descripción
Crea una nueva cabaña en el sistema. Requiere token de autenticación válido.

### Parámetros (JSON Body)
- `nombre` (requerido, string): Nombre de la cabaña
- `descripcion` (requerido, string): Descripción detallada de la cabaña
- `ubicacion` (requerido, string): Ubicación geográfica
- `capacidad_maxima` (requerido, integer): Cantidad máxima de personas
- `precio_noche` (requerido, number): Precio por noche en la moneda local

### Validaciones
- Todos los campos son obligatorios
- El nombre debe ser único (no puede existir otra cabaña con el mismo nombre)
- La capacidad máxima debe ser un número entero >= 1
- El precio debe ser un número positivo > 0
- El nombre, descripción y ubicación deben contener solo caracteres válidos

### Ejemplo de Petición
```bash
curl -X POST "http://localhost/microservices/cabanias-service/index.php/cabanias" \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{
    "nombre": "Cabaña del Río",
    "descripcion": "Hermosa cabaña a orillas del río con acceso a agua potable",
    "ubicacion": "Valle del Cauca",
    "capacidad_maxima": 4,
    "precio_noche": 180000
  }'
```

### Respuesta Exitosa (201)
```json
{
  "status": 201,
  "message": "Cabaña creada exitosamente",
  "data": {
    "id_cabania": 3,
    "nombre": "Cabaña del Río",
    "descripcion": "Hermosa cabaña a orillas del río con acceso a agua potable",
    "ubicacion": "Valle del Cauca",
    "capacidad_maxima": 4,
    "precio_noche": 180000,
    "url_imagen": null
  }
}
```

### Errores
- **400** - INVALID_JSON: JSON inválido
- **400** - MISSING_FIELDS: Faltan campos requeridos
- **400** - INVALID_NOMBRE: El nombre es inválido o contiene caracteres no permitidos
- **400** - INVALID_CAPACIDAD: La capacidad máxima debe ser >= 1
- **400** - INVALID_PRECIO: El precio debe ser un número positivo
- **401** - MISSING_TOKEN: No se envió token
- **401** - INVALID_TOKEN: Token inválido o expirado
- **409** - NOMBRE_DUPLICADO: Ya existe una cabaña con ese nombre
- **500** - DB_ERROR: Error en la base de datos

### Notas Importantes
- Una vez creada, la cabaña estará disponible inmediatamente para consultas
- La imagen de la cabaña debe cargarse por separado usando el endpoint de upload
- El ID de la cabaña se asigna automáticamente

---

## 4. Actualizar una Cabaña

### Endpoint
**PUT** `/cabanias`

### Descripción
Actualiza los datos de una cabaña existente. Requiere token de autenticación válido.

### Parámetros (JSON Body)
- `id_cabania` (requerido, integer): ID de la cabaña a actualizar
- `nombre` (requerido, string): Nuevo nombre de la cabaña
- `capacidad_maxima` (opcional, integer): Nueva capacidad máxima de personas
- `precio_noche` (opcional, number): Nuevo precio por noche

### Validaciones
- El `id_cabania` es obligatorio y debe existir
- El `nombre` es obligatorio y debe ser válido
- La capacidad máxima debe ser >= 1 (si se proporciona)
- El precio debe ser un número positivo (si se proporciona)

### Ejemplo de Petición
```bash
curl -X PUT "http://localhost/microservices/cabanias-service/index.php/cabanias" \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{
    "id_cabania": 1,
    "nombre": "Cabaña del Bosque Mejorada",
    "capacidad_maxima": 8,
    "precio_noche": 300000
  }'
```

### Respuesta Exitosa (200)
```json
{
  "status": 200,
  "message": "Cabaña actualizada exitosamente"
}
```

### Errores
- **400** - INVALID_JSON: JSON inválido
- **400** - MISSING_FIELDS: Faltan campos requeridos (id_cabania o nombre)
- **401** - MISSING_TOKEN: No se envió token
- **401** - INVALID_TOKEN: Token inválido o expirado
- **404** - CABANIA_NOT_FOUND: No existe cabaña con ese ID
- **500** - DB_ERROR: Error en la base de datos

---

## 5. Subir Imagen de Cabaña

### Endpoint
**POST** `/cabanias/upload`

### Descripción
Sube una imagen para una cabaña específica. La imagen se almacena en el servidor.

### Parámetros (FormData)
- `file` (requerido, archivo): Archivo de imagen (JPG, PNG)
- `id_cabania` (requerido, integer): ID de la cabaña a la que pertenece la imagen

### Validaciones
- El archivo debe ser una imagen válida (JPG o PNG)
- El tamaño máximo debe respetar los límites del servidor
- La cabaña con el ID especificado debe existir

### Ejemplo de Petición
```bash
curl -X POST "http://localhost/microservices/cabanias-service/index.php/cabanias/upload" \
  -H "Authorization: Bearer <token>" \
  -F "file=@/path/to/cabania.jpg" \
  -F "id_cabania=3"
```

### Respuesta Exitosa (200)
```json
{
  "status": 200,
  "message": "Imagen subida exitosamente",
  "data": {
    "id_cabania": 3,
    "url_imagen": "http://localhost/microservices/cabanias-service/uploads/cabania_3_1234567890.jpg"
  }
}
```

### Errores
- **400** - INVALID_FILE: Archivo inválido o tipo no permitido
- **400** - MISSING_FILE: No se envió archivo
- **401** - MISSING_TOKEN: No se envió token
- **401** - INVALID_TOKEN: Token inválido o expirado
- **404** - CABANIA_NOT_FOUND: No existe cabaña con ese ID
- **500** - UPLOAD_ERROR: Error al subir el archivo

---

## Flujo Completo: Crear una Cabaña

Aquí se muestra el flujo completo para crear una cabaña con imagen:

### Paso 1: Autenticación
Obtén un token JWT desde el servicio de login.

### Paso 2: Crear la Cabaña
```bash
curl -X POST "http://localhost/microservices/cabanias-service/index.php/cabanias" \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{
    "nombre": "Cabaña del Lago",
    "descripcion": "Hermosa cabaña con vista al lago",
    "ubicacion": "Caldas",
    "capacidad_maxima": 5,
    "precio_noche": 220000
  }'
```

Guarda el `id_cabania` de la respuesta.

### Paso 3: Subir Imagen (Opcional)
```bash
curl -X POST "http://localhost/microservices/cabanias-service/index.php/cabanias/upload" \
  -H "Authorization: Bearer <token>" \
  -F "file=@/path/to/imagen_cabania.jpg" \
  -F "id_cabania=<id_obtenido_en_paso_2>"
```

### Paso 4: Verificar la Cabaña
```bash
curl -X GET "http://localhost/microservices/cabanias-service/index.php/cabanias?id_cabania=<id_obtenido_en_paso_2>" \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json"
```

---

## Códigos de Estado HTTP

| Código | Significado |
|--------|-------------|
| 200 | OK - Solicitud exitosa |
| 201 | Created - Recurso creado exitosamente |
| 400 | Bad Request - Datos inválidos o incompletos |
| 401 | Unauthorized - Token faltante o inválido |
| 404 | Not Found - Recurso no encontrado |
| 409 | Conflict - Conflicto (ej: nombre duplicado) |
| 500 | Server Error - Error en el servidor |

---

## Notas de Seguridad
- Todos los endpoints requieren autenticación con token JWT válido
- Los tokens deben enviarse en el header `Authorization: Bearer <token>`
- Se validan todos los datos de entrada para prevenir inyecciones SQL
- Las imágenes subidas se almacenan de forma segura
