# Guía de Recuperación de Contraseña

## Descripción
El flujo de recuperación de contraseña en este microservicio utiliza tokens JWT y, opcionalmente, correo electrónico vía SMTP.

## Endpoints

### 1. Solicitar Recuperación de Contraseña
**POST** `/login/forgot-password`

**Body:**
```json
{
  "correo": "usuario@example.com"
}
```

**Respuestas:**
- `200`: Si el correo existe o no (por seguridad, no se especifica)
- Si `RESET_TOKEN_RETURN_IN_RESPONSE = true` (desarrollo):
  - El token se devuelve en la respuesta
- Si `RESET_TOKEN_RETURN_IN_RESPONSE = false` (producción):
  - Se envía por correo (requiere SMTP configurado)

**Respuesta en Desarrollo (con token):**
```json
{
  "status": 200,
  "message": "Si el correo existe, recibirás instrucciones para recuperar la contraseña",
  "reset_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
  "expires_in": 900
}
```

### 2. Validar Token de Recuperación
**GET** `/login/verify-reset-token?token=<token>`

**Parámetros:**
- `token`: El token de recuperación obtenido en el paso 1

**Respuesta (200):**
```json
{
  "status": 200,
  "message": "Token de recuperación válido",
  "data": {
    "correo": "usuario@example.com",
    "expira_en_segundos": 892
  }
}
```

**Errores:**
- `400`: Token faltante
- `401`: Token inválido o expirado

### 3. Restablecer Contraseña
**POST** `/login/reset-password`

**Body:**
```json
{
  "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
  "nueva_contrasena": "MiNuevaContra123"
}
```

**Validaciones:**
- Contraseña debe tener:
  - Mínimo 6 caracteres
  - Al menos una letra mayúscula
  - Al menos un número

**Respuesta (200):**
```json
{
  "status": 200,
  "message": "Contraseña actualizada exitosamente"
}
```

**Errores comunes:**
- `400 - INVALID_PASSWORD`: Contraseña no cumple requisitos
- `400 - SAME_PASSWORD`: La nueva contraseña es igual a la anterior
- `401 - INVALID_RESET_TOKEN`: Token inválido o expirado

---

## Configuración SMTP (Producción)

Si necesitas enviar correos en producción, configura en `config/config.php`:

```php
// Cambiar a false en producción
define('RESET_TOKEN_RETURN_IN_RESPONSE', false);

// Configuración SMTP para Gmail
define('EMAIL_SMTP_HOST', 'smtp.gmail.com');
define('EMAIL_SMTP_PORT', 587);
define('EMAIL_SMTP_AUTH', true);
define('EMAIL_SMTP_SECURE', 'tls');
define('EMAIL_SMTP_USERNAME', 'tu-email@gmail.com');
define('EMAIL_SMTP_PASSWORD', 'tu-contraseña-app');
define('EMAIL_FROM_ADDRESS', 'tu-email@gmail.com');
define('EMAIL_FROM_NAME', 'Microservices Reservas');
```

### Pasos para Gmail:
1. Habilita autenticación de 2 factores en tu cuenta de Google
2. Genera una contraseña de aplicación:
   - Ve a myaccount.google.com/apppasswords
   - Selecciona "Mail" y "Windows"
   - Copia la contraseña de 16 caracteres
3. Usa esa contraseña en `EMAIL_SMTP_PASSWORD`

---

## Testing en Desarrollo

### Flujo Completo:

1. **Solicitar recuperación:**
```bash
curl -X POST "http://localhost/microservices/login-service/index.php/login/forgot-password" \
  -H "Content-Type: application/json" \
  -d '{"correo": "usuario@example.com"}'
```

2. **Guardar el token devuelto** (si `RESET_TOKEN_RETURN_IN_RESPONSE = true`)

3. **Validar token:**
```bash
curl -X GET "http://localhost/microservices/login-service/index.php/login/verify-reset-token?token=<token>"
```

4. **Restablecer contraseña:**
```bash
curl -X POST "http://localhost/microservices/login-service/index.php/login/reset-password" \
  -H "Content-Type: application/json" \
  -d '{
    "token": "<token>",
    "nueva_contrasena": "MiNuevaContra123"
  }'
```

---

## Solución de Problemas

### "EMAIL_CONFIGURATION_ERROR"
**Causa:** Configuración SMTP incompleta
- Verifica que `EMAIL_SMTP_HOST`, `EMAIL_SMTP_USERNAME` y `EMAIL_SMTP_PASSWORD` estén configurados
- En desarrollo, usa `RESET_TOKEN_RETURN_IN_RESPONSE = true`

### "EMAIL_SEND_FAILED"
**Causa:** No se pudo conectar al servidor SMTP
- Verifica credenciales de Gmail
- Asegúrate de usar contraseña de aplicación, no contraseña de cuenta
- Gmail debe tener autenticación de 2FA habilitada

### Token expirado
**Causa:** El token tiene 15 minutos de validez
- Solicita uno nuevo con otro POST a `/forgot-password`

### "SAME_PASSWORD"
**Causa:** Usaste la contraseña actual como nueva
- Elige una contraseña diferente

---

## Configuración Actual

**RESET_TOKEN_RETURN_IN_RESPONSE:** `true` (Desarrollo)
**RESET_TOKEN_EXPIRATION:** `900` segundos (15 minutos)
**RESET_PASSWORD_URL:** `http://localhost:4200/recuperar-contrasena`
