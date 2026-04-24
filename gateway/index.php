<?php

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: http://localhost:4200");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");

$request = $_SERVER['REQUEST_URI'];
$method = $_SERVER['REQUEST_METHOD'];
$basePath = '/microservices/gateway';

if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Separar ruta de query string
$requestPath = parse_url($request, PHP_URL_PATH);
$queryString = $_SERVER['QUERY_STRING'] ?? '';

// limpiar ruta
$route = str_replace($basePath, "", $requestPath);
$path = $route;

// var_dump($request);
// var_dump($route);
// exit;
if (strpos($route, '/usuarios') === 0) {
    $url = "http://localhost/microservices/usuarios-service/index.php" . $path;
    // Pasar query string si existe
    if (!empty($queryString)) {
        $url .= '?' . $queryString;
    }
} elseif (strpos($route, '/login') === 0) {
    $url = "http://localhost/microservices/login-service/index.php" . $path;
    // Pasar query string si existe
    if (!empty($queryString)) {
        $url .= '?' . $queryString;
    }
} elseif (strpos($route, '/reservas') === 0) {
    $url = "http://localhost/microservices/reservas-service/index.php" . $path;
    // Pasar query string si existe
    if (!empty($queryString)) {
        $url .= '?' . $queryString;
    }
} elseif (strpos($route, '/cabanias') === 0) {
    $url = "http://localhost/microservices/cabanias-service/index.php" . $path;
    // Pasar query string si existe
    if (!empty($queryString)) {
        $url .= '?' . $queryString;
    }
} else {
    http_response_code(404);
    echo json_encode(["code" => "ROUTE_NOT_FOUND", "error" => "Ruta no encontrada"]);
    exit;
}

// 🔁 reenviar request
$ch = curl_init($url);

curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$isMultipart = stripos($contentType, 'multipart/form-data') !== false;

$forwardHeaders = [];
if (!$isMultipart) {
    $forwardHeaders[] = 'Content-Type: application/json';
}

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');

if (empty($authHeader) && function_exists('getallheaders')) {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? ($headers['authorization'] ?? '');
}

if (!empty($authHeader)) {
    $forwardHeaders[] = 'Authorization: ' . $authHeader;
}

curl_setopt($ch, CURLOPT_HTTPHEADER, $forwardHeaders);

// enviar body si aplica
if (in_array($method, ['GET', 'POST', 'PUT', 'DELETE'])) {
    if ($isMultipart) {
        $postFields = $_POST;
        foreach ($_FILES as $fieldName => $fileData) {
            if (!empty($fileData['tmp_name']) && is_uploaded_file($fileData['tmp_name'])) {
                $postFields[$fieldName] = new CURLFile(
                    $fileData['tmp_name'],
                    $fileData['type'] ?? 'application/octet-stream',
                    $fileData['name'] ?? 'upload.bin'
                );
            }
        }

        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    } else {
        $body = file_get_contents("php://input");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
}

$response = curl_exec($ch);
curl_close($ch);

echo $response;