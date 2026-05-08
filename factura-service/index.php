<?php

date_default_timezone_set('America/Bogota');

require_once __DIR__ . '/config/Conection.php';
require_once __DIR__ . '/util/ExceptionApi.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/config/config.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

header("Access-Control-Allow-Origin: http://localhost:4200");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Internal-Key");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Content-Type: application/json; charset=UTF-8");

$request = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = strtolower($_SERVER['REQUEST_METHOD']);
// echo $request;
// Normalizar y eliminar la parte base del microservicio, aceptar llamadas con o sin /index.php
$basePath = '/microservices/factura-service';
$route = preg_replace('#^' . preg_quote($basePath, '#') . '(/index\.php)?#', '', $request);
$route = $route === false ? '' : $route;

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

function responder($statusCode, array $payload)
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    return false;
}

function verificarTokenLogin()
{
    $authorizationHeader = getAuthorizationHeader();

    if (empty($authorizationHeader)) {
        return responder(401, [
            "status" => 401,
            "code" => "MISSING_TOKEN",
            "error" => "Token no enviado"
        ]);
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
        $decoded = json_decode($response, true);
        return responder(401, [
            "status" => 401,
            "code" => "INVALID_TOKEN",
            "error" => $decoded['error'] ?? "Token inválido o expirado"
        ]);
    }

    $decoded = json_decode($response, true);
    $data = $decoded['data'] ?? null;

    if (!is_array($data) || !isset($data['id_usuario'])) {
        return responder(401, [
            "status" => 401,
            "code" => "INVALID_TOKEN",
            "error" => "El token no contiene datos de usuario validos"
        ]);
    }

    return $data;
}

function verificarAccesoAdminOSistema()
{
    $internalKey = $_SERVER['HTTP_X_INTERNAL_KEY'] ?? '';
    if (!empty($internalKey) && hash_equals(INTERNAL_SERVICE_KEY, $internalKey)) {
        return [
            'modo' => 'sistema',
            'id_usuario' => 0,
            'id_rol' => ADMIN_ROLE_ID
        ];
    }

    $datosToken = verificarTokenLogin();
    if ($datosToken === false) {
        return false;
    }

    $postmanTestHeader = $_SERVER['HTTP_X_POSTMAN_TEST'] ?? '';
    if (ALLOW_POSTMAN_TESTS && !empty($postmanTestHeader) && in_array(strtolower($postmanTestHeader), ['1', 'true', 'si', 'yes'], true)) {
        return $datosToken;
    }

    $idRol = (int) ($datosToken['id_rol'] ?? 0);
    if ($idRol !== ADMIN_ROLE_ID) {
        return responder(403, [
            "status" => 403,
            "code" => "FORBIDDEN",
            "error" => "Solo el administrador puede ejecutar esta acción"
        ]);
    }

    return $datosToken;
}

function verificarAccesoGeneralOSistema()
{
    $internalKey = $_SERVER['HTTP_X_INTERNAL_KEY'] ?? '';
    if (!empty($internalKey) && hash_equals(INTERNAL_SERVICE_KEY, $internalKey)) {
        return [
            'modo' => 'sistema',
            'id_usuario' => 0,
            'id_rol' => ADMIN_ROLE_ID
        ];
    }

    return verificarTokenLogin();
}

function obtenerJsonBody()
{
    $body = json_decode(file_get_contents('php://input'), true);

    if (!is_array($body)) {
        return false;
    }

    return $body;
}

function formatearMoneda($valor)
{
    return number_format((float) $valor, 0, ',', '.');
}

function obtenerSecuenciaFactura(PDO $pdo)
{
    $query = "SELECT COALESCE(MAX(numero_factura), 0) + 1 AS siguiente FROM factura";
    $statement = $pdo->prepare($query);
    $statement->execute();
    $resultado = $statement->fetch(PDO::FETCH_ASSOC);

    return (int) ($resultado['siguiente'] ?? 1);
}

