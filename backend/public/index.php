<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

// Capturar cualquier output buffering early
ob_start();

error_log("=== API INICIANDO ===");
error_log("REQUEST_METHOD: " . ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN'));
error_log("REQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? 'UNKNOWN'));

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/funciones_CTES_servicios.php';

//error_log("=== ARCHIVOS CARGADOS ===");

/* Permitir CORS */
header('Access-Control-Allow-Origin: https://clinica-petaka.netlify.app');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');

/* Si es una solicitud OPTIONS, terminar aquí */
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // Limpiar cualquier output previo
    if (ob_get_level()) {
        ob_clean();
    }
    header('Content-Type: application/json');
    http_response_code(200);
    echo json_encode(['ok' => true]);
    exit;
}

// Limpiar el output buffer antes de procesamiento normal
if (ob_get_level()) {
    ob_clean();
}
/* .env */
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createUnsafeImmutable(__DIR__ . '/../');
    $dotenv->load();
}
/* Slim */
$app = AppFactory::create();
$app->addBodyParsingMiddleware();

// Middleware CORS para todas las respuestas
$app->add(function (Request $request, $handler) {
    $response = $handler->handle($request);

    $origin = $request->getHeaderLine('Origin');
    $allowedOrigins = ['https://clinica-petaka.netlify.app'];
    $useOrigin = in_array($origin, $allowedOrigins) ? $origin : 'https://clinica-petaka.netlify.app';

    return $response
        ->withHeader('Access-Control-Allow-Origin', $useOrigin)
        ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS')
        ->withHeader('Access-Control-Allow-Credentials', 'true');
});



// Middleware para manejar las solicitudes OPTIONS de CORS
$app->options('/{routes:.+}', function (Request $request, Response $response) {
    $origin = $request->getHeaderLine('Origin');
    $allowedOrigins = ['https://clinica-petaka.netlify.app'];
    $useOrigin = in_array($origin, $allowedOrigins) ? $origin : 'https://clinica-petaka.netlify.app';

    return $response
        ->withHeader('Access-Control-Allow-Origin', $useOrigin)
        ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS')
        ->withHeader('Access-Control-Allow-Credentials', 'true')
        ->withHeader('Content-Type', 'application/json')
        ->withStatus(200);
});


function jsonResponse(array $payload, int $code = 200): Response
{
    try {
        $jsonString = json_encode($payload, JSON_THROW_ON_ERROR);
        $r = new \Slim\Psr7\Response($code);
        $r->getBody()->write($jsonString);
        return $r
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    } catch (\Exception $e) {
        error_log('Error al generar respuesta JSON: ' . $e->getMessage());
        $r = new \Slim\Psr7\Response(500);
        $r->getBody()->write(json_encode(['ok' => false, 'mensaje' => 'Error interno del servidor']));
        return $r->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
/* ---------- RUTAS ---------- */

/* Health-check */
$app->get('/status', fn() => jsonResponse(['ok' => true]));


$app->post('/login', function (Request $req): Response {
    // Limpiar cualquier output previo que pueda estar interfiriendo
    if (ob_get_level()) {
        ob_clean();
    }

    error_log("=== INICIO LOGIN ENDPOINT ===");

    try {
        $data = $req->getParsedBody() ?? [];
        $email = trim($data['email'] ?? '');
        $pass  = trim($data['password'] ?? '');

        error_log("Login attempt for email: " . $email);

        if ($email === '' || $pass === '') {
            error_log("Login failed: missing credentials");
            return jsonResponse(['ok' => false, 'mensaje' => 'Email y contraseña requeridos']);
        }

        error_log("Calling iniciarSesionConEmail...");
        $resultado = iniciarSesionConEmail($email, $pass);
        error_log("Login result: " . json_encode($resultado));

        return jsonResponse($resultado);
    } catch (\Exception $e) {
        error_log("Login endpoint error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        return jsonResponse(['ok' => false, 'mensaje' => 'Error interno'], 500);
    }
});



/* reservar-cita  */
$app->post('/reservar-cita', function (Request $req): Response {
    try {
        $data = $req->getParsedBody() ?? [];
        error_log('Recibida solicitud de reserva: ' . json_encode($data));

        // Extraemos campos
        $nombre = trim($data['nombre'] ?? '');
        $email  = trim($data['email']  ?? '');
        $tel    = trim($data['tel']    ?? '');
        $motivo = trim($data['motivo'] ?? '');
        $fecha  = trim($data['fecha']  ?? '');

        // Validación básica
        if (empty($nombre) || empty($email) || empty($motivo) || empty($fecha)) {
            error_log('Faltan campos obligatorios en la solicitud de reserva');
            return jsonResponse(
                ['ok' => false, 'mensaje' => 'Faltan campos obligatorios para la cita'],
                400
            );
        }

        $res = pedirCitaNueva($nombre, $email, $tel, $motivo, $fecha);
        error_log('Resultado de pedirCitaNueva: ' . json_encode($res));


        return jsonResponse(
            ['ok' => $res['ok'], 'mensaje' => $res['mensaje']],
            $res['ok'] ? 200 : ($res['status'] ?? 400)
        );
    } catch (\Exception $e) {
        error_log('Error al procesar solicitud de reserva: ' . $e->getMessage());
        return jsonResponse(
            ['ok' => false, 'mensaje' => 'Error al procesar la solicitud: ' . $e->getMessage()],
            500
        );
    }
});

/* lee el último consentimiento del usuario */
$app->get('/consentimiento', function (Request $req): Response {
    $val = verificarTokenUsuario();
    if ($val === false) {
        return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);
    }
    $id = (int)$val['usuario']['id_persona'];
    $c  = obtenerUltimoConsentimiento($id);
    return jsonResponse([
        'ok'            => true,
        'consentimiento' => $c,
        'tieneVigente'  => tieneConsentimientoVigente($id),
        'token'         => $val['token']
    ], 200);
});

/* crea un nuevo consentimiento */
$app->post('/consentimiento', function (Request $req): Response {
    $val = verificarTokenUsuario();
    if ($val === false) {
        return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);
    }
    $id    = (int)$val['usuario']['id_persona'];
    $canal = strtoupper(trim($req->getParsedBody()['canal'] ?? ''));
    if (!in_array($canal, ['PAPEL', 'WEB', 'APP'], true)) {
        return jsonResponse(['ok' => false, 'mensaje' => 'Canal inválido'], 400);
    }
    $ok = execLogged(
        "INSERT INTO consentimiento (id_persona, fecha_otorgado, canal)
         VALUES (:id, CURRENT_TIMESTAMP, :canal)",
        [':id' => $id, ':canal' => $canal],
        $id,
        'consentimiento',
        $id
    );
    return $ok
        ? jsonResponse(['ok' => true, 'mensaje' => 'Consentimiento otorgado'], 200)
        : jsonResponse(['ok' => false, 'mensaje' => 'Error al otorgar'], 500);
});

/* revoca el consentimiento activo */
$app->post('/consentimiento/revocar', function (Request $req): Response {
    $val = verificarTokenUsuario();
    if ($val === false) {
        return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);
    }
    $id = (int)$val['usuario']['id_persona'];
    $ok = execLogged(
        "UPDATE consentimiento
            SET fecha_revocado = CURRENT_TIMESTAMP
          WHERE id_persona = :id
            AND fecha_revocado IS NULL",
        [':id' => $id],
        $id,
        'consentimiento',
        $id
    );
    return $ok
        ? jsonResponse(['ok' => true, 'mensaje' => 'Consentimiento revocado'], 200)
        : jsonResponse(['ok' => false, 'mensaje' => 'Error al revocar'], 500);
});



/* listar usuarios */
$app->get('/admin/usuarios', function (Request $req) {
    // validar token y extraer usuario + nuevo token
    $val = verificarTokenUsuario();
    if ($val === false) {
        return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);
    }
    $rol = $val['usuario']['rol'];
    // solo admin o profesional
    $rol = strtolower($val['usuario']['rol']);
    if (!in_array($rol, ['admin', 'profesional'], true)) {
        return jsonResponse(['ok' => false, 'mensaje' => 'Acceso denegado'], 403);
    }
    // obtener listado
    $lista = obtenerUsuarios();
    // devolver array + token renovado
    return jsonResponse([
        'ok'       => true,
        'usuarios' => $lista,
        'token'    => $val['token']
    ], 200);
});

/* /admin/usuarios/buscar  */
$app->get('/admin/usuarios/buscar', function ($req) {
    $val = verificarTokenUsuario();
    if ($val === false || strtolower($val['usuario']['rol']) !== 'admin')
        return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);

    $q = $req->getQueryParams();
    $r = buscarPersona($q['email'] ?? '', $q['tel'] ?? '');
    return jsonResponse(['ok' => true, 'data' => $r]);
});

