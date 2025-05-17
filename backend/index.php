<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require __DIR__ . '/vendor/autoload.php';

// cargar env
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// configuraciones CORS
$app = AppFactory::create();
$app->addRoutingMiddleware();
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

$app->add(function (Request $req, RequestHandlerInterface $handler) {
    $response = $handler->handle($req);
    return $response
        ->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Accept, Origin, Authorization')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
});

require __DIR__ . '/src/funciones_CTES_servicios.php';

// Login
$app->post('/login', function (Request $request, Response $response) {
    $params = (array)$request->getParsedBody();
    $res = loginEmailPassword($params['email'] ?? '', $params['password'] ?? '');
    $response->getBody()->write(json_encode($res));
    return $response->withHeader('Content-Type','application/json');
});

// Pacientes
$app->get('/pacientes', function (Request $req, Response $res) {
    $out = obtener_pacientes();
    $res->getBody()->write(json_encode($out));
    return $res->withHeader('Content-Type','application/json');
});
$app->get('/pacientes/{id}', function (Request $req, Response $res, array $args) {
    $out = obtener_paciente_por_id($args['id']);
    $res->getBody()->write(json_encode($out));
    return $res->withHeader('Content-Type','application/json');
});
$app->post('/crearPacientes', function (Request $req, Response $res) {
    $data = (array)$req->getParsedBody();
    $out = crear_paciente($data);
    $res->getBody()->write(json_encode($out));
    return $res->withHeader('Content-Type','application/json');
});
$app->delete('/pacientes/{id}', function (Request $req, Response $res, array $args) {
    $out = borrar_paciente($args['id']);
    $res->getBody()->write(json_encode($out));
    return $res->withHeader('Content-Type','application/json');
});

$app->run();