function obtenerReservaConTarifa(PDO $pdo, int $idReserva)
{
    try {


        $queryCabania = "SELECT r.id_reserva, r.fecha_hora_inicio, r.fecha_hora_fin, r.id_usuario, r.estado, r.cantidad_personas, r.descripcion,
                            rc.id_cabania,
                            c.nombre AS nombre_item,
                            c.precio_por_persona AS precio_base,
                            'cabania' AS tipo_reserva
                     FROM reserva r
                     INNER JOIN reserva_cabania rc ON rc.id_reserva = r.id_reserva
                     INNER JOIN cabania c ON c.id_cabania = rc.id_cabania
                     WHERE r.id_reserva = :id_reserva
                     LIMIT 1";
        $statementCabania = $pdo->prepare($queryCabania);
        $statementCabania->bindValue(':id_reserva', $idReserva, PDO::PARAM_INT);
        $statementCabania->execute();

        $reserva = $statementCabania->fetch(PDO::FETCH_ASSOC);
        if ($reserva){ return $reserva; } 
        if (!$reserva) {
            try {
                $queryMesa = "SELECT r.id_reserva, r.fecha_hora_inicio, r.fecha_hora_fin, r.id_usuario, r.estado, r.cantidad_personas, r.descripcion,
                                 rm.id_mesa,
                                 CONCAT('Mesa ', rm.id_mesa) AS nombre_item,
                                 m.precio_por_persona AS precio_base,
                                 'mesa' AS tipo_reserva
                          FROM reserva r
                          INNER JOIN reserva_mesa rm ON rm.id_reserva = r.id_reserva
                          INNER JOIN mesa m ON m.id_mesa = rm.id_mesa
                          WHERE r.id_reserva = :id_reserva
                          LIMIT 1";
                $statementMesa = $pdo->prepare($queryMesa);
                $statementMesa->bindValue(':id_reserva', $idReserva, PDO::PARAM_INT);
                $statementMesa->execute();
                $reserva = $statementMesa->fetch(PDO::FETCH_ASSOC);

                return $reserva;

            } catch (PDOException $e) {
                throw new Exception("Error al obtener la reserva con tarifa: " . $e->getMessage(), (int) $e->getCode());
            }
        }
    } catch (PDOException $e) {
        throw new Exception("Error al obtener la reserva con tarifa: " . $e->getMessage(), (int) $e->getCode());
    }

    return null;
}

function calcularMontosFactura($precioUnitario, $cantidad)
{
    $precioUnitario = (float) $precioUnitario;
    $cantidad = max(1, (int) $cantidad);

    $subtotal = (int) round($precioUnitario * $cantidad);
    $ivaUnitario = (int) round($precioUnitario * FACTURA_IVA);
    $ivaSubtotal = (int) round($subtotal * FACTURA_IVA);
    $total = $subtotal + $ivaSubtotal;

    return [
        'subtotal' => $subtotal,
        'iva_unitario' => $ivaUnitario,
        'iva_subtotal' => $ivaSubtotal,
        'total' => $total
    ];
}

function cargarFacturaCompletaPorIdFactura(PDO $pdo, int $idFactura)
{
    $query = "SELECT f.id_factura, f.numero_factura, f.fecha_emision, f.subtotal, f.impuestos, f.estado, f.id_reserva, f.total,
                     r.id_usuario, r.fecha_hora_inicio, r.fecha_hora_fin, r.descripcion AS descripcion_reserva
              FROM factura f
              INNER JOIN reserva r ON r.id_reserva = f.id_reserva
              WHERE f.id_factura = :id_factura
              LIMIT 1";
    $statement = $pdo->prepare($query);
    $statement->bindValue(':id_factura', $idFactura, PDO::PARAM_INT);
    $statement->execute();
    $factura = $statement->fetch(PDO::FETCH_ASSOC);

    if (!$factura) {
        return null;
    }

    $queryDetalles = "SELECT id_detalle, descripcion, cantidad, precio_unitario, subtotal, id_factura, iva_unitario, total, iva_subtotal
                      FROM detalle_factura
                      WHERE id_factura = :id_factura
                      ORDER BY id_detalle ASC";
    $statementDetalles = $pdo->prepare($queryDetalles);
    $statementDetalles->bindValue(':id_factura', $idFactura, PDO::PARAM_INT);
    $statementDetalles->execute();
    $detalles = $statementDetalles->fetchAll(PDO::FETCH_ASSOC);

    $factura['detalles'] = $detalles;
    return $factura;
}

function cargarFacturaCompletaPorReserva(PDO $pdo, int $idReserva)
{
    $query = "SELECT f.id_factura, f.numero_factura, f.fecha_emision, f.subtotal, f.impuestos, f.estado, f.id_reserva, f.total,
                     r.id_usuario, r.fecha_hora_inicio, r.fecha_hora_fin, r.descripcion AS descripcion_reserva
              FROM factura f
              INNER JOIN reserva r ON r.id_reserva = f.id_reserva
              WHERE f.id_reserva = :id_reserva
              LIMIT 1";
    $statement = $pdo->prepare($query);
    $statement->bindValue(':id_reserva', $idReserva, PDO::PARAM_INT);
    $statement->execute();
    $factura = $statement->fetch(PDO::FETCH_ASSOC);

    if (!$factura) {
        return null;
    }

    $queryDetalles = "SELECT id_detalle, descripcion, cantidad, precio_unitario, subtotal, id_factura, iva_unitario, total, iva_subtotal
                      FROM detalle_factura
                      WHERE id_factura = :id_factura
                      ORDER BY id_detalle ASC";
    $statementDetalles = $pdo->prepare($queryDetalles);
    $statementDetalles->bindValue(':id_factura', (int) $factura['id_factura'], PDO::PARAM_INT);
    $statementDetalles->execute();
    $detalles = $statementDetalles->fetchAll(PDO::FETCH_ASSOC);

    $factura['detalles'] = $detalles;
    return $factura;
}