/* obtener usuario individual por ID */
$app->get('/admin/usuarios/{id}', function (Request $req, Response $res, array $args) {
    // validar token y extraer usuario + nuevo token
    $val = verificarTokenUsuario();
    if ($val === false) {
        return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);
    }
    $rol = strtolower($val['usuario']['rol']);
    // solo admin o profesional
    if (!in_array($rol, ['admin', 'profesional'], true)) {
        return jsonResponse(['ok' => false, 'mensaje' => 'Acceso denegado'], 403);
    }
    $id = (int)$args['id'];
    $usuario = getUsuarioDetalle($id);

    if (!$usuario) {
        return jsonResponse(['ok' => false, 'mensaje' => 'Usuario no encontrado'], 404);
    }

    // devolver datos del usuario + token renovado
    return jsonResponse([
        'ok'    => true,
        'data'  => $usuario,
        'token' => $val['token']
    ], 200);
});


/* lista profesionales */
$app->get('/profesionales', function (Request $req) {
    $txt   = trim($req->getQueryParams()['search'] ?? '');
    $items = getProfesionales($txt);
    return jsonResponse(['ok' => true, 'data' => $items]);
});

/* agenda/global*/
$app->get('/agenda/global', function (Request $req) {
    $val = verificarTokenUsuario();
    if ($val === false)
        return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);

    $params = $req->getQueryParams();
    $profId = isset($params['profId']) ? (int)$params['profId'] : null;

    // Permitir seleccionar mes y año específicos
    $year = isset($params['year']) ? (int)$params['year'] : (int)date('Y');
    $month = isset($params['month']) ? (int)$params['month'] : (int)date('m');

    // Crear fechas de inicio y fin para el mes solicitado
    $inicioMes = sprintf('%04d-%02d-01', $year, $month);
    $finMes = date('Y-m-t', strtotime($inicioMes));

    error_log("Obteniendo eventos desde $inicioMes hasta $finMes para profesional ID: " . ($profId ?: 'todos'));

    $eventos = obtenerEventosAgenda($inicioMes, $finMes, $profId); // ← nuevo arg
    return jsonResponse(['ok' => true, 'data' => $eventos]);
});

/* crear bloque */
$app->post('/agenda/global', function (Request $req) {
    $val = verificarTokenUsuario();
    if ($val === false)
        return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);

    $data = $req->getParsedBody() ?? [];
    $prof = (int)($data['profId'] ?? 0);
    $tipo = trim($data['tipo'] ?? '');
    $ini  = trim($data['inicio'] ?? '');
    $fin  = trim($data['fin'] ?? '');
    $nota = trim($data['nota'] ?? '');
    $actor = (int)$val['usuario']['id_persona'];

    if (!$tipo || !$ini || !$fin)
        return jsonResponse(['ok' => false, 'mensaje' => 'Faltan datos requeridos'], 400);

    if (!crearBloqueAgenda($prof, $ini, $fin, $tipo, $nota, $actor))
        return jsonResponse(['ok' => false, 'mensaje' => 'Error al crear evento'], 500);

    return jsonResponse(['ok' => true, 'mensaje' => 'Evento creado']);
});

/* elimina bloque o cita */
$app->delete('/agenda/global/{id}', function (Request $req, Response $res, array $args) {
    $val = verificarTokenUsuario();
    if ($val === false)
        return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);

    $id = (int)$args['id'];
    $actor = (int)$val['usuario']['id_persona'];

    if (!eliminarEvento($id, $actor))
        return jsonResponse(['ok' => false, 'mensaje' => 'No se pudo eliminar'], 500);

    return jsonResponse(['ok' => true]);
});



/* notificaciones */
$app->get('/notificaciones', function ($req) {
    $val = verificarTokenUsuario();
    if ($val === false)
        return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);

    $uid = (int)$val['usuario']['id_persona'];
    $rol = strtolower($val['usuario']['rol']);

    $datos = obtenerNotificacionesPendientes($uid, $rol);
    return jsonResponse(['ok' => true, 'data' => $datos]);
});

/* notificaciones/{id}*/
$app->post('/notificaciones/{id}', function ($req, $res, $args) {
    $val = verificarTokenUsuario();
    if ($val === false)
        return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);

    $idCita = (int)$args['id'];
    $acc = strtoupper(trim(($req->getParsedBody()['accion'] ?? '')));

    if (!in_array($acc, ['CONFIRMAR', 'RECHAZAR', 'CANCELAR'], true)) {
        return jsonResponse(['ok' => false, 'mensaje' => 'Acción inválida'], 400);
    }

    // Si llega 'CANCELAR' lo convertimos a 'RECHAZAR' para compatibilidad
    if ($acc === 'CANCELAR') {
        $acc = 'RECHAZAR';
        error_log("Convirtiendo acción CANCELAR a RECHAZAR para compatibilidad");
    }

    $uid = (int)$val['usuario']['id_persona'];
    $rol = strtolower($val['usuario']['rol']);

    error_log("Procesando notificación - Cita ID: $idCita, Acción: $acc, Usuario: $uid, Rol: $rol");

    $ok = procesarNotificacion($idCita, $acc, $uid, $rol);

    if ($ok) {
        $mensaje = ($acc === 'CONFIRMAR') ? 'Cita confirmada correctamente' : 'Cita rechazada correctamente';
        return jsonResponse(['ok' => true, 'mensaje' => $mensaje]);
    } else {
        return jsonResponse(['ok' => false, 'mensaje' => 'No se pudo procesar la acción'], 500);
    }
});

$app->options('/notificaciones/{id}', function ($request, $response, $args) {
    $origin = $request->getHeaderLine('Origin');
    $allowedOrigins = [getenv('FRONTEND_URL') ?: 'https://clinica-petaka.netlify.app'];
    $useOrigin = in_array($origin, $allowedOrigins) ? $origin : '*';

    error_log("CORS OPTIONS para /notificaciones/{id}: Origin=$origin");

    return $response
        ->withHeader('Access-Control-Allow-Origin', $useOrigin)
        ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS')
        ->withHeader('Access-Control-Allow-Credentials', 'true')
        ->withHeader('Access-Control-Max-Age', '86400')
        ->withStatus(204);
});


$app->get('/', fn() => jsonResponse(['ok' => true, 'mensaje' => 'API Slim funcionando']));



/* /admin/usuarios*/
$app->post('/admin/usuarios', function ($req) {
    $val = verificarTokenUsuario();
    if ($val === false || strtolower($val['usuario']['rol']) !== 'admin')
        return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);

    $actorId = $val['usuario']['id_persona'];

    $body = $req->getParsedBody() ?? [];
    error_log("POST /admin/usuarios - Body recibido: " . json_encode($body));

    $tipo = strtoupper(trim($body['tipo'] ?? ''));
    $pdat = $body['persona'] ?? [];
    $xdat = $body['extra']   ?? [];

    if (!in_array($tipo, ['PROFESIONAL', 'PACIENTE'], true))
        return jsonResponse(['ok' => false, 'mensaje' => 'Tipo inválido'], 400);

    try {
        // BUSCAR SI YA EXISTE UNA PERSONA CON ESTE EMAIL
        $personaExistente = null;
        if (!empty($pdat['email'])) {
            $baseDatos = conectar();
            $consulta = $baseDatos->prepare("SELECT id_persona, rol, activo FROM persona WHERE email = ?");
            $consulta->execute([$pdat['email']]);
            $personaExistente = $consulta->fetch(PDO::FETCH_ASSOC);

            if ($personaExistente) {
                error_log("Persona existente encontrada: ID={$personaExistente['id_persona']}, Rol={$personaExistente['rol']}, Activo={$personaExistente['activo']}");
            }
        }

        $idPersona = null;

        if ($personaExistente) {
            // ACTUALIZAR persona existente
            $idPersona = actualizarOInsertarPersona($pdat, $tipo, $actorId, (int)$personaExistente['id_persona']);
            error_log("Persona existente actualizada con ID: $idPersona");
        } else {
            // CREAR nueva persona
            $idPersona = actualizarOInsertarPersona($pdat, $tipo, $actorId);
            error_log("Nueva persona creada con ID: $idPersona");
        }

        $tutor = null;
        if ($tipo === 'PACIENTE' && isset($xdat['tutor']) && $xdat['tutor']) {
            $tutor = $xdat['tutor'];
            unset($xdat['tutor']);
        }

        $ok = ($tipo === 'PROFESIONAL')
            ? actualizarOInsertarProfesional($idPersona, $xdat)
            : actualizarOInsertarPaciente($idPersona, $xdat);

        if (!$ok) {
            error_log("Error en actualizar datos específicos del $tipo");
            return jsonResponse(['ok' => false, 'mensaje' => 'No se pudo guardar los datos específicos'], 500);
        }

        $accion = $personaExistente ? 'actualizado' : 'creado';
        return jsonResponse([
            'ok' => true,
            'id' => $idPersona,
            'mensaje' => ucfirst(strtolower($tipo)) . " $accion correctamente"
        ]);
    } catch (Exception $e) {
        $mensaje = $e->getMessage();
        error_log("Error creando/actualizando $tipo: $mensaje");

        if (strpos($mensaje, 'ya está registrado') !== false) {
            return jsonResponse(['ok' => false, 'mensaje' => $mensaje], 409);
        }

        if (strpos($mensaje, 'inexistente') !== false) {
            return jsonResponse(['ok' => false, 'mensaje' => $mensaje], 404);
        }

        return jsonResponse(['ok' => false, 'mensaje' => 'Error interno: ' . $mensaje], 500);
    }
});


