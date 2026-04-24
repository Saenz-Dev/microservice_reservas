<?php

// Allow the specific origin
require_once 'config/Conection.php';
require_once 'util/ExceptionApi.php';

header("Access-Control-Allow-Origin: http://localhost:4200");
// Allow the Content-Type header specifically
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
// Allow the HTTP methods you are using
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Content-Type: application/json");

$request = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = strtolower($_SERVER['REQUEST_METHOD']);

// Quitar ruta base del microservicio
$basePath = '/microservices/usuarios-service/index.php';
$route = str_replace($basePath, "", $request);

if ($route === '' || $route === false) {
    $route = '/';
}

if ($route[0] !== '/') {
    $route = '/' . $route;
}

function getAuthorizationHeader()
{
    $authorizationHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');

    if (empty($authorizationHeader) && function_exists('getallheaders')) {
        $headers = getallheaders();
        $authorizationHeader = $headers['Authorization'] ?? ($headers['authorization'] ?? '');
    }

    return $authorizationHeader;
}

function validarTokenOrFail()
{
    $authorizationHeader = getAuthorizationHeader();

    if (empty($authorizationHeader)) {
        http_response_code(401);
        echo json_encode(["status" => 401, "code" => "MISSING_TOKEN", "error" => "Token no enviado"]);
        return false;
    }

    $ch = curl_init('http://localhost/microservices/login-service/index.php/login/verify');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: ' . $authorizationHeader
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        http_response_code(401);
        $decoded = json_decode($response, true);
        echo json_encode([
            "status" => 401,
            "code" => "INVALID_TOKEN",
            "error" => $decoded['error'] ?? "Token inválido o expirado"
        ]);
        return false;
    }

    return true;
}

