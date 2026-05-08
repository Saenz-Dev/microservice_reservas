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
$basePath = '/microservices/reservas-service/index.php';
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

function obtenerDatosTokenOrFail()
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

    $decoded = json_decode($response, true);
    $data = $decoded['data'] ?? null;

    if (!is_array($data) || !isset($data['id_usuario'])) {
        http_response_code(401);
        echo json_encode([
            "status" => 401,
            "code" => "INVALID_TOKEN",
            "error" => "El token no contiene datos de usuario validos"
        ]);
        return false;
    }

    return $data;
}

function validarDisponibilidadCabania($idCabania, $fechaHoraInicio, $fechaHoraFin)
{
    $query = "SELECT COUNT(*) AS total
              FROM reserva r
              INNER JOIN reserva_cabania rc ON rc.id_reserva = r.id_reserva
              WHERE rc.id_cabania = :id_cabania
                AND r.estado <> 0
                AND (:fecha_inicio < r.fecha_hora_fin AND :fecha_fin > r.fecha_hora_inicio)";

    $statement = Conection::getInstance()->getConection()->prepare($query);
    $statement->bindValue(':id_cabania', (int) $idCabania, PDO::PARAM_INT);
    $statement->bindValue(':fecha_inicio', $fechaHoraInicio, PDO::PARAM_STR);
    $statement->bindValue(':fecha_fin', $fechaHoraFin, PDO::PARAM_STR);
    $statement->execute();
    $resultado = $statement->fetch(PDO::FETCH_ASSOC);

    return ((int) ($resultado['total'] ?? 0)) === 0;
}

function validarDisponibilidadMesa($idMesa, $fechaHoraInicio, $fechaHoraFin)
{
    $query = "SELECT COUNT(*) AS total
              FROM reserva r
              INNER JOIN reserva_mesa rm ON rm.id_reserva = r.id_reserva
              WHERE rm.id_mesa = :id_mesa
                AND r.estado <> 0
                AND (:fecha_inicio < r.fecha_hora_fin AND :fecha_fin > r.fecha_hora_inicio)";

    $statement = Conection::getInstance()->getConection()->prepare($query);
    $statement->bindValue(':id_mesa', (int) $idMesa, PDO::PARAM_INT);
    $statement->bindValue(':fecha_inicio', $fechaHoraInicio, PDO::PARAM_STR);
    $statement->bindValue(':fecha_fin', $fechaHoraFin, PDO::PARAM_STR);
    $statement->execute();
    $resultado = $statement->fetch(PDO::FETCH_ASSOC);

    return ((int) ($resultado['total'] ?? 0)) === 0;
}

function normalizarEstadoReserva($estado)
{
    if (is_int($estado) || ctype_digit((string) $estado)) {
        return (int) $estado;
    }

    $estadoNormalizado = strtolower(trim((string) $estado));
    $mapaEstados = [
        'confirmada' => 1,
        'confirmado' => 1,
        'activa' => 1,
        'activo' => 1,
        'aprobada' => 1,
        'aprobado' => 1,
        'pendiente' => 2,
        'cancelada' => 0,
        'cancelado' => 0,
        'anulada' => 0,
        'anulado' => 0,
    ];

    return $mapaEstados[$estadoNormalizado] ?? null;
}

function generarFacturaAutomaticaOrFail(int $idReserva)
{
    $internalKey = 'sistema_interno_key_12345';
    $url = 'http://localhost/microservices/factura-service/index.php/facturas/generar-interno';
    $body = json_encode([
        'id_reserva' => $idReserva
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-Internal-Key: ' . $internalKey
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || !empty($curlError)) {
        return [
            'ok' => false,
            'status' => 500,
            'error' => 'No se pudo conectar con el servicio de facturación'
        ];
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'status' => 500,
            'error' => 'Respuesta inválida del servicio de facturación'
        ];
    }

    if (!in_array($httpCode, [200, 201], true)) {
        return [
            'ok' => false,
            'status' => $httpCode > 0 ? $httpCode : 500,
            'error' => $decoded['error'] ?? 'No se pudo generar la factura automáticamente'
        ];
    }

    return [
        'ok' => true,
        'status' => $httpCode,
        'payload' => $decoded
    ];
}

