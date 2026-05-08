<?php
// config.php
define('SECRET_KEY', '1234567890123456789012345678901234567890'); // Mejor usar algo seguro
define('TOKEN_EXPIRATION', 3600); // Token valido 1 hora
define('JWT_ALGORITHM', 'HS256');
define('ADMIN_ROLE_ID', 1); // ID del rol administrador
define('INTERNAL_SERVICE_KEY', 'sistema_interno_key_12345'); // Clave para llamadas internas entre servicios
define('ALLOW_POSTMAN_TESTS', false); // Permitir tests desde postman
define('FACTURA_IVA', 0.19); // IVA para facturas
define('MAIL_FROM_ADDRESS', getenv('MAIL_FROM_ADDRESS') ?: 'miguesaenz10@gmail.com');
define('MAIL_FROM_NAME', getenv('MAIL_FROM_NAME') ?: 'Servicio de Facturación');
define('MAIL_SMTP_HOST', getenv('MAIL_SMTP_HOST') ?: 'smtp.gmail.com');
define('MAIL_SMTP_PORT', (int) (getenv('MAIL_SMTP_PORT') ?: 587));
define('MAIL_SMTP_USERNAME', getenv('MAIL_SMTP_USERNAME') ?: 'Booking Manager System');
define('MAIL_SMTP_PASSWORD', getenv('MAIL_SMPT_PASSWORD') ?: 'iwhjmqxsvqvoccmh');
define('MAIL_SMTP_SECURE', getenv('MAIL_SMTP_SECURE') ?: 'tls');
