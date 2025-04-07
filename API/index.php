<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Accept, Origin, Authorization");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");

require_once "src/funciones_CTES_servicios.php";
require __DIR__ . '/Slim/autoload.php';

$app = new \Slim\App;

//Login sin tokens
$app->post('/login', function($request) {
    $email    = $request->getParam("email");    // Recogemos "email"
    $password = $request->getParam("password"); // Recogemos "password"

    $respuesta = loginEmailPassword($email, $password);
    echo json_encode($respuesta);
});



//GET /pacientes
$app->get('/pacientes', function () {
    $resultado = obtener_pacientes();
    echo json_encode($resultado);
});

// GET /pacientes/{id}
$app->get('/pacientes/{id}', function ($request) {
    $id_paciente = $request->getAttribute("id");
    $resultado = obtener_paciente_por_id($id_paciente);
    echo json_encode($resultado);
});

//POST /crearPacientes
$app->post('/crearPacientes', function ($request) {
    // Recogemos los parámetros que llegan por POST
    $nombre           = $request->getParam("nombre");
    $apellidos        = $request->getParam("apellidos");
    $fecha_nacimiento = $request->getParam("fecha_nacimiento");
    $email            = $request->getParam("email");
    $telefono         = $request->getParam("telefono");

    $datos_paciente = [
        "nombre"            => $nombre ?? "",
        "apellidos"         => $apellidos ?? "",
        "fecha_nacimiento"  => $fecha_nacimiento ?? null,
        "email"             => $email ?? "",
        "telefono"          => $telefono ?? ""
    ];

    $resultado = crear_paciente($datos_paciente);
    echo json_encode($resultado);
});


//DELETE /pacientes/{id}
$app->delete('/pacientes/{id}', function ($request) {
    $id_paciente = $request->getAttribute("id");

    $resultado = borrar_paciente($id_paciente);
    echo json_encode($resultado);
});


$app->run();
?>