/* /admin/borrar-usuario/{id} */
$app->delete('/admin/borrar-usuario/{id}', function ($req, $res, $args) {
    $val = verificarTokenUsuario();
    if ($val === false || strtolower($val['usuario']['rol']) !== 'admin')
        return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);

    $id = (int)$args['id'];
    $actorId = $val['usuario']['id_persona'];

    error_log("DELETE /admin/borrar-usuario/{$id} - Solicitud de marcar como inactivo por admin ID: $actorId");

    $resultado = marcarUsuarioInactivo($id, $actorId);

    if (!$resultado['ok']) {
        $codigo = $resultado['code'] ?? 500;
        error_log("Error al marcar usuario $id como inactivo: " . ($resultado['msg'] ?? 'Error desconocido'));
        return jsonResponse(['ok' => false, 'mensaje' => $resultado['msg']], $codigo);
    }

    return jsonResponse(['ok' => true, 'mensaje' => 'Usuario marcado como inactivo']);
});

/* /admin/usuario/{id} - PUT para editar usuario existente */
$app->put('/admin/usuarios/{id}', function ($req, $res, $args) {
    $val = verificarTokenUsuario();
    if ($val === false || strtolower($val['usuario']['rol']) !== 'admin')
        return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);

    $id = (int)$args['id'];
    $actorId = $val['usuario']['id_persona'];

    $body = $req->getParsedBody() ?? [];
    error_log("PUT /admin/usuario/{$id} - Body recibido: " . json_encode($body));

    // Determinar el tipo basado en el rol actual o el nuevo rol
    $baseDatos = conectar();
    $consulta = $baseDatos->prepare("SELECT rol FROM persona WHERE id_persona = ?");
    $consulta->execute([$id]);
    $usuarioActual = $consulta->fetch(PDO::FETCH_ASSOC);
    if (!$usuarioActual) {
        return jsonResponse(['ok' => false, 'mensaje' => 'Usuario no encontrado'], 404);
    }

    $rolFinal = strtoupper($body['rol'] ?? $usuarioActual['rol']);

    if (!in_array($rolFinal, ['PROFESIONAL', 'PACIENTE', 'ADMIN'], true))
        return jsonResponse(['ok' => false, 'mensaje' => 'Rol inválido'], 400);

    try {
        // Usar actualizarOInsertarPersona con el ID forzado para actualización
        error_log("Actualizando usuario ID: $id con rol: $rolFinal");
        $idPersona = actualizarOInsertarPersona($body, $rolFinal, $actorId, $id);        // Actualizar datos específicos según el rol
        if ($rolFinal === 'PROFESIONAL') {
            // Intentar obtener datos profesionales desde varias posibles fuentes
            $xdat = $body['datosprofesional'] ?? $body['extra'] ?? [];
            error_log("Actualizando datos profesionales: " . json_encode($xdat));
            $ok = actualizarOInsertarProfesional($idPersona, $xdat, $actorId);
        } else if ($rolFinal === 'PACIENTE') {
            // Intentar obtener datos paciente desde varias posibles fuentes
            $xdat = $body['datospaciente'] ?? $body['extra'] ?? [];
            error_log("Actualizando datos paciente: " . json_encode($xdat));
            $ok = actualizarOInsertarPaciente($idPersona, $xdat);
        } else {
            $ok = true;
        }

        return $ok
            ? jsonResponse(['ok' => true, 'id' => $idPersona, 'mensaje' => 'Usuario actualizado correctamente'])
            : jsonResponse(['ok' => false, 'mensaje' => 'No se pudo actualizar el usuario'], 500);
    } catch (Exception $e) {
        return jsonResponse(['ok' => false, 'mensaje' => $e->getMessage()], 400);
    }
});

/* /admin/informes - Usar función existente getInformeMes() */
$app->get('/admin/informes', function ($req, $res, $args) {
    $val = verificarTokenUsuario();
    if ($val === false || strtolower($val['usuario']['rol']) !== 'admin') {
        return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);
    }

    $params = $req->getQueryParams();
    $year = (int)($params['year'] ?? date('Y'));
    $month = (int)($params['month'] ?? date('m'));

    error_log("Obteniendo estadísticas para $year-$month");

    try {
        $estadisticas = getInformeMes($year, $month);
        return jsonResponse(['ok' => true, 'data' => $estadisticas]);
    } catch (Exception $e) {
        error_log("Error obteniendo estadísticas: " . $e->getMessage());
        return jsonResponse(['ok' => false, 'mensaje' => 'Error interno del servidor'], 500);
    }
});

