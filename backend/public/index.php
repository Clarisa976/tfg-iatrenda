<?php
declare(strict_types=1);

use Dotenv\Dotenv;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/funciones_CTES_servicios.php';

/* .env ------------------------------------------------------- */
$dotenv = Dotenv::createUnsafeImmutable(__DIR__ . '/../');
$dotenv->load();

error_log('DB_USER: ' . getenv('DB_USER'));
error_log('DB_PASS: ' . getenv('DB_PASS'));

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

/* Slim ------------------------------------------------------- */
$app = AppFactory::create();
$app->addBodyParsingMiddleware();          // JSON → $request->getParsedBody()

// Middleware para manejar las solicitudes OPTIONS de CORS
$app->options('/{routes:.+}', function (Request $request, Response $response) {
    return $response->withStatus(200);
});

// Nota: Ya no necesitamos CORS middleware adicional ya que manejamos CORS a nivel de PHP
// antes de que Slim procese la solicitud

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

    $resultado = loginEmailPassword($email, $pass);

    // Asegúrate de que $resultado['token'] existe si ok=true
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

        // Llamamos a la función que hace TODO: persona↔paciente + cita
        $res = reservarCita($nombre, $email, $tel, $motivo, $fecha);
        error_log('Resultado de reservarCita: ' . json_encode($res));

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
    $val = validateToken();
    if ($val === false) {
        return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'],401);
    }
    $id = (int)$val['usuario']['id_persona'];
    $c  = getConsentimiento($id);
    return jsonResponse([
      'ok'            => true,
      'consentimiento'=> $c,
      'tieneVigente'  => hasConsent($id),
      'token'         => $val['token']
    ],200);
});

