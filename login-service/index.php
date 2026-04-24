<?php

// Allow the specific origin
require_once 'config/Conection.php';
require_once 'util/ExceptionApi.php';
require_once '../vendor/autoload.php';
require_once 'config/config.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

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