/* /admin/logs - Usar función existente exportLogsCsv() */
$app->get('/admin/logs', function ($req, $res, $args) {
    $val = verificarTokenUsuario();
    if ($val === false || strtolower($val['usuario']['rol']) !== 'admin') {
        return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);
    }

    $params = $req->getQueryParams();
    $year = (int)($params['year'] ?? date('Y'));
    $month = (int)($params['month'] ?? date('m'));

    // error_log("=== DEBUG LOGS CSV ===");
    //error_log("Año: $year, Mes: $month");
    error_log("Usuario: {$val['usuario']['id_persona']} - Rol: {$val['usuario']['rol']}");

    try {
        error_log("Llamando a exportLogsCsv($year, $month)");
        $csvContent = exportLogsCsv($year, $month);
        error_log("CSV generado, longitud: " . strlen($csvContent));

        if (empty($csvContent)) {
            error_log("CSV vacío");
            return jsonResponse(['ok' => false, 'mensaje' => 'No hay logs para el período seleccionado'], 404);
        }

        $filename = sprintf('logs_%04d_%02d.csv', $year, $month);
        error_log("Enviando archivo: $filename");

        $response = $res
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', "attachment; filename=\"$filename\"")
            ->withHeader('Content-Length', strlen($csvContent));

        $response->getBody()->write($csvContent);

        error_log("CSV enviado exitosamente");
        return $response;
    } catch (Exception $e) {
        error_log("EXCEPCIÓN en logs CSV: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        return jsonResponse(['ok' => false, 'mensaje' => 'Error: ' . $e->getMessage()], 500);
    } catch (Error $e) {
        error_log("ERROR FATAL en logs CSV: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        return jsonResponse(['ok' => false, 'mensaje' => 'Error fatal del servidor'], 500);
    }
});

/* crear-contrasena*/
$app->post('/crear-contrasena', function ($req) {
    $b = $req->getParsedBody() ?? [];
    $uid = $b['uid'] ?? '';
    $pass = $b['password'] ?? '';

    if (!$uid || strlen($pass) < 8)
        return jsonResponse(['ok' => false, 'msg' => 'Datos inválidos'], 400);

    $id = decodificarUid($uid);
    $baseDatos = conectar();

    /* Verificar que el usuario existe */
    $consulta = $baseDatos->prepare("
      SELECT password_hash FROM persona WHERE id_persona = ? LIMIT 1");
    $consulta->execute([$id]);
    $row = $consulta->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return jsonResponse(['ok' => false, 'msg' => 'Usuario no encontrado'], 400);
    }

    /* si existe el usuario y tiene contraseña se la resescribe sino la crea */
    $consulta = $baseDatos->prepare("
      UPDATE persona         
      SET password_hash = ENCODE(DIGEST(:p, 'sha256'), 'hex'),
             password_hash_creado = CURRENT_TIMESTAMP
       WHERE id_persona = :id");
    $consulta->execute([':p' => $pass, ':id' => $id]);

    return jsonResponse(['ok' => true]);
});

/* forgot-password */
$app->post('/forgot-password', function ($req) {
    $b = $req->getParsedBody() ?? [];
    $email = trim($b['email'] ?? '');

    if (!$email) {
        return jsonResponse(['ok' => false, 'mensaje' => 'Email requerido'], 400);
    }

    try {
        $baseDatos = conectar();

        // Verificar si el email existe en la base de datos
        $consulta = $baseDatos->prepare("
            SELECT id_persona, nombre, email, activo 
            FROM persona 
            WHERE email = :email 
            AND rol IN ('PACIENTE', 'PROFESIONAL', 'ADMIN')
            LIMIT 1
        ");
        $consulta->execute([':email' => $email]);
        $usuario = $consulta->fetch(PDO::FETCH_ASSOC);

        if (!$usuario) {
            // No existe el email - devolver error
            return jsonResponse(['ok' => false, 'mensaje' => 'El correo no está registrado en la base de datos']);
        }

        if (!$usuario['activo']) {
            // Usuario inactivo - devolver error
            return jsonResponse(['ok' => false, 'mensaje' => 'La cuenta está desactivada']);
        }

        // El email existe - generar token de recuperación y enviar email
        $uid = rtrim(strtr(base64_encode((string)$usuario['id_persona']), '+/', '-_'), '=');
        $front = getenv('FRONTEND_URL') ?: 'https://clinica-petaka.netlify.app';
        $link = "$front/crear-contrasena?uid=$uid";

        $html = "
            <p>Hola {$usuario['nombre']}:</p>
            <p>Has solicitado restablecer tu contraseña en <strong>Clínica Petaka</strong>.</p>
            <p>Haz clic en el siguiente enlace para crear una nueva contraseña:</p>
            <p><a href=\"$link\">Restablecer contraseña</a></p>
            <p>Si no solicitaste este cambio, puedes ignorar este mensaje.</p>
        ";

        $emailEnviado = enviarEmail($usuario['email'], 'Restablecer contraseña – Petaka', $html);

        if ($emailEnviado) {
            return jsonResponse(['ok' => true, 'mensaje' => 'Email de recuperación enviado']);
        } else {
            return jsonResponse(['ok' => false, 'mensaje' => 'Error al enviar el email']);
        }
    } catch (Exception $e) {
        error_log("Error en forgot-password: " . $e->getMessage());
        return jsonResponse(['ok' => false, 'mensaje' => 'Error interno del servidor'], 500);
    }
});


/* obtiene perfil del paciente logueado */
$app->get('/pac/perfil', function (Request $req): Response {
    try {

        $val = verificarTokenUsuario();
        if ($val === false) {
            return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);
        }
        if ($val['usuario']['rol'] !== 'PACIENTE') {
            return jsonResponse(['ok' => false, 'mensaje' => 'Acceso denegado'], 403);
        }

        $idPaciente = (int)$val['usuario']['id_persona'];
        if ($idPaciente <= 0) {
            error_log("ID de paciente inválido: " . $idPaciente);
            return jsonResponse(['ok' => false, 'mensaje' => 'ID de paciente no válido'], 400);
        }

        $data = getUsuarioDetalle($idPaciente);
        if (!$data) {
            error_log("No se encontraron datos para el paciente ID: " . $idPaciente);
            return jsonResponse(['ok' => false, 'mensaje' => 'No se encontraron datos del paciente'], 404);
        }

        if (isset($data['persona']['fecha_nacimiento'])) {
            $data['persona']['fecha_nacimiento'] = date('Y-m-d', strtotime($data['persona']['fecha_nacimiento']));
        }

        if (isset($data['tutor']) && isset($data['tutor']['fecha_nacimiento'])) {
            $data['tutor']['fecha_nacimiento'] = date('Y-m-d', strtotime($data['tutor']['fecha_nacimiento']));
        }

        return jsonResponse([
            'ok' => true,
            'data' => $data,
            'token' => $val['token']
        ]);
    } catch (\Exception $e) {
        error_log("Error en /pac/perfil: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        return jsonResponse(['ok' => false, 'mensaje' => 'Error al cargar el perfil: ' . $e->getMessage()], 500);
    }
});

/*  actualiza perfil del paciente actual */
$app->put('/pac/perfil', function (Request $req): Response {
    $val = verificarTokenUsuario();
    if ($val === false) {
        return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);
    }
    if ($val['usuario']['rol'] !== 'PACIENTE') {
        return jsonResponse(['ok' => false, 'mensaje' => 'Acceso denegado'], 403);
    }

    try {
        $idPaciente = (int)$val['usuario']['id_persona'];
        $data = $req->getParsedBody() ?? [];

        // Process persona data
        if (!empty($data['persona'])) {
            // Permitir solo los campos de contacto
            $permitidos = [
                'email',
                'telefono',
                'tipo_via',
                'nombre_calle',
                'numero',
                'escalera',
                'piso',
                'puerta',
                'codigo_postal',
                'ciudad',
                'provincia',
                'pais'
            ];
            $personaData = array_intersect_key($data['persona'] ?? [], array_flip($permitidos));

            if (!empty($personaData)) {
                // Auditoría
                error_log("Paciente ID $idPaciente actualizando datos de contacto: " . json_encode($personaData));
                actualizarOInsertarPersona($personaData, 'PACIENTE', $idPaciente, $idPaciente);
            }
        }

        // Process paciente data
        if (!empty($data['paciente'])) {
            error_log("Paciente ID $idPaciente actualizando datos de paciente: " . json_encode($data['paciente']));

            actualizarOInsertarPaciente($idPaciente, $data['paciente']);
        }

        if (!empty($data['tutor']) && isset($data['paciente']['tipo_paciente']) && $data['paciente']['tipo_paciente'] !== 'ADULTO') {
            error_log("Paciente ID $idPaciente actualizando datos de tutor: " . json_encode($data['tutor']));

            // Modificar el paciente para incluir el tutor
            $datosPaciente = [
                'tipo_paciente' => $data['paciente']['tipo_paciente'] ?? 'ADOLESCENTE',
                'observaciones_generales' => $data['paciente']['observaciones_generales'] ?? '',
                'tutor' => $data['tutor']
            ];

            actualizarOInsertarPaciente($idPaciente, $datosPaciente);
        }

        return jsonResponse([
            'ok' => true,
            'mensaje' => 'Datos actualizados correctamente',
            'token' => $val['token']
        ]);
    } catch (\Exception $e) {
        error_log("Error actualizando perfil paciente ID $idPaciente: " . $e->getMessage());
        return jsonResponse(['ok' => false, 'mensaje' => $e->getMessage()], 500);
    }
});

/* obtiene tareas asignadas al paciente actual */
$app->get('/pac/tareas', function (Request $req): Response {
    try {

        $val = verificarTokenUsuario();
        if ($val === false) {
            return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);
        }

        if ($val['usuario']['rol'] !== 'PACIENTE') {
            return jsonResponse(['ok' => false, 'mensaje' => 'Acceso denegado'], 403);
        }

        $idPaciente = (int)$val['usuario']['id_persona'];
        if ($idPaciente <= 0) {
            error_log("ID de paciente inválido: " . $idPaciente);
            return jsonResponse(['ok' => false, 'mensaje' => 'ID de paciente no válido'], 400);
        }

        $tareas = getTareasPaciente($idPaciente);

        return jsonResponse([
            'ok' => true,
            'tareas' => $tareas,
            'total' => count($tareas),
            'token' => $val['token']
        ]);
    } catch (\Exception $e) {
        error_log("Error en /pac/tareas: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        return jsonResponse([
            'ok' => false,
            'mensaje' => 'Error al cargar las tareas: ' . $e->getMessage()
        ], 500);
    }
});

/* obtiene documentos del historial clínico del paciente actual */
$app->get('/pac/historial', function (Request $req): Response {
    try {

        $val = verificarTokenUsuario();
        if ($val === false) {
            return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);
        }

        if ($val['usuario']['rol'] !== 'PACIENTE') {
            return jsonResponse(['ok' => false, 'mensaje' => 'Acceso denegado'], 403);
        }

        $idPaciente = (int)$val['usuario']['id_persona'];
        if ($idPaciente <= 0) {
            error_log("ID de paciente inválido: " . $idPaciente);
            return jsonResponse(['ok' => false, 'mensaje' => 'ID de paciente no válido'], 400);
        }

        $documentos = getHistorialPaciente($idPaciente);


        return jsonResponse([
            'ok' => true,
            'documentos' => $documentos,
            'total' => count($documentos),
            'token' => $val['token']
        ]);
    } catch (\Exception $e) {
        error_log("Error en /pac/historial: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        return jsonResponse([
            'ok' => false,
            'mensaje' => 'Error al cargar el historial: ' . $e->getMessage()
        ], 500);
    }
});

