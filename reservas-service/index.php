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

switch ($method) {
    case 'get':
        if ($route == '/reservas/disponibilidad') {
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

                $query = "SELECT * FROM reserva WHERE id_usuario = :id_usuario";
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

                $query = "SELECT * FROM reserva WHERE id_reserva = :id_reserva";
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
                $query = "SELECT * FROM reserva";
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

            $query = "INSERT INTO reserva (fecha_hora_inicio, fecha_hora_fin, id_usuario, estado, cantidad_personas) VALUES (:fecha_hora_inicio, :fecha_hora_fin, :id_usuario, :estado, :cantidad_personas)";
            $statement = Conection::getInstance()->getConection()->prepare($query);
            $statement->bindValue(':fecha_hora_inicio', $inicioFormateado, PDO::PARAM_STR);
            $statement->bindValue(':fecha_hora_fin', $finFormateado, PDO::PARAM_STR);
            $statement->bindValue(':id_usuario', (int) $idUsuario, PDO::PARAM_INT);
            $statement->bindValue(':estado', (int) $estado, PDO::PARAM_INT);
            $statement->bindValue(':cantidad_personas', (int) $cantidadPersonas, PDO::PARAM_INT);


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
                    "nombre_cabania" => $nombreCabania,
                    "id_cabania" => $idCabania
                ]
            ]);
        } else {
            http_response_code(404);
            echo json_encode(["status" => 404, "code" => "ROUTE_NOT_FOUND", "error" => "Ruta no encontrada en reservas"]);
        }
        break;
    case 'put':
    //
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