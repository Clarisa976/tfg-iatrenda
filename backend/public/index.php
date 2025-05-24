<?php
declare(strict_types=1);

use Dotenv\Dotenv;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/funciones_CTES_servicios.php';

/* .env ------------------------------------------------------- */
$dotenv = Dotenv::createUnsafeImmutable(__DIR__ . '/../');
$dotenv->load();

error_log('DB_USER: ' . getenv('DB_USER'));
error_log('DB_PASS: ' . getenv('DB_PASS'));

/* Slim ------------------------------------------------------- */
$app = AppFactory::create();
$app->addBodyParsingMiddleware();          // JSON → $request->getParsedBody()

/* CORS ------------------------------------------------------- */
$app->add(function (Request $req, $handler): Response {
    $origin = $req->getHeaderLine('Origin');
    $allowed = array_map('trim', explode(',', getenv('CORS_ORIGINS') ?: '*'));

    // Si hay Origin y está permitido, añade los headers
    if ($origin && ($allowed[0] === '*' || in_array($origin, $allowed, true))) {
        $headers = [
            'Access-Control-Allow-Origin'      => $origin,
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Allow-Headers'     => 'Content-Type, Accept, Origin, Authorization',
            'Access-Control-Allow-Methods'     => 'GET, POST, PUT, DELETE, OPTIONS',
        ];
    } else {
        $headers = [];
    }

    // Si es OPTIONS, responde directamente con los headers
    if ($req->getMethod() === 'OPTIONS') {
        $res = new \Slim\Psr7\Response();
        foreach ($headers as $k => $v) {
            $res = $res->withHeader($k, $v);
        }
        return $res->withStatus(200);
    }

    // Para el resto, añade los headers a la respuesta normal
    $res = $handler->handle($req);
    foreach ($headers as $k => $v) {
        $res = $res->withHeader($k, $v);
    }
    return $res;
});

/* ---------- RUTAS ---------- */

/* Health-check */
$app->get('/status', fn() => jsonResponse(['ok'=>true]));

/* POST /login  {email,password} */
$app->post('/login', function (Request $req): Response {
    $data = $req->getParsedBody() ?? [];
    $email = trim($data['email'] ?? '');
    $pass  = trim($data['password'] ?? '');

    if ($email === '' || $pass === '') {
        return jsonResponse(['ok'=>false,'mensaje'=>'Email y contraseña requeridos']);
    }

    // Llama a tu función de login que consulta la base de datos
    $resultado = loginEmailPassword($email, $pass);

    // Devuelve la respuesta tal cual la función
    return jsonResponse($resultado);
});



/* POST /reservar-cita {nombre, email, telefono, motivo, fecha, hora} */

$app->post('/reservar-cita', function (Request $req): Response {
    $data = $req->getParsedBody() ?? [];

    // Extraemos campos
    $nombre = trim($data['nombre'] ?? '');
    $email  = trim($data['email']  ?? '');
    $tel    = trim($data['tel']    ?? '');
    $motivo = trim($data['motivo'] ?? '');
    $fecha  = trim($data['fecha']  ?? '');

    // Llamamos a la función que hace TODO: persona↔paciente + cita
    $res = reservarCita($nombre, $email, $tel, $motivo, $fecha);

    // Devuelve el mismo payload de la función, con el código adecuado
    return jsonResponse(
        ['ok'=>$res['ok'], 'mensaje'=>$res['mensaje']],
        $res['ok'] ? 200 : ($res['status'] ?? 400)
    );
});



$app->get('/', fn() => jsonResponse(['ok'=>true, 'mensaje'=>'API Slim funcionando']));

$app->run();
/* ------------------------------------------------------------ */
function jsonResponse(array $payload, int $code = 200): Response
{
    $res = (new \Slim\Psr7\Response())->withStatus($code)
                                      ->withHeader('Content-Type','application/json');
    $res->getBody()->write(json_encode($payload));
    return $res;
}

// Fix Slim base path if running in a subfolder
$scriptName = dirname($_SERVER['SCRIPT_NAME']);
$path = $_SERVER['REQUEST_URI'];
if (strpos($path, $scriptName) === 0) {
    $_SERVER['REQUEST_URI'] = substr($path, strlen($scriptName));
    if ($_SERVER['REQUEST_URI'] === '') $_SERVER['REQUEST_URI'] = '/';
}