/* obtiene citas del paciente actual */
$app->get('/pac/citas', function (Request $req): Response {
    try {

        $val = verificarTokenUsuario();
        if ($val === false) {
            return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);
        }

        if ($val['usuario']['rol'] !== 'PACIENTE') {
            return jsonResponse(['ok' => false, 'mensaje' => 'Acceso denegado'], 403);
        }

        $idPaciente = (int)$val['usuario']['id_persona'];
        if ($idPaciente <= 0) {
            error_log("ID de paciente inválido: " . $idPaciente);
            return jsonResponse(['ok' => false, 'mensaje' => 'ID de paciente no válido'], 400);
        }

        // Obtener citas del paciente sin filtrar por profesional
        $citas = getCitasPaciente($idPaciente);


        return jsonResponse([
            'ok' => true,
            'citas' => $citas,
            'total' => count($citas),
            'token' => $val['token']
        ]);
    } catch (\Exception $e) {
        error_log("Error en /pac/citas: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        return jsonResponse([
            'ok' => false,
            'mensaje' => 'Error al cargar las citas: ' . $e->getMessage()
        ], 500);
    }
});

/* procesa solicitudes de cambio/cancelación */
$app->post('/pac/citas/{id}/solicitud', function (Request $req, Response $res, array $args): Response {
    try {
        //error_log("=== INICIO /pac/citas/{id}/solicitud ===");
        // error_log("Headers: " . json_encode($req->getHeaders()));
        // error_log("Body raw: " . $req->getBody()->getContents());
        $req->getBody()->rewind(); // Reset stream after reading

        $val = verificarTokenUsuario();
        if ($val === false) {
            error_log("Token inválido");
            return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);
        }

        if ($val['usuario']['rol'] !== 'PACIENTE') {
            error_log("Rol inválido: " . $val['usuario']['rol']);
            return jsonResponse(['ok' => false, 'mensaje' => 'Acceso denegado'], 403);
        }

        $idPaciente = (int)$val['usuario']['id_persona'];
        $idCita = (int)$args['id'];
        $body = $req->getParsedBody();

        //error_log("ID Paciente: $idPaciente");
        //error_log("ID Cita: $idCita");
        // error_log("Body parsed: " . json_encode($body));

        $accion = strtoupper(trim($body['accion'] ?? ''));
        $nuevaFecha = $body['nueva_fecha'] ?? null;

        //error_log("Acción: '$accion'");
        //error_log("Nueva fecha: '$nuevaFecha'");

        if (!in_array($accion, ['CAMBIAR', 'CANCELAR'])) {
            error_log("Acción no válida: '$accion'");
            return jsonResponse(['ok' => false, 'mensaje' => 'Acción no válida'], 400);
        }

        // Procesar la solicitud
        $resultado = procesarSolicitudCitaPaciente($idCita, $accion, $idPaciente, $nuevaFecha);

        if ($resultado['ok']) {
            error_log("Solicitud procesada exitosamente");
            return jsonResponse([
                'ok' => true,
                'mensaje' => $resultado['mensaje'],
                'token' => $val['token']
            ]);
        } else {
            error_log("Error procesando solicitud: " . $resultado['mensaje']);
            return jsonResponse([
                'ok' => false,
                'mensaje' => $resultado['mensaje']
            ], 400);
        }
    } catch (\Exception $e) {
        error_log("Error en solicitud de cita: " . $e->getMessage());
        return jsonResponse([
            'ok' => false,
            'mensaje' => 'Error al procesar la solicitud: ' . $e->getMessage()
        ], 500);
    }
});

/* /pac/profesional/{id}/dias-bloqueados — obtener días bloqueados para pacientes */
$app->get('/pac/profesional/{id}/dias-bloqueados', function (Request $req, Response $res, array $args) {
    $val = verificarTokenUsuario();
    if ($val === false || $val['usuario']['rol'] !== 'PACIENTE') {
        return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);
    }

    $profId = (int)$args['id'];
    $fechaInicio = $req->getQueryParams()['fecha_inicio'] ?? '';
    $fechaFin = $req->getQueryParams()['fecha_fin'] ?? '';

    if (!$fechaInicio || !$fechaFin) {
        return jsonResponse(['ok' => false, 'mensaje' => 'Faltan parámetros'], 400);
    }

    try {
        $diasBloqueados = obtenerDiasBloqueados($profId, $fechaInicio, $fechaFin);
        return jsonResponse([
            'ok' => true,
            'dias_bloqueados' => $diasBloqueados,
            'token' => $val['token']
        ]);
    } catch (Exception $e) {
        return jsonResponse(['ok' => false, 'mensaje' => 'Error interno'], 500);
    }
});

/* obtener horas disponibles para pacientes */
$app->get('/pac/profesional/{id}/horas-disponibles', function (Request $req, Response $res, array $args) {
    $val = verificarTokenUsuario();
    if ($val === false || $val['usuario']['rol'] !== 'PACIENTE') {
        return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);
    }

    $profId = (int)$args['id'];
    $fecha = $req->getQueryParams()['fecha'] ?? '';

    if (!$fecha) {
        return jsonResponse(['ok' => false, 'mensaje' => 'Falta parámetro fecha'], 400);
    }

    try {
        $horas = obtenerHorasDisponibles($profId, $fecha);
        return jsonResponse([
            'ok' => true,
            'horas' => $horas,
            'token' => $val['token']
        ]);
    } catch (Exception $e) {
        return jsonResponse(['ok' => false, 'mensaje' => 'Error interno'], 500);
    }
});
$app->get('/prof/horas-disponibles', function (Request $req, Response $res): Response {
    $val = verificarTokenUsuario();
    if ($val === false) {
        return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);
    }

    if ($val['usuario']['rol'] !== 'PROFESIONAL') {
        return jsonResponse(['ok' => false, 'mensaje' => 'Acceso denegado'], 403);
    }

    $params = $req->getQueryParams();
    $profesionalId = (int)($params['profesional_id'] ?? 0);
    $fecha = $params['fecha'] ?? '';

    if (!$profesionalId || !$fecha) {
        return jsonResponse(['ok' => false, 'mensaje' => 'Faltan parámetros'], 400);
    }

    try {
        $horasDisponibles = obtenerHorasDisponibles($profesionalId, $fecha);

        return jsonResponse([
            'ok' => true,
            'horas' => $horasDisponibles,
            'token' => $val['token']
        ]);
    } catch (Exception $e) {
        return jsonResponse(['ok' => false, 'mensaje' => $e->getMessage()], 500);
    }
});
/* buscar profesional por nombre para pacientes */
$app->get('/prof/buscar-por-nombre', function (Request $req): Response {
    $val = verificarTokenUsuario();
    if ($val === false) {
        return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);
    }

    $nombre = trim($req->getQueryParams()['nombre'] ?? '');
    if (empty($nombre)) {
        return jsonResponse(['ok' => false, 'mensaje' => 'Nombre requerido'], 400);
    }

    try {
        $baseDatos = conectar();

        // Buscar profesional por nombre con coincidencia parcial
        $consulta = $baseDatos->prepare("
            SELECT 
                p.id_profesional as id,
                (pe.nombre || ' ' || pe.apellido1 || 
                       CASE WHEN pe.apellido2 IS NOT NULL THEN (' ' || pe.apellido2) ELSE '' END) as nombre_completo,
                pr.especialidad
            FROM profesional p 
                JOIN persona pe ON pe.id_persona = p.id_profesional
            LEFT JOIN profesional pr ON pr.id_profesional = p.id_profesional
            WHERE pe.activo = true
            AND (
                (pe.nombre || ' ' || pe.apellido1 || COALESCE(' ' || pe.apellido2, '')) ILIKE :nombre
                OR pe.nombre ILIKE :nombre
                OR pe.apellido1 ILIKE :nombre
            )
            ORDER BY 
                CASE 
                    WHEN (pe.nombre || ' ' || pe.apellido1 || COALESCE(' ' || pe.apellido2, '')) = :nombre_exacto THEN 1
                    WHEN (pe.nombre || ' ' || pe.apellido1 || COALESCE(' ' || pe.apellido2, '')) ILIKE :nombre_inicio THEN 2
                    ELSE 3
                END
            LIMIT 1
        ");

        $nombrePattern = '%' . $nombre . '%';
        $nombreInicio = $nombre . '%';

        $consulta->execute([
            ':nombre' => $nombrePattern,
            ':nombre_exacto' => $nombre,
            ':nombre_inicio' => $nombreInicio
        ]);

        $profesional = $consulta->fetch(PDO::FETCH_ASSOC);

        if ($profesional) {
            return jsonResponse([
                'ok' => true,
                'profesional' => $profesional,
                'token' => $val['token']
            ]);
        } else {
            return jsonResponse([
                'ok' => false,
                'mensaje' => 'Profesional no encontrado',
                'token' => $val['token']
            ]);
        }
    } catch (Exception $e) {
        error_log("Error buscando profesional por nombre: " . $e->getMessage());
        return jsonResponse(['ok' => false, 'mensaje' => 'Error interno del servidor'], 500);
    }
});