switch ($method) {
    case 'get':
        if ($route == '/usuarios') {
            if (isset($_GET['ct']) && !validarTokenOrFail()) {
                break;
            }

            // Si hay parámetro correo en query string
            if (isset($_GET['correo'])) {
                $correo = trim($_GET['correo']);
                // Validar que sea un correo válido
                if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                    http_response_code(400);
                    echo json_encode(["status" => 400, "code" => "INVALID_EMAIL", "error" => "El parámetro correo debe ser un correo válido"]);
                    break;
                }

                $query = "SELECT u.nombres, u.apellidos, u.tipo_documento, u.numero_documento, u.telefono, u.direccion, u.ciudad, u.fecha_nacimiento, c.correo FROM cuenta c JOIN usuario u ON c.id_usuario = u.id_usuario WHERE c.correo = :correo";
                $statement = Conection::getInstance()->getConection()->prepare($query);
                $statement->bindValue(':correo', $correo);
                $statement->execute();
                $usuario = $statement->fetch(PDO::FETCH_ASSOC);

                if ($usuario) {
                    http_response_code(200);
                    echo json_encode(["status" => 200] + $usuario);
                } else {
                    http_response_code(404);
                    echo json_encode(["status" => 404, "code" => "USER_NOT_FOUND", "error" => "Usuario no encontrado", "busqueda" => $correo]);
                }
            } elseif (isset($_GET['numero_documento'])) {
                $idUsuario = trim($_GET['numero_documento']);
                if (!ctype_digit($idUsuario)) {
                    http_response_code(400);
                    echo json_encode(["status" => 400, "code" => "INVALID_ID_USUARIO", "error" => "El parámetro id_usuario debe ser un número entero"]);
                    break;
                }

                $query = "SELECT u.nombres, u.apellidos, u.tipo_documento, u.numero_documento, u.telefono, u.direccion, u.ciudad, u.fecha_nacimiento, c.correo FROM cuenta c JOIN usuario u ON c.id_usuario = u.id_usuario WHERE u.numero_documento = :numero_documento";
                $statement = Conection::getInstance()->getConection()->prepare($query);
                $statement->bindValue(':numero_documento', $idUsuario, PDO::PARAM_STR);
                $statement->execute();
                $usuario = $statement->fetch(PDO::FETCH_ASSOC);

                if ($usuario) {
                    http_response_code(200);
                    echo json_encode(["status" => 200] + $usuario);
                } else {
                    http_response_code(404);
                    echo json_encode(["status" => 404, "code" => "USER_NOT_FOUND", "error" => "Usuario no encontrado", "busqueda" => $idUsuario]);
                }
            } else {
                //Consulta a la base de datos para obtener todos los usuarios
                $query = "SELECT * FROM usuario";
                // Preparación del statement
                $statement = Conection::getInstance()->getConection()->prepare($query);
                $statement->execute();
                $usuarios = $statement->fetchAll(PDO::FETCH_ASSOC);
                http_response_code(200);
                echo json_encode(["status" => 200, "data" => $usuarios]);
            }
        }
        break;
    case 'post':
        if ($route == '/usuarios') {
            $body = json_decode(file_get_contents("php://input"), true);

            if (!isset($body['nombres'], $body['apellidos'], $body['tipo_documento'], $body['numero_documento'], $body['telefono'], $body['ciudad'], $body['fecha_nacimiento'], $body['estado'], $body['id_rol'], $body['correo'], $body['contrasena'])) {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "MISSING_FIELDS", "error" => "Todos los campos son obligatorios"]);
                break;
            }
            if (!in_array($body['tipo_documento'], ['CC', 'TI', 'CE'])) {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "INVALID_TIPO_DOCUMENTO", "error" => "El campo Tipo de documento debe ser CC, TI o CE"]);
                break;
            }
            if (!preg_match('/^\d{7,10}$/', $body['numero_documento'])) {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "INVALID_NUMERO_DOCUMENTO", "error" => "El campo Número de documento debe ser un número entre 7 y 10 dígitos"]);
                break;
            }
            if (!preg_match('/^\d{7,10}$/', $body['telefono'])) {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "INVALID_TELEFONO", "error" => "El campo Teléfono debe ser un número entre 7 y 10 dígitos"]);
                break;
            }
            if (!in_array($body['estado'], [0, 1])) {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "INVALID_ESTADO", "error" => "El campo Estado debe ser 0 o 1"]);
                break;
            }
            if (!filter_var($body['correo'], FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "INVALID_CORREO", "error" => "El campo Correo debe ser un correo electrónico válido"]);
                break;
            }
            if (!preg_match('/^(?=.*[A-Z])(?=.*[0-9]).{6,}$/', $body['contrasena'])) {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "INVALID_CONTRASEÑA", "error" => "El campo Contraseña debe tener al menos 6 caracteres, una letra mayúscula y un número"]);
                break;
            }

            $queryUsuario = "INSERT INTO usuario (nombres, apellidos, tipo_documento, numero_documento, telefono, direccion, ciudad, fecha_nacimiento, estado, id_rol) VALUES (:nombres, :apellidos, :tipo_documento, :numero_documento, :telefono, :direccion, :ciudad, :fecha_nacimiento, :estado, :id_rol)";
            $statementUsuario = Conection::getInstance()->getConection()->prepare($queryUsuario);
            $statementUsuario->bindValue(':nombres', $body['nombres']);
            $statementUsuario->bindValue(':apellidos', $body['apellidos']);
            $statementUsuario->bindValue(':tipo_documento', $body['tipo_documento']);
            $statementUsuario->bindValue(':numero_documento', $body['numero_documento']);
            $statementUsuario->bindValue(':telefono', $body['telefono']);
            $statementUsuario->bindValue(':direccion', '');
            $statementUsuario->bindValue(':ciudad', $body['ciudad']);
            $statementUsuario->bindValue(':fecha_nacimiento', $body['fecha_nacimiento']);
            $statementUsuario->bindValue(':estado', $body['estado']);
            $statementUsuario->bindValue(':id_rol', $body['id_rol']);
            $statementUsuario->execute();
            $idUsuario = Conection::getInstance()->getConection()->lastInsertId();
            $queryCuenta = "INSERT INTO cuenta (correo, contrasena, id_usuario, estado_sesion) VALUES (:correo, :contrasena, :id_usuario, 0)";
            $statementCuenta = Conection::getInstance()->getConection()->prepare($queryCuenta);
            $statementCuenta->bindValue(':correo', $body['correo']);
            $statementCuenta->bindValue(':contrasena', password_hash($body['contrasena'], PASSWORD_BCRYPT));
            $statementCuenta->bindValue(':id_usuario', $idUsuario);
            $statementCuenta->execute();
            http_response_code(201);
            echo json_encode(["status" => 201, "message" => "Usuario creado exitosamente"]);
        } else {
            http_response_code(404);
            echo json_encode(["status" => 404, "code" => "ROUTE_NOT_FOUND", "error" => "Ruta no encontrada en usuarios"]);
        }
        break;
    case 'put':
        if (!validarTokenOrFail()) {
            break;
        }
        if ($route == '/usuarios') {
            $body = json_decode(file_get_contents("php://input"), true);

            if (!isset($body['nombres'], $body['apellidos'], $body['tipo_documento'], $body['numero_documento'], $body['telefono'], $body['ciudad'], $body['fecha_nacimiento'], $body['estado'], $body['id_rol'])) {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "MISSING_FIELDS", "error" => "Todos los campos son obligatorios"]);
                break;
            }
            if (!in_array($body['tipo_documento'], ['CC', 'CE'])) {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "INVALID_TIPO_DOCUMENTO", "error" => "El campo Tipo de documento debe ser CC o CE"]);
                break;
            }
            if (!preg_match('/^\d{7,10}$/', $body['numero_documento'])) {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "INVALID_NUMERO_DOCUMENTO", "error" => "El campo Número de documento debe ser un número entre 7 y 10 dígitos"]);
                break;
            }
            if (!preg_match('/^\d{7,10}$/', $body['telefono'])) {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "INVALID_TELEFONO", "error" => "El campo Teléfono debe ser un número entre 7 y 10 dígitos"]);
                break;
            }
            if (!in_array($body['estado'], [0, 1])) {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "INVALID_ESTADO", "error" => "El campo Estado debe ser 0 o 1"]);
                break;
            }

            $queryUsuario = "UPDATE usuario SET nombres = :nombres, apellidos = :apellidos, tipo_documento = :tipo_documento, numero_documento = :numero_documento, telefono = :telefono, ciudad = :ciudad, fecha_nacimiento = :fecha_nacimiento, estado = :estado, id_rol = :id_rol WHERE id_usuario = :id_usuario";
            $statementUsuario = Conection::getInstance()->getConection()->prepare($queryUsuario);
            $statementUsuario->bindValue(':nombres', $body['nombres']);
            $statementUsuario->bindValue(':apellidos', $body['apellidos']);
            $statementUsuario->bindValue(':tipo_documento', $body['tipo_documento']);
            $statementUsuario->bindValue(':numero_documento', $body['numero_documento']);
            $statementUsuario->bindValue(':telefono', $body['telefono']);
            $statementUsuario->bindValue(':ciudad', $body['ciudad']);
            $statementUsuario->bindValue(':fecha_nacimiento', $body['fecha_nacimiento']);
            $statementUsuario->bindValue(':estado', $body['estado']);
            $statementUsuario->bindValue(':id_rol', $body['id_rol']);
            $statementUsuario->bindValue(':id_usuario', $body['id_usuario']);
            $statementUsuario->execute();
            // $idUsuario = Conection::getInstance()->getConection()->lastInsertId();
            // $queryCuenta = "INSERT INTO cuenta (correo, contrasena, id_usuario, estado_sesion) VALUES (:correo, :contrasena, :id_usuario, 0)";
            // $statementCuenta = Conection::getInstance()->getConection()->prepare($queryCuenta);
            // $statementCuenta->bindValue(':correo', $body['correo']); 
            // $statementCuenta->bindValue(':contrasena', password_hash($body['contrasena'], PASSWORD_BCRYPT));
            // $statementCuenta->bindValue(':id_usuario', $idUsuario);
            // $statementCuenta->execute();
            http_response_code(200);
            echo json_encode(["status" => 200, "message" => "Usuario actualizado exitosamente"]);
        } else {
            http_response_code(404);
            echo json_encode(["status" => 404, "code" => "ROUTE_NOT_FOUND", "error" => "Ruta no encontrada en usuarios"]);
        }
        break;
    case 'delete':
        if (!validarTokenOrFail()) {
            break;
        }
        if (!validarTokenOrFail()) {
            break;
        }
        if ($route == '/usuarios') {
            $body = json_decode(file_get_contents("php://input"), true);
            if (!isset($body['correo'])) {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "MISSING_CORREO", "error" => "El campo correo es obligatorio"]);
                break;
            }
            $query = "UPDATE usuario SET estado = 0 WHERE id_usuario = (SELECT id_usuario FROM cuenta WHERE correo = :correo LIMIT 1)";
            $statement = Conection::getInstance()->getConection()->prepare($query);
            $statement->bindValue(':correo', $body['correo']);
            $statement->execute();
            $queryEstadoUsuario = "SELECT estado FROM usuario WHERE id_usuario = (SELECT id_usuario FROM cuenta WHERE correo = :correo LIMIT 1)";
            $statementEstadoUsuario = Conection::getInstance()->getConection()->prepare($queryEstadoUsuario);
            $statementEstadoUsuario->bindValue(':correo', $body['correo']);
            $statementEstadoUsuario->execute();
            $estadoUsuario = $statementEstadoUsuario->fetch(PDO::FETCH_ASSOC);
            if ($estadoUsuario && $estadoUsuario['estado'] == 0) {
                http_response_code(200);
                echo json_encode(["status" => 200, "message" => "Usuario eliminado exitosamente"]);
            } else {
                http_response_code(404);
                echo json_encode(["status" => 404, "code" => "USER_NOT_FOUND", "error" => "Usuario no encontrado o ya eliminado"]);
            }
        } else {
            http_response_code(404);
            echo json_encode(["status" => 404, "code" => "ROUTE_NOT_FOUND", "error" => "Ruta no encontrada en usuarios"]);
        }
        break;
    default:
        http_response_code(404);
        echo json_encode(["status" => 404, "code" => "ROUTE_NOT_FOUND", "error" => "Ruta no encontrada en usuarios"]);
        break;
}