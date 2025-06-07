<?php
declare(strict_types=1);

use Dotenv\Dotenv;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/funciones_CTES_servicios.php';

/* .env */
$dotenv = Dotenv::createUnsafeImmutable(__DIR__ . '/../');
$dotenv->load();

/*error_log('DB_USER: ' . getenv('DB_USER'));
error_log('DB_PASS: ' . getenv('DB_PASS'));*/

/* Permitir CORS */
header('Access-Control-Allow-Origin: http://localhost:3000');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');

/* Si es una solicitud OPTIONS, terminar aquí */
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/* Slim */
$app = AppFactory::create();
$app->addBodyParsingMiddleware();

// Middleware para manejar las solicitudes OPTIONS de CORS
$app->options('/{routes:.+}', function (Request $request, Response $response) {
    return $response->withStatus(200);
});


// helper JSON
function jsonResponse(array $payload, int $code=200): Response {
    try {
        $jsonString = json_encode($payload, JSON_THROW_ON_ERROR);
        $r = new \Slim\Psr7\Response($code);
        $r->getBody()->write($jsonString);
        return $r
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store');
    } catch (\Exception $e) {
        error_log('Error al generar respuesta JSON: ' . $e->getMessage());
        $r = new \Slim\Psr7\Response(500);
        $r->getBody()->write(json_encode(['ok' => false, 'mensaje' => 'Error interno del servidor']));
        return $r->withHeader('Content-Type', 'application/json');
    }
}
/* ---------- RUTAS ---------- */

/* Health-check */
$app->get('/status', fn() => jsonResponse(['ok'=>true]));


$app->post('/login', function (Request $req): Response {
    $data = $req->getParsedBody() ?? [];
    $email = trim($data['email'] ?? '');
    $pass  = trim($data['password'] ?? '');

    if ($email === '' || $pass === '') {
        return jsonResponse(['ok'=>false,'mensaje'=>'Email y contraseña requeridos']);
    }

    $resultado = iniciarSesionConEmail($email, $pass);

    return jsonResponse($resultado);
});