/* solicitar nueva cita para paciente autenticado */
$app->post('/pac/solicitar-cita', function (Request $req): Response {
    try {

        $val = verificarTokenUsuario();
        if ($val === false) {
            return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);
        }

        if ($val['usuario']['rol'] !== 'PACIENTE') {
            return jsonResponse(['ok' => false, 'mensaje' => 'Acceso denegado'], 403);
        }

        $idPaciente = (int)$val['usuario']['id_persona'];
        $data = $req->getParsedBody();

        $profesionalId = (int)($data['profesional_id'] ?? 0);
        $motivo = trim($data['motivo'] ?? '');
        $fecha = trim($data['fecha'] ?? '');

        error_log("Solicitud de cita autenticada - Paciente ID: $idPaciente, Profesional ID: $profesionalId, Motivo: $motivo, Fecha: $fecha");

        if (!$profesionalId || !$motivo || !$fecha) {
            return jsonResponse(['ok' => false, 'mensaje' => 'Faltan campos obligatorios'], 400);
        }

        // Crear la cita directamente en la base de datos
        $baseDatos = conectar();
        $baseDatos->beginTransaction();

        try {
            // Verificar que el profesional existe
            $consultaProf = $baseDatos->prepare("
                SELECT p.id_profesional, pe.nombre, pe.apellido1
                FROM profesional p                JOIN persona pe ON pe.id_persona = p.id_profesional
                WHERE p.id_profesional = ? AND pe.activo = true
            ");
            $consultaProf->execute([$profesionalId]);
            $profesional = $consultaProf->fetch(PDO::FETCH_ASSOC);

            if (!$profesional) {
                throw new Exception('Profesional no encontrado');
            }

            // Validar fecha
            $fechaObj = new DateTime($fecha);
            $ahora = new DateTime();

            if ($fechaObj <= $ahora) {
                throw new Exception('La fecha debe ser posterior al momento actual');
            }

            // Verificar disponibilidad del profesional
            $consultaDisponibilidad = $baseDatos->prepare("
                SELECT COUNT(*) as ocupado FROM cita 
                WHERE id_profesional = ? 
                AND fecha_hora = ? 
                AND estado IN ('CONFIRMADA', 'PENDIENTE_VALIDACION', 'SOLICITADA')
            ");
            $consultaDisponibilidad->execute([$profesionalId, $fecha]);
            $disponibilidad = $consultaDisponibilidad->fetch(PDO::FETCH_ASSOC);

            if ($disponibilidad['ocupado'] > 0) {
                throw new Exception('La fecha y hora seleccionada ya está ocupada');
            }

            // Verificar bloqueos del profesional (ausencias, vacaciones, etc.)
            $consultaBloqueos = $baseDatos->prepare("
                SELECT COUNT(*) as bloqueado FROM bloque_agenda
                WHERE id_profesional = ?
                AND tipo_bloque IN ('AUSENCIA', 'VACACIONES', 'BAJA', 'EVENTO')
                AND ? BETWEEN fecha_inicio AND (fecha_fin - INTERVAL '1 second')
            ");
            $consultaBloqueos->execute([$profesionalId, $fecha]);
            $bloqueo = $consultaBloqueos->fetch(PDO::FETCH_ASSOC);

            if ($bloqueo['bloqueado'] > 0) {
                throw new Exception('El profesional no está disponible en esta fecha');
            }

            // Obtener datos del paciente para la cita
            $consultaPaciente = $baseDatos->prepare("
                SELECT nombre, apellido1, email, telefono
                FROM persona
                WHERE id_persona = ?
            ");
            $consultaPaciente->execute([$idPaciente]);
            $paciente = $consultaPaciente->fetch(PDO::FETCH_ASSOC);

            if (!$paciente) {
                throw new Exception('Datos del paciente no encontrados');
            }
            // Crear la cita
            $insertarCita = $baseDatos->prepare("
                INSERT INTO cita (
                    id_paciente, id_profesional, fecha_hora, estado, 
                    nombre_contacto, email_contacto, telefono_contacto,
                    motivo, origen
                ) VALUES (?, ?, ?, 'SOLICITADA', ?, ?, ?, ?, 'WEB')
                RETURNING id_cita
            ");

            $nombreCompleto = trim($paciente['nombre'] . ' ' . $paciente['apellido1']);

            $insertarCita->execute([
                $idPaciente,
                $profesionalId,
                $fecha,
                $nombreCompleto,
                $paciente['email'],
                $paciente['telefono'],
                $motivo
            ]);

            $idCita = $insertarCita->fetchColumn();

            // Registrar actividad
            registrarActividad($idPaciente, $idPaciente,  'cita', 'crear', null, $idCita, 'INSERT');

            $baseDatos->commit();

            error_log("Cita creada exitosamente - ID: $idCita");

            return jsonResponse([
                'ok' => true,
                'mensaje' => '¡Solicitud de cita enviada! Te avisaremos cuando el profesional la confirme',
                'id_cita' => $idCita,
                'token' => $val['token']
            ]);
        } catch (Exception $e) {
            $baseDatos->rollBack();
            error_log("Error al crear cita: " . $e->getMessage());

            return jsonResponse([
                'ok' => false,
                'mensaje' => $e->getMessage()
            ], 400);
        }
    } catch (Exception $e) {
        error_log("Error general en /pac/solicitar-cita: " . $e->getMessage());
        return jsonResponse([
            'ok' => false,
            'mensaje' => 'Error al procesar la solicitud'
        ], 500);
    }
});

// ---------- RUTAS S3 DOCUMENTOS ----------

//  Health-check de S3
$app->get('/api/s3/health', function (Request $req, Response $res) {
    try {
        $c = new App\Controllers\DocumentController();
        return $c->healthCheck($req, $res);
    } catch (Exception $e) {
        error_log('S3 Health check error: ' . $e->getMessage());
        return jsonResponse([
            'ok' => false,
            'mensaje' => 'Error en health check: ' . $e->getMessage()
        ], 500);
    }
});

//  Subir documento
$app->post('/api/s3/upload', function (Request $req, Response $res) {
    try {
        $val = verificarTokenUsuario();
        if (!$val) {
            return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);
        }
        if (!in_array(strtolower($val['usuario']['rol']), ['profesional', 'admin'])) {
            return jsonResponse(['ok' => false, 'mensaje' => 'Acceso denegado'], 403);
        }

        $c = new App\Controllers\DocumentController();
        return $c->uploadDocument($req, $res);
    } catch (Exception $e) {
        error_log('S3 Upload error: ' . $e->getMessage());
        error_log('Stack trace: ' . $e->getTraceAsString());
        return jsonResponse([
            'ok' => false,
            'mensaje' => 'Error en upload: ' . $e->getMessage()
        ], 500);
    }
});

//  Obtener URL firmada
$app->get('/api/s3/documentos/{idDoc}/url', function (Request $req, Response $res, array $args) {
    try {
        $val = verificarTokenUsuario();
        if (!$val) {
            return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);
        }

        $c = new App\Controllers\DocumentController();
        return $c->getDocumentUrl($req, $res, ['idDoc' => $args['idDoc']]);
    } catch (Exception $e) {
        error_log('S3 Get URL error: ' . $e->getMessage());
        return jsonResponse([
            'ok' => false,
            'mensaje' => 'Error obteniendo URL: ' . $e->getMessage()
        ], 500);
    }
});

// Eliminar documento
$app->delete('/api/s3/documentos/{id}', function (Request $req, Response $res, array $args) {
    try {
        $val = verificarTokenUsuario();
        if (!$val) {
            return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);
        }
        if (!in_array(strtolower($val['usuario']['rol']), ['profesional', 'admin'])) {
            return jsonResponse(['ok' => false, 'mensaje' => 'Acceso denegado'], 403);
        }

        //error_log('=== ELIMINANDO DOCUMENTO S3 ===');
        //error_log('ID documento: ' . $args['id']);
        //error_log('Usuario: ' . $val['usuario']['id_persona']);

        $c = new App\Controllers\DocumentController();
        $response = $c->deleteDocument($req, $res, ['id' => $args['id']]);

        // error_log('Respuesta eliminación: ' . $response->getBody());
        return $response;
    } catch (Exception $e) {
        error_log('S3 Delete error: ' . $e->getMessage());
        error_log('Stack trace: ' . $e->getTraceAsString());
        return jsonResponse([
            'ok' => false,
            'mensaje' => 'Error eliminando documento: ' . $e->getMessage()
        ], 500);
    }
});

//  Listar documentos
$app->get('/api/s3/documentos', function (Request $req, Response $res) {
    try {
        $val = verificarTokenUsuario();
        if (!$val) {
            return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);
        }

        $c = new App\Controllers\DocumentController();
        return $c->listDocuments($req, $res);
    } catch (Exception $e) {
        error_log('S3 List error: ' . $e->getMessage());
        return jsonResponse([
            'ok' => false,
            'mensaje' => 'Error listando documentos: ' . $e->getMessage()
        ], 500);
    }
});

