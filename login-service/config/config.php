<?php
// config.php
define('SECRET_KEY', '1234567890123456789012345678901234567890'); // Mejor usar algo seguro
define('TOKEN_EXPIRATION', 3600); // Token valido 1 hora
define('JWT_ALGORITHM', 'HS256');
define('RESET_TOKEN_EXPIRATION', 900); // Token de recuperación valido 15 minutos
define('RESET_TOKEN_RETURN_IN_RESPONSE', true); // true para development/testing; cambiar a false en producción

// URL del frontend o página donde el usuario reestablece su contraseña
define('RESET_PASSWORD_URL', 'http://localhost:4200/recuperar-contrasena');

// Configuración SMTP para PHPMailer
// IMPORTANTE: Para Gmail, necesitas:
// 1. Habilitar "Acceso para aplicaciones poco seguras" o usar contraseña de aplicación
// 2. Si tienes 2FA, usar contraseña de aplicación (16 caracteres de Google)
// 3. El USERNAME debe ser tu email de Gmail (saenm963@gmail.com)
define('EMAIL_SMTP_HOST', 'smtp.gmail.com');
define('EMAIL_SMTP_PORT', 587);
define('EMAIL_SMTP_AUTH', true);
define('EMAIL_SMTP_SECURE', 'tls'); // tls o ssl
define('EMAIL_SMTP_USERNAME', 'saenm963@gmail.com'); // DEBE ser un email válido, no un nombre
define('EMAIL_SMTP_PASSWORD', 'your-app-password-here'); // Cambiar por contraseña de aplicación de Google
define('EMAIL_FROM_ADDRESS', 'saenm963@gmail.com');
define('EMAIL_FROM_NAME', 'Microservices Reservas');