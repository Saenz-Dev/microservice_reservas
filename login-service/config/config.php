<?php
// config.php
define('SECRET_KEY', '1234567890123456789012345678901234567890'); // Mejor usar algo seguro
define('TOKEN_EXPIRATION', 3600); // Token valido 1 hora
define('JWT_ALGORITHM', 'HS256');
define('RESET_TOKEN_EXPIRATION', 900); // Token de recuperación valido 15 minutos

// ===== MODO DESARROLLO vs PRODUCCIÓN =====
// true = Desarrollo: El token se devuelve en la respuesta HTTP (para testing sin Gmail)
// false = Producción: Se envía el token por email a través de SMTP
define('RESET_TOKEN_RETURN_IN_RESPONSE', false);

// URL del frontend donde el usuario reestablece su contraseña
define('RESET_PASSWORD_URL', 'http://localhost:4200/recuperar-contrasena');

// ===== CONFIGURACIÓN SMTP para PHPMailer (PARA PRODUCCIÓN) =====
// SOLO necesitas configurar esto si vas a enviar emails reales
// Para desarrollo local, puedes dejar EMAIL_SMTP_PASSWORD vacío

define('EMAIL_SMTP_HOST', 'smtp.gmail.com');
define('EMAIL_SMTP_PORT', 587);
define('EMAIL_SMTP_AUTH', true);
define('EMAIL_SMTP_SECURE', 'tls');

// ⚠️ REEMPLAZA ESTOS VALORES CON TUS CREDENCIALES DE GMAIL:
// 1. Ve a: https://myaccount.google.com/apppasswords (requiere 2FA activo)
// 2. Selecciona "Mail" y "Windows"
// 3. Copia la contraseña de 16 caracteres
// 4. Pégala en EMAIL_SMTP_PASSWORD abajo
define('EMAIL_SMTP_USERNAME', 'saenzm963@gmail.com');         // ⚠️ Tu email de Gmail
define('EMAIL_SMTP_PASSWORD', 'vrnaiosgcopximcw');            // ⚠️ Contraseña de aplicación (vacío = desarrollo)
define('EMAIL_FROM_ADDRESS', 'saenzm963@gmail.com');          // ⚠️ DEBE ser igual a EMAIL_SMTP_USERNAME
define('EMAIL_FROM_NAME', 'Microservices Reservas');