//Obtener tratamientos con documentos
$app->get('/api/s3/tratamientos/{paciente_id}', function (Request $req, Response $res, array $args) {
    try {
        $val = verificarTokenUsuario();
        if (!$val) {
            return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);
        }

        $c = new App\Controllers\DocumentController();
        return $c->getTreatmentsWithDocuments($req, $res, ['paciente_id' => $args['paciente_id']]);
    } catch (Exception $e) {
        error_log('S3 Treatments error: ' . $e->getMessage());
        return jsonResponse([
            'ok' => false,
            'mensaje' => 'Error obteniendo tratamientos: ' . $e->getMessage()
        ], 500);
    }
});

/* Actualizar diagnóstico final del historial */
$app->put('/historial/{historial_id}/diagnostico', function (Request $req, Response $res, array $args) {
    $val = verificarTokenUsuario();
    if (!$val) {
        return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);
    }

    $historialId = (int)$args['historial_id'];
    $data = $req->getParsedBody() ?? [];

    if (!isset($data['diagnostico_final'])) {
        return jsonResponse(['ok' => false, 'mensaje' => 'Diagnóstico final requerido'], 400);
    }

    try {
        $baseDatos = conectar();

        // Verificar que el historial existe
        $checkSql = "SELECT id_historial FROM historial_clinico WHERE id_historial = ?";
        $checkStmt = $baseDatos->prepare($checkSql);
        $checkStmt->execute([$historialId]);

        if (!$checkStmt->fetch()) {
            return jsonResponse(['ok' => false, 'mensaje' => 'Historial clínico no encontrado'], 404);
        }

        // Actualizar diagnóstico
        $sql = "UPDATE historial_clinico SET diagnostico_final = ? WHERE id_historial = ?";
        $stmt = $baseDatos->prepare($sql);
        $success = $stmt->execute([trim($data['diagnostico_final']), $historialId]);

        if ($success) {
            error_log("Diagnóstico actualizado para historial $historialId");

            // Registrar actividad
            registrarActividad(
                $val['usuario']['id_persona'],
                null,
                'historial_clinico',
                'diagnostico_final',
                null,
                trim($data['diagnostico_final']),
                'UPDATE'
            );

            return jsonResponse(['ok' => true, 'mensaje' => 'Diagnóstico guardado correctamente']);
        } else {
            return jsonResponse(['ok' => false, 'mensaje' => 'No se pudo actualizar'], 500);
        }
    } catch (Exception $e) {
        error_log('Error updating diagnosis: ' . $e->getMessage());
        return jsonResponse(['ok' => false, 'mensaje' => 'Error interno: ' . $e->getMessage()], 500);
    }
});

/* /prof/perfil */
$app->get('/prof/perfil', function (Request $req, Response $res): Response {
    $val = verificarTokenUsuario();
    if ($val === false) {
        return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);
    }

    if ($val['usuario']['rol'] !== 'PROFESIONAL') {
        return jsonResponse(['ok' => false, 'mensaje' => 'Acceso denegado'], 403);
    }

    $idProfesional = (int)$val['usuario']['id_persona'];

    try {
        $datos = obtenerPerfilProfesional($idProfesional);
        return jsonResponse([
            'ok' => true,
            'data' => $datos,
            'token' => $val['token']
        ]);
    } catch (Exception $e) {
        return jsonResponse(['ok' => false, 'mensaje' => 'Error al cargar el perfil'], 500);
    }
});

/* /prof/perfil */
$app->put('/prof/perfil', function (Request $req, Response $res): Response {
    $val = verificarTokenUsuario();
    if ($val === false) {
        return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);
    }

    if ($val['usuario']['rol'] !== 'PROFESIONAL') {
        return jsonResponse(['ok' => false, 'mensaje' => 'Acceso denegado'], 403);
    }

    $idProfesional = (int)$val['usuario']['id_persona'];
    $data = $req->getParsedBody() ?? [];

    try {
        actualizarPerfilProfesional($idProfesional, $data, $idProfesional);
        return jsonResponse([
            'ok' => true,
            'mensaje' => 'Perfil actualizado correctamente',
            'token' => $val['token']
        ]);
    } catch (Exception $e) {
        return jsonResponse(['ok' => false, 'mensaje' => 'Error al actualizar el perfil'], 500);
    }
});

/* obtiene detalles de un paciente específico para el profesional */
$app->get('/prof/pacientes/{id}', function (Request $req, Response $res, array $args): Response {
    try {
        $val = verificarTokenUsuario();
        if ($val === false) {
            return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);
        }

        if ($val['usuario']['rol'] !== 'PROFESIONAL') {
            return jsonResponse(['ok' => false, 'mensaje' => 'Acceso denegado'], 403);
        }

        $idProfesional = (int)$val['usuario']['id_persona'];
        $idPaciente = (int)$args['id'];

        if ($idPaciente <= 0) {
            error_log("ID de paciente inválido: " . $idPaciente);
            return jsonResponse(['ok' => false, 'mensaje' => 'ID de paciente no válido'], 400);
        }

        // Verificar que el paciente pertenece al profesional
        if (!verificarPacienteProfesional($idPaciente, $idProfesional)) {
            error_log("Acceso no autorizado: Profesional ID: $idProfesional, Paciente ID: $idPaciente");
            return jsonResponse(['ok' => false, 'mensaje' => 'No tiene acceso a este paciente'], 403);
        }

        $detallesPaciente = getDetallesPacienteProfesional($idPaciente, $idProfesional);

        return jsonResponse([
            'ok' => true,
            'data' => $detallesPaciente,
            'token' => $val['token']
        ]);
    } catch (Exception $e) {
        error_log("Error en /prof/pacientes/{id}: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        return jsonResponse(['ok' => false, 'mensaje' => 'Error al cargar el paciente: ' . $e->getMessage()], 500);
    }
});

$app->delete('/prof/pacientes/{id}/tareas/{id_tratamiento}', function (Request $request, Response $response, array $args) {
    $val = verificarTokenUsuario();
    if ($val === false) {
        return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);
    }

    $tratamientoId = $args['id_tratamiento'];

    $db = conectar();
    $stmt = $db->prepare("DELETE FROM tratamiento WHERE id_tratamiento = ?");
    $stmt->execute([$tratamientoId]);

    return jsonResponse(['ok' => true]);
});

/* actualiza datos de un paciente por parte del profesional */
$app->put('/prof/pacientes/{id}', function (Request $req, Response $res, array $args): Response {
    try {
        $val = verificarTokenUsuario();
        if ($val === false) {
            return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);
        }

        if ($val['usuario']['rol'] !== 'PROFESIONAL') {
            return jsonResponse(['ok' => false, 'mensaje' => 'Acceso denegado'], 403);
        }

        $idProfesional = (int)$val['usuario']['id_persona'];
        $idPaciente = (int)$args['id'];

        if ($idPaciente <= 0) {
            error_log("ID de paciente inválido: " . $idPaciente);
            return jsonResponse(['ok' => false, 'mensaje' => 'ID de paciente no válido'], 400);
        }

        // Verificar que el paciente pertenece al profesional
        if (!verificarPacienteProfesional($idPaciente, $idProfesional)) {
            error_log("Acceso no autorizado: Profesional ID: $idProfesional, Paciente ID: $idPaciente");
            return jsonResponse(['ok' => false, 'mensaje' => 'No tiene acceso a este paciente'], 403);
        }

        $data = $req->getParsedBody() ?? [];

        // Actualizar datos de persona
        if (!empty($data['persona'])) {
            error_log("Actualizando datos de persona para paciente ID: $idPaciente por profesional ID: $idProfesional");
            actualizarOInsertarPersona($data['persona'], 'PACIENTE', $idProfesional, $idPaciente);
        }

        // Actualizar datos de paciente
        if (!empty($data['paciente'])) {
            error_log("Actualizando datos de paciente ID: $idPaciente por profesional ID: $idProfesional");

            // Extraer datos del tutor si existen
            $datosPaciente = $data['paciente'];

            // Verificar si hay tutor y actualizar
            if (isset($datosPaciente['tutor']) && is_array($datosPaciente['tutor']) && $datosPaciente['tipo_paciente'] !== 'ADULTO') {
                error_log("Actualizando datos de tutor para paciente ID: $idPaciente");
            }

            actualizarOInsertarPaciente($idPaciente, $datosPaciente);
        }

        return jsonResponse([
            'ok' => true,
            'mensaje' => 'Datos del paciente actualizados correctamente',
            'token' => $val['token']
        ]);
    } catch (Exception $e) {
        error_log("Error en /prof/pacientes/{id} PUT: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        return jsonResponse(['ok' => false, 'mensaje' => 'Error al actualizar el paciente: ' . $e->getMessage()], 500);
    }
});