/* POST /reservar-cita  */
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
        // Llamamos a la función que hace TODO: persona-paciente + cita
        $res = pedirCitaNueva($nombre, $email, $tel, $motivo, $fecha);
        error_log('Resultado de pedirCitaNueva: ' . json_encode($res));

        // Devuelve el mismo payload de la función, con el código adecuado
        return jsonResponse(
            ['ok'=>$res['ok'], 'mensaje'=>$res['mensaje']],
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

/** GET /consentimiento — lee el último consentimiento del usuario */
$app->get('/consentimiento', function (Request $req): Response {
    $val = verificarTokenUsuario();
    if ($val === false) {
        return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'],401);
    }
    $id = (int)$val['usuario']['id_persona'];
    $c  = obtenerUltimoConsentimiento($id);
    return jsonResponse([
      'ok'            => true,
      'consentimiento'=> $c,
      'tieneVigente'  => tieneConsentimientoVigente($id),
      'token'         => $val['token']
    ],200);
});

/** POST /consentimiento — crea un nuevo consentimiento */
$app->post('/consentimiento', function (Request $req): Response {
    $val = verificarTokenUsuario();
    if ($val === false) {
        return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'],401);
    }
    $id    = (int)$val['usuario']['id_persona'];
    $canal = strtoupper(trim($req->getParsedBody()['canal'] ?? ''));
    if (!in_array($canal,['PAPEL','WEB','APP'], true)) {
        return jsonResponse(['ok'=>false,'mensaje'=>'Canal inválido'],400);
    }
    $ok = execLogged(
        "INSERT INTO consentimiento (id_persona, fecha_otorgado, canal)
         VALUES (:id, NOW(), :canal)",
        [':id'=>$id,':canal'=>$canal],
        $id, 'consentimiento', $id
    );
    return $ok
      ? jsonResponse(['ok'=>true,'mensaje'=>'Consentimiento otorgado'],200)
      : jsonResponse(['ok'=>false,'mensaje'=>'Error al otorgar'],500);
});

/** POST /consentimiento/revocar — revoca el consentimiento activo */
$app->post('/consentimiento/revocar', function (Request $req): Response {
    $val = verificarTokenUsuario();
    if ($val === false) {
        return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'],401);
    }
    $id = (int)$val['usuario']['id_persona'];
    $ok = execLogged(
        "UPDATE consentimiento
            SET fecha_revocado = NOW()
          WHERE id_persona = :id
            AND fecha_revocado IS NULL",
        [':id'=>$id],
        $id, 'consentimiento', $id
    );
    return $ok
      ? jsonResponse(['ok'=>true,'mensaje'=>'Consentimiento revocado'],200)
      : jsonResponse(['ok'=>false,'mensaje'=>'Error al revocar'],500);
});



// Listar usuarios 
$app->get('/admin/usuarios', function(Request $req) {
    // validar token y extraer usuario + nuevo token
    $val = verificarTokenUsuario();
    if ($val === false) {
        return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'], 401);
    }
    $rol = $val['usuario']['rol'];
    // solo admin o profesional
    $rol = strtolower($val['usuario']['rol']);
    if (!in_array($rol, ['admin','profesional'], true)) {
        return jsonResponse(['ok'=>false,'mensaje'=>'Acceso denegado'], 403);
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


/**  GET /profesionales[?search=txt]  — selector & buscador  */
$app->get('/profesionales', function(Request $req) {
    $txt   = trim($req->getQueryParams()['search'] ?? '');
    $items = getProfesionales($txt);              // ← función actualizada
    return jsonResponse(['ok'=>true,'data'=>$items]);
});

/**  GET /agenda/global[?profId=n&month=m&year=y]   */
$app->get('/agenda/global', function(Request $req) {
    $val = verificarTokenUsuario();                       // protegido
    if ($val === false)
        return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'], 401);

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
    return jsonResponse(['ok'=>true,'data'=>$eventos]);
});

/**  POST /agenda/global   — crear bloque */
$app->post('/agenda/global', function(Request $req) {
    $val = verificarTokenUsuario();
    if ($val === false)
        return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'], 401);

    $data = $req->getParsedBody() ?? [];
    $prof = (int)($data['profId'] ?? 0);      // 0 = todos
    $tipo = trim($data['tipo']   ?? '');
    $ini  = trim($data['inicio'] ?? '');
    $fin  = trim($data['fin']    ?? '');
    $nota = trim($data['nota']   ?? '');
    $actor = (int)$val['usuario']['id_persona']; // Get the logged-in user ID

    if (!$tipo || !$ini || !$fin)
        return jsonResponse(['ok'=>false,'mensaje'=>'Faltan datos requeridos'],400);

    if (!crearBloqueAgenda($prof,$ini,$fin,$tipo,$nota,$actor)) // Pass the actor ID
        return jsonResponse(['ok'=>false,'mensaje'=>'Error al crear evento'],500);

    return jsonResponse(['ok'=>true,'mensaje'=>'Evento creado']);
});

/**  DELETE /agenda/global/{id}  — elimina bloque **o** cita */
$app->delete('/agenda/global/{id}', function(Request $req, Response $res, array $args){
    $val = verificarTokenUsuario();
    if ($val === false)
        return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'], 401);

    $id = (int)$args['id'];
    $actor = (int)$val['usuario']['id_persona']; // Get the logged-in user ID
    
    if (!eliminarEvento($id, $actor))         // Pass the actor ID
        return jsonResponse(['ok'=>false,'mensaje'=>'No se pudo eliminar'],500);

    return jsonResponse(['ok'=>true]);
});

/* ----------  NOTIFICACIONES  ---------- */

/* GET /notificaciones */
$app->get('/notificaciones', function ($req) {
    $val = verificarTokenUsuario();
    if ($val === false)
        return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'],401);

    $uid = (int)$val['usuario']['id_persona'];
    $rol = strtolower($val['usuario']['rol']);

    $datos = obtenerNotificacionesPendientes($uid,$rol);
    return jsonResponse(['ok'=>true,'data'=>$datos]);
});

/* POST /notificaciones/{id}  body:{accion:'CONFIRMAR'|'RECHAZAR'} */
$app->post('/notificaciones/{id}', function ($req,$res,$args){
    $val = verificarTokenUsuario();
    if ($val === false)
        return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'],401);

    $idCita = (int)$args['id'];
    $acc    = strtoupper(trim(($req->getParsedBody()['accion']??'')));
    if (!in_array($acc,['CONFIRMAR','RECHAZAR'],true))
        return jsonResponse(['ok'=>false,'mensaje'=>'Acción inválida'],400);

    $uid = (int)$val['usuario']['id_persona'];
    $rol = strtolower($val['usuario']['rol']);

    // Loguear para depuración
    error_log("Procesando notificación: ID=$idCita, Acción=$acc, Usuario=$uid, Rol=$rol");

    $ok = procesarNotificacion($idCita,$acc,$uid,$rol);
    return $ok
      ? jsonResponse(['ok'=>true])
      : jsonResponse(['ok'=>false,'mensaje'=>'No se pudo procesar'],500);
});

// Handler específico para OPTIONS en /notificaciones/{id}
$app->options('/notificaciones/{id}', function ($request, $response, $args) {
    $origin = $request->getHeaderLine('Origin');
    $allowedOrigins = ['http://localhost:3000', 'http://127.0.0.1:3000'];
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


$app->get('/', fn() => jsonResponse(['ok'=>true, 'mensaje'=>'API Slim funcionando']));



/* GET /admin/usuarios/buscar?email=...&tel=...  */
$app->get('/admin/usuarios/buscar', function ($req) {
    $val = verificarTokenUsuario();
    if ($val === false || strtolower($val['usuario']['rol'])!=='admin')
        return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'],401);

    $q = $req->getQueryParams();
    $r = buscarPersona($q['email']??'', $q['tel']??'');
    return jsonResponse(['ok'=>true,'data'=>$r]);
});

/* POST /admin/usuarios*/
$app->post('/admin/usuarios', function ($req) {
    $val = verificarTokenUsuario();
    if ($val === false || strtolower($val['usuario']['rol'])!=='admin')
        return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'],401);

    // Obtener el ID del usuario logueado
    $actorId = $val['usuario']['id_persona'];
    
    $body = $req->getParsedBody() ?? [];
    $tipo = strtoupper(trim($body['tipo'] ?? ''));
    $pdat = $body['persona'] ?? [];
    $xdat = $body['extra']   ?? [];

    if (!in_array($tipo,['PROFESIONAL','PACIENTE'],true))
        return jsonResponse(['ok'=>false,'mensaje'=>'Tipo inválido'],400);

    // Pasar el ID del actor a la función upsertPersona
    $idPersona = actualizarOInsertarPersona($pdat, $tipo, $actorId);

    $ok = ($tipo==='PROFESIONAL')
        ? upsertProfesional($idPersona,$xdat)
        : upsertPaciente($idPersona,$xdat);

    return $ok
      ? jsonResponse(['ok'=>true,'id'=>$idPersona])
      : jsonResponse(['ok'=>false,'mensaje'=>'No se pudo guardar'],500);
});

/* POST /crear-pass { uid, password } */
$app->post('/crear-pass', function ($req) {
    $b = $req->getParsedBody() ?? [];
    $uid = $b['uid'] ?? '';
    $pass= $b['password'] ?? '';

    if (!$uid || strlen($pass)<8)
        return jsonResponse(['ok'=>false,'msg'=>'Datos inválidos'],400);
    
    $id = decodificarUid($uid);
    $baseDatos = conectar();

    /* la contraseña solo puede crearse cuando aún está vacía */
    $consulta = $baseDatos->prepare("
      SELECT password_hash FROM persona WHERE id_persona = ? LIMIT 1");
    $consulta->execute([$id]);
    $row = $consulta->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['password_hash'] !== null)
        return jsonResponse(['ok'=>false,'msg'=>'Enlace caducado'],400);    $consulta = $baseDatos->prepare("
      UPDATE persona
         SET password_hash = SHA2(:p,256),
             password_hash_creado = NOW()
       WHERE id_persona = :id");
    $consulta->execute([':p'=>$pass, ':id'=>$id]);

    return jsonResponse(['ok'=>true]);
});


$app->get('/admin/usuarios/{id}', function (Request $req, Response $res, array $args) {
    $val = verificarTokenUsuario();
    if ($val === false || strtolower($val['usuario']['rol']) !== 'admin') {
        return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'],401);
    }
    $id = (int)$args['id'];
    $data = getUsuarioDetalle($id);
    if (!$data) {
        return jsonResponse(['ok'=>false,'mensaje'=>'No encontrado'],404);
    }
    return jsonResponse(['ok'=>true,'data'=>$data]);
});
/** DELETE /admin/usuarios/{id} — elimina persona */
$app->delete('/admin/usuarios/{id}', function ($req,$res,$args) {
    $val = verificarTokenUsuario();
    if ($val === false || strtolower($val['usuario']['rol'])!=='admin')
        return jsonResponse(['ok'=>false,'msg'=>'No autorizado'],401);

    $out = eliminarUsuario((int)$args['id'], (int)$val['usuario']['id_persona']);
    if (!$out['ok'])
        return jsonResponse(['ok'=>false,'msg'=>$out['msg']], $out['code']);
    return jsonResponse(['ok'=>true]);
});


/** POST /admin/borrar-usuario/{id} — desactiva usuario en vez de eliminarlo */
$app->post('/admin/borrar-usuario/{id}', function ($req, $res, $args) {
    $val = verificarTokenUsuario();
    if ($val === false || strtolower($val['usuario']['rol']) !== 'admin')
        return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'], 401);

    $id = (int)$args['id'];
    $actor = (int)$val['usuario']['id_persona'];
    
    // Verificar si tiene citas confirmadas o pendientes
    $baseDatos = conectar();
    $consulta = $baseDatos->prepare("
        SELECT COUNT(*) 
        FROM cita 
        WHERE (id_paciente = ? OR id_profesional = ?) 
        AND estado IN ('CONFIRMADA', 'PENDIENTE_VALIDACION', 'SOLICITADA')
    ");
    $consulta->execute([$id, $id]);
    $citasActivas = (int)$consulta->fetchColumn();
    
    if ($citasActivas > 0) {
        return jsonResponse([
            'ok' => false,
            'mensaje' => 'No se puede desactivar el usuario porque tiene citas confirmadas o pendientes'
        ], 409);
    }
    
    try {
        // Desactivar el usuario en lugar de eliminarlo
        $consulta = $baseDatos->prepare("UPDATE persona SET activo = 0 WHERE id_persona = ?");
        $ok = $consulta->execute([$id]);
          if ($ok) {
            // Registrar la acción en los logs
            registrarActividad(
                $actor, 
                $id, 
                'persona', 
                'activo', 
                '1', 
                '0',
                'UPDATE'
            );
            return jsonResponse(['ok'=>true, 'mensaje'=>'Usuario desactivado correctamente']);
        } else {
            return jsonResponse(['ok'=>false, 'mensaje'=>'Error al desactivar usuario'], 500);
        }
    } catch (Exception $e) {
        return jsonResponse(['ok'=>false, 'mensaje'=>'Error: '.$e->getMessage()], 500);
    }
});


/** PUT /admin/usuarios/{id} — actualiza datos de usuario existente */
$app->put('/admin/usuarios/{id}', function ($req, $res, $args) {
    $val = verificarTokenUsuario();
    if ($val === false || strtolower($val['usuario']['rol']) !== 'admin')
        return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'], 401);

    $id = (int)$args['id'];
    $actor = (int)$val['usuario']['id_persona'];
    $body = $req->getParsedBody() ?? [];
    
    $tipo = strtoupper(trim($body['tipo'] ?? ''));
    $pdat = $body['persona'] ?? [];
    $xdat = $body['extra'] ?? [];

    if (!in_array($tipo, ['PROFESIONAL', 'PACIENTE'], true))
        return jsonResponse(['ok'=>false,'mensaje'=>'Tipo inválido'], 400);

    try {
        // Usar el nuevo parámetro forceUpdateId para forzar la actualización del usuario con este ID
        $idPersona = actualizarOInsertarPersona($pdat, $tipo, $actor, $id);

        // Actualizar datos específicos según tipo
        $ok = ($tipo === 'PROFESIONAL')
            ? upsertProfesional($idPersona, $xdat, $actor)
            : upsertPaciente($idPersona, $xdat);

        return $ok
            ? jsonResponse(['ok'=>true,'id'=>$idPersona])
            : jsonResponse(['ok'=>false,'mensaje'=>'No se pudo actualizar el usuario'], 500);
    } catch (Exception $e) {
        return jsonResponse(['ok'=>false,'mensaje'=>$e->getMessage()], 400);
    }
});


/* =========  INFORMES & LOGS  ========= */

/* GET /admin/informes — estadísticas del mes en curso */
$app->get('/admin/informes', function (Request $req, Response $res) {

    $val = verificarTokenUsuario();
    if ($val === false || strtolower($val['usuario']['rol']) !== 'admin') {
        return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'], 401);
    }

    $y = (int)($req->getQueryParams()['year']  ?? date('Y'));
    $m = (int)($req->getQueryParams()['month'] ?? date('n'));

    $data = getInformeMes($y, $m);
    return jsonResponse(['ok'=>true,'data'=>$data]);
});

/* GET /admin/logs?year=YYYY&month=MM */

$app->get('/admin/logs', function (Request $req, Response $res) {

    /* auth - solo admin */
    $val = verificarTokenUsuario();
    if ($val === false || strtolower($val['usuario']['rol']) !== 'admin') {
        return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'], 401);
    }

    /* año y mes*/
    $y = (int)($req->getQueryParams()['year']  ?? date('Y'));
    $m = (int)($req->getQueryParams()['month'] ?? date('n'));

    /* genera CSV */
    $csv = exportLogsCsv($y, $m);          // ← función añadida más abajo

    /* lo devolvemos como descarga */
    $file = sprintf('logs_%d_%02d.csv', $y, $m);
    $res  = $res
        ->withHeader('Content-Type',        'text/csv; charset=UTF-8')
        ->withHeader('Content-Disposition', "attachment; filename=\"$file\"");

    $res->getBody()->write($csv);
    return $res;
});

/*--------------PROFESIONAL----------------*/
/* GET  /prof/perfil  — obtener datos del profesional logeado */
$app->get('/prof/perfil', function ($req) {
    $val = verificarTokenUsuario();
    if ($val === false || strtolower($val['usuario']['rol']) !== 'profesional') {
        return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'],401);
    }
    $id = (int)$val['usuario']['id_persona'];
    $data = getUsuarioDetalle($id);
    return jsonResponse(['ok'=>true,'data'=>$data]);
});

/* PUT  /prof/perfil  — actualizar únicamente su propia persona */
$app->put('/prof/perfil', function ($req) {
    $val = verificarTokenUsuario();
    if ($val === false || strtolower($val['usuario']['rol']) !== 'profesional') {
        return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'],401);
    }
    $actor = (int)$val['usuario']['id_persona'];
    $body  = $req->getParsedBody() ?? [];
    $in    = $body['persona'] ?? [];

    // Filtrar solo campos de persona que puede editar
    $permitidos = [
      'nombre','apellido1','apellido2','email','telefono',
      'fecha_nacimiento','tipo_via','nombre_calle','numero','escalera',
      'piso','puerta','codigo_postal','ciudad','provincia','pais'
    ];
    $datos = array_intersect_key($in, array_flip($permitidos));
    if (!$datos) {
        return jsonResponse(['ok'=>false,'mensaje'=>'Nada que actualizar'],400);
    }

    // upsertPersona: actor = él mismo, forceUpdate = su propio id
    try {
        $id = actualizarOInsertarPersona($datos,'PROFESIONAL',$actor,$actor);
        return jsonResponse(['ok'=>true]);
    } catch (Exception $e) {
        return jsonResponse(['ok'=>false,'mensaje'=>$e->getMessage()],400);
    }
});

/* GET /prof/pacientes — lista solo de mis pacientes */
$app->get('/prof/pacientes', function ($req){
    $val = verificarTokenUsuario();
    if($val===false || strtolower($val['usuario']['rol'])!=='profesional')
        return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'],401);

    $idPr = (int)$val['usuario']['id_persona'];
    $pacientes = getPacientesProfesional($idPr);
    
    return jsonResponse(['ok'=>true,'pacientes'=>$pacientes,
                         'token'=>$val['token']]);
});

/* GET  /prof/pacientes/{id} — datos completos de MI paciente */
$app->get('/prof/pacientes/{id}', function ($req,$res,$args){
    $val = verificarTokenUsuario();
    if ($val===false || strtolower($val['usuario']['rol'])!=='profesional')
        return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'],401);

    $idProf=(int)$val['usuario']['id_persona'];
    $idPac =(int)$args['id'];

    // Verificar que el paciente pertenezca a este profesional
    if(!verificarPacienteProfesional($idPac, $idProf))
        return jsonResponse(['ok'=>false,'mensaje'=>'Prohibido'],403);

    $out = getDetallesPacienteProfesional($idPac, $idProf);
    return jsonResponse(['ok'=>true,'data'=>$out,'token'=>$val['token']]);
});

/* PUT /prof/pacientes/{id} — actualiza persona + consentimiento */
$app->put('/prof/pacientes/{id}', function($req,$res,$args){
    $val = verificarTokenUsuario();
    if ($val===false || strtolower($val['usuario']['rol'])!=='profesional')
        return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'],401);

    $idProf=(int)$val['usuario']['id_persona'];
    $idPac =(int)$args['id'];

    /* control de propiedad*/
    if(!verificarPacienteProfesional($idPac, $idProf))
        return jsonResponse(['ok'=>false,'mensaje'=>'Prohibido'],403);

    $b   = $req->getParsedBody();
    actualizarOInsertarPersona ($b['persona']  ?? [],'PACIENTE',$idProf,$idPac);
    upsertPaciente($idPac,$b['paciente'] ?? []);
    if(!empty($b['paciente']['tutor'])) upsertTutor($b['paciente']['tutor']);

    registrarConsentimiento($idPac, (bool)($b['rgpd']??false), $idProf);

    return jsonResponse(['ok'=>true,'token'=>$val['token']]);
});



/** POST /prof/pacientes/{id}/tareas — crea tratamiento con título + descripción + opcional archivo */
$app->post('/prof/pacientes/{id}/tareas', function($req,$res,$args){
    $val = verificarTokenUsuario();
    if($val===false || strtolower($val['usuario']['rol'])!=='profesional')
        return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'],401);

    $idPac  = (int)$args['id'];
    $idProf = (int)$val['usuario']['id_persona'];
    
    // Verificar que sea su paciente
    if(!verificarPacienteProfesional($idPac, $idProf))
        return jsonResponse(['ok'=>false,'mensaje'=>'Prohibido'],403);

    // Lectura de datos y fichero
    $d = $req->getParsedBody();
    $file = $req->getUploadedFiles()['file'] ?? null;

    // Validación básica
    if (empty($d['titulo'])) {
        return jsonResponse(['ok'=>false,'mensaje'=>'El título es obligatorio'], 400);
    }

    try {
        // Crear tratamiento con todos los campos
        crearTratamiento(
            $idPac,
            $idProf,
            $d['titulo'] ?? '',
            $d['descripcion'] ?? '',
            $file,
            $d['fecha_inicio'] ?? null,
            $d['fecha_fin'] ?? null,
            isset($d['frecuencia_sesiones']) ? (int)$d['frecuencia_sesiones'] : null        );
        
        // Registrar en logs
        registrarActividad(
            $idProf, 
            $idPac,
            'tratamiento',
            null,
            null,
            $d['titulo'],
            'INSERT'
        );
        
        return jsonResponse(['ok'=>true, 'mensaje'=>'Tratamiento creado correctamente']);
    } catch (Throwable $e) {
        error_log('Error al crear tratamiento: ' . $e->getMessage());
        return jsonResponse(['ok'=>false, 'mensaje'=>'Error: ' . $e->getMessage()], 500);
    }
});


/** DELETE /prof/pacientes/{id}/tareas/{idTratamiento} — elimina un tratamiento */
$app->delete('/prof/pacientes/{id}/tareas/{idTratamiento}', function($req, $res, $args) {
    $val = verificarTokenUsuario();
    if($val === false || strtolower($val['usuario']['rol']) !== 'profesional')
        return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);

    $idPac = (int)$args['id'];
    $idTratamiento = (int)$args['idTratamiento'];
    $idProf = (int)$val['usuario']['id_persona'];

    // Verificar que sea su paciente
    if(!verificarPacienteProfesional($idPac, $idProf))
        return jsonResponse(['ok' => false, 'mensaje' => 'Prohibido'], 403);
        
    try {
        eliminarTratamiento($idTratamiento, $idProf, $idPac);
        return jsonResponse(['ok' => true, 'mensaje' => 'Tarea eliminada correctamente']);
    } catch (Throwable $e) {
        error_log('Error al eliminar tarea: ' . $e->getMessage());
        return jsonResponse(['ok' => false, 'mensaje' => 'Error: ' . $e->getMessage()], 500);
    }
});

/** POST /prof/pacientes/{id}/documentos — sube documento al historial clínico */
$app->post('/prof/pacientes/{id}/documentos', function($req, $res, $args){
    $val = verificarTokenUsuario();
    if($val === false || strtolower($val['usuario']['rol']) !== 'profesional')
        return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);

    $idPac = (int)$args['id'];
    $idProf = (int)$val['usuario']['id_persona'];
    
    // Verificar que sea su paciente 
    $db = conectar();
    $q = $db->prepare("SELECT 1 FROM cita WHERE id_paciente = ? AND id_profesional = ? LIMIT 1");
    $q->execute([$idPac, $idProf]);
    if(!$q->fetch()) return jsonResponse(['ok' => false, 'mensaje' => 'Prohibido'], 403);    // Obtener archivo subido
    $file = $req->getUploadedFiles()['file'] ?? null;    // Obtener datos del formulario
    $d = $req->getParsedBody();
    $diagnosticoPreliminar = $d['diagnostico_preliminar'] ?? '';

    
    if (!$file) {
        return jsonResponse(['ok' => false, 'mensaje' => 'No se proporcionó ningún archivo'], 400);
    }
    
    if ($file->getError() !== UPLOAD_ERR_OK) {
        return jsonResponse(['ok' => false, 'mensaje' => 'Error al subir el archivo'], 400);
    }    try {
        // Crear documento en el historial
        $resultado = crearDocumentoHistorial($idPac, $idProf, $file, $diagnosticoPreliminar);        
        // Registrar en logs
        registrarActividad(
            $idProf, 
            $idPac,
            'documento_historial',
            null,
            null,
            'Documento subido al historial',
            'INSERT'
        );
        
        return jsonResponse($resultado);
    } catch (Throwable $e) {
        error_log('Error al subir documento al historial: ' . $e->getMessage());
        return jsonResponse(['ok' => false, 'mensaje' => 'Error: ' . $e->getMessage()], 500);
    }
});

/** DELETE /prof/pacientes/{id}/documentos/{doc_id} — elimina documento del historial clínico */
$app->delete('/prof/pacientes/{id}/documentos/{doc_id}', function($req, $res, $args){
    $val = verificarTokenUsuario();
    if($val === false || strtolower($val['usuario']['rol']) !== 'profesional')
        return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);

    $idPac = (int)$args['id'];
    $idDoc = (int)$args['doc_id'];
    $idProf = (int)$val['usuario']['id_persona'];
    
    // Verificar que sea su paciente 
    if(!verificarPacienteProfesional($idPac, $idProf))
        return jsonResponse(['ok' => false, 'mensaje' => 'Prohibido'], 403);
    
    try {
        eliminarDocumentoHistorial($idDoc, $idPac, $idProf);
        
        return jsonResponse([
            'ok' => true,
            'mensaje' => 'Documento eliminado correctamente'
        ]);
    } catch (Throwable $e) {
        error_log('Error al eliminar documento: ' . $e->getMessage());
        
        if ($e->getMessage() === 'Documento no encontrado') {
            return jsonResponse(['ok' => false, 'mensaje' => 'Documento no encontrado'], 404);
        }
        
        return jsonResponse(['ok' => false, 'mensaje' => 'Error: ' . $e->getMessage()], 500);
    }
});

/* PUT /prof/pacientes/{id}/documentos/{doc_id} — actualiza diagnóstico final de un documento */
$app->put('/prof/pacientes/{id}/documentos/{doc_id}', function($req, $res, $args){
    $val = verificarTokenUsuario();
    if($val === false || strtolower($val['usuario']['rol']) !== 'profesional')
        return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);

    $idPac = (int)$args['id'];
    $idDoc = (int)$args['doc_id'];
    $idProf = (int)$val['usuario']['id_persona'];
    
    // Verificar que sea su paciente 
    $db = conectar();
    $q = $db->prepare("SELECT 1 FROM cita WHERE id_paciente = ? AND id_profesional = ? LIMIT 1");
    $q->execute([$idPac, $idProf]);
    if(!$q->fetch()) return jsonResponse(['ok' => false, 'mensaje' => 'Prohibido'], 403);
      // Obtener datos
    $body = $req->getParsedBody();
    $diagnosticoFinal = $body['diagnostico_final'] ?? '';
    
    // Debug log
    /*error_log('PUT request to update diagnosis - ID Paciente: ' . $idPac . ', ID Documento: ' . $idDoc . ', Diagnóstico Final: ' . $diagnosticoFinal);*/
    
    try {
        $db->beginTransaction();
        
        // Obtener información del documento
        $stDoc = $db->prepare("
            SELECT d.*, h.id_paciente, h.id_historial
            FROM documento_clinico d
            JOIN historial_clinico h ON d.id_historial = h.id_historial
            WHERE d.id_documento = ? AND h.id_paciente = ?
        ");
        $stDoc->execute([$idDoc, $idPac]);
        $documento = $stDoc->fetch(PDO::FETCH_ASSOC);
        
        if (!$documento) {
            return jsonResponse(['ok' => false, 'mensaje' => 'Documento no encontrado'], 404);
        }        
        // Actualizar el diagnóstico final en el historial
        $stUpdate = $db->prepare("
            UPDATE historial_clinico 
            SET diagnostico_final = ? 
            WHERE id_historial = ?
        ");
        $stUpdate->execute([$diagnosticoFinal, $documento['id_historial']]);
        
        //  Registrar en logs
        registrarActividad(
            $idProf, 
            $idPac,
            'historial_clinico',
            'diagnostico_final',
            $documento['diagnostico_final'] ?? '',
            $diagnosticoFinal,
            'UPDATE'
        );
        $db->commit();
        
        return jsonResponse([
            'ok' => true,
            'mensaje' => 'Diagnóstico final actualizado correctamente'
        ]);
    } catch (Throwable $e) {
        $db->rollBack();
        error_log('Error al actualizar diagnóstico: ' . $e->getMessage());
        return jsonResponse(['ok' => false, 'mensaje' => 'Error: ' . $e->getMessage()], 500);
    }
});

/* GET para obtener horas disponibles */
$app->get('/prof/horas-disponibles', function($req, $res, $args) {
    $val = verificarTokenUsuario();
    if ($val === false || strtolower($val['usuario']['rol']) !== 'profesional')
        return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);

    $profesionalId = (int)($req->getQueryParams()['profesional_id'] ?? 0);
    $fecha = $req->getQueryParams()['fecha'] ?? '';

    if (!$profesionalId || !$fecha) {
        return jsonResponse(['ok' => false, 'mensaje' => 'Faltan parámetros requeridos'], 400);
    }

    try {
        $horas = obtenerHorasDisponibles($profesionalId, $fecha);
        
        return jsonResponse([
            'ok' => true, 
            'horas' => $horas,
            'token' => $val['token']
        ]);

    } catch (Exception $e) {
        error_log("Error en horas-disponibles: " . $e->getMessage());
        return jsonResponse(['ok' => false, 'mensaje' => 'Error interno del servidor'], 500);
    }
});

/* POST para reprogramar citas*/
$app->post('/prof/citas/{id}/accion', function($req, $res, $args) {
    $val = verificarTokenUsuario();
    if ($val === false || strtolower($val['usuario']['rol']) !== 'profesional')
        return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);

    $idProf = (int)$val['usuario']['id_persona'];
    $citaId = (int)$args['id'];
    $body = $req->getParsedBody();
    $accion = $body['accion'] ?? '';

    if (!$accion) {
        return jsonResponse(['ok' => false, 'mensaje' => 'Acción requerida'], 400);
    }

    try {
        // Verificar que la cita pertenece al profesional
        $db = conectar();
        $stmtVerificar = $db->prepare("
            SELECT * FROM cita 
            WHERE id_cita = ? AND id_profesional = ?
        ");
        $stmtVerificar->execute([$citaId, $idProf]);
        $cita = $stmtVerificar->fetch(PDO::FETCH_ASSOC);

        if (!$cita) {
            return jsonResponse(['ok' => false, 'mensaje' => 'Cita no encontrada'], 404);
        }

        // Procesar la acción usando la función existente
        procesarAccionCitaProfesional($citaId, $body);

        return jsonResponse([
            'ok' => true, 
            'mensaje' => 'Acción procesada exitosamente',
            'token' => $val['token']
        ]);

    } catch (Exception $e) {
        error_log("Error en accion cita: " . $e->getMessage());
        return jsonResponse(['ok' => false, 'mensaje' => $e->getMessage()], 500);
    }
});

/* GET para obtener días bloqueados */
$app->get('/prof/dias-bloqueados', function($req, $res, $args) {
   $val = verificarTokenUsuario();
   if ($val === false || strtolower($val['usuario']['rol']) !== 'profesional')
       return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);

   $profesionalId = (int)($req->getQueryParams()['profesional_id'] ?? 0);
   $fechaInicio = $req->getQueryParams()['fecha_inicio'] ?? '';
   $fechaFin = $req->getQueryParams()['fecha_fin'] ?? '';

   if (!$profesionalId || !$fechaInicio || !$fechaFin) {
       return jsonResponse(['ok' => false, 'mensaje' => 'Faltan parámetros requeridos'], 400);
   }

   try {
       $diasBloqueados = obtenerDiasBloqueados($profesionalId, $fechaInicio, $fechaFin);
       
       return jsonResponse([
           'ok' => true, 
           'dias_bloqueados' => $diasBloqueados,
           'token' => $val['token']
       ]);

   } catch (Exception $e) {
       error_log("Error en dias-bloqueados: " . $e->getMessage());
       return jsonResponse(['ok' => false, 'mensaje' => 'Error interno del servidor'], 500);
   }
});



// POST para migrar documentos existentes (solo para desarrollo/mantenimiento)
$app->post('/migrate/documentos', function (Request $request, Response $response) {
    try {
        $db = conectar();
        
        $result = [
            'ok' => true,
            'mensaje' => 'Migración de documentos completada',
            'detalles' => []
        ];
        
        // Mostrar documentos sin tratamiento
        $stmt = $db->query("
            SELECT 
                d.id_documento,
                d.id_historial,
                d.id_profesional,
                d.ruta,
                d.fecha_subida
            FROM documento_clinico d 
            WHERE d.id_tratamiento IS NULL
        ");
        $documentosSinTratamiento = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $result['detalles']['documentos_sin_tratamiento'] = count($documentosSinTratamiento);
        
        if (empty($documentosSinTratamiento)) {
            $result['mensaje'] = 'No hay documentos sin tratamiento asociado';
            return jsonResponse($result);
        }
        
        // Ejecutar la actualización
        $stmt = $db->prepare("
            UPDATE documento_clinico d
            SET d.id_tratamiento = (
                SELECT t.id_tratamiento
                FROM tratamiento t
                WHERE t.id_historial = d.id_historial 
                  AND t.id_profesional = d.id_profesional
                ORDER BY t.fecha_inicio DESC
                LIMIT 1
            )
            WHERE d.id_tratamiento IS NULL
              AND EXISTS (
                SELECT 1 
                FROM tratamiento t
                WHERE t.id_historial = d.id_historial 
                  AND t.id_profesional = d.id_profesional
              )
        ");
        
        $updateResult = $stmt->execute();
        $affectedRows = $stmt->rowCount();
        
        $result['detalles']['documentos_actualizados'] = $affectedRows;
        
        if (!$updateResult) {
            throw new Exception('Error al ejecutar la actualización');
        }
        
        // Verificar resultado
        $stmt = $db->query("
            SELECT COUNT(*) as total_sin_tratamiento
            FROM documento_clinico 
            WHERE id_tratamiento IS NULL
        ");
        $remainingCount = $stmt->fetchColumn();
        
        $result['detalles']['documentos_restantes_sin_tratamiento'] = $remainingCount;
        
        return jsonResponse($result);
        
    } catch (Exception $e) {
        return jsonResponse([
            'ok' => false,
            'mensaje' => 'Error en la migración: ' . $e->getMessage()
        ], 500);
    }
});


/* ---------- RUTAS PACIENTE ---------- */

/* GET /pac/perfil — obtiene perfil del paciente logueado */
$app->get('/pac/perfil', function(Request $req): Response {
  try {
    // Validación de token y rol
    $val = verificarTokenUsuario();
    if ($val === false) {
      return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'],401);
    }
    if ($val['usuario']['rol'] !== 'PACIENTE') {
      return jsonResponse(['ok'=>false,'mensaje'=>'Acceso denegado'], 403);
    }
    
    // Obtener ID del paciente logueado
    $idPaciente = (int)$val['usuario']['id_persona'];
    if ($idPaciente <= 0) {
      error_log("ID de paciente inválido: " . $idPaciente);
      return jsonResponse(['ok'=>false,'mensaje'=>'ID de paciente no válido'], 400);
    }
    
    // Obtener datos completos
    $data = getUsuarioDetalle($idPaciente);
    if (!$data) {
      error_log("No se encontraron datos para el paciente ID: " . $idPaciente);
      return jsonResponse(['ok'=>false,'mensaje'=>'No se encontraron datos del paciente'], 404);
    }
    
    // Debug
    /*error_log("Datos del paciente ID " . $idPaciente . ": " . json_encode($data));*/
    

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
    return jsonResponse(['ok'=>false,'mensaje'=>'Error al cargar el perfil: ' . $e->getMessage()], 500);
  }
});

/* PUT /pac/perfil — actualiza perfil del paciente actual */
$app->put('/pac/perfil', function(Request $req): Response {
  $val = verificarTokenUsuario();
  if ($val === false) {
      return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'],401);
  }
  if ($val['usuario']['rol'] !== 'PACIENTE') {
      return jsonResponse(['ok'=>false,'mensaje'=>'Acceso denegado'], 403);
  }
  
  try {
    $idPaciente = (int)$val['usuario']['id_persona'];
    $data = $req->getParsedBody() ?? [];
    
    // Actualizar solo datos de contacto 
    $permitidos = [
      'email','telefono','tipo_via','nombre_calle','numero','escalera',
      'piso','puerta','codigo_postal','ciudad','provincia','pais'
    ];
    $personaData = array_intersect_key($data['persona'] ?? [], array_flip($permitidos));
    
    if (!empty($personaData)) {
      // Auditoría
      error_log("Paciente ID $idPaciente actualizando datos de contacto: " . json_encode($personaData));
      actualizarOInsertarPersona($personaData, 'PACIENTE', $idPaciente, $idPaciente);
    }
    

    return jsonResponse([
      'ok' => true,
      'mensaje' => 'Datos de contacto actualizados',
      'token' => $val['token']
    ]);
  } catch (\Exception $e) {
    error_log("Error actualizando perfil paciente ID $idPaciente: " . $e->getMessage());
    return jsonResponse(['ok'=>false,'mensaje'=>$e->getMessage()], 500);
  }
});

/* GET /pac/tareas — obtiene tareas asignadas al paciente actual */
$app->get('/pac/tareas', function(Request $req): Response {
    try {
        // Validar token y rol
        $val = verificarTokenUsuario();
        if ($val === false) {
            return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'], 401);
        }
        
        if ($val['usuario']['rol'] !== 'PACIENTE') {
            return jsonResponse(['ok'=>false,'mensaje'=>'Acceso denegado'], 403);
        }
        
        // Obtener ID del paciente logueado
        $idPaciente = (int)$val['usuario']['id_persona'];
        if ($idPaciente <= 0) {
            error_log("ID de paciente inválido: " . $idPaciente);
            return jsonResponse(['ok'=>false,'mensaje'=>'ID de paciente no válido'], 400);
        }
        
        // Obtener tareas del paciente
        $tareas = getTareasPaciente($idPaciente);
        
        // Debug
      /*  error_log("Obtenidas " . count($tareas) . " tareas para paciente ID: $idPaciente");*/
        
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

/* GET /pac/historial — obtiene documentos del historial clínico del paciente actual */
$app->get('/pac/historial', function(Request $req): Response {
    try {
        // Validar token y rol
        $val = verificarTokenUsuario();
        if ($val === false) {
            return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'], 401);
        }
        
        if ($val['usuario']['rol'] !== 'PACIENTE') {
            return jsonResponse(['ok'=>false,'mensaje'=>'Acceso denegado'], 403);
        }
        
        // Obtener ID del paciente logueado
        $idPaciente = (int)$val['usuario']['id_persona'];
        if ($idPaciente <= 0) {
            error_log("ID de paciente inválido: " . $idPaciente);
            return jsonResponse(['ok'=>false,'mensaje'=>'ID de paciente no válido'], 400);
        }
        
        // Obtener documentos del historial clínico
        $documentos = getHistorialPaciente($idPaciente);
        
        // Debug
       /* error_log("Obtenidos " . count($documentos) . " documentos del historial para paciente ID: $idPaciente");*/
        
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

/* GET /pac/citas — obtiene citas del paciente actual */
$app->get('/pac/citas', function(Request $req): Response {
    try {
        // Validar token y rol
        $val = verificarTokenUsuario();
        if ($val === false) {
            return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'], 401);
        }
        
        if ($val['usuario']['rol'] !== 'PACIENTE') {
            return jsonResponse(['ok'=>false,'mensaje'=>'Acceso denegado'], 403);
        }
        
        // Obtener ID del paciente logeado
        $idPaciente = (int)$val['usuario']['id_persona'];
        if ($idPaciente <= 0) {
            error_log("ID de paciente inválido: " . $idPaciente);
            return jsonResponse(['ok'=>false,'mensaje'=>'ID de paciente no válido'], 400);
        }
        
        // Obtener citas del paciente sin filtrar por profesional
        $citas = getCitasPaciente($idPaciente);
        
        // Debug
        error_log("Obtenidas " . count($citas) . " citas para paciente ID: $idPaciente");
        
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

/* POST /pac/citas/{id}/solicitud — procesa solicitudes de cambio/cancelación */
$app->post('/pac/citas/{id}/solicitud', function(Request $req, Response $res, array $args): Response {
    try {
        // Validar token y rol
        $val = verificarTokenUsuario();
        if ($val === false) {
            return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'], 401);
        }
        
        if ($val['usuario']['rol'] !== 'PACIENTE') {
            return jsonResponse(['ok'=>false,'mensaje'=>'Acceso denegado'], 403);
        }
        
        $idPaciente = (int)$val['usuario']['id_persona'];
        $idCita = (int)$args['id'];
        $body = $req->getParsedBody();
        
        $accion = strtoupper(trim($body['accion'] ?? ''));
        $nuevaFecha = $body['nueva_fecha'] ?? null;
        
        if (!in_array($accion, ['CAMBIAR', 'CANCELAR'])) {
            return jsonResponse(['ok'=>false,'mensaje'=>'Acción no válida'], 400);
        }
        
        // Procesar la solicitud
        $resultado = procesarSolicitudCitaPaciente($idCita, $accion, $idPaciente, $nuevaFecha);
        
        if ($resultado['ok']) {
            return jsonResponse([
                'ok' => true,
                'mensaje' => $resultado['mensaje'],
                'token' => $val['token']
            ]);
        } else {
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

/* GET /pac/profesional/{id}/dias-bloqueados — obtener días bloqueados para pacientes */
$app->get('/pac/profesional/{id}/dias-bloqueados', function(Request $req, Response $res, array $args) {
    $val = verificarTokenUsuario();
    if ($val === false || $val['usuario']['rol'] !== 'PACIENTE') {
        return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'], 401);
    }
    
    $profId = (int)$args['id'];
    $fechaInicio = $req->getQueryParams()['fecha_inicio'] ?? '';
    $fechaFin = $req->getQueryParams()['fecha_fin'] ?? '';
    
    if (!$fechaInicio || !$fechaFin) {
        return jsonResponse(['ok'=>false,'mensaje'=>'Faltan parámetros'], 400);
    }
    
    try {
        $diasBloqueados = obtenerDiasBloqueados($profId, $fechaInicio, $fechaFin);
        return jsonResponse([
            'ok' => true,
            'dias_bloqueados' => $diasBloqueados,
            'token' => $val['token']
        ]);
    } catch (Exception $e) {
        return jsonResponse(['ok'=>false,'mensaje'=>'Error interno'], 500);
    }
});

/* GET /pac/profesional/{id}/horas-disponibles — obtener horas disponibles para pacientes */
$app->get('/pac/profesional/{id}/horas-disponibles', function(Request $req, Response $res, array $args) {
    $val = verificarTokenUsuario();
    if ($val === false || $val['usuario']['rol'] !== 'PACIENTE') {
        return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'], 401);
    }
    
    $profId = (int)$args['id'];
    $fecha = $req->getQueryParams()['fecha'] ?? '';
    
    if (!$fecha) {
        return jsonResponse(['ok'=>false,'mensaje'=>'Falta parámetro fecha'], 400);
    }
    
    try {
        $horas = obtenerHorasDisponibles($profId, $fecha);
        return jsonResponse([
            'ok' => true,
            'horas' => $horas,
            'token' => $val['token']
        ]);
    } catch (Exception $e) {
        return jsonResponse(['ok'=>false,'mensaje'=>'Error interno'], 500);
    }
});

/* GET /prof/buscar-por-nombre - buscar profesional por nombre para pacientes */
$app->get('/prof/buscar-por-nombre', function(Request $req): Response {
    $val = verificarTokenUsuario();
    if ($val === false) {
        return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'], 401);
    }
    
    $nombre = trim($req->getQueryParams()['nombre'] ?? '');
    if (empty($nombre)) {
        return jsonResponse(['ok'=>false,'mensaje'=>'Nombre requerido'], 400);
    }
    
    try {
        $baseDatos = conectar();
        
        // Buscar profesional por nombre con coincidencia parcial
        $consulta = $baseDatos->prepare("
            SELECT 
                p.id_profesional as id,
                CONCAT(pe.nombre, ' ', pe.apellido1, 
                       CASE WHEN pe.apellido2 IS NOT NULL THEN CONCAT(' ', pe.apellido2) ELSE '' END) as nombre_completo,
                pr.especialidad
            FROM profesional p
            JOIN persona pe ON pe.id_persona = p.id_profesional
            LEFT JOIN profesional pr ON pr.id_profesional = p.id_profesional
            WHERE pe.activo = 1
            AND (
                CONCAT(pe.nombre, ' ', pe.apellido1, COALESCE(pe.apellido2, '')) LIKE :nombre
                OR pe.nombre LIKE :nombre
                OR pe.apellido1 LIKE :nombre
            )
            ORDER BY 
                CASE 
                    WHEN CONCAT(pe.nombre, ' ', pe.apellido1, COALESCE(pe.apellido2, '')) = :nombre_exacto THEN 1
                    WHEN CONCAT(pe.nombre, ' ', pe.apellido1, COALESCE(pe.apellido2, '')) LIKE :nombre_inicio THEN 2
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
        return jsonResponse(['ok'=>false,'mensaje'=>'Error interno del servidor'], 500);
    }
});

/* POST /pac/solicitar-cita — solicitar nueva cita para paciente autenticado */
$app->post('/pac/solicitar-cita', function(Request $req): Response {
    try {
        // Validar token y rol
        $val = verificarTokenUsuario();
        if ($val === false) {
            return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'], 401);
        }
        
        if ($val['usuario']['rol'] !== 'PACIENTE') {
            return jsonResponse(['ok'=>false,'mensaje'=>'Acceso denegado'], 403);
        }
        
        // Obtener datos del paciente autenticado
        $idPaciente = (int)$val['usuario']['id_persona'];
        $data = $req->getParsedBody();
        
        $profesionalId = (int)($data['profesional_id'] ?? 0);
        $motivo = trim($data['motivo'] ?? '');
        $fecha = trim($data['fecha'] ?? '');
        
        error_log("Solicitud de cita autenticada - Paciente ID: $idPaciente, Profesional ID: $profesionalId, Motivo: $motivo, Fecha: $fecha");
        
        // Validación
        if (!$profesionalId || !$motivo || !$fecha) {
            return jsonResponse(['ok'=>false,'mensaje'=>'Faltan campos obligatorios'], 400);
        }
        
        // Crear la cita directamente en la base de datos
        $baseDatos = conectar();
        $baseDatos->beginTransaction();
        
        try {
            // Verificar que el profesional existe
            $consultaProf = $baseDatos->prepare("
                SELECT p.id_profesional, pe.nombre, pe.apellido1
                FROM profesional p
                JOIN persona pe ON pe.id_persona = p.id_profesional
                WHERE p.id_profesional = ? AND pe.activo = 1
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
                AND ? BETWEEN fecha_inicio AND DATE_SUB(fecha_fin, INTERVAL 1 SECOND)
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
            
            $idCita = $baseDatos->lastInsertId();
            
            // Registrar actividad
            registrarActividad(
                $idPaciente,
                $idPaciente, 
                'cita',
                'crear',
                null,
                $idCita,
                'INSERT'
            );
            
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









/* ---- */
/* corre la aplicación */
$app->run();