/** POST /consentimiento — crea un nuevo consentimiento */
$app->post('/consentimiento', function (Request $req): Response {
    $val = validateToken();
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
    $val = validateToken();
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
    // 1 validar token y extraer usuario + nuevo token
    $val = validateToken();
    if ($val === false) {
        return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'], 401);
    }
    $rol = $val['usuario']['rol'];
    // 2) sólo admin o profesional
    $rol = strtolower($val['usuario']['rol']);
    if (!in_array($rol, ['admin','profesional'], true)) {
        return jsonResponse(['ok'=>false,'mensaje'=>'Acceso denegado'], 403);
    }
    // 3) obtener listado
    $lista = obtenerUsuarios();
    // 4) devolver array + token renovado
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

/**  GET /agenda/global[?profId=n]   */
$app->get('/agenda/global', function(Request $req) {
    $val = validateToken();                       // protegido
    if ($val === false)
        return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'], 401);

    $params = $req->getQueryParams();
    $profId = isset($params['profId']) ? (int)$params['profId'] : null;

    $hoy       = date('Y-m-d');
    $inicioMes = date('Y-m-01');
    $finMes    = date('Y-m-t');

    $eventos = getEventosAgenda($inicioMes, $finMes, $profId); // ← nuevo arg
    return jsonResponse(['ok'=>true,'data'=>$eventos]);
});

/**  POST /agenda/global   — crear bloque */
$app->post('/agenda/global', function(Request $req) {
    $val = validateToken();
    if ($val === false)
        return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'], 401);

    $data = $req->getParsedBody() ?? [];
    $prof = (int)($data['profId'] ?? 0);      // 0 = todos
    $tipo = trim($data['tipo']   ?? '');
    $ini  = trim($data['inicio'] ?? '');
    $fin  = trim($data['fin']    ?? '');
    $nota = trim($data['nota']   ?? '');

    if (!$tipo || !$ini || !$fin)
        return jsonResponse(['ok'=>false,'mensaje'=>'Faltan datos requeridos'],400);

    if (!crearBloqueAgenda($prof,$ini,$fin,$tipo,$nota))
        return jsonResponse(['ok'=>false,'mensaje'=>'Error al crear evento'],500);

    return jsonResponse(['ok'=>true,'mensaje'=>'Evento creado']);
});

/**  DELETE /agenda/global/{id}  — elimina bloque **o** cita */
$app->delete('/agenda/global/{id}', function(Request $req, Response $res, array $args){
    $val = validateToken();
    if ($val === false)
        return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'], 401);

    $id = (int)$args['id'];
    if (!eliminarEvento($id))                // ← nueva función
        return jsonResponse(['ok'=>false,'mensaje'=>'No se pudo eliminar'],500);

    return jsonResponse(['ok'=>true]);
});

/* ----------  NOTIFICACIONES  ---------- */

/* GET /notificaciones */
$app->get('/notificaciones', function ($req) {
    $val = validateToken();
    if ($val === false)
        return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'],401);

    $uid = (int)$val['usuario']['id_persona'];
    $rol = strtolower($val['usuario']['rol']);

    $datos = getNotificacionesPendientes($uid,$rol);
    return jsonResponse(['ok'=>true,'data'=>$datos]);
});

/* POST /notificaciones/{id}  body:{accion:'CONFIRMAR'|'RECHAZAR'} */
$app->post('/notificaciones/{id}', function ($req,$res,$args){
    $val = validateToken();
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
    $val = validateToken();
    if ($val === false || strtolower($val['usuario']['rol'])!=='admin')
        return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'],401);

    $q = $req->getQueryParams();
    $r = buscarPersona($q['email']??'', $q['tel']??'');
    return jsonResponse(['ok'=>true,'data'=>$r]);
});

/*
   POST /admin/usuarios
*/
$app->post('/admin/usuarios', function ($req) {
    $val = validateToken();
    if ($val === false || strtolower($val['usuario']['rol'])!=='admin')
        return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'],401);

    // Obtener el ID del usuario autenticado
    $actorId = $val['usuario']['id_persona'];
    
    $body = $req->getParsedBody() ?? [];
    $tipo = strtoupper(trim($body['tipo'] ?? ''));
    $pdat = $body['persona'] ?? [];
    $xdat = $body['extra']   ?? [];

    if (!in_array($tipo,['PROFESIONAL','PACIENTE'],true))
        return jsonResponse(['ok'=>false,'mensaje'=>'Tipo inválido'],400);

    // Pasar el ID del actor a la función upsertPersona
    $idPersona = upsertPersona($pdat, $tipo, $actorId);

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

    $id = uidDecode($uid);
    $db = conectar();

    /* la contraseña solo puede crearse cuando aún está vacía */
    $st = $db->prepare("
      SELECT password_hash FROM persona WHERE id_persona = ? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['password_hash'] !== null)
        return jsonResponse(['ok'=>false,'msg'=>'Enlace caducado'],400);

    $db->prepare("
      UPDATE persona
         SET password_hash = SHA2(:p,256),
             password_hash_creado = NOW()
       WHERE id_persona = :id")
       ->execute([':p'=>$pass, ':id'=>$id]);

    return jsonResponse(['ok'=>true]);
});


$app->get('/admin/usuarios/{id}', function (Request $req, Response $res, array $args) {
    $val = validateToken();
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
    $val = validateToken();
    if ($val === false || strtolower($val['usuario']['rol'])!=='admin')
        return jsonResponse(['ok'=>false,'msg'=>'No autorizado'],401);

    $out = eliminarUsuario((int)$args['id'], (int)$val['usuario']['id_persona']);
    if (!$out['ok'])
        return jsonResponse(['ok'=>false,'msg'=>$out['msg']], $out['code']);
    return jsonResponse(['ok'=>true]);
});


/** POST /admin/borrar-usuario/{id} — desactiva usuario en vez de eliminarlo */
$app->post('/admin/borrar-usuario/{id}', function ($req, $res, $args) {
    $val = validateToken();
    if ($val === false || strtolower($val['usuario']['rol']) !== 'admin')
        return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'], 401);

    $id = (int)$args['id'];
    $actor = (int)$val['usuario']['id_persona'];
    
    // Verificar si tiene citas confirmadas o pendientes
    $db = conectar();
    $st = $db->prepare("
        SELECT COUNT(*) 
        FROM cita 
        WHERE (id_paciente = ? OR id_profesional = ?) 
        AND estado IN ('CONFIRMADA', 'PENDIENTE_VALIDACION', 'SOLICITADA')
    ");
    $st->execute([$id, $id]);
    $citasActivas = (int)$st->fetchColumn();
    
    if ($citasActivas > 0) {
        return jsonResponse([
            'ok' => false,
            'mensaje' => 'No se puede desactivar el usuario porque tiene citas confirmadas o pendientes'
        ], 409);
    }
    
    try {
        // Desactivar el usuario en lugar de eliminarlo
        $st = $db->prepare("UPDATE persona SET activo = 0 WHERE id_persona = ?");
        $ok = $st->execute([$id]);
        
        if ($ok) {
            // Registrar la acción en los logs
            logEvento(
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
    $val = validateToken();
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
        $idPersona = upsertPersona($pdat, $tipo, $actor, $id);

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

    $val = validateToken();
    if ($val === false || strtolower($val['usuario']['rol']) !== 'admin') {
        return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'], 401);
    }

    $y = (int)($req->getQueryParams()['year']  ?? date('Y'));
    $m = (int)($req->getQueryParams()['month'] ?? date('n'));

    $data = getInformeMes($y, $m);
    return jsonResponse(['ok'=>true,'data'=>$data]);
});

/* GET /admin/logs?year=YYYY&month=MM  — CSV */
/* ───────────  DESCARGA LOGS CSV ─────────── */
$app->get('/admin/logs', function (Request $req, Response $res) {

    /* 1) auth ⇒ solo admin */
    $val = validateToken();
    if ($val === false || strtolower($val['usuario']['rol']) !== 'admin') {
        return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'], 401);
    }

    /* 2) año y mes (por defecto, mes actual) */
    $y = (int)($req->getQueryParams()['year']  ?? date('Y'));
    $m = (int)($req->getQueryParams()['month'] ?? date('n'));

    /* 3) genera CSV */
    $csv = exportLogsCsv($y, $m);          // ← función añadida más abajo

    /* 4) lo devolvemos como descarga */
    $file = sprintf('logs_%d_%02d.csv', $y, $m);
    $res  = $res
        ->withHeader('Content-Type',        'text/csv; charset=UTF-8')
        ->withHeader('Content-Disposition', "attachment; filename=\"$file\"");

    $res->getBody()->write($csv);
    return $res;
});

/*--------------PROFESIONAL----------------*/
/**
 * GET  /prof/perfil  — obtener datos del profesional logeado
 */
$app->get('/prof/perfil', function ($req) {
    $val = validateToken();
    if ($val === false || strtolower($val['usuario']['rol']) !== 'profesional') {
        return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'],401);
    }
    $id = (int)$val['usuario']['id_persona'];
    $data = getUsuarioDetalle($id);
    return jsonResponse(['ok'=>true,'data'=>$data]);
});

/**
 * PUT  /prof/perfil  — actualizar únicamente su propia persona
 * Body: { persona: { nombre, apellido1, … } }
 */
$app->put('/prof/perfil', function ($req) {
    $val = validateToken();
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
        $id = upsertPersona($datos,'PROFESIONAL',$actor,$actor);
        return jsonResponse(['ok'=>true]);
    } catch (Exception $e) {
        return jsonResponse(['ok'=>false,'mensaje'=>$e->getMessage()],400);
    }
});


/*-------------------- Profesional------------------------*/
/* GET /prof/pacientes — lista solo de mis pacientes */
$app->get('/prof/pacientes', function ($req){
    $val = validateToken();
    if($val===false || strtolower($val['usuario']['rol'])!=='profesional')
        return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'],401);

    $db   = conectar();
    $idPr = (int)$val['usuario']['id_persona'];
    $sql = "
      SELECT DISTINCT
        pe.id_persona   AS id,
        pe.nombre, pe.apellido1, pe.apellido2,
        MIN(ci.fecha_hora) proxima_cita
      FROM persona pe
      JOIN cita ci ON ci.id_paciente = pe.id_persona
      WHERE ci.id_profesional = :pr
        AND pe.activo = 1
      GROUP BY pe.id_persona
      ORDER BY pe.nombre, pe.apellido1";
    $st=$db->prepare($sql); $st->execute([':pr'=>$idPr]);
    return jsonResponse(['ok'=>true,'pacientes'=>$st->fetchAll(PDO::FETCH_ASSOC),
                         'token'=>$val['token']]);
});

/* GET  /prof/pacientes/{id} — datos completos de MI paciente */
$app->get('/prof/pacientes/{id}', function ($req,$res,$args){
    $val = validateToken();
    if ($val===false || strtolower($val['usuario']['rol'])!=='profesional')
        return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'],401);

    $idProf=(int)$val['usuario']['id_persona'];
    $idPac =(int)$args['id'];

    $db=conectar();
    $q=$db->prepare("SELECT 1 FROM cita
                      WHERE id_paciente=:p AND id_profesional=:pr LIMIT 1");
    $q->execute([':p'=>$idPac,':pr'=>$idProf]);
    if(!$q->fetch()) return jsonResponse(['ok'=>false,'mensaje'=>'Prohibido'],403);

    $det = getUsuarioDetalle($idPac);
    $out = [
      'persona'      => $det['persona'],
      'paciente'     => $det['paciente'],
      'tutor'        => $det['tutor'],
      'tratamientos' => getTratamientosPaciente($idPac,$idProf),
      'documentos'   => getDocsPaciente($idPac,$idProf),
      'citas'        => getCitasPaciente($idPac,$idProf),
      'consentimiento_activo'=> tieneConsentimientoActivo($idPac)
    ];
    return jsonResponse(['ok'=>true,'data'=>$out,'token'=>$val['token']]);
});

/* PUT /prof/pacientes/{id} — actualiza persona + consentimiento */
$app->put('/prof/pacientes/{id}', function($req,$res,$args){
    $val = validateToken();
    if ($val===false || strtolower($val['usuario']['rol'])!=='profesional')
        return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'],401);

    $idProf=(int)$val['usuario']['id_persona'];
    $idPac =(int)$args['id'];

    /* control de propiedad (cita al menos una vez) */
    $db=conectar();
    $q=$db->prepare("SELECT 1 FROM cita
                      WHERE id_paciente=:p AND id_profesional=:pr LIMIT 1");
    $q->execute([':p'=>$idPac,':pr'=>$idProf]);
    if(!$q->fetch()) return jsonResponse(['ok'=>false,'mensaje'=>'Prohibido'],403);

    $b   = $req->getParsedBody();
    upsertPersona ($b['persona']  ?? [],'PACIENTE',$idProf,$idPac);
    upsertPaciente($idPac,$b['paciente'] ?? []);
    if(!empty($b['paciente']['tutor'])) upsertTutor($b['paciente']['tutor']);

    registrarConsentimiento($idPac, (bool)($b['rgpd']??false), $idProf);

    return jsonResponse(['ok'=>true,'token'=>$val['token']]);
});

/* POST /prof/citas/{id}/accion — todas las acciones de la tabla */
$app->post('/prof/citas/{id}/accion', function($req,$res,$args){
    $val = validateToken();
    if($val===false || strtolower($val['usuario']['rol'])!=='profesional')
        return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'],401);

    try{
        procesarAccionCitaProfesional((int)$args['id'],
                                      $req->getParsedBody()??[]);
        return jsonResponse(['ok'=>true]);
    }catch(Throwable $e){
        return jsonResponse(['ok'=>false,'mensaje'=>$e->getMessage()],400);
    }
});

/** POST /prof/pacientes/{id}/tareas — crea tratamiento con título + descripción + opcional archivo */
$app->post('/prof/pacientes/{id}/tareas', function($req,$res,$args){
    $val = validateToken();
    if($val===false || strtolower($val['usuario']['rol'])!=='profesional')
        return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'],401);

    $idPac  = (int)$args['id'];
    $idProf = (int)$val['usuario']['id_persona'];
    
    // Verificar propiedad
    $db = conectar();
    $q  = $db->prepare("SELECT 1 FROM cita WHERE id_paciente=? AND id_profesional=? LIMIT 1");
    $q->execute([$idPac,$idProf]);
    if(!$q->fetch()) return jsonResponse(['ok'=>false,'mensaje'=>'Prohibido'],403);

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
            isset($d['frecuencia_sesiones']) ? (int)$d['frecuencia_sesiones'] : null
        );
        
        // Registrar en logs (opcional)
        logEvento(
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
    $val = validateToken();
    if($val === false || strtolower($val['usuario']['rol']) !== 'profesional')
        return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);

    $idPac = (int)$args['id'];
    $idTratamiento = (int)$args['idTratamiento'];
    $idProf = (int)$val['usuario']['id_persona'];

    // Verificar propiedad
    $db = conectar();
    $q = $db->prepare("SELECT 1 FROM cita WHERE id_paciente = ? AND id_profesional = ? LIMIT 1");
    $q->execute([$idPac, $idProf]);
    if(!$q->fetch()) return jsonResponse(['ok' => false, 'mensaje' => 'Prohibido'], 403);    try {
        $db->beginTransaction();
        
        // 1. Obtener el historial clínico del tratamiento
        $st = $db->prepare("
            SELECT id_historial FROM tratamiento WHERE id_tratamiento = ?
        ");
        $st->execute([$idTratamiento]);
        $idHistorial = $st->fetchColumn();
        
        // 2. Buscar documentos asociados al historial y al tratamiento
        $stDocs = $db->prepare("
            SELECT id_documento, ruta 
            FROM documento_clinico 
            WHERE id_historial = ? AND id_profesional = ?
        ");
        $stDocs->execute([$idHistorial, $idProf]);
        $documentos = $stDocs->fetchAll(PDO::FETCH_ASSOC);
        
        // 3. Eliminar documentos asociados y sus archivos físicos
        foreach ($documentos as $doc) {
            // Eliminar el archivo físico si existe
            if (!empty($doc['ruta']) && file_exists(__DIR__ . '/../' . $doc['ruta'])) {
                unlink(__DIR__ . '/../' . $doc['ruta']);
            }
            
            // Eliminar el registro del documento
            $db->prepare("
                DELETE FROM documento_clinico
                WHERE id_documento = ?
            ")->execute([$doc['id_documento']]);
        }
        
        // 4. Eliminar el tratamiento
        $st = $db->prepare("
            DELETE FROM tratamiento
            WHERE id_tratamiento = ? AND id_profesional = ?
        ");
        $result = $st->execute([$idTratamiento, $idProf]);
        
        $db->commit();
        
        // Registrar en log
        logEvento(
            $idProf, 
            $idPac,
            'tratamiento',
            'id_tratamiento',
            $idTratamiento,
            null,
            'DELETE'
        );
          return jsonResponse(['ok' => true, 'mensaje' => 'Tarea eliminado correctamente']);
    } catch (Throwable $e) {
        $db->rollBack();
        error_log('Error al eliminar tarea: ' . $e->getMessage());
        return jsonResponse(['ok' => false, 'mensaje' => 'Error: ' . $e->getMessage()], 500);
    }
});

/** POST /prof/pacientes/{id}/documentos — sube documento al historial clínico */
$app->post('/prof/pacientes/{id}/documentos', function($req, $res, $args){
    $val = validateToken();
    if($val === false || strtolower($val['usuario']['rol']) !== 'profesional')
        return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);

    $idPac = (int)$args['id'];
    $idProf = (int)$val['usuario']['id_persona'];
    
    // Verificar propiedad (que el profesional tiene al menos una cita con el paciente)
    $db = conectar();
    $q = $db->prepare("SELECT 1 FROM cita WHERE id_paciente = ? AND id_profesional = ? LIMIT 1");
    $q->execute([$idPac, $idProf]);
    if(!$q->fetch()) return jsonResponse(['ok' => false, 'mensaje' => 'Prohibido'], 403);    // Obtener archivo subido
    $file = $req->getUploadedFiles()['file'] ?? null;    // Obtener datos del formulario
    $d = $req->getParsedBody();
    $diagnosticoPreliminar = $d['diagnostico_preliminar'] ?? '';
    // No extraer diagnostico_final ya que es para completar después
    
    if (!$file) {
        return jsonResponse(['ok' => false, 'mensaje' => 'No se proporcionó ningún archivo'], 400);
    }
    
    if ($file->getError() !== UPLOAD_ERR_OK) {
        return jsonResponse(['ok' => false, 'mensaje' => 'Error al subir el archivo'], 400);
    }    try {
        // Crear documento en el historial - solo con diagnóstico preliminar
        $resultado = crearDocumentoHistorial($idPac, $idProf, $file, $diagnosticoPreliminar);
        
        // Registrar en logs
        logEvento(
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
    $val = validateToken();
    if($val === false || strtolower($val['usuario']['rol']) !== 'profesional')
        return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);

    $idPac = (int)$args['id'];
    $idDoc = (int)$args['doc_id'];
    $idProf = (int)$val['usuario']['id_persona'];
    
    // Verificar propiedad (que el profesional tiene al menos una cita con el paciente)
    $db = conectar();
    $q = $db->prepare("SELECT 1 FROM cita WHERE id_paciente = ? AND id_profesional = ? LIMIT 1");
    $q->execute([$idPac, $idProf]);
    if(!$q->fetch()) return jsonResponse(['ok' => false, 'mensaje' => 'Prohibido'], 403);
    
    try {
        $db->beginTransaction();
        
        // 1. Obtener información del documento
        $stDoc = $db->prepare("
            SELECT d.*, h.id_paciente
            FROM documento_clinico d
            JOIN historial_clinico h ON d.id_historial = h.id_historial
            WHERE d.id_documento = ? AND h.id_paciente = ?
        ");
        $stDoc->execute([$idDoc, $idPac]);
        $documento = $stDoc->fetch(PDO::FETCH_ASSOC);
        
        if (!$documento) {
            return jsonResponse(['ok' => false, 'mensaje' => 'Documento no encontrado'], 404);
        }
        
        // 2. Eliminar documento de la BD
        $stDel = $db->prepare("DELETE FROM documento_clinico WHERE id_documento = ?");
        $stDel->execute([$idDoc]);
          // 3. Eliminar archivo físico si existe
        if (!empty($documento['ruta'])) {
            $rutaCompleta = __DIR__ . '/../public/' . $documento['ruta'];
            if (file_exists($rutaCompleta)) {
                unlink($rutaCompleta);
            } else {
                // Intentar con la ruta alternativa en caso de error
                $rutaAlternativa = __DIR__ . '/' . $documento['ruta'];
                if (file_exists($rutaAlternativa)) {
                    unlink($rutaAlternativa);
                }
            }
        }
          // Registrar en logs
        logEvento(
            $idProf, 
            $idPac,
            'documento_historial',
            (string)$idDoc,
            null,
            'Documento eliminado del historial',
            'DELETE'
        );
        
        $db->commit();
        
        return jsonResponse([
            'ok' => true,
            'mensaje' => 'Documento eliminado correctamente'
        ]);
    } catch (Throwable $e) {
        $db->rollBack();
        error_log('Error al eliminar documento: ' . $e->getMessage());
        return jsonResponse(['ok' => false, 'mensaje' => 'Error: ' . $e->getMessage()], 500);
    }
});

// Endpoint para migrar documentos existentes (solo para desarrollo/mantenimiento)
$app->post('/migrate/documentos', function (Request $request, Response $response) {
    try {
        $db = conectar();
        
        $result = [
            'ok' => true,
            'mensaje' => 'Migración de documentos completada',
            'detalles' => []
        ];
        
        // 1. Mostrar documentos sin tratamiento
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
        
        // 2. Ejecutar la actualización
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
        
        // 3. Verificar resultado
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


/* corre la aplicación */

/* PUT /prof/pacientes/{id}/documentos/{doc_id} — actualiza diagnóstico final de un documento */
$app->put('/prof/pacientes/{id}/documentos/{doc_id}', function($req, $res, $args){
    $val = validateToken();
    if($val === false || strtolower($val['usuario']['rol']) !== 'profesional')
        return jsonResponse(['ok' => false, 'mensaje' => 'No autorizado'], 401);

    $idPac = (int)$args['id'];
    $idDoc = (int)$args['doc_id'];
    $idProf = (int)$val['usuario']['id_persona'];
    
    // Verificar propiedad (que el profesional tiene al menos una cita con el paciente)
    $db = conectar();
    $q = $db->prepare("SELECT 1 FROM cita WHERE id_paciente = ? AND id_profesional = ? LIMIT 1");
    $q->execute([$idPac, $idProf]);
    if(!$q->fetch()) return jsonResponse(['ok' => false, 'mensaje' => 'Prohibido'], 403);
      // Obtener datos del body
    $body = $req->getParsedBody();
    $diagnosticoFinal = $body['diagnostico_final'] ?? '';
    
    // Debug log
    error_log('PUT request to update diagnosis - ID Paciente: ' . $idPac . ', ID Documento: ' . $idDoc . ', Diagnóstico Final: ' . $diagnosticoFinal);
    
    try {
        $db->beginTransaction();
        
        // 1. Obtener información del documento
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
        }        // 2. Actualizar el diagnóstico final en el historial
        $stUpdate = $db->prepare("
            UPDATE historial_clinico 
            SET diagnostico_final = ? 
            WHERE id_historial = ?
        ");
        $stUpdate->execute([$diagnosticoFinal, $documento['id_historial']]);
        
        // 3. Para futura referencia, debemos considerar mover el campo diagnostico_final a la tabla documento_clinico
        // para permitir diagnósticos individuales por documento
        
        // 4. Registrar en logs
        logEvento(
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

$app->run();
/* ------------------------------------------------------------ */

// Fix Slim base path if running in a subfolder
$scriptName = dirname($_SERVER['SCRIPT_NAME']);
$path = $_SERVER['REQUEST_URI'];
if (strpos($path, $scriptName) === 0) {
    $_SERVER['REQUEST_URI'] = substr($path, strlen($scriptName));
    if ($_SERVER['REQUEST_URI'] === '') $_SERVER['REQUEST_URI'] = '/';
}

/* ------------------------------------------------------------ */