/* obtiene listado de pacientes del profesional */
$app->get('/prof/pacientes', function (Request $req): Response {
    try {
        $val = verificarTokenUsuario();
        if ($val === false) {
            return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);
        }

        if ($val['usuario']['rol'] !== 'PROFESIONAL') {
            return jsonResponse(['ok' => false, 'mensaje' => 'Acceso denegado'], 403);
        }

        $idProfesional = (int)$val['usuario']['id_persona'];

        // Obtener listado de pacientes del profesional
        $baseDatos = conectar();
        $consulta = $baseDatos->prepare("
            SELECT DISTINCT 
                p.id_persona, 
                p.nombre, 
                p.apellido1, 
                p.apellido2,
                (SELECT MIN(c2.fecha_hora) 
                 FROM cita c2 
                 WHERE c2.id_paciente = p.id_persona 
                 AND c2.id_profesional = :idprof
                 AND c2.fecha_hora > CURRENT_TIMESTAMP
                 AND c2.estado NOT IN ('CANCELADA', 'NO_ATENDIDA')
                ) as proxima_cita
            FROM persona p
            JOIN cita c ON c.id_paciente = p.id_persona
            WHERE c.id_profesional = :idprof
            AND p.activo = true
            ORDER BY proxima_cita ASC NULLS LAST, p.apellido1, p.nombre
        ");

        $consulta->execute([':idprof' => $idProfesional]);
        $pacientes = $consulta->fetchAll(PDO::FETCH_ASSOC);

        return jsonResponse([
            'ok' => true,
            'pacientes' => $pacientes,
            'token' => $val['token']
        ]);
    } catch (Exception $e) {
        error_log("Error en /prof/pacientes GET: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        return jsonResponse(['ok' => false, 'mensaje' => 'Error al obtener los pacientes: ' . $e->getMessage()], 500);
    }
});

/* procesar acciones en citas por profesionales */
$app->post('/prof/citas/{id}/accion', function (Request $req, Response $res, array $args): Response {
    try {
        $val = verificarTokenUsuario();
        if ($val === false) {
            return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);
        }

        if ($val['usuario']['rol'] !== 'PROFESIONAL') {
            return jsonResponse(['ok' => false, 'mensaje' => 'Acceso denegado'], 403);
        }

        $idProfesional = (int)$val['usuario']['id_persona'];
        $idCita = (int)$args['id'];
        $body = $req->getParsedBody() ?? [];

        $accion = strtoupper(trim($body['accion'] ?? ''));
        $fecha = $body['fecha'] ?? null;

        if (empty($accion)) {
            return jsonResponse(['ok' => false, 'mensaje' => 'Acción no especificada'], 400);
        }

        error_log("Profesional ID: $idProfesional realizando acción: $accion en cita ID: $idCita");

        procesarAccionCitaProfesional($idCita, [
            'accion' => $accion,
            'fecha' => $fecha
        ]);

        return jsonResponse([
            'ok' => true,
            'mensaje' => 'Acción procesada correctamente',
            'token' => $val['token']
        ]);
    } catch (Exception $e) {
        error_log("Error en /prof/citas/{id}/accion: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        return jsonResponse(['ok' => false, 'mensaje' => $e->getMessage()], 500);
    }
});


// ========== RUTAS DE BACKUP ==========

/* crear backup manual para admins */
$app->post('/admin/backup/create', function ($req) {
    $val = verificarTokenUsuario();
    if ($val === false || strtolower($val['usuario']['rol']) !== 'admin') {
        return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);
    }

    try {
        require_once __DIR__ . '/../src/Services/BackupService.php';
        $backupService = new BackupService();
        $result = $backupService->createFullBackup();

        return jsonResponse([
            'ok' => true,
            'mensaje' => 'Backup creado exitosamente',
            'data' => $result
        ]);
    } catch (Exception $e) {
        error_log('Error creando backup: ' . $e->getMessage());
        return jsonResponse([
            'ok' => false,
            'mensaje' => 'Error creando backup',
            'error' => $e->getMessage()
        ], 500);
    }
});

/* listar backups para admins */
$app->get('/admin/backup/list', function ($req) {
    $val = verificarTokenUsuario();
    if ($val === false || strtolower($val['usuario']['rol']) !== 'admin') {
        return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);
    }

    try {
        require_once __DIR__ . '/../src/Services/BackupService.php';
        $backupService = new BackupService();
        $backups = $backupService->listBackups();

        return jsonResponse([
            'ok' => true,
            'data' => $backups
        ]);
    } catch (Exception $e) {
        error_log('Error listando backups: ' . $e->getMessage());
        return jsonResponse([
            'ok' => false,
            'mensaje' => 'Error listando backups',
            'error' => $e->getMessage()
        ], 500);
    }
});

/* limpiar backups antiguos para admins */
$app->post('/admin/backup/cleanup', function ($req) {
    $val = verificarTokenUsuario();
    if ($val === false || strtolower($val['usuario']['rol']) !== 'admin') {
        return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);
    }

    try {
        $input = $req->getParsedBody() ?? [];
        $keep = $input['keep'] ?? 10;

        require_once __DIR__ . '/../src/Services/BackupService.php';
        $backupService = new BackupService();
        $result = $backupService->deleteOldBackups($keep);

        return jsonResponse([
            'ok' => true,
            'mensaje' => $result['message'],
            'deleted' => $result['deleted']
        ]);
    } catch (Exception $e) {
        error_log('Error en cleanup: ' . $e->getMessage());
        return jsonResponse([
            'ok' => false,
            'mensaje' => 'Error en cleanup de backups',
            'error' => $e->getMessage()
        ], 500);
    }
});

/* ===== RUTAS PARA CRON AUTOMÁTICO ===== */

// Ruta única que acepta GET y POST para máxima compatibilidad
$app->map(['GET', 'POST'], '/cron/backup/run', function ($req) {
    try {
        // Obtener token desde header o query parameter
        $cronToken = $_SERVER['HTTP_X_CRON_TOKEN'] ??
            $req->getQueryParams()['token'] ??
            '';

        if ($cronToken !== $_ENV['CRON_SECRET_TOKEN']) {
            return jsonResponse(['ok' => false, 'mensaje' => 'Token invalido'], 401);
        }

        $method = $req->getMethod();
        error_log("=== BACKUP AUTOMÁTICO INICIADO VIA {$method} ===");

        require_once __DIR__ . '/../src/Services/BackupService.php';
        $backupService = new BackupService();

        $backupResult = $backupService->createFullBackup();
        $cleanupResult = $backupService->deleteOldBackups(10);

        error_log("Backup automático completado exitosamente via {$method}");

        return jsonResponse([
            'ok' => true,
            'mensaje' => "Backup completado via {$method}",
            'backup' => $backupResult,
            'cleanup' => $cleanupResult,
            'method' => $method,
            'timestamp' => date('c')
        ]);
    } catch (Exception $e) {
        error_log('Error en backup automático: ' . $e->getMessage());
        return jsonResponse(['ok' => false, 'mensaje' => $e->getMessage()], 500);
    }
});

// Ruta alternativa más simple para cron-job.org
$app->get('/backup/auto/{token}', function ($req, $res, $args) {
    try {
        $token = $args['token'] ?? '';

        if ($token !== $_ENV['CRON_SECRET_TOKEN']) {
            return jsonResponse(['ok' => false, 'mensaje' => 'Token invalido'], 401);
        }

        error_log("=== BACKUP AUTOMÁTICO SIMPLE INICIADO ===");

        require_once __DIR__ . '/../src/Services/BackupService.php';
        $backupService = new BackupService();

        $backupResult = $backupService->createFullBackup();
        $cleanupResult = $backupService->deleteOldBackups(10);

        error_log("Backup automático simple completado");

        return jsonResponse([
            'ok' => true,
            'mensaje' => 'Backup automático completado',
            'backup' => $backupResult,
            'cleanup' => $cleanupResult,
            'timestamp' => date('c')
        ]);
    } catch (Exception $e) {
        error_log('Error en backup automático: ' . $e->getMessage());
        return jsonResponse(['ok' => false, 'mensaje' => $e->getMessage()], 500);
    }
});


/* corre la aplicación */
$app->run();
