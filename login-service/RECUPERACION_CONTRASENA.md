# Guía Completa: Recuperación de Contraseña

## ⚡ Quick Start (5 minutos)

### Para Desarrollo (Testing Local)
Simplemente **ya está listo**. El token se devuelve en la respuesta:
```bash
curl -X POST "http://localhost/microservices/login-service/index.php/login/forgot-password" \
  -H "Content-Type: application/json" \
  -d '{"correo": "usuario@example.com"}'
```

### Para Producción (Enviar Emails a Gmail)

**Paso 1:** Ir a [myaccount.google.com/apppasswords](https://myaccount.google.com/apppasswords)
- Si no aparece, primero activa 2FA en [myaccount.google.com/security](https://myaccount.google.com/security)

**Paso 2:** Selecciona "Mail" y "Windows" → Haz clic en "Generar"

**Paso 3:** Copia la contraseña de 16 caracteres

**Paso 4:** Actualiza `login-service/config/config.php`:
```php
define('RESET_TOKEN_RETURN_IN_RESPONSE', false);
define('EMAIL_SMTP_USERNAME', 'tu-email@gmail.com');
define('EMAIL_SMTP_PASSWORD', 'abcd efgh ijkl mnop');  // Contraseña que copiaste
define('EMAIL_FROM_ADDRESS', 'tu-email@gmail.com');
```

**Paso 5:** ¡Listo! Los emails se enviarán automáticamente a Gmail.

---

## Descripción
El flujo de recuperación de contraseña implementa un sistema seguro mediante:
- **Tokens JWT** con propósito específico (`password_reset`) y expiración de 15 minutos
- **Validación de contraseña** con requisitos de seguridad (mínimo 6 caracteres, 1 mayúscula, 1 número)
- **Prevención de reutilización** de contraseña anterior
- **Correo SMTP** opcional con PHPMailer (para producción)
- **Modo desarrollo** con tokens en respuesta HTTP (para testing)

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

## Configuración SMTP (Envío de Emails a Gmail)

### 🚀 Pasos para Configurar Gmail (OBLIGATORIO para enviar emails)

#### **PASO 1: Habilitar Autenticación de 2 Factores en Google**

1. Ve a [myaccount.google.com/security](https://myaccount.google.com/security)
2. Encuentra **"Verificación en 2 pasos"** 
3. Haz clic en **"Activar"** o **"Comenzar la configuración"**
4. Sigue los pasos (confirmar número de teléfono, etc.)
5. ✅ Cuando esté activado, verás un ✓ verde

⚠️ **SIN 2FA, no puedes generar contraseña de aplicación**

---

#### **PASO 2: Generar Contraseña de Aplicación de Google**

1. Ve a [myaccount.google.com/apppasswords](https://myaccount.google.com/apppasswords)
2. Deberías ver dos menús desplegables:
   - **Selecciona la aplicación**: Elige **"Correo"** (Mail)
   - **Selecciona el dispositivo**: Elige **"Windows"** (o tu SO)
3. Haz clic en **"Generar"**
4. Google te mostrará una contraseña de **16 caracteres** (similar a: `abcd efgh ijkl mnop`)
5. 📋 **COPIA EXACTAMENTE esta contraseña** (incluyendo espacios o sin ellos)
6. ✅ Google te confirmará: "Tu contraseña de aplicación ha sido creada"

---

#### **PASO 3: Actualizar config.php**

En `login-service/config/config.php`, actualiza estos valores:

```php
// CAMBIAR A FALSE PARA ENVIAR EMAILS (producción)
define('RESET_TOKEN_RETURN_IN_RESPONSE', false);

// CONFIGURACIÓN SMTP - REEMPLAZA CON TUS DATOS
define('EMAIL_SMTP_USERNAME', 'tu-email@gmail.com');      // ⚠️ Ejemplo: usuario@gmail.com
define('EMAIL_SMTP_PASSWORD', 'abcd efgh ijkl mnop');     // ⚠️ Contraseña de 16 caracteres de Google
define('EMAIL_FROM_ADDRESS', 'tu-email@gmail.com');       // ⚠️ DEBE ser igual a USERNAME
define('RESET_PASSWORD_URL', 'https://tudominio.com/recuperar-contrasena');  // ⚠️ URL de tu frontend
```

**Ejemplo real (NUNCA compartas esto):**
```php
define('RESET_TOKEN_RETURN_IN_RESPONSE', false);
define('EMAIL_SMTP_USERNAME', 'miapp@gmail.com');
define('EMAIL_SMTP_PASSWORD', 'jksd nvms abcd efgh');  // Contraseña de aplicación real
define('EMAIL_FROM_ADDRESS', 'miapp@gmail.com');
define('RESET_PASSWORD_URL', 'https://misapp.com/recuperar-contrasena');
```

---

#### **PASO 4: Probar que Gmail Funciona**

Usa el script de prueba incluido para verificar la conexión:

```bash
cd login-service
php test_email.php
```

**Resultado esperado:**
```
✅ ¡ÉXITO! Email enviado correctamente a tu-email@gmail.com

📧 Revisa tu bandeja de entrada en Gmail para confirmar.

🎉 Tu configuración SMTP está lista para producción.
   Ahora puedes cambiar RESET_TOKEN_RETURN_IN_RESPONSE a false
```

Si hay error, el script te mostrará exactamente qué está mal y cómo solucionarlo.

---

#### **PASO 5: Probar el Flujo Completo**
```bash
curl -X POST "http://localhost/microservices/login-service/index.php/login/forgot-password" \
  -H "Content-Type: application/json" \
  -d '{"correo": "usuario@gmail.com"}'
```

**Respuesta esperada:**
```json
{
  "status": 200,
  "message": "Si el correo existe, recibirás instrucciones para recuperar la contraseña"
}
```

⚠️ **IMPORTANTE**: No hay `reset_token` en la respuesta (está en el email) porque `RESET_TOKEN_RETURN_IN_RESPONSE = false`

**2️⃣ Revisa tu email de Gmail:**
- Abre Gmail
- Busca un email de **"Microservices Reservas"**
- El email contiene un enlace con el token: `http://localhost:4200/recuperar-contrasena?token=xxx`
- Haz clic en el enlace o copia el token

### Errores Comunes de SMTP

| Error | Causa | Solución |
|-------|-------|----------|
| `EMAIL_SEND_FAILED` | No puede conectar al servidor SMTP | Verifica credenciales de Gmail, 2FA debe estar activo |
| `SMTP Authentication failed` | Contraseña incorrecta | Usa contraseña de aplicación (16 caracteres), no tu contraseña de Google |
| `SMTP connect() failed` | Configuración de HOST/PORT incorrecta | Verifica que EMAIL_SMTP_HOST='smtp.gmail.com' y PORT=587 |
| `Encryption required` | Puerto/Seguridad incorrecto | Usa SECURE='tls' con puerto 587, o SECURE='ssl' con puerto 465 |

---

## Flujo Completo (Con Envío de Email)

### Cuando RESET_TOKEN_RETURN_IN_RESPONSE = false (Producción)

**1️⃣ Usuario solicita recuperación:**
```bash
curl -X POST "http://localhost/microservices/login-service/index.php/login/forgot-password" \
  -H "Content-Type: application/json" \
  -d '{"correo": "usuario@gmail.com"}'
```

📨 **El servidor envía un email a usuario@gmail.com**

---

**2️⃣ Usuario recibe email de "Microservices Reservas":**

Email recibido:
```
De: Microservices Reservas <miapp@gmail.com>
Asunto: Recuperación de contraseña

Recibimos una solicitud para recuperar tu contraseña.

Haz clic en el siguiente enlace para restablecerla:
https://tudominio.com/recuperar-contrasena?token=eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...

Este enlace expirará en 15 minutos.
```

---

**3️⃣ Usuario hace clic en el enlace o copia el token:**

Frontend recibe el token de la URL y permite al usuario:
- Ingresar nueva contraseña
- Validar que cumple requisitos

---

**4️⃣ Frontend envía la nueva contraseña:**
```bash
curl -X POST "http://localhost/microservices/login-service/index.php/login/reset-password" \
  -H "Content-Type: application/json" \
  -d '{
    "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
    "nueva_contrasena": "MiNuevaContra123"
  }'
```

✅ **Respuesta:**
```json
{
  "status": 200,
  "message": "Contraseña actualizada exitosamente"
}
```

---

**5️⃣ Usuario puede hacer login con su nueva contraseña:**
```bash
curl -X POST "http://localhost/microservices/login-service/index.php/login" \
  -H "Content-Type: application/json" \
  -d '{
    "correo": "usuario@gmail.com",
    "contrasena": "MiNuevaContra123"
  }'
```

✅ **Login exitoso!**

Para desarrollo local, la configuración por defecto ya está lista. Solo mantén:

```php
// EN DESARROLLO
define('RESET_TOKEN_RETURN_IN_RESPONSE', true);  // El token se devuelve en la respuesta
define('EMAIL_SMTP_PASSWORD', '');               // Puede estar vacío, el token se devuelve
```

Así puedes testar sin configurar Gmail.

---

## Testing Completo (Desarrollo)

### Flujo Paso a Paso

**1️⃣ Solicitar Recuperación:**
```bash
curl -X POST "http://localhost/microservices/login-service/index.php/login/forgot-password" \
  -H "Content-Type: application/json" \
  -d '{"correo": "usuario@example.com"}'
```

**Respuesta esperada (Desarrollo con RESET_TOKEN_RETURN_IN_RESPONSE=true):**
```json
{
  "status": 200,
  "message": "Si el correo existe, recibirás instrucciones para recuperar la contraseña",
  "reset_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpYXQiOjE3MTU2MTUxMjAsImV4cCI6MTcxNTYxNTEyMSwicHVycG9zZSI6InBhc3N3b3JkX3Jlc2V0IiwiZGF0YSI6eyJpZF91c3VhcmlvIjoxLCJjb3JyZW8iOiJ1c3VhcmlvQGV4YW1wbGUuY29tIn19.xxx",
  "expires_in": 900
}
```

⚠️ **Guarda el `reset_token` para los siguientes pasos**

---

**2️⃣ Validar Token (Opcional, pero recomendado):**
```bash
curl -X GET "http://localhost/microservices/login-service/index.php/login/verify-reset-token?token=<PEGA_TU_TOKEN_AQUI>"
```

**Respuesta esperada:**
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

Si expira_en_segundos ≤ 0, el token ha expirado y debe solicitar uno nuevo.

---

**3️⃣ Reestablecer Contraseña:**
```bash
curl -X POST "http://localhost/microservices/login-service/index.php/login/reset-password" \
  -H "Content-Type: application/json" \
  -d '{
    "token": "<PEGA_TU_TOKEN_AQUI>",
    "nueva_contrasena": "MiNuevaContra123"
  }'
```

**Respuesta esperada:**
```json
{
  "status": 200,
  "message": "Contraseña actualizada exitosamente"
}
```

---

**4️⃣ Verifica que el Login Funciona con la Nueva Contraseña:**
```bash
curl -X POST "http://localhost/microservices/login-service/index.php/login" \
  -H "Content-Type: application/json" \
  -d '{
    "correo": "usuario@example.com",
    "contrasena": "MiNuevaContra123"
  }'
```

✅ Si recibiste un token con `"status": 200`, ¡todo funciona!

---

## 🔧 Solución de Problemas

### Error: "EMAIL_CONFIGURATION_ERROR" (En Producción)

**Síntomas:**
```json
{
  "status": 500,
  "code": "EMAIL_CONFIGURATION_ERROR",
  "error": "Configuración de correo incompleta o inválida"
}
```

**Causas y soluciones:**

1. **`EMAIL_SMTP_PASSWORD` está vacío o incompleto**
   - ✅ Solución: Copia exactamente la contraseña de 16 caracteres desde [myaccount.google.com/apppasswords](https://myaccount.google.com/apppasswords)
   - Ejemplo correcto: `jksd nvms abcd efgh` (con espacios) o `jksdnvmsabcdefgh`

2. **`RESET_TOKEN_RETURN_IN_RESPONSE` sigue siendo `true`**
   - Si es `true`, el código NO intenta enviar email
   - ✅ Solución: Cambia a `false` si quieres enviar emails:
   ```php
   define('RESET_TOKEN_RETURN_IN_RESPONSE', false);
   ```

3. **`EMAIL_SMTP_HOST`, `EMAIL_SMTP_PORT`, o `EMAIL_SMTP_SECURE` incorrectos**
   - ✅ Verifica que sean exactamente:
   ```php
   define('EMAIL_SMTP_HOST', 'smtp.gmail.com');
   define('EMAIL_SMTP_PORT', 587);
   define('EMAIL_SMTP_SECURE', 'tls');
   ```

4. **`EMAIL_FROM_ADDRESS` no coincide con `EMAIL_SMTP_USERNAME`**
   - Google rechaza emails que no vienen de la misma dirección autenticada
   - ✅ Solución: Haz que sean idénticos:
   ```php
   define('EMAIL_SMTP_USERNAME', 'miapp@gmail.com');
   define('EMAIL_FROM_ADDRESS', 'miapp@gmail.com');  // MISMO
   ```

---

### Error: "EMAIL_SEND_FAILED"

**Síntomas:**
```json
{
  "status": 500,
  "code": "EMAIL_SEND_FAILED",
  "error": "No se pudo enviar el correo de recuperación"
}
```

**Esto significa:** La conexión a Gmail falló. Revisa lo siguiente:

#### **1. Verificar que 2FA está ACTIVO en Google**

- Ve a [myaccount.google.com/security](https://myaccount.google.com/security)
- Busca "Verificación en 2 pasos"
- Debe mostrar un ✓ verde "Activado"
- Si no: ⚠️ **ACTÍVALO PRIMERO**, la contraseña de aplicación NO funciona sin 2FA

#### **2. Verificar que la contraseña es de APLICACIÓN (no de Google)**

```
❌ INCORRECTO: Tu contraseña de Google (cualquier longitud)
❌ INCORRECTO: Contraseña sin espacios que copiaste mal

✅ CORRECTO: Contraseña de 16 caracteres exactos de Google:
   jksd nvms abcd efgh
   (Cópiala de: myaccount.google.com/apppasswords)
```

#### **3. Verificar Credenciales Completas**

```php
// Verifica que TODOS estos campos tengan valores
define('EMAIL_SMTP_HOST', 'smtp.gmail.com');              // ✅ Correcto
define('EMAIL_SMTP_PORT', 587);                           // ✅ Correcto
define('EMAIL_SMTP_AUTH', true);                          // ✅ Correcto
define('EMAIL_SMTP_SECURE', 'tls');                       // ✅ Correcto
define('EMAIL_SMTP_USERNAME', 'miapp@gmail.com');         // ✅ Tu email
define('EMAIL_SMTP_PASSWORD', 'jksd nvms abcd efgh');     // ✅ Contraseña app
define('EMAIL_FROM_ADDRESS', 'miapp@gmail.com');          // ✅ Igual a USERNAME
define('EMAIL_FROM_NAME', 'Microservices Reservas');      // ✅ Nombre visible
```

#### **4. Probar Manualmente**

Crea un archivo `test_email.php` en la raíz del proyecto:

```php
<?php
require_once 'vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;

$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->Port = 587;
    $mail->SMTPAuth = true;
    $mail->SMTPSecure = 'tls';
    $mail->Username = 'tu-email@gmail.com';        // ⚠️ REEMPLAZA
    $mail->Password = 'tu-contraseña-app-16-chars'; // ⚠️ REEMPLAZA
    $mail->CharSet = 'UTF-8';
    
    $mail->setFrom('tu-email@gmail.com', 'Test');
    $mail->addAddress('destinatario@example.com');
    $mail->Subject = 'Test Email';
    $mail->Body = 'Este es un email de prueba';
    
    if ($mail->send()) {
        echo "✅ Email enviado correctamente!";
    }
} catch (Exception $e) {
    echo "❌ Error: " . $mail->ErrorInfo;
}
?>
```

Luego ejecuta:
```bash
php test_email.php
```

#### **5. Si Aún Falla: Revisa el Log de Gmail**

1. Ve a [myaccount.google.com/security](https://myaccount.google.com/security)
2. Busca **"Tu actividad de seguridad reciente"**
3. Busca intentos fallidos de login
4. Gmail podría estar bloqueando conexiones inseguras

---

### Error: "INVALID_PASSWORD"

**Síntomas:**
```json
{
  "status": 400,
  "code": "INVALID_PASSWORD",
  "error": "La nueva contraseña debe tener al menos 6 caracteres, una letra mayúscula y un número"
}
```

**Requisitos de Contraseña:**
- ✅ Mínimo **6 caracteres**
- ✅ Al menos **1 letra mayúscula** (A-Z)
- ✅ Al menos **1 número** (0-9)

**Ejemplos válidos:**
- `Contraseña123`
- `Miapp2025`
- `Pass001`

**Ejemplos inválidos:**
- `abc123` ❌ (sin mayúscula)
- `ABCDEF` ❌ (sin número)
- `Ab12` ❌ (muy corto)

---

### Error: "SAME_PASSWORD"

**Síntomas:**
```json
{
  "status": 400,
  "code": "SAME_PASSWORD",
  "error": "La nueva contraseña debe ser diferente a la actual"
}
```

**Causa:** Usaste la misma contraseña que la anterior

**Solución:** Elige una contraseña diferente

---

### Error: "INVALID_RESET_TOKEN"

**Síntomas:**
```json
{
  "status": 401,
  "code": "INVALID_RESET_TOKEN",
  "error": "Token de recuperación inválido o expirado"
}
```

**Causas:**
1. **Token expirado** (validez: 15 minutos)
   - Solución: Solicita un nuevo token con POST a `/forgot-password`

2. **Token malformado o modificado**
   - Solución: Copia exactamente el token devuelto, sin espacios

3. **Token de un usuario diferente**
   - Solución: Asegúrate de usar el token correcto para cada usuario

---

### Error: "INVALID_EMAIL" en forgot-password

**Síntomas:**
```json
{
  "status": 400,
  "code": "INVALID_EMAIL",
  "error": "El campo correo es obligatorio y debe ser un correo válido"
}
```

**Causa:** El email no tiene formato válido

**Solución:** Asegúrate de enviar:
```bash
curl -X POST "..." \
  -d '{"correo": "usuario@example.com"}'  # ✅ Formato correcto
```

**NO envíes:**
```json
{"email": "usuario@example.com"}           // ❌ Campo debe ser "correo"
{"correo": "usuario_invalido"}             // ❌ No es un email válido
```

---

### Error: "USER_NOT_FOUND"

**Síntomas:**
```json
{
  "status": 404,
  "code": "USER_NOT_FOUND",
  "error": "Usuario no encontrado"
}
```

**Causa:** El email en el token no existe en la BD

**Nota:** Este error no debería ocurrir en uso normal. Si lo ves:
- Verifica que la tabla `cuenta` tenga el usuario
- Comprueba que no se eliminó el usuario entre los pasos 1 y 3

---

## Validación Rápida

### Checklist de Configuración

- [ ] Tabla `cuenta` existe en BD `reservas` con columnas: `id_usuario`, `correo`, `contrasena`
- [ ] `RESET_TOKEN_EXPIRATION` = 900 (15 minutos)
- [ ] `RESET_TOKEN_RETURN_IN_RESPONSE` = true (desarrollo) o false (producción)
- [ ] `RESET_PASSWORD_URL` apunta a la página de reset del frontend
- [ ] `EMAIL_SMTP_HOST` = 'smtp.gmail.com'
- [ ] `EMAIL_SMTP_PORT` = 587
- [ ] `EMAIL_SMTP_SECURE` = 'tls'
- [ ] `EMAIL_SMTP_USERNAME` = tu email de Gmail (ej: usuario@gmail.com)
- [ ] `EMAIL_SMTP_PASSWORD` = contraseña de aplicación de Google (16 caracteres)
- [ ] `EMAIL_FROM_ADDRESS` = IDÉNTICO a `EMAIL_SMTP_USERNAME`

---

## 📋 Configuración Actual

| Parámetro | Valor | Ambiente |
|-----------|-------|----------|
| **RESET_TOKEN_RETURN_IN_RESPONSE** | `true` | Desarrollo |
| **RESET_TOKEN_EXPIRATION** | `900` segundos (15 min) | Ambos |
| **RESET_PASSWORD_URL** | `http://localhost:4200/recuperar-contrasena` | Desarrollo |
| **EMAIL_SMTP_HOST** | `smtp.gmail.com` | Ambos |
| **EMAIL_SMTP_PORT** | `587` | Ambos |
| **EMAIL_SMTP_SECURE** | `tls` | Ambos |
| **EMAIL_SMTP_AUTH** | `true` | Ambos |
| **EMAIL_SMTP_USERNAME** | ⚠️ **REEMPLAZA** | Producción |
| **EMAIL_SMTP_PASSWORD** | ⚠️ **REEMPLAZA** | Producción |
| **EMAIL_FROM_ADDRESS** | ⚠️ **REEMPLAZA** | Producción |

---

## 🚀 Resumen de Endpoints

| Método | Ruta | Propósito | Autenticación |
|--------|------|----------|----------------|
| **POST** | `/login/forgot-password` | Solicitar token de recuperación | No |
| **GET** | `/login/verify-reset-token` | Validar token (opcional) | No |
| **POST** | `/login/reset-password` | Reestablecer contraseña | Token en body |
| **POST** | `/login` | Login con usuario y contraseña | No |

---

## 📚 Estructura de Respuestas

### Respuesta Exitosa (200)
```json
{
  "status": 200,
  "message": "Descripción de éxito",
  "data": { }  // Si aplica
}
```

### Respuesta con Error (400-500)
```json
{
  "status": 400,
  "code": "ERROR_CODE",
  "error": "Descripción del error"
}
```

---

## 🔐 Notas de Seguridad

✅ **Implementado:**
- Tokens JWT con propósito específico (`password_reset`)
- Contraseñas hasheadas con bcrypt
- Validación de requisitos de contraseña
- Prevención de reutilización de contraseña anterior
- Expiración automática de tokens (15 minutos)
- Validación de email con formato RFC

⚠️ **Recomendaciones Producción:**
- Crea una contraseña de aplicación específica en Google (no reutilices para otros servicios)
- Rotación periódica de `SECRET_KEY` en config.php
- HTTPS obligatorio en todas las rutas
- Rate limiting en `/forgot-password` para prevenir abuso
- Logging de intentos de reset fallidos
- Considera agregar CAPTCHA en el frontend

---

## ❓ Preguntas Frecuentes (FAQ)

### ¿Puedo probar sin configurar Gmail?
**Sí.** En desarrollo, simplemente:
```php
define('RESET_TOKEN_RETURN_IN_RESPONSE', true);  // ✅ El token se devuelve en la respuesta
```
El token estará en el JSON, lo puedes copiar y usar directamente.

---

### ¿Dónde obtengo la contraseña de aplicación de Google?
1. Ve a [myaccount.google.com/apppasswords](https://myaccount.google.com/apppasswords)
2. **IMPORTANTE**: Si no ves la opción, primero activa 2FA en [myaccount.google.com/security](https://myaccount.google.com/security)
3. Selecciona "Mail" y "Windows Computer"
4. Google te dará 16 caracteres. Cópialos exactamente.

---

### ¿Qué pasa si no tengo 2FA activado?
Google **no permite** generar contraseñas de aplicación sin 2FA. Debes:
1. Ir a [myaccount.google.com/security](https://myaccount.google.com/security)
2. Buscar "Verificación en 2 pasos"
3. Hacer clic en "Activar"
4. Seguir los pasos (confirmar teléfono, etc.)

---

### ¿Por qué cambiar RESET_TOKEN_RETURN_IN_RESPONSE?

| Valor | Ambiente | Comportamiento |
|-------|----------|-----------------|
| `true` | Desarrollo | El token se devuelve en la respuesta HTTP (fácil para testing) |
| `false` | Producción | El token se envía por email a Gmail (seguro) |

En producción **siempre debe ser `false`** para no exponer tokens en logs o redes.

---

### ¿El email llegará a la bandeja principal o spam?
Gmail generalmente lo detecta correctamente si:
- ✅ Usas una contraseña de aplicación válida
- ✅ EMAIL_FROM_ADDRESS = EMAIL_SMTP_USERNAME
- ✅ El sender reputation es bueno

Pero por seguridad, algunos emails pueden ir a spam. El usuario debe revisar ambas carpetas.

---

### ¿Puedo usar Outlook, Hotmail o Yahoo?
Sí, pero necesitarías configurar los servidores SMTP diferentes:

**Outlook/Hotmail:**
```php
define('EMAIL_SMTP_HOST', 'smtp.outlook.com');
define('EMAIL_SMTP_PORT', 587);
define('EMAIL_SMTP_SECURE', 'tls');
```

**Yahoo:**
```php
define('EMAIL_SMTP_HOST', 'smtp.mail.yahoo.com');
define('EMAIL_SMTP_PORT', 587);
define('EMAIL_SMTP_SECURE', 'tls');
```

(Consulta su documentación para contraseñas de aplicación)

---

### ¿Puedo usar un servidor SMTP propio?
Sí, simplemente actualiza los valores en config.php con tus credenciales SMTP.

---

## 📞 Soporte

Si necesitas ayuda:
1. Revisa la sección **Solución de Problemas**
2. Verifica el **Checklist de Configuración**
3. Prueba con los ejemplos de **Testing Completo**
