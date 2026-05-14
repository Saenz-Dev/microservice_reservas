<?php
/**
 * Script de Prueba para Verificar Conexión a Gmail
 * 
 * Uso: php test_email.php
 * 
 * Este script verifica que:
 * 1. PHPMailer está instalado
 * 2. La conexión a Gmail funciona
 * 3. Las credenciales son correctas
 */

require_once '../vendor/autoload.php';
require_once 'config/config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

echo "========================================\n";
echo "🔧 TEST DE CONFIGURACIÓN SMTP - GMAIL\n";
echo "========================================\n\n";

// Verificar que config.php tiene valores
echo "📋 Verificando configuración:\n";
echo "  HOST: " . EMAIL_SMTP_HOST . "\n";
echo "  PORT: " . EMAIL_SMTP_PORT . "\n";
echo "  SECURE: " . EMAIL_SMTP_SECURE . "\n";
echo "  USERNAME: " . EMAIL_SMTP_USERNAME . "\n";
echo "  PASSWORD: " . (empty(EMAIL_SMTP_PASSWORD) ? "❌ VACÍO" : "✅ Configurado") . "\n";
echo "  FROM_ADDRESS: " . EMAIL_FROM_ADDRESS . "\n\n";

// Validar que no están vacíos
if (EMAIL_SMTP_USERNAME === 'tu-email@gmail.com' || empty(EMAIL_SMTP_PASSWORD)) {
    echo "⚠️ ADVERTENCIA: EMAIL_SMTP_USERNAME o EMAIL_SMTP_PASSWORD no están configurados\n\n";
    echo "Para configurar:\n";
    echo "1. Ve a: https://myaccount.google.com/apppasswords\n";
    echo "2. Selecciona 'Mail' y 'Windows'\n";
    echo "3. Copia la contraseña de 16 caracteres\n";
    echo "4. Actualiza config.php con tus valores reales\n\n";
    exit(1);
}

// Intentar conexión
echo "🔌 Conectando a Gmail...\n\n";

$mail = new PHPMailer(true);

try {
    // Configuración SMTP
    $mail->isSMTP();
    $mail->Host = EMAIL_SMTP_HOST;
    $mail->Port = EMAIL_SMTP_PORT;
    $mail->SMTPAuth = EMAIL_SMTP_AUTH;
    $mail->SMTPSecure = EMAIL_SMTP_SECURE;
    $mail->Username = EMAIL_SMTP_USERNAME;
    $mail->Password = EMAIL_SMTP_PASSWORD;
    $mail->CharSet = 'UTF-8';
    
    // Permitir conexiones inseguras (solo para testing)
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ];
    
    // Configurar correo de prueba
    $mail->setFrom(EMAIL_FROM_ADDRESS, EMAIL_FROM_NAME);
    $mail->addAddress(EMAIL_SMTP_USERNAME); // Enviar a nosotros mismos
    $mail->Subject = '✅ Email de Prueba - Recuperación de Contraseña';
    $mail->isHTML(true);
    $mail->Body = '
        <h2>✅ Configuración SMTP Correcta!</h2>
        <p>Este es un email de prueba para verificar que Gmail está configurado correctamente.</p>
        <p><strong>Detalles:</strong></p>
        <ul>
            <li>Host: ' . EMAIL_SMTP_HOST . '</li>
            <li>Port: ' . EMAIL_SMTP_PORT . '</li>
            <li>Seguridad: ' . EMAIL_SMTP_SECURE . '</li>
            <li>Usuario: ' . EMAIL_SMTP_USERNAME . '</li>
            <li>Timestamp: ' . date('Y-m-d H:i:s') . '</li>
        </ul>
        <p>Si recibes este email, ¡significa que todo funciona correctamente!</p>
    ';
    $mail->AltBody = 'Configuración SMTP correcta. Email de prueba recibido.';
    
    // Enviar
    echo "📨 Intentando enviar email de prueba...\n";
    if ($mail->send()) {
        echo "\n✅ ¡ÉXITO! Email enviado correctamente a " . EMAIL_SMTP_USERNAME . "\n";
        echo "\n📧 Revisa tu bandeja de entrada en Gmail para confirmar.\n";
        echo "\n🎉 Tu configuración SMTP está lista para producción.\n";
        echo "   Ahora puedes cambiar RESET_TOKEN_RETURN_IN_RESPONSE a false\n";
    }
    
} catch (Exception $e) {
    echo "\n❌ ERROR AL ENVIAR EMAIL:\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo $mail->ErrorInfo . "\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    
    echo "🔍 SOLUCIÓN:\n";
    if (strpos($mail->ErrorInfo, 'SMTP') !== false || strpos($mail->ErrorInfo, 'Failed to connect') !== false) {
        echo "1. ❌ No se puede conectar a Gmail\n";
        echo "   → Verifica que EMAIL_SMTP_HOST y EMAIL_SMTP_PORT sean correctos\n";
        echo "   → Verifica tu conexión a Internet\n\n";
    }
    if (strpos($mail->ErrorInfo, 'authentication') !== false || strpos($mail->ErrorInfo, 'Authentication failed') !== false) {
        echo "1. ❌ Credenciales incorrectas\n";
        echo "   → Verifica que EMAIL_SMTP_USERNAME sea tu email de Gmail\n";
        echo "   → Verifica que EMAIL_SMTP_PASSWORD sea la contraseña de APLICACIÓN (16 caracteres)\n";
        echo "   → NO uses tu contraseña de Google, usa la de aplicación\n";
        echo "   → Requiere 2FA activo en tu cuenta de Google\n\n";
    }
    
    echo "💡 Más información: https://myaccount.google.com/apppasswords\n";
    
    exit(1);
}

echo "\n";
?>
