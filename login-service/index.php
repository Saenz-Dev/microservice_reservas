<?php

// Allow the specific origin
require_once 'config/Conection.php';
require_once 'util/ExceptionApi.php';
require_once '../vendor/autoload.php';
require_once 'config/config.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

header("Access-Control-Allow-Origin: http://localhost:4200");
// Allow the Content-Type header specifically
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
// Allow the HTTP methods you are using
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Content-Type: application/json");


$request = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = strtolower($_SERVER['REQUEST_METHOD']);

// Quitar ruta base del microservicio
$basePath = '/microservices/login-service/index.php';
$route = str_replace($basePath, "", $request);

if ($route === '' || $route === false) {
    $route = '/';
}

if ($route[0] !== '/') {
    $route = '/' . $route;
}

function getBearerToken()
{

    $authorizationHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');

    if (empty($authorizationHeader) && function_exists('getallheaders')) {
        $headers = getallheaders();
        $authorizationHeader = $headers['Authorization'] ?? ($headers['authorization'] ?? '');
    }

    if (preg_match('/Bearer\s+(.*)$/i', $authorizationHeader, $matches)) {
        return trim($matches[1]);
    }

    return null;
}

function generarToken($user)
{
    $ahora = time();
    $payload = [
        'iat' => $ahora,
        'exp' => $ahora + TOKEN_EXPIRATION,
        'data' => [
            'id_usuario' => $user['id_usuario'] ?? null,
            'correo' => $user['correo'] ?? null,
            'id_rol' => $user['id_rol'] ?? null
        ]
    ];

    return JWT::encode($payload, SECRET_KEY, JWT_ALGORITHM);
}

function generarTokenRecuperacion($user)
{
    $ahora = time();
    $payload = [
        'iat' => $ahora,
        'exp' => $ahora + RESET_TOKEN_EXPIRATION,
        'purpose' => 'password_reset',
        'data' => [
            'id_usuario' => $user['id_usuario'] ?? null,
            'correo' => $user['correo'] ?? null
        ]
    ];

    return JWT::encode($payload, SECRET_KEY, JWT_ALGORITHM);
}

function contraseñaValida($password)
{
    return preg_match('/^(?=.*[A-Z])(?=.*[0-9]).{6,}$/', $password) === 1;
}

function construirUrlRecuperacion($token)
{
    $separator = (strpos(RESET_PASSWORD_URL, '?') !== false) ? '&' : '?';
    return RESET_PASSWORD_URL . $separator . 'token=' . urlencode($token);
}