function cargarCorreoClientePorReserva(PDO $pdo, int $idReserva)
{
    $query = "SELECT c.correo, u.nombres, u.apellidos
              FROM reserva r
              INNER JOIN cuenta c ON c.id_usuario = r.id_usuario
              INNER JOIN usuario u ON u.id_usuario = r.id_usuario
              WHERE r.id_reserva = :id_reserva
              LIMIT 1";
    $statement = $pdo->prepare($query);
    $statement->bindValue(':id_reserva', $idReserva, PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
}

function construirHtmlFactura(array $factura)
{
    $detalles = $factura['detalles'] ?? [];
    $fechaEmision = htmlspecialchars((string) ($factura['fecha_emision'] ?? ''), ENT_QUOTES, 'UTF-8');
    $numeroFactura = htmlspecialchars((string) ($factura['numero_factura'] ?? ''), ENT_QUOTES, 'UTF-8');
    $estado = htmlspecialchars((string) ($factura['estado'] ?? ''), ENT_QUOTES, 'UTF-8');
    $idReserva = htmlspecialchars((string) ($factura['id_reserva'] ?? ''), ENT_QUOTES, 'UTF-8');
    $subtotal = formatearMoneda((int) ($factura['subtotal'] ?? 0));
    $impuestos = formatearMoneda((int) ($factura['impuestos'] ?? 0));
    $total = formatearMoneda((int) ($factura['total'] ?? 0));
    $reservaInicio = htmlspecialchars((string) ($factura['fecha_hora_inicio'] ?? ''), ENT_QUOTES, 'UTF-8');
    $reservaFin = htmlspecialchars((string) ($factura['fecha_hora_fin'] ?? ''), ENT_QUOTES, 'UTF-8');
    $descripcionReserva = htmlspecialchars((string) ($factura['descripcion_reserva'] ?? ''), ENT_QUOTES, 'UTF-8');

    $filas = '';
    foreach ($detalles as $detalle) {
        $filas .= '<tr>'
            . '<td>' . htmlspecialchars((string) ($detalle['descripcion'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td style="text-align:right;">' . (int) ($detalle['cantidad'] ?? 0) . '</td>'
            . '<td style="text-align:right;">' . formatearMoneda((int) ($detalle['precio_unitario'] ?? 0)) . '</td>'
            . '<td style="text-align:right;">' . formatearMoneda((int) ($detalle['subtotal'] ?? 0)) . '</td>'
            . '<td style="text-align:right;">' . formatearMoneda((int) ($detalle['iva_unitario'] ?? 0)) . '</td>'
            . '<td style="text-align:right;">' . formatearMoneda((int) ($detalle['iva_subtotal'] ?? 0)) . '</td>'
            . '<td style="text-align:right;">' . formatearMoneda((int) ($detalle['total'] ?? 0)) . '</td>'
            . '</tr>';
    }

    return '<!DOCTYPE html>'
        . '<html lang="es">'
        . '<head>'
        . '<meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<title>Factura ' . $numeroFactura . '</title>'
        . '<style>'
        . 'body{font-family:Arial,Helvetica,sans-serif;color:#111;padding:32px;background:#f6f7fb;}'
        . '.sheet{max-width:980px;margin:0 auto;background:#fff;border:1px solid #dfe3eb;border-radius:12px;padding:28px;box-shadow:0 12px 28px rgba(15,23,42,.08);}'
        . '.header{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;border-bottom:2px solid #111;padding-bottom:16px;margin-bottom:20px;}'
        . '.title{font-size:28px;font-weight:700;margin:0 0 6px;}'
        . '.meta{font-size:14px;line-height:1.5;color:#334155;}'
        . '.box{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;margin-bottom:18px;}'
        . 'table{width:100%;border-collapse:collapse;font-size:14px;}'
        . 'th,td{border:1px solid #cbd5e1;padding:10px;vertical-align:top;}'
        . 'th{background:#0f172a;color:#fff;text-align:left;}'
        . '.totals{margin-top:18px;display:flex;justify-content:flex-end;}'
        . '.totals table{width:360px;}'
        . '.totals td{font-weight:700;}'
        . '.print-hint{margin-top:12px;font-size:12px;color:#64748b;}'
        . '@media print{body{background:#fff;padding:0}.sheet{box-shadow:none;border:none;border-radius:0;max-width:none}}'
        . '</style>'
        . '<script>window.onload=function(){window.print();};</script>'
        . '</head>'
        . '<body>'
        . '<div class="sheet">'
        . '<div class="header">'
        . '<div>'
        . '<h1 class="title">Factura ' . $numeroFactura . '</h1>'
        . '<div class="meta">Estado: ' . $estado . '<br>Reserva: ' . $idReserva . '<br>Fecha emisión: ' . $fechaEmision . '</div>'
        . '</div>'
        . '<div class="meta" style="text-align:right;">'
        . '<strong>Servicio de Facturación</strong><br>'
        . 'Reserva desde: ' . $reservaInicio . '<br>'
        . 'Reserva hasta: ' . $reservaFin . '<br>'
        . 'Descripción: ' . $descripcionReserva
        . '</div>'
        . '</div>'
        . '<div class="box">'
        . '<strong>Resumen</strong><br>'
        . 'Subtotal: $' . $subtotal . '<br>'
        . 'Impuestos: $' . $impuestos . '<br>'
        . 'Total: $' . $total
        . '</div>'
        . '<table>'
        . '<thead><tr><th>Descripción</th><th>Cantidad</th><th>Precio unitario</th><th>Subtotal</th><th>IVA unitario</th><th>IVA subtotal</th><th>Total</th></tr></thead>'
        . '<tbody>' . $filas . '</tbody>'
        . '</table>'
        . '<div class="totals">'
        . '<table>'
        . '<tr><td>Subtotal</td><td style="text-align:right;">$' . $subtotal . '</td></tr>'
        . '<tr><td>Impuestos</td><td style="text-align:right;">$' . $impuestos . '</td></tr>'
        . '<tr><td>Total</td><td style="text-align:right;">$' . $total . '</td></tr>'
        . '</table>'
        . '</div>'
        . '</div>'
        . '</body>'
        . '</html>';
}

function enviarFacturaPorCorreo(array $factura, array $destinatario)
{
    if (empty($destinatario['correo'])) {
        return [
            'status' => 400,
            'payload' => [
                'status' => 400,
                'code' => 'EMAIL_NOT_FOUND',
                'error' => 'No se encontró un correo válido para el destinatario'
            ]
        ];
    }

    $correoDesde = 'saenzm963@gmail.com';
    if ($correoDesde === '') {
        return [
            'status' => 500,
            'payload' => [
                'status' => 500,
                'code' => 'MAIL_NOT_CONFIGURED',
                'error' => 'Configura MAIL_FROM_ADDRESS para poder enviar correos'
            ]
        ];
    }

    $mailer = new PHPMailer(true);

    try {
        $mailer->CharSet = 'UTF-8';


        $mailer->isSMTP();
        $mailer->Host = 'smtp.gmail.com';
        $mailer->Port = 587;
        $mailer->SMTPAuth = true;
        $mailer->Username = 'saenzm963@gmail.com';
        $mailer->Password = 'vrnaiosgcopximcw';
        $mailer->SMTPSecure = 'tls';
        $mailer->setFrom('saenzm963@gmail.com', 'Booking Manager System');
        $mailer->addAddress($destinatario['correo']);
        $mailer->isHTML(true);
        $mailer->Subject = 'Factura ' . ($factura['numero_factura'] ?? '');
        $mailer->Body = construirHtmlFactura($factura);
        $mailer->AltBody = 'Se ha generado la factura #' . ($factura['numero_factura'] ?? '') . ' para su reserva.';


        $mailer->send();

        return [
            'status' => 200,
            'payload' => [
                'status' => 200,
                'message' => 'Factura enviada por correo exitosamente',
                'data' => [
                    'destinatario' => $destinatario['correo'],
                    'numero_factura' => $factura['numero_factura'] ?? null
                ]
            ]
        ];
    } catch (MailException $e) {
        return [
            'status' => 500,
            'payload' => [
                'status' => 500,
                'code' => 'MAIL_SEND_FAILED',
                'error' => $e->getMessage()
            ]
        ];
    }
}

function crearFacturaDesdeReserva(PDO $pdo, int $idReserva, array $acceso = [])
{
    $pdo->beginTransaction();

    try {
        $queryReserva = "SELECT id_reserva, fecha_hora_inicio, fecha_hora_fin, id_usuario, estado, cantidad_personas, descripcion FROM reserva WHERE id_reserva = :id_reserva LIMIT 1";
        $statementReserva = $pdo->prepare($queryReserva);
        $statementReserva->bindValue(':id_reserva', $idReserva, PDO::PARAM_INT);
        $statementReserva->execute();
        $reservaBase = $statementReserva->fetch(PDO::FETCH_ASSOC);
        if (!$reservaBase) {
            $pdo->rollBack();
            return [
                'status' => 404,
                'payload' => [
                    'status' => 404,
                    'code' => 'RESERVA_NOT_FOUND',
                    'error' => 'La reserva no existe'
                ]
            ];
        }

        if ((int) $reservaBase['estado'] === 1) {
            $pdo->rollBack();
            return [
                'status' => 409,
                'payload' => [
                    'status' => 409,
                    'code' => 'RESERVA_INVALIDA',
                    'error' => 'La reserva no está confirmada o está anulada'
                ]
            ];
        }

        $esSistema = (string) ($acceso['modo'] ?? '') === 'sistema';
        $esAdmin = (int) ($acceso['id_rol'] ?? 0) === ADMIN_ROLE_ID;
        $idUsuarioSolicitante = (int) ($acceso['id_usuario'] ?? 0);

        if (!$esSistema && !$esAdmin && $idUsuarioSolicitante > 0 && (int) ($reservaBase['id_usuario'] ?? 0) !== $idUsuarioSolicitante) {
            $pdo->rollBack();
            return [
                'status' => 403,
                'payload' => [
                    'status' => 403,
                    'code' => 'FORBIDDEN',
                    'error' => 'No tienes permisos para generar factura de esta reserva'
                ]
            ];
        }

        $queryFacturaExistente = "SELECT id_factura, numero_factura, fecha_emision, subtotal, impuestos, estado, id_reserva, total FROM factura WHERE id_reserva = :id_reserva LIMIT 1";
        $statementFacturaExistente = $pdo->prepare($queryFacturaExistente);
        $statementFacturaExistente->bindValue(':id_reserva', $idReserva, PDO::PARAM_INT);
        $statementFacturaExistente->execute();
        $facturaExistente = $statementFacturaExistente->fetch(PDO::FETCH_ASSOC);


        if ($facturaExistente) {
            $pdo->commit();
            $facturaCompleta = cargarFacturaCompletaPorIdFactura($pdo, (int) $facturaExistente['id_factura']);
            return [
                'status' => 200,
                'payload' => [
                    'status' => 200,
                    'code' => 'FACTURA_EXISTENTE',
                    'message' => 'La reserva ya tiene factura generada',
                    'data' => $facturaCompleta
                ]
            ];
        }


        $reservaConTarifa = obtenerReservaConTarifa($pdo, $idReserva);
        if (!$reservaConTarifa) {
            $pdo->rollBack();
            return [
                'status' => 409,
                'payload' => [
                    'status' => 409,
                    'code' => 'TIPO_RESERVA_NO_ENCONTRADO',
                    'error' => 'No se pudo determinar si la reserva pertenece a cabaña o mesa'
                ]
            ];
        }
        

        $cantidad = (int) ($reservaConTarifa['cantidad_personas'] ?? 0);

        $precioBase = (float) ($reservaConTarifa['precio_base'] ?? 0);
        if ($precioBase <= 0) {
            $pdo->rollBack();
            return [
                'status' => 409,
                'payload' => [
                    'status' => 409,
                    'code' => 'PRECIO_NO_CONFIGURADO',
                    'error' => 'La cabaña o la mesa no tienen precio configurado'
                ]
            ];
        }

        $montos = calcularMontosFactura($precioBase, $cantidad);
        $numeroFactura = obtenerSecuenciaFactura($pdo);
        $fechaEmision = date('Y-m-d H:i:s');
        $descripcionDetalle = $reservaConTarifa['tipo_reserva'] === 'cabania'
            ? 'Reserva de cabaña: ' . ($reservaConTarifa['nombre_item'] ?? 'Cabaña')
            : 'Reserva de mesa: ' . ($reservaConTarifa['nombre_item'] ?? 'Mesa');

        $queryFactura = "INSERT INTO factura (numero_factura, fecha_emision, subtotal, impuestos, estado, id_reserva, total)
                         VALUES (:numero_factura, :fecha_emision, :subtotal, :impuestos, :estado, :id_reserva, :total)";
        $statementFactura = $pdo->prepare($queryFactura);
        $statementFactura->bindValue(':numero_factura', $numeroFactura, PDO::PARAM_INT);
        $statementFactura->bindValue(':fecha_emision', $fechaEmision, PDO::PARAM_STR);
        $statementFactura->bindValue(':subtotal', $montos['subtotal'], PDO::PARAM_INT);
        $statementFactura->bindValue(':impuestos', $montos['iva_subtotal'], PDO::PARAM_INT);
        $statementFactura->bindValue(':estado', 'paga', PDO::PARAM_STR);
        $statementFactura->bindValue(':id_reserva', $idReserva, PDO::PARAM_INT);
        $statementFactura->bindValue(':total', $montos['total'], PDO::PARAM_INT);

        if (!$statementFactura->execute()) {
            $pdo->rollBack();
            return [
                'status' => 500,
                'payload' => [
                    'status' => 500,
                    'code' => 'DB_ERROR',
                    'error' => 'No se pudo crear la factura'
                ]
            ];
        }
        $idFactura = (int) $pdo->lastInsertId();

        $queryDetalle = "INSERT INTO detalle_factura (descripcion, cantidad, precio_unitario, subtotal, id_factura, iva_unitario, total, iva_subtotal)
                         VALUES (:descripcion, :cantidad, :precio_unitario, :subtotal, :id_factura, :iva_unitario, :total, :iva_subtotal)";
        $statementDetalle = $pdo->prepare($queryDetalle);
        $statementDetalle->bindValue(':descripcion', $descripcionDetalle, PDO::PARAM_STR);
        $statementDetalle->bindValue(':cantidad', $cantidad, PDO::PARAM_INT);
        $statementDetalle->bindValue(':precio_unitario', (int) round($precioBase), PDO::PARAM_INT);
        $statementDetalle->bindValue(':subtotal', $montos['subtotal'], PDO::PARAM_INT);
        $statementDetalle->bindValue(':id_factura', $idFactura, PDO::PARAM_INT);
        $statementDetalle->bindValue(':iva_unitario', $montos['iva_unitario'], PDO::PARAM_INT);
        $statementDetalle->bindValue(':total', $montos['total'], PDO::PARAM_INT);
        $statementDetalle->bindValue(':iva_subtotal', $montos['iva_subtotal'], PDO::PARAM_INT);

        if (!$statementDetalle->execute()) {
            $pdo->rollBack();
            return [
                'status' => 500,
                'payload' => [
                    'status' => 500,
                    'code' => 'DB_ERROR',
                    'error' => 'No se pudo crear el detalle de factura'
                ]
            ];
        }

        $pdo->commit();
        $facturaCompleta = cargarFacturaCompletaPorIdFactura($pdo, $idFactura);

        return [
            'status' => 201,
            'payload' => [
                'status' => 201,
                'message' => 'Factura creada exitosamente',
                'data' => [
                    'factura' => $facturaCompleta,
                    'tipo_reserva' => $reservaConTarifa['tipo_reserva'],
                    'cantidad' => $cantidad,
                    'precio_base' => (int) round($precioBase)
                ]
            ]
        ];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return [
            'status' => 500,
            'payload' => [
                'status' => 500,
                'code' => 'DB_ERROR',
                'error' => 'Error al generar la factura'
            ]
        ];
    }
}

switch ($method) {
    case 'get':
        if ($route === '/facturas') {
            $datosToken = verificarAccesoGeneralOSistema();
            if ($datosToken === false) {
                break;
            }

            $pdo = Conection::getInstance()->getConection();
            $idUsuarioToken = (int) ($datosToken['id_usuario'] ?? 0);
            $esAdmin = (int) ($datosToken['id_rol'] ?? 0) === ADMIN_ROLE_ID;

            if (isset($_GET['id_factura'])) {
                $idFactura = trim((string) $_GET['id_factura']);
                if (!ctype_digit($idFactura)) {
                    responder(400, ["status" => 400, "code" => "INVALID_ID_FACTURA", "error" => "El parámetro id_factura debe ser un número entero"]);
                    break;
                }

                $factura = cargarFacturaCompletaPorIdFactura($pdo, (int) $idFactura);
                if (!$factura) {
                    responder(404, ["status" => 404, "code" => "FACTURA_NOT_FOUND", "error" => "Factura no encontrada"]);
                    break;
                }

                if (!$esAdmin && $idUsuarioToken > 0 && (int) ($factura['id_usuario'] ?? 0) !== $idUsuarioToken) {
                    responder(403, ["status" => 403, "code" => "FORBIDDEN", "error" => "No tienes permisos para ver esta factura"]);
                    break;
                }

                responder(200, ["status" => 200, "data" => $factura]);
                break;
            }

            if (isset($_GET['id_reserva'])) {
                $idReserva = trim((string) $_GET['id_reserva']);
                if (!ctype_digit($idReserva)) {
                    responder(400, ["status" => 400, "code" => "INVALID_ID_RESERVA", "error" => "El parámetro id_reserva debe ser un número entero"]);
                    break;
                }

                $factura = cargarFacturaCompletaPorReserva($pdo, (int) $idReserva);
                if (!$factura) {
                    responder(404, ["status" => 404, "code" => "FACTURA_NOT_FOUND", "error" => "Factura no encontrada para esa reserva"]);
                    break;
                }

                if (!$esAdmin && $idUsuarioToken > 0 && (int) ($factura['id_usuario'] ?? 0) !== $idUsuarioToken) {
                    responder(403, ["status" => 403, "code" => "FORBIDDEN", "error" => "No tienes permisos para ver esta factura"]);
                    break;
                }

                responder(200, ["status" => 200, "data" => $factura]);
                break;
            }

            if ($esAdmin) {
                $query = "SELECT f.id_factura, f.numero_factura, f.fecha_emision, f.subtotal, f.impuestos, f.estado, f.id_reserva, f.total,
                                 r.id_usuario
                          FROM factura f
                          INNER JOIN reserva r ON r.id_reserva = f.id_reserva
                          ORDER BY f.fecha_emision DESC";
                $statement = $pdo->prepare($query);
                $statement->execute();
                $facturas = $statement->fetchAll(PDO::FETCH_ASSOC);

                responder(200, ["status" => 200, "data" => $facturas]);
            } else {
                $query = "SELECT f.id_factura, f.numero_factura, f.fecha_emision, f.subtotal, f.impuestos, f.estado, f.id_reserva, f.total
                          FROM factura f
                          INNER JOIN reserva r ON r.id_reserva = f.id_reserva
                          WHERE r.id_usuario = :id_usuario
                          ORDER BY f.fecha_emision DESC";
                $statement = $pdo->prepare($query);
                $statement->bindValue(':id_usuario', $idUsuarioToken, PDO::PARAM_INT);
                $statement->execute();
                $facturas = $statement->fetchAll(PDO::FETCH_ASSOC);

                responder(200, ["status" => 200, "data" => $facturas]);
            }
        } elseif ($route === '/facturas/pdf') {
            $datosToken = verificarAccesoGeneralOSistema();
            if ($datosToken === false) {
                break;
            }
            // echo $_GET['id_factura'];
            $idFactura = $_GET['id_factura'] ?? null;
            if (!$idFactura || !ctype_digit((string) $idFactura)) {
                responder(400, ["status" => 400, "code" => "INVALID_ID_FACTURA", "error" => "id_factura requerido y debe ser numérico"]);
                break;
            }

            $pdo = Conection::getInstance()->getConection();
            $factura = cargarFacturaCompletaPorIdFactura($pdo, (int) $idFactura);

            if (!$factura) {
                responder(404, ["status" => 404, "code" => "FACTURA_NOT_FOUND", "error" => "Factura no encontrada"]);
                break;
            }

            $idUsuarioToken = (int) ($datosToken['id_usuario'] ?? 0);
            $esAdmin = (int) ($datosToken['id_rol'] ?? 0) === ADMIN_ROLE_ID;

            if (!$esAdmin && $idUsuarioToken > 0 && (int) ($factura['id_usuario'] ?? 0) !== $idUsuarioToken) {
                responder(403, ["status" => 403, "code" => "FORBIDDEN", "error" => "No tienes permisos para descargar esta factura"]);
                break;
            }

            header('Content-Type: text/html; charset=utf-8');
            echo construirHtmlFactura($factura);
        } else {
            responder(404, ["status" => 404, "code" => "ROUTE_NOT_FOUND", "error" => "Ruta no encontrada en factura"]);
        }
        break;

    case 'post':
        if ($route === '/facturas/generar' || $route === '/facturas/generar-interno') {
            if ($route === '/facturas/generar') {
                $internalKey = $_SERVER['HTTP_X_INTERNAL_KEY'] ?? '';
                if (!empty($internalKey) && hash_equals(INTERNAL_SERVICE_KEY, $internalKey)) {
                    $acceso = [
                        'modo' => 'sistema',
                        'id_usuario' => 0,
                        'id_rol' => ADMIN_ROLE_ID
                    ];
                } else {
                    $acceso = verificarTokenLogin();
                    if ($acceso === false) {
                        break;
                    }
                }
            } else {
                $internalKey = $_SERVER['HTTP_X_INTERNAL_KEY'] ?? '';
                if (empty($internalKey) || !hash_equals(INTERNAL_SERVICE_KEY, $internalKey)) {
                    responder(401, ["status" => 401, "code" => "INVALID_INTERNAL_KEY", "error" => "Clave interna inválida"]);
                    break;
                }
                $acceso = [
                    'modo' => 'sistema',
                    'id_usuario' => 0,
                    'id_rol' => ADMIN_ROLE_ID
                ];
            }

            $body = obtenerJsonBody();
            if ($body === false) {
                responder(400, ["status" => 400, "code" => "INVALID_JSON", "error" => "El cuerpo debe ser un JSON valido"]);
                break;
            }

            if (!isset($body['id_reserva']) || $body['id_reserva'] === '') {
                responder(400, ["status" => 400, "code" => "MISSING_FIELDS", "error" => "Falta el campo obligatorio: id_reserva"]);
                break;
            }

            $idReserva = (string) $body['id_reserva'];
            if (!ctype_digit($idReserva)) {
                responder(400, ["status" => 400, "code" => "INVALID_ID_RESERVA", "error" => "id_reserva debe ser un número entero"]);
                break;
            }

            $resultado = crearFacturaDesdeReserva(Conection::getInstance()->getConection(), (int) $idReserva, $acceso);
            http_response_code($resultado['status']);
            echo json_encode($resultado['payload'], JSON_UNESCAPED_UNICODE);
        } elseif ($route === '/facturas/enviar-correo') {
            $acceso = verificarAccesoGeneralOSistema();
            if ($acceso === false) {
                break;
            }

            $body = obtenerJsonBody();
            if ($body === false) {
                responder(400, ["status" => 400, "code" => "INVALID_JSON", "error" => "El cuerpo debe ser un JSON valido"]);
                break;
            }

            if (!isset($body['id_factura']) && !isset($body['id_reserva'])) {
                responder(400, ["status" => 400, "code" => "MISSING_FIELDS", "error" => "Debes enviar id_factura o id_reserva"]);
                break;
            }

            $pdo = Conection::getInstance()->getConection();
            $factura = null;

            if (isset($body['id_factura']) && ctype_digit((string) $body['id_factura'])) {
                $factura = cargarFacturaCompletaPorIdFactura($pdo, (int) $body['id_factura']);
            } elseif (isset($body['id_reserva']) && ctype_digit((string) $body['id_reserva'])) {
                $factura = cargarFacturaCompletaPorReserva($pdo, (int) $body['id_reserva']);
            }

            if (!$factura) {
                responder(404, ["status" => 404, "code" => "FACTURA_NOT_FOUND", "error" => "No se encontró la factura solicitada"]);
                break;
            }

            $idUsuarioToken = (int) ($acceso['id_usuario'] ?? 0);
            $esAdmin = (int) ($acceso['id_rol'] ?? 0) === ADMIN_ROLE_ID;
            if (!$esAdmin && $idUsuarioToken > 0 && (int) ($factura['id_usuario'] ?? 0) !== $idUsuarioToken) {
                responder(403, ["status" => 403, "code" => "FORBIDDEN", "error" => "No tienes permisos para enviar esta factura"]);
                break;
            }

            $destinatario = null;
            if (!empty($body['correo'])) {
                $destinatario = [
                    'correo' => trim((string) $body['correo']),
                    'nombres' => $body['nombres'] ?? '',
                    'apellidos' => $body['apellidos'] ?? ''
                ];
            } else {
                $destinatario = cargarCorreoClientePorReserva($pdo, (int) $factura['id_reserva']);
            }
            if (!$destinatario) {
                responder(404, ["status" => 404, "code" => "EMAIL_NOT_FOUND", "error" => "No se encontró correo para el cliente de la reserva"]);
                break;
            }

            if (!filter_var($destinatario['correo'], FILTER_VALIDATE_EMAIL)) {
                responder(400, ["status" => 400, "code" => "INVALID_EMAIL", "error" => "El correo del destinatario no es válido"]);
                break;
            }


            $resultado = enviarFacturaPorCorreo($factura, $destinatario);
            http_response_code($resultado['status']);
            echo json_encode($resultado['payload'], JSON_UNESCAPED_UNICODE);
        } else {
            responder(404, ["status" => 404, "code" => "ROUTE_NOT_FOUND", "error" => "Ruta no encontrada en factura"]);
        }
        break;

    case 'put':
        if ($route === '/facturas/estado') {
            $acceso = verificarAccesoAdminOSistema();
            if ($acceso === false) {
                break;
            }

            $body = obtenerJsonBody();
            if ($body === false) {
                responder(400, ["status" => 400, "code" => "INVALID_JSON", "error" => "El cuerpo debe ser un JSON valido"]);
                break;
            }

            $required = ['id_factura', 'estado'];
            foreach ($required as $field) {
                if (!isset($body[$field]) || $body[$field] === '') {
                    responder(400, ["status" => 400, "code" => "MISSING_FIELDS", "error" => "Falta el campo obligatorio: $field"]);
                    break 2;
                }
            }

            if (!ctype_digit((string) $body['id_factura'])) {
                responder(400, ["status" => 400, "code" => "INVALID_ID_FACTURA", "error" => "id_factura debe ser un número entero"]);
                break;
            }

            $estado = trim((string) $body['estado']);
            if (!in_array($estado, ['paga', 'pendiente'], true)) {
                responder(400, ["status" => 400, "code" => "INVALID_ESTADO", "error" => "El estado solo puede ser paga o pendiente"]);
                break;
            }

            $pdo = Conection::getInstance()->getConection();
            $query = "UPDATE factura SET estado = :estado WHERE id_factura = :id_factura";
            $statement = $pdo->prepare($query);
            $statement->bindValue(':estado', $estado, PDO::PARAM_STR);
            $statement->bindValue(':id_factura', (int) $body['id_factura'], PDO::PARAM_INT);

            if (!$statement->execute()) {
                responder(500, ["status" => 500, "code" => "DB_ERROR", "error" => "No se pudo actualizar la factura"]);
                break;
            }

            responder(200, [
                "status" => 200,
                "message" => "Estado de factura actualizado exitosamente",
                "data" => [
                    "id_factura" => (int) $body['id_factura'],
                    "estado" => $estado
                ]
            ]);
        } else {
            responder(404, ["status" => 404, "code" => "ROUTE_NOT_FOUND", "error" => "Ruta no encontrada en factura"]);
        }
        break;

    default:
        responder(404, ["status" => 404, "code" => "ROUTE_NOT_FOUND", "error" => "Método no soportado en factura"]);
}