switch ($method) {
    case 'get':
        if ($route == '/reservas/mis-reservas') {
            $datosToken = obtenerDatosTokenOrFail();
            if ($datosToken === false) {
                break;
            }

            $idUsuario = (int) ($datosToken['id_usuario'] ?? 0);
            if ($idUsuario <= 0) {
                http_response_code(401);
                echo json_encode([
                    "status" => 401,
                    "code" => "INVALID_TOKEN",
                    "error" => "No se pudo identificar el usuario autenticado"
                ]);
                break;
            }

            $query = "SELECT r.*, rc.id_cabania, c.nombre AS nombre_cabania
                      FROM reserva_cabania rc
                      LEFT JOIN reserva r ON rc.id_reserva = r.id_reserva
                      LEFT JOIN cabania c ON c.id_cabania = rc.id_cabania
                      WHERE r.id_usuario = :id_usuario
                      ORDER BY r.fecha_hora_inicio DESC";
            $statement = Conection::getInstance()->getConection()->prepare($query);
            $statement->bindValue(':id_usuario', $idUsuario, PDO::PARAM_INT);
            $statement->execute();
            $reservasUsuario = $statement->fetchAll(PDO::FETCH_ASSOC);

            http_response_code(200);
            if (empty($reservasUsuario)) {
                echo json_encode([
                    "status" => 200,
                    "code" => "NO_RESERVAS",
                    "message" => "El usuario aun no ha realizado reservas",
                    "data" => []
                ]);
            } else {
                echo json_encode(["status" => 200, "data" => $reservasUsuario]);
            }
        } elseif ($route == '/reservas/mesas/mis-reservas') {
            $datosToken = obtenerDatosTokenOrFail();
            if ($datosToken === false) {
                break;
            }

            $idUsuario = (int) ($datosToken['id_usuario'] ?? 0);
            if ($idUsuario <= 0) {
                http_response_code(401);
                echo json_encode([
                    "status" => 401,
                    "code" => "INVALID_TOKEN",
                    "error" => "No se pudo identificar el usuario autenticado"
                ]);
                break;
            }

            $query = "SELECT r.*, rm.id_mesa
                      FROM reserva_mesa rm
                      LEFT JOIN reserva r ON rm.id_reserva = r.id_reserva
                      WHERE r.id_usuario = :id_usuario
                      ORDER BY r.fecha_hora_inicio DESC";
            $statement = Conection::getInstance()->getConection()->prepare($query);
            $statement->bindValue(':id_usuario', $idUsuario, PDO::PARAM_INT);
            $statement->execute();
            $reservasUsuario = $statement->fetchAll(PDO::FETCH_ASSOC);

            http_response_code(200);
            if (empty($reservasUsuario)) {
                echo json_encode([
                    "status" => 200,
                    "code" => "NO_RESERVAS",
                    "message" => "El usuario aun no ha realizado reservas de mesas",
                    "data" => []
                ]);
            } else {
                echo json_encode(["status" => 200, "data" => $reservasUsuario]);
            }
        } elseif ($route == '/reservas/disponibilidad') {
            if (!validarTokenOrFail()) {
                break;
            }

            $nombreCabania = trim((string) ($_GET['nombre_cabania'] ?? ''));
            $fechaInicio = trim((string) ($_GET['fecha_hora_inicio'] ?? ''));
            $fechaFin = trim((string) ($_GET['fecha_hora_fin'] ?? ''));

            if ($fechaInicio === '' || $fechaFin === '') {
                http_response_code(400);
                echo json_encode([
                    "status" => 400,
                    "code" => "MISSING_FIELDS",
                    "error" => "Debe enviar fecha_hora_inicio y fecha_hora_fin"
                ]);
                break;
            }

            $dtInicio = date_create($fechaInicio);
            $dtFin = date_create($fechaFin);
            if (!$dtInicio || !$dtFin || $dtFin <= $dtInicio) {
                http_response_code(400);
                echo json_encode([
                    "status" => 400,
                    "code" => "INVALID_RANGE",
                    "error" => "Las fechas no son validas o el rango es incorrecto"
                ]);
                break;
            }

            $inicioFormateado = $dtInicio->format('Y-m-d H:i:s');
            $finFormateado = $dtFin->format('Y-m-d H:i:s');

            if ($nombreCabania === '') {
                $queryCabanias = "SELECT id_cabania, nombre FROM cabania";
                $statementCabanias = Conection::getInstance()->getConection()->prepare($queryCabanias);
                $statementCabanias->execute();
                $cabanias = $statementCabanias->fetchAll(PDO::FETCH_ASSOC);

                if (empty($cabanias)) {
                    http_response_code(200);
                    echo json_encode([
                        "status" => 200,
                        "code" => "NO_CABANIAS",
                        "message" => "No hay cabañas registradas",
                        "data" => []
                    ]);
                    break;
                }

                $resultado = [];
                foreach ($cabanias as $item) {
                    $idCabaniaItem = (int) $item['id_cabania'];
                    $resultado[] = [
                        "id_cabania" => $idCabaniaItem,
                        "nombre_cabania" => $item['nombre'],
                        "fecha_hora_inicio" => $inicioFormateado,
                        "fecha_hora_fin" => $finFormateado,
                        "disponible" => validarDisponibilidadCabania($idCabaniaItem, $inicioFormateado, $finFormateado)
                    ];
                }

                http_response_code(200);
                echo json_encode([
                    "status" => 200,
                    "data" => $resultado
                ]);
                break;
            }

            $queryCabania = "SELECT id_cabania FROM cabania WHERE nombre = :nombre_cabania LIMIT 1";
            $statementCabania = Conection::getInstance()->getConection()->prepare($queryCabania);
            $statementCabania->bindValue(':nombre_cabania', $nombreCabania, PDO::PARAM_STR);
            $statementCabania->execute();
            $cabania = $statementCabania->fetch(PDO::FETCH_ASSOC);

            if (!$cabania) {
                http_response_code(404);
                echo json_encode(["status" => 404, "code" => "CABANIA_NOT_FOUND", "error" => "No existe una cabaña con ese nombre"]);
                break;
            }

            $idCabania = (int) $cabania['id_cabania'];
            $disponible = validarDisponibilidadCabania($idCabania, $inicioFormateado, $finFormateado);

            http_response_code(200);
            echo json_encode([
                "status" => 200,
                "data" => [
                    "id_cabania" => $idCabania,
                    "nombre_cabania" => $nombreCabania,
                    "fecha_hora_inicio" => $inicioFormateado,
                    "fecha_hora_fin" => $finFormateado,
                    "disponible" => $disponible
                ]
            ]);
        } elseif ($route == '/reservas/mesas/disponibilidad') {
            if (!validarTokenOrFail()) {
                break;
            }

            $idMesa = trim((string) ($_GET['id_mesa'] ?? ''));
            $fechaInicio = trim((string) ($_GET['fecha_hora_inicio'] ?? ''));
            $fechaFin = trim((string) ($_GET['fecha_hora_fin'] ?? ''));

            if ($fechaInicio === '' || $fechaFin === '') {
                http_response_code(400);
                echo json_encode([
                    "status" => 400,
                    "code" => "MISSING_FIELDS",
                    "error" => "Debe enviar fecha_hora_inicio y fecha_hora_fin"
                ]);
                break;
            }

            $dtInicio = date_create($fechaInicio);
            $dtFin = date_create($fechaFin);
            if (!$dtInicio || !$dtFin || $dtFin <= $dtInicio) {
                http_response_code(400);
                echo json_encode([
                    "status" => 400,
                    "code" => "INVALID_RANGE",
                    "error" => "Las fechas no son validas o el rango es incorrecto"
                ]);
                break;
            }

            $inicioFormateado = $dtInicio->format('Y-m-d H:i:s');
            $finFormateado = $dtFin->format('Y-m-d H:i:s');

            if ($idMesa === '') {
                $queryMesas = "SELECT * FROM mesa";
                $statementMesas = Conection::getInstance()->getConection()->prepare($queryMesas);
                $statementMesas->execute();
                $mesas = $statementMesas->fetchAll(PDO::FETCH_ASSOC);

                if (empty($mesas)) {
                    http_response_code(200);
                    echo json_encode([
                        "status" => 200,
                        "code" => "NO_MESAS",
                        "message" => "No hay mesas registradas",
                        "data" => []
                    ]);
                    break;
                }

                $resultado = [];
                foreach ($mesas as $item) {
                    $idMesaItem = (int) $item['id_mesa'];
                    $resultado[] = array_merge($item, [
                        "fecha_hora_inicio" => $inicioFormateado,
                        "fecha_hora_fin" => $finFormateado,
                        "disponible" => validarDisponibilidadMesa($idMesaItem, $inicioFormateado, $finFormateado)
                    ]);
                }

                http_response_code(200);
                echo json_encode([
                    "status" => 200,
                    "data" => $resultado
                ]);
                break;
            }

            if (!ctype_digit($idMesa)) {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "INVALID_ID_MESA", "error" => "id_mesa debe ser un numero entero"]);
                break;
            }

            $queryMesa = "SELECT * FROM mesa WHERE id_mesa = :id_mesa LIMIT 1";
            $statementMesa = Conection::getInstance()->getConection()->prepare($queryMesa);
            $statementMesa->bindValue(':id_mesa', (int) $idMesa, PDO::PARAM_INT);
            $statementMesa->execute();
            $mesa = $statementMesa->fetch(PDO::FETCH_ASSOC);

            if (!$mesa) {
                http_response_code(404);
                echo json_encode(["status" => 404, "code" => "MESA_NOT_FOUND", "error" => "No existe una mesa con ese id"]);
                break;
            }

            $idMesaInt = (int) $mesa['id_mesa'];
            $disponible = validarDisponibilidadMesa($idMesaInt, $inicioFormateado, $finFormateado);

            http_response_code(200);
            echo json_encode([
                "status" => 200,
                "data" => array_merge($mesa, [
                    "fecha_hora_inicio" => $inicioFormateado,
                    "fecha_hora_fin" => $finFormateado,
                    "disponible" => $disponible
                ])
            ]);
        } elseif ($route == '/reservas/mesas') {
            if (!validarTokenOrFail()) {
                break;
            }

            if (isset($_GET['id_usuario'])) {
                $idUsuario = trim($_GET['id_usuario']);
                if (empty($idUsuario) || !ctype_digit($idUsuario)) {
                    http_response_code(400);
                    echo json_encode(["status" => 400, "code" => "INVALID_ID_USUARIO", "error" => "El parámetro id_usuario debe ser un número entero"]);
                    break;
                }

                $query = "SELECT r.*, rm.id_mesa
                          FROM reserva r
                          LEFT JOIN reserva_mesa rm ON rm.id_reserva = r.id_reserva
                          WHERE r.id_usuario = :id_usuario ORDER BY r.fecha_hora_inicio DESC";
                $statement = Conection::getInstance()->getConection()->prepare($query);
                $statement->bindValue(':id_usuario', $idUsuario, PDO::PARAM_STR);
                $statement->execute();
                $reservasUsuario = $statement->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($reservasUsuario)) {
                    http_response_code(200);
                    if (count($reservasUsuario) > 1) {
                        echo json_encode(["status" => 200, "data" => $reservasUsuario]);
                    } else {
                        echo json_encode(["status" => 200] + $reservasUsuario[0]);
                    }
                } else {
                    http_response_code(200);
                    echo json_encode([
                        "status" => 200,
                        "code" => "NO_RESERVAS",
                        "message" => "El usuario aun no ha realizado reservas de mesas",
                        "busqueda" => $idUsuario,
                        "data" => []
                    ]);
                }
            } elseif (isset($_GET['id_reserva'])) {
                $idReserva = trim($_GET['id_reserva']);
                if (!ctype_digit($idReserva)) {
                    http_response_code(400);
                    echo json_encode(["status" => 400, "code" => "INVALID_ID_RESERVA", "error" => "El parámetro id_reserva debe ser un número entero"]);
                    break;
                }

                $query = "SELECT r.*, rm.id_mesa
                          FROM reserva r
                          LEFT JOIN reserva_mesa rm ON rm.id_reserva = r.id_reserva
                          WHERE r.id_reserva = :id_reserva
                          LIMIT 1";
                $statement = Conection::getInstance()->getConection()->prepare($query);
                $statement->bindValue(':id_reserva', $idReserva, PDO::PARAM_STR);
                $statement->execute();
                $reserva = $statement->fetch(PDO::FETCH_ASSOC);

                if ($reserva) {
                    http_response_code(200);
                    echo json_encode(["status" => 200] + $reserva);
                } else {
                    http_response_code(404);
                    echo json_encode(["status" => 404, "code" => "RESERVA_NOT_FOUND", "error" => "Reserva no encontrada", "busqueda" => $idReserva]);
                }
            } elseif (isset($_GET['id_mesa'])) {
                $idMesa = trim($_GET['id_mesa']);
                if (!ctype_digit($idMesa)) {
                    http_response_code(400);
                    echo json_encode(["status" => 400, "code" => "INVALID_ID_MESA", "error" => "El parámetro id_mesa debe ser un número entero"]);
                    break;
                }

                $query = "SELECT r.*, rm.id_mesa
                          FROM reserva r
                          INNER JOIN reserva_mesa rm ON rm.id_reserva = r.id_reserva
                          WHERE rm.id_mesa = :id_mesa
                          ORDER BY r.fecha_hora_inicio DESC";
                $statement = Conection::getInstance()->getConection()->prepare($query);
                $statement->bindValue(':id_mesa', (int) $idMesa, PDO::PARAM_INT);
                $statement->execute();
                $reservasMesa = $statement->fetchAll(PDO::FETCH_ASSOC);

                http_response_code(200);
                if (empty($reservasMesa)) {
                    echo json_encode([
                        "status" => 200,
                        "code" => "NO_RESERVAS",
                        "message" => "Aun no hay reservas para esa mesa",
                        "busqueda" => (int) $idMesa,
                        "data" => []
                    ]);
                } else {
                    echo json_encode(["status" => 200, "data" => $reservasMesa]);
                }
            } else {
                $query = "SELECT r.*, rm.id_mesa
                          FROM reserva r
                          LEFT JOIN reserva_mesa rm ON rm.id_reserva = r.id_reserva";
                $statement = Conection::getInstance()->getConection()->prepare($query);
                $statement->execute();
                $reservas = $statement->fetchAll(PDO::FETCH_ASSOC);
                http_response_code(200);
                if (empty($reservas)) {
                    echo json_encode([
                        "status" => 200,
                        "code" => "NO_RESERVAS",
                        "message" => "Aun no hay reservas de mesas registradas",
                        "data" => []
                    ]);
                } else {
                    echo json_encode(["status" => 200, "data" => $reservas]);
                }
            }
        } elseif ($route == '/reservas') {
            if (!validarTokenOrFail()) {
                break;
            }

            // Si hay parámetro id_usuario en query string
            if (isset($_GET['id_usuario'])) {
                $idUsuario = trim($_GET['id_usuario']);
                // Validar que sea un ID de usuario válido
                if (empty($idUsuario) || !ctype_digit($idUsuario)) {
                    http_response_code(400);
                    echo json_encode(["status" => 400, "code" => "INVALID_ID_USUARIO", "error" => "El parámetro id_usuario debe ser un número entero"]);
                    break;
                }

                $query = "SELECT r.*, rc.id_cabania, c.nombre AS nombre_cabania
                          FROM reserva r
                          LEFT JOIN reserva_cabania rc ON rc.id_reserva = r.id_reserva
                          LEFT JOIN cabania c ON c.id_cabania = rc.id_cabania
                          WHERE r.id_usuario = :id_usuario ORDER BY r.fecha_hora_inicio DESC";
                $statement = Conection::getInstance()->getConection()->prepare($query);
                $statement->bindValue(':id_usuario', $idUsuario, PDO::PARAM_STR);
                $statement->execute();
                $reservasUsuario = $statement->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($reservasUsuario)) {
                    http_response_code(200);
                    if (count($reservasUsuario) > 1) {
                        echo json_encode(["status" => 200, "data" => $reservasUsuario]);
                    } else {
                        echo json_encode(["status" => 200] + $reservasUsuario[0]);
                    }
                } else {
                    http_response_code(200);
                    echo json_encode([
                        "status" => 200,
                        "code" => "NO_RESERVAS",
                        "message" => "El usuario aun no ha realizado reservas",
                        "busqueda" => $idUsuario,
                        "data" => []
                    ]);
                }
            } elseif (isset($_GET['id_reserva'])) {
                $idReserva = trim($_GET['id_reserva']);
                if (!ctype_digit($idReserva)) {
                    http_response_code(400);
                    echo json_encode(["status" => 400, "code" => "INVALID_ID_RESERVA", "error" => "El parámetro id_reserva debe ser un número entero"]);
                    break;
                }

                $query = "SELECT r.*, rc.id_cabania, c.nombre AS nombre_cabania
                          FROM reserva r
                          LEFT JOIN reserva_cabania rc ON rc.id_reserva = r.id_reserva
                          LEFT JOIN cabania c ON c.id_cabania = rc.id_cabania
                          WHERE r.id_reserva = :id_reserva
                          LIMIT 1";
                $statement = Conection::getInstance()->getConection()->prepare($query);
                $statement->bindValue(':id_reserva', $idReserva, PDO::PARAM_STR);
                $statement->execute();
                $reserva = $statement->fetch(PDO::FETCH_ASSOC);

                if ($reserva) {
                    http_response_code(200);
                    echo json_encode(["status" => 200] + $reserva);
                } else {
                    http_response_code(404);
                    echo json_encode(["status" => 404, "code" => "RESERVA_NOT_FOUND", "error" => "Reserva no encontrada", "busqueda" => $idReserva]);
                }
            } else {
                //Consulta a la base de datos para obtener todas las reservas
                $query = "SELECT r.*, rc.id_cabania, c.nombre AS nombre_cabania
                          FROM reserva r
                          LEFT JOIN reserva_cabania rc ON rc.id_reserva = r.id_reserva
                          LEFT JOIN cabania c ON c.id_cabania = rc.id_cabania";
                // Preparación del statement
                $statement = Conection::getInstance()->getConection()->prepare($query);
                $statement->execute();
                $reservas = $statement->fetchAll(PDO::FETCH_ASSOC);
                http_response_code(200);
                if (empty($reservas)) {
                    echo json_encode([
                        "status" => 200,
                        "code" => "NO_RESERVAS",
                        "message" => "Aun no hay reservas registradas",
                        "data" => []
                    ]);
                } else {
                    echo json_encode(["status" => 200, "data" => $reservas]);
                }
            }
        }
        break;
    case 'post':
        if ($route == '/reservas') {
            if (!validarTokenOrFail()) {
                break;
            }

            $body = json_decode(file_get_contents("php://input"), true);

            if (!is_array($body)) {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "INVALID_JSON", "error" => "El cuerpo debe ser un JSON valido"]);
                break;
            }

            $required = ['fecha_hora_inicio', 'fecha_hora_fin', 'id_usuario', 'estado', 'cantidad_personas', 'nombre_cabania'];
            foreach ($required as $field) {
                if (!isset($body[$field]) || $body[$field] === '') {
                    http_response_code(400);
                    echo json_encode(["status" => 400, "code" => "MISSING_FIELDS", "error" => "Falta el campo obligatorio: $field"]);
                    break 2;
                }
            }

            $fechaInicio = trim((string) $body['fecha_hora_inicio']);
            $fechaFin = trim((string) $body['fecha_hora_fin']);
            $idUsuario = (string) $body['id_usuario'];
            $estado = (string) $body['estado'];
            $cantidadPersonas = (string) $body['cantidad_personas'];
            $descripcion = trim((string) ($body['descripcion'] ?? ''));
            $nombreCabania = trim((string) $body['nombre_cabania']);

            if (!ctype_digit($idUsuario)) {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "INVALID_ID_USUARIO", "error" => "id_usuario debe ser un numero entero"]);
                break;
            }

            if (!ctype_digit($estado)) {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "INVALID_ESTADO", "error" => "estado debe ser un numero entero"]);
                break;
            }

            if (!ctype_digit($cantidadPersonas) || (int) $cantidadPersonas < 1) {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "INVALID_CANTIDAD_PERSONAS", "error" => "Cantidad de personas debe ser un numero entero mayor a 0"]);
                break;
            }

            if ($nombreCabania === '') {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "INVALID_NOMBRE_CABANIA", "error" => "Nombre de cabaña es obligatorio"]);
                break;
            }

            $dtInicio = date_create($fechaInicio);
            $dtFin = date_create($fechaFin);
            if (!$dtInicio || !$dtFin) {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "INVALID_FECHA", "error" => "Fecha de inicio y fecha de fin deben tener un formato de fecha valido"]);
                break;
            }

            if ($dtFin <= $dtInicio) {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "INVALID_RANGE", "error" => "Fecha de fin debe ser mayor que fecha de inicio"]);
                break;
            }

            $queryCabania = "SELECT id_cabania FROM cabania WHERE nombre = :nombre_cabania LIMIT 1";
            $statementCabania = Conection::getInstance()->getConection()->prepare($queryCabania);
            $statementCabania->bindValue(':nombre_cabania', $nombreCabania, PDO::PARAM_STR);
            $statementCabania->execute();
            $cabania = $statementCabania->fetch(PDO::FETCH_ASSOC);

            if (!$cabania) {
                http_response_code(404);
                echo json_encode(["status" => 404, "code" => "CABANIA_NOT_FOUND", "error" => "No existe una cabaña con ese nombre"]);
                break;
            }

            $idCabania = (int) $cabania['id_cabania'];
            $inicioFormateado = $dtInicio->format('Y-m-d H:i:s');
            $finFormateado = $dtFin->format('Y-m-d H:i:s');

            $queryDuplicada = "SELECT COUNT(*) AS total
                               FROM reserva r
                               INNER JOIN reserva_cabania rc ON rc.id_reserva = r.id_reserva
                               WHERE r.id_usuario = :id_usuario
                                 AND rc.id_cabania = :id_cabania
                                 AND r.fecha_hora_inicio = :fecha_inicio
                                 AND r.fecha_hora_fin = :fecha_fin
                                 AND r.estado <> 0";
            $statementDuplicada = Conection::getInstance()->getConection()->prepare($queryDuplicada);
            $statementDuplicada->bindValue(':id_usuario', (int) $idUsuario, PDO::PARAM_INT);
            $statementDuplicada->bindValue(':id_cabania', $idCabania, PDO::PARAM_INT);
            $statementDuplicada->bindValue(':fecha_inicio', $inicioFormateado, PDO::PARAM_STR);
            $statementDuplicada->bindValue(':fecha_fin', $finFormateado, PDO::PARAM_STR);
            $statementDuplicada->execute();
            $duplicada = $statementDuplicada->fetch(PDO::FETCH_ASSOC);

            if (((int) ($duplicada['total'] ?? 0)) > 0) {
                http_response_code(409);
                echo json_encode([
                    "status" => 409,
                    "code" => "RESERVA_DUPLICADA",
                    "error" => "El usuario ya tiene una reserva igual para esa cabaña y ese rango de fechas"
                ]);
                break;
            }

            if (!validarDisponibilidadCabania($idCabania, $inicioFormateado, $finFormateado)) {
                http_response_code(409);
                echo json_encode([
                    "status" => 409,
                    "code" => "CABANIA_NO_DISPONIBLE",
                    "error" => "La cabaña no está disponible en ese rango de fechas"
                ]);
                break;
            }

            $query = "INSERT INTO reserva (fecha_hora_inicio, fecha_hora_fin, id_usuario, estado, cantidad_personas, descripcion) VALUES (:fecha_hora_inicio, :fecha_hora_fin, :id_usuario, :estado, :cantidad_personas, :descripcion)";
            $statement = Conection::getInstance()->getConection()->prepare($query);
            $statement->bindValue(':fecha_hora_inicio', $inicioFormateado, PDO::PARAM_STR);
            $statement->bindValue(':fecha_hora_fin', $finFormateado, PDO::PARAM_STR);
            $statement->bindValue(':id_usuario', (int) $idUsuario, PDO::PARAM_INT);
            $statement->bindValue(':estado', (int) $estado, PDO::PARAM_INT);
            $statement->bindValue(':cantidad_personas', (int) $cantidadPersonas, PDO::PARAM_INT);
            $statement->bindValue(':descripcion', $descripcion, PDO::PARAM_STR);


            if (!$statement->execute()) {
                http_response_code(500);
                echo json_encode(["status" => 500, "code" => "DB_ERROR", "error" => "No se pudo crear la reserva"]);
                break;
            }

            $lastInsertId = Conection::getInstance()->getConection()->lastInsertId();
            $query = "INSERT INTO reserva_cabania (id_reserva, id_cabania) VALUES (:id_reserva, :id_cabania)";
            $statement = Conection::getInstance()->getConection()->prepare($query);
            $statement->bindValue(':id_reserva', (int) $lastInsertId, PDO::PARAM_INT);
            $statement->bindValue(':id_cabania', $idCabania, PDO::PARAM_INT);

            if (!$statement->execute()) {
                http_response_code(500);
                echo json_encode(["status" => 500, "code" => "DB_ERROR", "error" => "No se pudo asociar la reserva con la cabaña"]);
                break;
            }

            http_response_code(201);
            echo json_encode([
                "status" => 201,
                "message" => "Reserva creada exitosamente",
                "data" => [
                    "id_reserva" => $lastInsertId,
                    "fecha_hora_inicio" => $inicioFormateado,
                    "fecha_hora_fin" => $finFormateado,
                    "id_usuario" => (int) $idUsuario,
                    "estado" => (int) $estado,
                    "cantidad_personas" => (int) $cantidadPersonas,
                    "descripcion" => $descripcion,
                    "nombre_cabania" => $nombreCabania,
                    "id_cabania" => $idCabania
                ]
            ]);
        } elseif ($route == '/reservas/mesas') {
            if (!validarTokenOrFail()) {
                break;
            }

            $body = json_decode(file_get_contents("php://input"), true);

            if (!is_array($body)) {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "INVALID_JSON", "error" => "El cuerpo debe ser un JSON valido"]);
                break;
            }

            $required = ['fecha_hora_inicio', 'fecha_hora_fin', 'id_usuario', 'estado', 'cantidad_personas', 'id_mesa'];
            foreach ($required as $field) {
                if (!isset($body[$field]) || $body[$field] === '') {
                    http_response_code(400);
                    echo json_encode(["status" => 400, "code" => "MISSING_FIELDS", "error" => "Falta el campo obligatorio: $field"]);
                    break 2;
                }
            }

            $fechaInicio = trim((string) $body['fecha_hora_inicio']);
            $fechaFin = trim((string) $body['fecha_hora_fin']);
            $idUsuario = (string) $body['id_usuario'];
            $estado = (string) $body['estado'];
            $cantidadPersonas = (string) $body['cantidad_personas'];
            $descripcion = trim((string) ($body['descripcion'] ?? ''));
            $idMesa = (string) $body['id_mesa'];

            if (!ctype_digit($idUsuario)) {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "INVALID_ID_USUARIO", "error" => "id_usuario debe ser un numero entero"]);
                break;
            }

            if (!ctype_digit($estado)) {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "INVALID_ESTADO", "error" => "estado debe ser un numero entero"]);
                break;
            }

            if (!ctype_digit($cantidadPersonas) || (int) $cantidadPersonas < 1) {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "INVALID_CANTIDAD_PERSONAS", "error" => "Cantidad de personas debe ser un numero entero mayor a 0"]);
                break;
            }

            if (!ctype_digit($idMesa)) {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "INVALID_ID_MESA", "error" => "id_mesa debe ser un numero entero"]);
                break;
            }

            $dtInicio = date_create($fechaInicio);
            $dtFin = date_create($fechaFin);
            if (!$dtInicio || !$dtFin) {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "INVALID_FECHA", "error" => "Fecha de inicio y fecha de fin deben tener un formato de fecha valido"]);
                break;
            }

            if ($dtFin <= $dtInicio) {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "INVALID_RANGE", "error" => "Fecha de fin debe ser mayor que fecha de inicio"]);
                break;
            }

            $queryMesa = "SELECT id_mesa FROM mesa WHERE id_mesa = :id_mesa LIMIT 1";
            $statementMesa = Conection::getInstance()->getConection()->prepare($queryMesa);
            $statementMesa->bindValue(':id_mesa', (int) $idMesa, PDO::PARAM_INT);
            $statementMesa->execute();
            $mesa = $statementMesa->fetch(PDO::FETCH_ASSOC);

            if (!$mesa) {
                http_response_code(404);
                echo json_encode(["status" => 404, "code" => "MESA_NOT_FOUND", "error" => "No existe una mesa con ese id"]);
                break;
            }

            $idMesaInt = (int) $mesa['id_mesa'];
            $inicioFormateado = $dtInicio->format('Y-m-d H:i:s');
            $finFormateado = $dtFin->format('Y-m-d H:i:s');

            $queryDuplicada = "SELECT COUNT(*) AS total
                               FROM reserva r
                               INNER JOIN reserva_mesa rm ON rm.id_reserva = r.id_reserva
                               WHERE r.id_usuario = :id_usuario
                                 AND rm.id_mesa = :id_mesa
                                 AND r.fecha_hora_inicio = :fecha_inicio
                                 AND r.fecha_hora_fin = :fecha_fin
                                 AND r.estado <> 0";
            $statementDuplicada = Conection::getInstance()->getConection()->prepare($queryDuplicada);
            $statementDuplicada->bindValue(':id_usuario', (int) $idUsuario, PDO::PARAM_INT);
            $statementDuplicada->bindValue(':id_mesa', $idMesaInt, PDO::PARAM_INT);
            $statementDuplicada->bindValue(':fecha_inicio', $inicioFormateado, PDO::PARAM_STR);
            $statementDuplicada->bindValue(':fecha_fin', $finFormateado, PDO::PARAM_STR);
            $statementDuplicada->execute();
            $duplicada = $statementDuplicada->fetch(PDO::FETCH_ASSOC);

            if (((int) ($duplicada['total'] ?? 0)) > 0) {
                http_response_code(409);
                echo json_encode([
                    "status" => 409,
                    "code" => "RESERVA_DUPLICADA",
                    "error" => "El usuario ya tiene una reserva igual para esa mesa y ese rango de fechas"
                ]);
                break;
            }

            if (!validarDisponibilidadMesa($idMesaInt, $inicioFormateado, $finFormateado)) {
                http_response_code(409);
                echo json_encode([
                    "status" => 409,
                    "code" => "MESA_NO_DISPONIBLE",
                    "error" => "La mesa no está disponible en ese rango de fechas"
                ]);
                break;
            }

            $query = "INSERT INTO reserva (fecha_hora_inicio, fecha_hora_fin, id_usuario, estado, cantidad_personas, descripcion) VALUES (:fecha_hora_inicio, :fecha_hora_fin, :id_usuario, :estado, :cantidad_personas, :descripcion)";
            $statement = Conection::getInstance()->getConection()->prepare($query);
            $statement->bindValue(':fecha_hora_inicio', $inicioFormateado, PDO::PARAM_STR);
            $statement->bindValue(':fecha_hora_fin', $finFormateado, PDO::PARAM_STR);
            $statement->bindValue(':id_usuario', (int) $idUsuario, PDO::PARAM_INT);
            $statement->bindValue(':estado', (int) $estado, PDO::PARAM_INT);
            $statement->bindValue(':cantidad_personas', (int) $cantidadPersonas, PDO::PARAM_INT);
            $statement->bindValue(':descripcion', $descripcion, PDO::PARAM_STR);


            if (!$statement->execute()) {
                http_response_code(500);
                echo json_encode(["status" => 500, "code" => "DB_ERROR", "error" => "No se pudo crear la reserva de mesa"]);
                break;
            }

            $lastInsertId = Conection::getInstance()->getConection()->lastInsertId();
            $query = "INSERT INTO reserva_mesa (id_reserva, id_mesa) VALUES (:id_reserva, :id_mesa)";
            $statement = Conection::getInstance()->getConection()->prepare($query);
            $statement->bindValue(':id_reserva', (int) $lastInsertId, PDO::PARAM_INT);
            $statement->bindValue(':id_mesa', $idMesaInt, PDO::PARAM_INT);

            if (!$statement->execute()) {
                http_response_code(500);
                echo json_encode(["status" => 500, "code" => "DB_ERROR", "error" => "No se pudo asociar la reserva con la mesa"]);
                break;
            }

            http_response_code(201);
            echo json_encode([
                "status" => 201,
                "message" => "Reserva de mesa creada exitosamente",
                "data" => [
                    "id_reserva" => $lastInsertId,
                    "fecha_hora_inicio" => $inicioFormateado,
                    "fecha_hora_fin" => $finFormateado,
                    "id_usuario" => (int) $idUsuario,
                    "estado" => (int) $estado,
                    "cantidad_personas" => (int) $cantidadPersonas,
                    "descripcion" => $descripcion,
                    "id_mesa" => $idMesaInt
                ]
            ]);
        } else {
            http_response_code(404);
            echo json_encode(["status" => 404, "code" => "ROUTE_NOT_FOUND", "error" => "Ruta no encontrada en reservas"]);
        }
        break;
    case 'put':
        if ($route == '/reservas') {
            if (!validarTokenOrFail()) {
                break;
            }

            $body = json_decode(file_get_contents("php://input"), true);

            if (!is_array($body)) {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "INVALID_JSON", "error" => "El cuerpo debe ser un JSON valido"]);
                break;
            }

            if (isset($body['id_reserva'], $body['estado']) && count($body) === 2) {
                $idReserva = trim((string) $body['id_reserva']);
                if (!ctype_digit($idReserva)) {
                    http_response_code(400);
                    echo json_encode(["status" => 400, "code" => "INVALID_ID_RESERVA", "error" => "id_reserva debe ser un numero entero"]);
                    break;
                }

                $estadoNormalizado = normalizarEstadoReserva($body['estado']);
                if ($estadoNormalizado === null) {
                    http_response_code(400);
                    echo json_encode(["status" => 400, "code" => "INVALID_ESTADO", "error" => "estado debe ser un numero entero o un valor valido como confirmada/cancelada"]);
                    break;
                }

                $pdo = Conection::getInstance()->getConection();
                $queryExiste = "SELECT id_reserva FROM reserva WHERE id_reserva = :id_reserva LIMIT 1";
                $statementExiste = $pdo->prepare($queryExiste);
                $statementExiste->bindValue(':id_reserva', (int) $idReserva, PDO::PARAM_INT);
                $statementExiste->execute();

                if (!$statementExiste->fetch(PDO::FETCH_ASSOC)) {
                    http_response_code(404);
                    echo json_encode(["status" => 404, "code" => "RESERVA_NOT_FOUND", "error" => "Reserva no encontrada"]);
                    break;
                }

                $queryUpdateEstado = "UPDATE reserva SET estado = :estado WHERE id_reserva = :id_reserva";
                $statementUpdateEstado = $pdo->prepare($queryUpdateEstado);
                $statementUpdateEstado->bindValue(':estado', $estadoNormalizado, PDO::PARAM_INT);
                $statementUpdateEstado->bindValue(':id_reserva', (int) $idReserva, PDO::PARAM_INT);

                if (!$statementUpdateEstado->execute()) {
                    http_response_code(500);
                    echo json_encode(["status" => 500, "code" => "DB_ERROR", "error" => "No se pudo actualizar el estado de la reserva"]);
                    break;
                }

                $facturaAuto = null;
                if ($estadoNormalizado === 1) {
                    $resultadoFactura = generarFacturaAutomaticaOrFail((int) $idReserva);
                    $facturaAuto = [
                        "intentada" => true,
                        "generada" => $resultadoFactura['ok'] === true,
                        "detalle" => $resultadoFactura['ok'] === true
                            ? "Factura generada o ya existente"
                            : ($resultadoFactura['error'] ?? "No se pudo generar la factura automáticamente")
                    ];
                }

                http_response_code(200);
                echo json_encode([
                    "status" => 200,
                    "message" => "Estado de reserva actualizado exitosamente",
                    "data" => [
                        "id_reserva" => (int) $idReserva,
                        "estado" => $estadoNormalizado,
                        "factura_automatica" => $facturaAuto
                    ]
                ]);
                break;
            }

            $required = ['id_reserva', 'fecha_hora_inicio', 'fecha_hora_fin', 'id_usuario', 'estado', 'cantidad_personas', 'nombre_cabania'];
            foreach ($required as $field) {
                if (!isset($body[$field]) || $body[$field] === '') {
                    http_response_code(400);
                    echo json_encode(["status" => 400, "code" => "MISSING_FIELDS", "error" => "Falta el campo obligatorio: $field"]);
                    break 2;
                }
            }

            $idReserva = trim((string) $body['id_reserva']);
            $fechaInicio = trim((string) $body['fecha_hora_inicio']);
            $fechaFin = trim((string) $body['fecha_hora_fin']);
            $idUsuario = (string) $body['id_usuario'];
            $estado = (string) $body['estado'];
            $cantidadPersonas = (string) $body['cantidad_personas'];
            $descripcion = trim((string) ($body['descripcion'] ?? ''));
            $nombreCabania = trim((string) $body['nombre_cabania']);

            if (!ctype_digit($idReserva)) {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "INVALID_ID_RESERVA", "error" => "id_reserva debe ser un numero entero"]);
                break;
            }

            if (!ctype_digit($idUsuario)) {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "INVALID_ID_USUARIO", "error" => "id_usuario debe ser un numero entero"]);
                break;
            }

            $estadoNormalizado = normalizarEstadoReserva($estado);
            if ($estadoNormalizado === null) {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "INVALID_ESTADO", "error" => "estado debe ser un numero entero o un valor valido como confirmada/cancelada"]);
                break;
            }

            if (!ctype_digit($cantidadPersonas) || (int) $cantidadPersonas < 1) {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "INVALID_CANTIDAD_PERSONAS", "error" => "Cantidad de personas debe ser un numero entero mayor a 0"]);
                break;
            }

            if ($nombreCabania === '') {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "INVALID_NOMBRE_CABANIA", "error" => "Nombre de cabaña es obligatorio"]);
                break;
            }

            $dtInicio = date_create($fechaInicio);
            $dtFin = date_create($fechaFin);
            if (!$dtInicio || !$dtFin) {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "INVALID_FECHA", "error" => "Fecha de inicio y fecha de fin deben tener un formato de fecha valido"]);
                break;
            }

            if ($dtFin <= $dtInicio) {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "INVALID_RANGE", "error" => "Fecha de fin debe ser mayor que fecha de inicio"]);
                break;
            }

            $queryCabania = "SELECT id_cabania FROM cabania WHERE nombre = :nombre_cabania LIMIT 1";
            $statementCabania = Conection::getInstance()->getConection()->prepare($queryCabania);
            $statementCabania->bindValue(':nombre_cabania', $nombreCabania, PDO::PARAM_STR);
            $statementCabania->execute();
            $cabania = $statementCabania->fetch(PDO::FETCH_ASSOC);

            if (!$cabania) {
                http_response_code(404);
                echo json_encode(["status" => 404, "code" => "CABANIA_NOT_FOUND", "error" => "No existe una cabaña con ese nombre"]);
                break;
            }

            $idCabania = (int) $cabania['id_cabania'];
            $inicioFormateado = $dtInicio->format('Y-m-d H:i:s');
            $finFormateado = $dtFin->format('Y-m-d H:i:s');

            $pdo = Conection::getInstance()->getConection();

            try {
                $pdo->beginTransaction();

                // Verificar que la reserva exista
                $queryExiste = "SELECT id_reserva FROM reserva WHERE id_reserva = :id_reserva LIMIT 1";
                $statementExiste = $pdo->prepare($queryExiste);
                $statementExiste->bindValue(':id_reserva', (int) $idReserva, PDO::PARAM_INT);
                $statementExiste->execute();
                $reservaExistente = $statementExiste->fetch(PDO::FETCH_ASSOC);

                if (!$reservaExistente) {
                    $pdo->rollBack();
                    http_response_code(404);
                    echo json_encode([
                        "status" => 404,
                        "code" => "RESERVA_NOT_FOUND",
                        "error" => "Reserva no encontrada"
                    ]);
                    break;
                }

                // Validar disponibilidad de la cabaña (excluyendo la reserva actual)
                $queryDisponibilidad = "SELECT COUNT(*) AS total
                                      FROM reserva r
                                      INNER JOIN reserva_cabania rc ON rc.id_reserva = r.id_reserva
                                      WHERE rc.id_cabania = :id_cabania
                                        AND r.estado <> 0
                                        AND r.id_reserva <> :id_reserva
                                        AND (:fecha_inicio < r.fecha_hora_fin AND :fecha_fin > r.fecha_hora_inicio)";
                $statementDisponibilidad = $pdo->prepare($queryDisponibilidad);
                $statementDisponibilidad->bindValue(':id_cabania', $idCabania, PDO::PARAM_INT);
                $statementDisponibilidad->bindValue(':id_reserva', (int) $idReserva, PDO::PARAM_INT);
                $statementDisponibilidad->bindValue(':fecha_inicio', $inicioFormateado, PDO::PARAM_STR);
                $statementDisponibilidad->bindValue(':fecha_fin', $finFormateado, PDO::PARAM_STR);
                $statementDisponibilidad->execute();
                $disponibilidad = $statementDisponibilidad->fetch(PDO::FETCH_ASSOC);

                if (((int) ($disponibilidad['total'] ?? 0)) > 0) {
                    $pdo->rollBack();
                    http_response_code(409);
                    echo json_encode([
                        "status" => 409,
                        "code" => "CABANIA_NO_DISPONIBLE",
                        "error" => "La cabaña no está disponible en ese rango de fechas para otra reserva"
                    ]);
                    break;
                }

                // Actualizar la reserva principal
                $queryUpdate = "UPDATE reserva SET fecha_hora_inicio = :fecha_hora_inicio, fecha_hora_fin = :fecha_hora_fin, id_usuario = :id_usuario, estado = :estado, cantidad_personas = :cantidad_personas, descripcion = :descripcion WHERE id_reserva = :id_reserva";
                $statementUpdate = $pdo->prepare($queryUpdate);
                $statementUpdate->bindValue(':fecha_hora_inicio', $inicioFormateado, PDO::PARAM_STR);
                $statementUpdate->bindValue(':fecha_hora_fin', $finFormateado, PDO::PARAM_STR);
                $statementUpdate->bindValue(':id_usuario', (int) $idUsuario, PDO::PARAM_INT);
                $statementUpdate->bindValue(':estado', $estadoNormalizado, PDO::PARAM_INT);
                $statementUpdate->bindValue(':cantidad_personas', (int) $cantidadPersonas, PDO::PARAM_INT);
                $statementUpdate->bindValue(':descripcion', $descripcion, PDO::PARAM_STR);
                $statementUpdate->bindValue(':id_reserva', (int) $idReserva, PDO::PARAM_INT);

                if (!$statementUpdate->execute()) {
                    $pdo->rollBack();
                    http_response_code(500);
                    echo json_encode(["status" => 500, "code" => "DB_ERROR", "error" => "No se pudo actualizar la reserva"]);
                    break;
                }

                // Actualizar la relación de cabaña si cambió
                $queryUpdateCabania = "UPDATE reserva_cabania SET id_cabania = :id_cabania WHERE id_reserva = :id_reserva";
                $statementUpdateCabania = $pdo->prepare($queryUpdateCabania);
                $statementUpdateCabania->bindValue(':id_cabania', $idCabania, PDO::PARAM_INT);
                $statementUpdateCabania->bindValue(':id_reserva', (int) $idReserva, PDO::PARAM_INT);

                if (!$statementUpdateCabania->execute()) {
                    $pdo->rollBack();
                    http_response_code(500);
                    echo json_encode(["status" => 500, "code" => "DB_ERROR", "error" => "No se pudo actualizar la cabaña de la reserva"]);
                    break;
                }

                $pdo->commit();

                $facturaAuto = null;
                if ($estadoNormalizado === 1) {
                    $resultadoFactura = generarFacturaAutomaticaOrFail((int) $idReserva);
                    $facturaAuto = [
                        "intentada" => true,
                        "generada" => $resultadoFactura['ok'] === true,
                        "detalle" => $resultadoFactura['ok'] === true
                            ? "Factura generada o ya existente"
                            : ($resultadoFactura['error'] ?? "No se pudo generar la factura automáticamente")
                    ];
                }

                http_response_code(200);
                echo json_encode([
                    "status" => 200,
                    "message" => "Reserva actualizada exitosamente",
                    "data" => [
                        "id_reserva" => (int) $idReserva,
                        "fecha_hora_inicio" => $inicioFormateado,
                        "fecha_hora_fin" => $finFormateado,
                        "id_usuario" => (int) $idUsuario,
                        "estado" => $estadoNormalizado,
                        "cantidad_personas" => (int) $cantidadPersonas,
                        "descripcion" => $descripcion,
                        "nombre_cabania" => $nombreCabania,
                        "id_cabania" => $idCabania,
                        "factura_automatica" => $facturaAuto
                    ]
                ]);
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                http_response_code(500);
                echo json_encode([
                    "status" => 500,
                    "code" => "DB_ERROR",
                    "error" => "Error al actualizar la reserva"
                ]);
            }
        } elseif ($route == '/reservas/mesas') {
            if (!validarTokenOrFail()) {
                break;
            }

            $body = json_decode(file_get_contents("php://input"), true);

            if (!is_array($body)) {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "INVALID_JSON", "error" => "El cuerpo debe ser un JSON valido"]);
                break;
            }

            if (isset($body['id_reserva'], $body['estado']) && count($body) === 2) {
                $idReserva = trim((string) $body['id_reserva']);
                if (!ctype_digit($idReserva)) {
                    http_response_code(400);
                    echo json_encode(["status" => 400, "code" => "INVALID_ID_RESERVA", "error" => "id_reserva debe ser un numero entero"]);
                    break;
                }

                $estadoNormalizado = normalizarEstadoReserva($body['estado']);
                if ($estadoNormalizado === null) {
                    http_response_code(400);
                    echo json_encode(["status" => 400, "code" => "INVALID_ESTADO", "error" => "estado debe ser un numero entero o un valor valido como confirmada/cancelada"]);
                    break;
                }

                $pdo = Conection::getInstance()->getConection();
                $queryExiste = "SELECT id_reserva FROM reserva WHERE id_reserva = :id_reserva LIMIT 1";
                $statementExiste = $pdo->prepare($queryExiste);
                $statementExiste->bindValue(':id_reserva', (int) $idReserva, PDO::PARAM_INT);
                $statementExiste->execute();

                if (!$statementExiste->fetch(PDO::FETCH_ASSOC)) {
                    http_response_code(404);
                    echo json_encode(["status" => 404, "code" => "RESERVA_NOT_FOUND", "error" => "Reserva no encontrada"]);
                    break;
                }

                $queryUpdateEstado = "UPDATE reserva SET estado = :estado WHERE id_reserva = :id_reserva";
                $statementUpdateEstado = $pdo->prepare($queryUpdateEstado);
                $statementUpdateEstado->bindValue(':estado', $estadoNormalizado, PDO::PARAM_INT);
                $statementUpdateEstado->bindValue(':id_reserva', (int) $idReserva, PDO::PARAM_INT);

                if (!$statementUpdateEstado->execute()) {
                    http_response_code(500);
                    echo json_encode(["status" => 500, "code" => "DB_ERROR", "error" => "No se pudo actualizar el estado de la reserva"]);
                    break;
                }

                $facturaAuto = null;
                if ($estadoNormalizado === 1) {
                    $resultadoFactura = generarFacturaAutomaticaOrFail((int) $idReserva);
                    $facturaAuto = [
                        "intentada" => true,
                        "generada" => $resultadoFactura['ok'] === true,
                        "detalle" => $resultadoFactura['ok'] === true
                            ? "Factura generada o ya existente"
                            : ($resultadoFactura['error'] ?? "No se pudo generar la factura automáticamente")
                    ];
                }

                http_response_code(200);
                echo json_encode([
                    "status" => 200,
                    "message" => "Estado de reserva de mesa actualizado exitosamente",
                    "data" => [
                        "id_reserva" => (int) $idReserva,
                        "estado" => $estadoNormalizado,
                        "factura_automatica" => $facturaAuto
                    ]
                ]);
                break;
            }

            $required = ['id_reserva', 'fecha_hora_inicio', 'fecha_hora_fin', 'id_usuario', 'estado', 'cantidad_personas', 'id_mesa'];
            foreach ($required as $field) {
                if (!isset($body[$field]) || $body[$field] === '') {
                    http_response_code(400);
                    echo json_encode(["status" => 400, "code" => "MISSING_FIELDS", "error" => "Falta el campo obligatorio: $field"]);
                    break 2;
                }
            }

            $idReserva = trim((string) $body['id_reserva']);
            $fechaInicio = trim((string) $body['fecha_hora_inicio']);
            $fechaFin = trim((string) $body['fecha_hora_fin']);
            $idUsuario = (string) $body['id_usuario'];
            $estado = (string) $body['estado'];
            $cantidadPersonas = (string) $body['cantidad_personas'];
            $descripcion = trim((string) ($body['descripcion'] ?? ''));
            $idMesa = (string) $body['id_mesa'];

            if (!ctype_digit($idReserva)) {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "INVALID_ID_RESERVA", "error" => "id_reserva debe ser un numero entero"]);
                break;
            }

            if (!ctype_digit($idUsuario)) {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "INVALID_ID_USUARIO", "error" => "id_usuario debe ser un numero entero"]);
                break;
            }

            $estadoNormalizado = normalizarEstadoReserva($estado);
            if ($estadoNormalizado === null) {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "INVALID_ESTADO", "error" => "estado debe ser un numero entero o un valor valido como confirmada/cancelada"]);
                break;
            }

            if (!ctype_digit($cantidadPersonas) || (int) $cantidadPersonas < 1) {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "INVALID_CANTIDAD_PERSONAS", "error" => "Cantidad de personas debe ser un numero entero mayor a 0"]);
                break;
            }

            if (!ctype_digit($idMesa)) {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "INVALID_ID_MESA", "error" => "id_mesa debe ser un numero entero"]);
                break;
            }

            $dtInicio = date_create($fechaInicio);
            $dtFin = date_create($fechaFin);
            if (!$dtInicio || !$dtFin) {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "INVALID_FECHA", "error" => "Fecha de inicio y fecha de fin deben tener un formato de fecha valido"]);
                break;
            }

            if ($dtFin <= $dtInicio) {
                http_response_code(400);
                echo json_encode(["status" => 400, "code" => "INVALID_RANGE", "error" => "Fecha de fin debe ser mayor que fecha de inicio"]);
                break;
            }

            $queryMesa = "SELECT id_mesa FROM mesa WHERE id_mesa = :id_mesa LIMIT 1";
            $statementMesa = Conection::getInstance()->getConection()->prepare($queryMesa);
            $statementMesa->bindValue(':id_mesa', (int) $idMesa, PDO::PARAM_INT);
            $statementMesa->execute();
            $mesa = $statementMesa->fetch(PDO::FETCH_ASSOC);

            if (!$mesa) {
                http_response_code(404);
                echo json_encode(["status" => 404, "code" => "MESA_NOT_FOUND", "error" => "No existe una mesa con ese id"]);
                break;
            }

            $idMesaInt = (int) $mesa['id_mesa'];
            $inicioFormateado = $dtInicio->format('Y-m-d H:i:s');
            $finFormateado = $dtFin->format('Y-m-d H:i:s');

            $pdo = Conection::getInstance()->getConection();

            try {
                $pdo->beginTransaction();

                $queryExiste = "SELECT id_reserva FROM reserva WHERE id_reserva = :id_reserva LIMIT 1";
                $statementExiste = $pdo->prepare($queryExiste);
                $statementExiste->bindValue(':id_reserva', (int) $idReserva, PDO::PARAM_INT);
                $statementExiste->execute();
                $reservaExistente = $statementExiste->fetch(PDO::FETCH_ASSOC);

                if (!$reservaExistente) {
                    $pdo->rollBack();
                    http_response_code(404);
                    echo json_encode([
                        "status" => 404,
                        "code" => "RESERVA_NOT_FOUND",
                        "error" => "Reserva no encontrada"
                    ]);
                    break;
                }

                $queryDisponibilidad = "SELECT COUNT(*) AS total
                                      FROM reserva r
                                      INNER JOIN reserva_mesa rm ON rm.id_reserva = r.id_reserva
                                      WHERE rm.id_mesa = :id_mesa
                                        AND r.estado <> 0
                                        AND r.id_reserva <> :id_reserva
                                        AND (:fecha_inicio < r.fecha_hora_fin AND :fecha_fin > r.fecha_hora_inicio)";
                $statementDisponibilidad = $pdo->prepare($queryDisponibilidad);
                $statementDisponibilidad->bindValue(':id_mesa', $idMesaInt, PDO::PARAM_INT);
                $statementDisponibilidad->bindValue(':id_reserva', (int) $idReserva, PDO::PARAM_INT);
                $statementDisponibilidad->bindValue(':fecha_inicio', $inicioFormateado, PDO::PARAM_STR);
                $statementDisponibilidad->bindValue(':fecha_fin', $finFormateado, PDO::PARAM_STR);
                $statementDisponibilidad->execute();
                $disponibilidad = $statementDisponibilidad->fetch(PDO::FETCH_ASSOC);

                if (((int) ($disponibilidad['total'] ?? 0)) > 0) {
                    $pdo->rollBack();
                    http_response_code(409);
                    echo json_encode([
                        "status" => 409,
                        "code" => "MESA_NO_DISPONIBLE",
                        "error" => "La mesa no está disponible en ese rango de fechas para otra reserva"
                    ]);
                    break;
                }

                $queryUpdate = "UPDATE reserva SET fecha_hora_inicio = :fecha_hora_inicio, fecha_hora_fin = :fecha_hora_fin, id_usuario = :id_usuario, estado = :estado, cantidad_personas = :cantidad_personas, descripcion = :descripcion WHERE id_reserva = :id_reserva";
                $statementUpdate = $pdo->prepare($queryUpdate);
                $statementUpdate->bindValue(':fecha_hora_inicio', $inicioFormateado, PDO::PARAM_STR);
                $statementUpdate->bindValue(':fecha_hora_fin', $finFormateado, PDO::PARAM_STR);
                $statementUpdate->bindValue(':id_usuario', (int) $idUsuario, PDO::PARAM_INT);
                $statementUpdate->bindValue(':estado', $estadoNormalizado, PDO::PARAM_INT);
                $statementUpdate->bindValue(':cantidad_personas', (int) $cantidadPersonas, PDO::PARAM_INT);
                $statementUpdate->bindValue(':descripcion', $descripcion, PDO::PARAM_STR);
                $statementUpdate->bindValue(':id_reserva', (int) $idReserva, PDO::PARAM_INT);

                if (!$statementUpdate->execute()) {
                    $pdo->rollBack();
                    http_response_code(500);
                    echo json_encode(["status" => 500, "code" => "DB_ERROR", "error" => "No se pudo actualizar la reserva de mesa"]);
                    break;
                }

                $queryUpdateMesa = "UPDATE reserva_mesa SET id_mesa = :id_mesa WHERE id_reserva = :id_reserva";
                $statementUpdateMesa = $pdo->prepare($queryUpdateMesa);
                $statementUpdateMesa->bindValue(':id_mesa', $idMesaInt, PDO::PARAM_INT);
                $statementUpdateMesa->bindValue(':id_reserva', (int) $idReserva, PDO::PARAM_INT);

                if (!$statementUpdateMesa->execute()) {
                    $pdo->rollBack();
                    http_response_code(500);
                    echo json_encode(["status" => 500, "code" => "DB_ERROR", "error" => "No se pudo actualizar la mesa de la reserva"]);
                    break;
                }

                $pdo->commit();

                $facturaAuto = null;
                if ($estadoNormalizado === 1) {
                    $resultadoFactura = generarFacturaAutomaticaOrFail((int) $idReserva);
                    $facturaAuto = [
                        "intentada" => true,
                        "generada" => $resultadoFactura['ok'] === true,
                        "detalle" => $resultadoFactura['ok'] === true
                            ? "Factura generada o ya existente"
                            : ($resultadoFactura['error'] ?? "No se pudo generar la factura automáticamente")
                    ];
                }

                http_response_code(200);
                echo json_encode([
                    "status" => 200,
                    "message" => "Reserva de mesa actualizada exitosamente",
                    "data" => [
                        "id_reserva" => (int) $idReserva,
                        "fecha_hora_inicio" => $inicioFormateado,
                        "fecha_hora_fin" => $finFormateado,
                        "id_usuario" => (int) $idUsuario,
                        "estado" => $estadoNormalizado,
                        "cantidad_personas" => (int) $cantidadPersonas,
                        "descripcion" => $descripcion,
                        "id_mesa" => $idMesaInt,
                        "factura_automatica" => $facturaAuto
                    ]
                ]);
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                http_response_code(500);
                echo json_encode([
                    "status" => 500,
                    "code" => "DB_ERROR",
                    "error" => "Error al actualizar la reserva de mesa"
                ]);
            }
        } else {
            http_response_code(404);
            echo json_encode(["status" => 404, "code" => "ROUTE_NOT_FOUND", "error" => "Ruta no encontrada en reservas"]);
        }
        break;
    case 'delete':
        if ($route == '/reservas') {
            if (!validarTokenOrFail()) {
                break;
            }

            $idReserva = $_GET['id_reserva'] ?? null;
            if ($idReserva === null || $idReserva === '') {
                $body = json_decode(file_get_contents("php://input"), true);
                if (is_array($body) && isset($body['id_reserva'])) {
                    $idReserva = $body['id_reserva'];
                }
            }

            $idReserva = trim((string) $idReserva);
            if ($idReserva === '' || !ctype_digit($idReserva)) {
                http_response_code(400);
                echo json_encode([
                    "status" => 400,
                    "code" => "INVALID_ID_RESERVA",
                    "error" => "Debe enviar id_reserva numérico"
                ]);
                break;
            }

            $pdo = Conection::getInstance()->getConection();

            try {
                $pdo->beginTransaction();

                $queryExiste = "SELECT id_reserva FROM reserva WHERE id_reserva = :id_reserva LIMIT 1";
                $statementExiste = $pdo->prepare($queryExiste);
                $statementExiste->bindValue(':id_reserva', (int) $idReserva, PDO::PARAM_INT);
                $statementExiste->execute();
                $reserva = $statementExiste->fetch(PDO::FETCH_ASSOC);

                if (!$reserva) {
                    $pdo->rollBack();
                    http_response_code(404);
                    echo json_encode([
                        "status" => 404,
                        "code" => "RESERVA_NOT_FOUND",
                        "error" => "Reserva no encontrada"
                    ]);
                    break;
                }

                // Primero elimina relación de la tabla pivote.
                $queryDeleteRelacion = "DELETE FROM reserva_cabania WHERE id_reserva = :id_reserva";
                $statementDeleteRelacion = $pdo->prepare($queryDeleteRelacion);
                $statementDeleteRelacion->bindValue(':id_reserva', (int) $idReserva, PDO::PARAM_INT);
                $statementDeleteRelacion->execute();

                // Luego elimina la reserva principal.
                $queryDeleteReserva = "DELETE FROM reserva WHERE id_reserva = :id_reserva";
                $statementDeleteReserva = $pdo->prepare($queryDeleteReserva);
                $statementDeleteReserva->bindValue(':id_reserva', (int) $idReserva, PDO::PARAM_INT);
                $statementDeleteReserva->execute();

                if ($statementDeleteReserva->rowCount() === 0) {
                    $pdo->rollBack();
                    http_response_code(500);
                    echo json_encode([
                        "status" => 500,
                        "code" => "DELETE_FAILED",
                        "error" => "No se pudo eliminar la reserva"
                    ]);
                    break;
                }

                $pdo->commit();

                http_response_code(200);
                echo json_encode([
                    "status" => 200,
                    "message" => "Reserva eliminada exitosamente",
                    "id_reserva" => (int) $idReserva
                ]);
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                http_response_code(500);
                echo json_encode([
                    "status" => 500,
                    "code" => "DB_ERROR",
                    "error" => "Error al eliminar la reserva"
                ]);
            }
        } elseif ($route == '/reservas/mesas') {
            if (!validarTokenOrFail()) {
                break;
            }

            $idReserva = $_GET['id_reserva'] ?? null;
            if ($idReserva === null || $idReserva === '') {
                $body = json_decode(file_get_contents("php://input"), true);
                if (is_array($body) && isset($body['id_reserva'])) {
                    $idReserva = $body['id_reserva'];
                }
            }

            $idReserva = trim((string) $idReserva);
            if ($idReserva === '' || !ctype_digit($idReserva)) {
                http_response_code(400);
                echo json_encode([
                    "status" => 400,
                    "code" => "INVALID_ID_RESERVA",
                    "error" => "Debe enviar id_reserva numérico"
                ]);
                break;
            }

            $pdo = Conection::getInstance()->getConection();

            try {
                $pdo->beginTransaction();

                $queryExiste = "SELECT id_reserva FROM reserva WHERE id_reserva = :id_reserva LIMIT 1";
                $statementExiste = $pdo->prepare($queryExiste);
                $statementExiste->bindValue(':id_reserva', (int) $idReserva, PDO::PARAM_INT);
                $statementExiste->execute();
                $reserva = $statementExiste->fetch(PDO::FETCH_ASSOC);

                if (!$reserva) {
                    $pdo->rollBack();
                    http_response_code(404);
                    echo json_encode([
                        "status" => 404,
                        "code" => "RESERVA_NOT_FOUND",
                        "error" => "Reserva no encontrada"
                    ]);
                    break;
                }

                $queryDeleteRelacion = "DELETE FROM reserva_mesa WHERE id_reserva = :id_reserva";
                $statementDeleteRelacion = $pdo->prepare($queryDeleteRelacion);
                $statementDeleteRelacion->bindValue(':id_reserva', (int) $idReserva, PDO::PARAM_INT);
                $statementDeleteRelacion->execute();

                $queryDeleteReserva = "DELETE FROM reserva WHERE id_reserva = :id_reserva";
                $statementDeleteReserva = $pdo->prepare($queryDeleteReserva);
                $statementDeleteReserva->bindValue(':id_reserva', (int) $idReserva, PDO::PARAM_INT);
                $statementDeleteReserva->execute();

                if ($statementDeleteReserva->rowCount() === 0) {
                    $pdo->rollBack();
                    http_response_code(500);
                    echo json_encode([
                        "status" => 500,
                        "code" => "DELETE_FAILED",
                        "error" => "No se pudo eliminar la reserva de mesa"
                    ]);
                    break;
                }

                $pdo->commit();

                http_response_code(200);
                echo json_encode([
                    "status" => 200,
                    "message" => "Reserva de mesa eliminada exitosamente",
                    "id_reserva" => (int) $idReserva
                ]);
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                http_response_code(500);
                echo json_encode([
                    "status" => 500,
                    "code" => "DB_ERROR",
                    "error" => "Error al eliminar la reserva de mesa"
                ]);
            }
        } else {
            http_response_code(404);
            echo json_encode(["status" => 404, "code" => "ROUTE_NOT_FOUND", "error" => "Ruta no encontrada en reservas"]);
        }
        break;
    default:
        http_response_code(404);
        echo json_encode(["status" => 404, "code" => "ROUTE_NOT_FOUND", "error" => "Ruta no encontrada en reservas"]);
        break;
}