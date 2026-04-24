<?php

// Allow the specific origin
require_once 'config/Conection.php';
require_once 'util/ExceptionApi.php';
require_once 'util/upload.php';   

header("Access-Control-Allow-Origin: http://localhost:4200");
// Allow the Content-Type header specifically
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
// Allow the HTTP methods you are using
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Content-Type: application/json");

$request = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = strtolower($_SERVER['REQUEST_METHOD']);

// Quitar ruta base del microservicio
$basePath = '/microservices/cabanias-service/index.php';
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

function normalizarUrlImagenCabania(array $cabania)
{
    if (!empty($cabania['url_imagen']) && strpos($cabania['url_imagen'], 'http://') !== 0 && strpos($cabania['url_imagen'], 'https://') !== 0) {
        $cabania['url_imagen'] = 'http://localhost/microservices/cabanias-service/uploads/' . ltrim($cabania['url_imagen'], '/');
    }

    return $cabania;
}

switch ($method) {
    case 'get':
        if ($route == '/cabanias') {
            if (!validarTokenOrFail()) {
                break;
            }

            // Si hay parámetro nombre en query string
            if (isset($_GET['nombre'])) {
                $nombre = trim($_GET['nombre']);
                // Validar que sea un nombre válido
                if (empty($nombre) || !preg_match('/^[a-zA-Z\s]+$/', $nombre)) {
                    http_response_code(400);
                    echo json_encode(["status" => 400, "code" => "INVALID_NAME", "error" => "El parámetro nombre debe ser un nombre válido"]);
                    break;
                }

                $query = "SELECT * FROM cabania WHERE nombre LIKE :nombre";
                $statement = Conection::getInstance()->getConection()->prepare($query);
                $statement->bindValue(':nombre', "%$nombre%", PDO::PARAM_STR);
                $statement->execute();
                $cabania = $statement->fetch(PDO::FETCH_ASSOC);

                if ($cabania) {
                    $cabania = normalizarUrlImagenCabania($cabania);
                    http_response_code(200);
                    echo json_encode(["status" => 200] + $cabania);
                } else {
                    http_response_code(404);
                    echo json_encode(["status" => 404, "code" => "USER_NOT_FOUND", "error" => "Cabania no encontrado", "busqueda" => $nombre]);
                }
            } elseif (isset($_GET['id_cabania'])) {
                $idCabania = trim($_GET['id_cabania']);
                if (!ctype_digit($idCabania)) {
                    http_response_code(400);
                    echo json_encode(["status" => 400, "code" => "INVALID_ID_CABANIA", "error" => "El parámetro id_cabania debe ser un número entero"]);
                    break;
                }

                $query = "SELECT * FROM cabania WHERE id_cabania = :id_cabania";
                $statement = Conection::getInstance()->getConection()->prepare($query);
                $statement->bindValue(':id_cabania', $idCabania, PDO::PARAM_STR);
                $statement->execute();
                $cabania = $statement->fetch(PDO::FETCH_ASSOC);

                if ($cabania) {
                    $cabania = normalizarUrlImagenCabania($cabania);
                    http_response_code(200);
                    echo json_encode(["status" => 200] + $cabania);
                } else {
                    http_response_code(404);
                    echo json_encode(["status" => 404, "code" => "CABANIA_NOT_FOUND", "error" => "Cabania no encontrada", "busqueda" => $idCabania]);
                }
            } else {
                //Consulta a la base de datos para obtener todas las cabanias
                $query = "SELECT * FROM cabania";
                // Preparación del statement
                $statement = Conection::getInstance()->getConection()->prepare($query);
                $statement->execute();
                $cabanias = $statement->fetchAll(PDO::FETCH_ASSOC);
                $cabanias = array_map('normalizarUrlImagenCabania', $cabanias);
                http_response_code(200);
                echo json_encode(["status" => 200, "data" => $cabanias]);
            }
        }
        break;
    case 'post':
        if ($route == '/cabanias/upload') {
            if (!validarTokenOrFail()) {
                break;
            }

            subirImagenCabania();
        } else {
            http_response_code(404);
            echo json_encode(["status" => 404, "code" => "ROUTE_NOT_FOUND", "error" => "Ruta no encontrada en cabanias"]);
        }
        break;
    case 'put':
        //
    case 'delete':
        //
        break;
    default:
        http_response_code(404);
        echo json_encode(["status" => 404, "code" => "ROUTE_NOT_FOUND", "error" => "Ruta no encontrada en usuarios"]);
        break;
}