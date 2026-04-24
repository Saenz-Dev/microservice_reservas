<?php
function subirImagenCabania()
{
    if (!isset($_FILES['imagen']) || empty($_FILES['imagen']['name'])) {
        http_response_code(400);
        echo json_encode(["status" => 400, "code" => "MISSING_IMAGE", "error" => "Debe enviar una imagen en el campo 'imagen'"]);
        return;
    }

    $directorio = __DIR__ . '/../uploads/';
    if (!file_exists($directorio)) {
        mkdir($directorio, 0777, true);
    }

    $nombreOriginal = basename($_FILES['imagen']['name']);
    $nombre = time() . "_" . preg_replace('/[^a-zA-Z0-9._-]/', '_', $nombreOriginal);
    $rutaAbsoluta = $directorio . $nombre;
    $rutaRelativa = 'uploads/' . $nombre;

    if (!move_uploaded_file($_FILES['imagen']['tmp_name'], $rutaAbsoluta)) {
        http_response_code(500);
        echo json_encode(["status" => 500, "code" => "UPLOAD_ERROR", "error" => "Error al subir la imagen"]);
        return;
    }

    http_response_code(201);
    echo json_encode([
        "status" => 201,
        "message" => "Imagen subida exitosamente",
        "url_imagen" => $rutaRelativa
    ]);
}