function enviarCorreoRecuperacion($correo, $resetToken)
{
    if (empty(EMAIL_SMTP_HOST) || empty(EMAIL_FROM_ADDRESS) || empty(RESET_PASSWORD_URL)) {
        throw new Exception('Configuración SMTP incompleta para envío de recuperación de contraseña');
    }

    if (EMAIL_SMTP_AUTH && (empty(EMAIL_SMTP_USERNAME) || empty(EMAIL_SMTP_PASSWORD))) {
        throw new Exception('Credenciales SMTP incompletas');
    }

    $urlRecuperacion = construirUrlRecuperacion($resetToken);

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = EMAIL_SMTP_HOST;
    $mail->Port = EMAIL_SMTP_PORT;
    $mail->SMTPAuth = EMAIL_SMTP_AUTH;

    if (!empty(EMAIL_SMTP_SECURE)) {
        $mail->SMTPSecure = EMAIL_SMTP_SECURE;
    }

    if (EMAIL_SMTP_AUTH) {
        $mail->Username = EMAIL_SMTP_USERNAME;
        $mail->Password = EMAIL_SMTP_PASSWORD;
    }

    $mail->CharSet = 'UTF-8';
    $mail->setFrom(EMAIL_FROM_ADDRESS, EMAIL_FROM_NAME);
    $mail->addAddress($correo);
    $mail->isHTML(true);
    $mail->Subject = 'Recuperación de contraseña';
    $mail->Body = '<p>Recibimos una solicitud para recuperar tu contraseña.</p>'
        . '<p>Haz clic en el siguiente enlace para restablecerla:</p>'
        . '<p><a href="' . htmlspecialchars($urlRecuperacion, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($urlRecuperacion, ENT_QUOTES, 'UTF-8') . '</a></p>'
        . '<p>Este enlace expirará en ' . intval(RESET_TOKEN_EXPIRATION / 60) . ' minutos.</p>';
    $mail->AltBody = "Recibimos una solicitud para recuperar tu contraseña. "
        . "Usa este enlace para restablecerla: " . $urlRecuperacion
        . ". Este enlace expirará en " . intval(RESET_TOKEN_EXPIRATION / 60) . " minutos.";

    $mail->send();
}

switch ($method) {
    case 'get':
        if ($route == '/verify' || $route == '/login/verify') {
            $token = getBearerToken();
            if (empty($token)) {
                http_response_code(401);
                echo json_encode(["status" => 401, "code" => "MISSING_TOKEN", "error" => "Token no enviado"]);
                break;
            }

            try {
                $decoded = JWT::decode($token, new Key(SECRET_KEY, JWT_ALGORITHM));
                http_response_code(200);
                echo json_encode([
                    "status" => 200,
                    "message" => "Token válido",
                    "data" => $decoded->data ?? null
                ]);
            } catch (Exception $e) {
                http_response_code(401);
                echo json_encode(["status" => 401, "code" => "INVALID_TOKEN", "error" => "Token inválido o expirado"]);
            }
        } elseif ($route == '/verify-reset-token' || $route == '/login/verify-reset-token') {
            $resetToken = $_GET['token'] ?? null;

            if (empty($resetToken)) {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "MISSING_TOKEN", "error" => "Parámetro 'token' es obligatorio"]);
                break;
            }

            try {
                $decoded = JWT::decode($resetToken, new Key(SECRET_KEY, JWT_ALGORITHM));
                if (($decoded->purpose ?? '') !== 'password_reset') {
                    http_response_code(401);
                    echo json_encode(["status" => 401, "code" => "INVALID_TOKEN", "error" => "Token de recuperación inválido"]);
                    break;
                }

                http_response_code(200);
                echo json_encode([
                    "status" => 200,
                    "message" => "Token de recuperación válido",
                    "data" => [
                        "correo" => $decoded->data->correo ?? null,
                        "expira_en_segundos" => ($decoded->exp ?? 0) - time()
                    ]
                ]);
            } catch (Exception $e) {
                http_response_code(401);
                echo json_encode(["status" => 401, "code" => "INVALID_TOKEN", "error" => "Token de recuperación inválido o expirado"]);
            }
        } else {
            http_response_code(404);
            echo json_encode(["status" => 404, "code" => "ROUTE_NOT_FOUND", "error" => "Ruta no encontrada en login"]);
        }
        break;

    case 'post':
        if ($route == '/login') {
            $body = json_decode(file_get_contents("php://input"), true);
            $correo = $body['correo'] ?? null;
            if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "INVALID_EMAIL", "error" => "El campo correo es obligatorio y debe ser un correo válido"]);
                break;
            }

            $password = $body['contrasena'] ?? null;
            if (empty($password)) {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "INVALID_PASSWORD", "error" => "El campo password es obligatorio"]);
                break;
            }

            // Verificación de datos si las credenciales son correctas
            $query = "SELECT c.id_usuario, c.correo, c.contrasena, u.id_rol FROM cuenta c JOIN usuario u ON c.id_usuario = u.id_usuario WHERE c.correo = :correo";
            $statement = Conection::getInstance()->getConection()->prepare($query);
            $statement->bindParam(':correo', $correo);
            $statement->execute();
            $user = $statement->fetch(PDO::FETCH_ASSOC);

            if ($user == null) {
                http_response_code(401);
                echo json_encode(["status" => 401, "code" => "INVALID_CREDENTIALS", "error" => "Credenciales inválidas"]);
                break;
            }

            if (password_verify($password, $user["contrasena"])) {
                $token = generarToken($user);
                http_response_code(200);
                echo json_encode([
                    "status" => 200,
                    "message" => "Login exitoso",
                    "token" => $token,
                    "expires_in" => TOKEN_EXPIRATION,
                    "usuario" => [
                        "id_usuario" => $user['id_usuario'] ?? null,
                        "correo" => $user['correo'] ?? null,
                        "id_rol" => $user['id_rol'] ?? null
                    ]
                ]);
            } else {
                http_response_code(401);
                echo json_encode(["status" => 401, "code" => "INVALID_CREDENTIALS", "error" => "Credenciales inválidas"]);
            }
        } elseif ($route == '/forgot-password' || $route == '/login/forgot-password') {
            $body = json_decode(file_get_contents("php://input"), true);
            $correo = $body['correo'] ?? null;

            if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "INVALID_EMAIL", "error" => "El campo correo es obligatorio y debe ser un correo válido"]);
                break;
            }

            $query = "SELECT id_usuario, correo FROM cuenta WHERE correo = :correo";
            $statement = Conection::getInstance()->getConection()->prepare($query);
            $statement->bindParam(':correo', $correo);
            $statement->execute();
            $user = $statement->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                $resetToken = generarTokenRecuperacion($user);
                $response = [
                    "status" => 200,
                    "message" => "Si el correo existe, recibirás instrucciones para recuperar la contraseña"
                ];

                try {
                    enviarCorreoRecuperacion($user['correo'], $resetToken);
                } catch (MailException $e) {
                    http_response_code(500);
                    echo json_encode([
                        "status" => 500,
                        "code" => "EMAIL_SEND_FAILED",
                        "error" => "No se pudo enviar el correo de recuperación"
                    ]);
                    break;
                } catch (Exception $e) {
                    http_response_code(500);
                    echo json_encode([
                        "status" => 500,
                        "code" => "EMAIL_CONFIGURATION_ERROR",
                        "error" => "Configuración de correo incompleta o inválida"
                    ]);
                    break;
                }

                if (RESET_TOKEN_RETURN_IN_RESPONSE) {
                    $response['reset_token'] = $resetToken;
                    $response['expires_in'] = RESET_TOKEN_EXPIRATION;
                }

                http_response_code(200);
                echo json_encode($response);
            } else {
                http_response_code(200);
                echo json_encode([
                    "status" => 200,
                    "message" => "Si el correo existe, recibirás instrucciones para recuperar la contraseña"
                ]);
            }
        } elseif ($route == '/reset-password' || $route == '/login/reset-password') {
            $body = json_decode(file_get_contents("php://input"), true);
            $resetToken = $body['token'] ?? null;
            $newPassword = $body['nueva_contrasena'] ?? null;

            if (empty($resetToken)) {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "MISSING_RESET_TOKEN", "error" => "El campo token es obligatorio"]);
                break;
            }

            if (empty($newPassword) || !contraseñaValida($newPassword)) {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "INVALID_PASSWORD", "error" => "La nueva contraseña debe tener al menos 6 caracteres, una letra mayúscula y un número"]);
                break;
            }

            try {
                $decoded = JWT::decode($resetToken, new Key(SECRET_KEY, JWT_ALGORITHM));
                if (($decoded->purpose ?? '') !== 'password_reset') {
                    http_response_code(401);
                    echo json_encode(["status" => 401, "code" => "INVALID_RESET_TOKEN", "error" => "Token de recuperación inválido"]);
                    break;
                }

                $correo = $decoded->data->correo ?? null;
                if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                    http_response_code(401);
                    echo json_encode(["status" => 401, "code" => "INVALID_RESET_TOKEN", "error" => "Token de recuperación inválido"]);
                    break;
                }

                $query = "SELECT id_usuario, contrasena FROM cuenta WHERE correo = :correo";
                $statement = Conection::getInstance()->getConection()->prepare($query);
                $statement->bindParam(':correo', $correo);
                $statement->execute();
                $user = $statement->fetch(PDO::FETCH_ASSOC);

                if (!$user) {
                    http_response_code(404);
                    echo json_encode(["status" => 404, "code" => "USER_NOT_FOUND", "error" => "Usuario no encontrado"]);
                    break;
                }

                if (password_verify($newPassword, $user['contrasena'])) {
                    http_response_code(400);
                    echo json_encode(["status" => 400, "code" => "SAME_PASSWORD", "error" => "La nueva contraseña debe ser diferente a la actual"]);
                    break;
                }

                $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
                $update = "UPDATE cuenta SET contrasena = :contrasena WHERE id_usuario = :id_usuario";
                $updateStatement = Conection::getInstance()->getConection()->prepare($update);
                $updateStatement->bindValue(':contrasena', $hashedPassword);
                $updateStatement->bindValue(':id_usuario', $user['id_usuario'], PDO::PARAM_INT);
                $updateStatement->execute();

                http_response_code(200);
                echo json_encode(["status" => 200, "message" => "Contraseña actualizada exitosamente"]);
            } catch (Exception $e) {
                http_response_code(401);
                echo json_encode(["status" => 401, "code" => "INVALID_RESET_TOKEN", "error" => "Token de recuperación inválido o expirado"]);
            }
        } else {
            http_response_code(404);
            echo json_encode(["status" => 404, "code" => "ROUTE_NOT_FOUND", "error" => "Ruta no encontrada en login"]);
        }

        break;

    default:
        http_response_code(404);
        echo json_encode(["status" => 404, "code" => "ROUTE_NOT_FOUND", "error" => "Ruta no encontrada en login"]);
        break;
}