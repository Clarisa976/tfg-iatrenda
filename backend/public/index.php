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

/* lee el último consentimiento del usuario */
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

/* crea un nuevo consentimiento */
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
         VALUES (:id, CURRENT_TIMESTAMP, :canal)",
        [':id'=>$id,':canal'=>$canal],
        $id, 'consentimiento', $id
    );
    return $ok
      ? jsonResponse(['ok'=>true,'mensaje'=>'Consentimiento otorgado'],200)
      : jsonResponse(['ok'=>false,'mensaje'=>'Error al otorgar'],500);
});

/* revoca el consentimiento activo */
$app->post('/consentimiento/revocar', function (Request $req): Response {
    $val = verificarTokenUsuario();
    if ($val === false) {
        return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'],401);
    }
    $id = (int)$val['usuario']['id_persona'];
    $ok = execLogged(
        "UPDATE consentimiento
            SET fecha_revocado = CURRENT_TIMESTAMP
          WHERE id_persona = :id
            AND fecha_revocado IS NULL",
        [':id'=>$id],
        $id, 'consentimiento', $id
    );
    return $ok
      ? jsonResponse(['ok'=>true,'mensaje'=>'Consentimiento revocado'],200)
      : jsonResponse(['ok'=>false,'mensaje'=>'Error al revocar'],500);
});



/* listar usuarios */
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


/* lista profesionales */
$app->get('/profesionales', function(Request $req) {
    $txt   = trim($req->getQueryParams()['search'] ?? '');
    $items = getProfesionales($txt);
    return jsonResponse(['ok'=>true,'data'=>$items]);
});

/* agenda/global*/
$app->get('/agenda/global', function(Request $req) {
    $val = verificarTokenUsuario(); 
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

/* crear bloque */
$app->post('/agenda/global', function(Request $req) {
    $val = verificarTokenUsuario();
    if ($val === false)
        return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'], 401);

    $data = $req->getParsedBody() ?? [];
    $prof = (int)($data['profId'] ?? 0);
    $tipo = trim($data['tipo'] ?? '');
    $ini  = trim($data['inicio'] ?? '');
    $fin  = trim($data['fin'] ?? '');
    $nota = trim($data['nota'] ?? '');
    $actor = (int)$val['usuario']['id_persona'];

    if (!$tipo || !$ini || !$fin)
        return jsonResponse(['ok'=>false,'mensaje'=>'Faltan datos requeridos'],400);

    if (!crearBloqueAgenda($prof,$ini,$fin,$tipo,$nota,$actor))
        return jsonResponse(['ok'=>false,'mensaje'=>'Error al crear evento'],500);

    return jsonResponse(['ok'=>true,'mensaje'=>'Evento creado']);
});

/* elimina bloque o cita */
$app->delete('/agenda/global/{id}', function(Request $req, Response $res, array $args){
    $val = verificarTokenUsuario();
    if ($val === false)
        return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'], 401);

    $id = (int)$args['id'];
    $actor = (int)$val['usuario']['id_persona'];
    
    if (!eliminarEvento($id, $actor))
        return jsonResponse(['ok'=>false,'mensaje'=>'No se pudo eliminar'],500);

    return jsonResponse(['ok'=>true]);
});



/* notificaciones */
$app->get('/notificaciones', function ($req) {
    $val = verificarTokenUsuario();
    if ($val === false)
        return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'],401);

    $uid = (int)$val['usuario']['id_persona'];
    $rol = strtolower($val['usuario']['rol']);

    $datos = obtenerNotificacionesPendientes($uid,$rol);
    return jsonResponse(['ok'=>true,'data'=>$datos]);
});

/* notificaciones/{id}*/
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



/* /admin/usuarios/buscar*/
$app->get('/admin/usuarios/buscar', function ($req) {
    $val = verificarTokenUsuario();
    if ($val === false || strtolower($val['usuario']['rol'])!=='admin')
        return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'],401);

    $q = $req->getQueryParams();
    $r = buscarPersona($q['email']??'', $q['tel']??'');
    return jsonResponse(['ok'=>true,'data'=>$r]);
});

/* /admin/usuarios*/
$app->post('/admin/usuarios', function ($req) {
    $val = verificarTokenUsuario();
    if ($val === false || strtolower($val['usuario']['rol'])!=='admin')
        return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'],401);

    $actorId = $val['usuario']['id_persona'];
    
    $body = $req->getParsedBody() ?? [];
    $tipo = strtoupper(trim($body['tipo'] ?? ''));
    $pdat = $body['persona'] ?? [];
    $xdat = $body['extra']   ?? [];

    if (!in_array($tipo,['PROFESIONAL','PACIENTE'],true))
        return jsonResponse(['ok'=>false,'mensaje'=>'Tipo inválido'],400);

    $idPersona = actualizarOInsertarPersona($pdat, $tipo, $actorId);

    $ok = ($tipo==='PROFESIONAL')
        ? actualizarOInsertarProfesional($idPersona,$xdat)
        : actualizarOInsertarPaciente($idPersona,$xdat);

    return $ok
      ? jsonResponse(['ok'=>true,'id'=>$idPersona])
      : jsonResponse(['ok'=>false,'mensaje'=>'No se pudo guardar'],500);
});

/* crear-contrasena*/
$app->post('/crear-contrasena', function ($req) {
    $b = $req->getParsedBody() ?? [];
    $uid = $b['uid'] ?? '';
    $pass= $b['password'] ?? '';

    if (!$uid || strlen($pass)<8)
        return jsonResponse(['ok'=>false,'msg'=>'Datos inválidos'],400);
    
    $id = decodificarUid($uid);
    $baseDatos = conectar();

    /* Verificar que el usuario existe */
    $consulta = $baseDatos->prepare("
      SELECT password_hash FROM persona WHERE id_persona = ? LIMIT 1");
    $consulta->execute([$id]);
    $row = $consulta->fetch(PDO::FETCH_ASSOC);
    
    if (!$row) {
        return jsonResponse(['ok'=>false,'msg'=>'Usuario no encontrado'],400);
    }
    
    /* si existe el usuario y tiene contraseña se la resescribe sino la crea */    
    $consulta = $baseDatos->prepare("
      UPDATE persona         
      SET password_hash = ENCODE(DIGEST(:p, 'sha256'), 'hex'),
             password_hash_creado = CURRENT_TIMESTAMP
       WHERE id_persona = :id");
    $consulta->execute([':p'=>$pass, ':id'=>$id]);

    return jsonResponse(['ok'=>true]);
});

/* forgot-password */
$app->post('/forgot-password', function ($req) {
    $b = $req->getParsedBody() ?? [];
    $email = trim($b['email'] ?? '');

    if (!$email) {
        return jsonResponse(['ok'=>false,'mensaje'=>'Email requerido'],400);
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
            return jsonResponse(['ok'=>false,'mensaje'=>'El correo no está registrado en la base de datos']);
        }

        if (!$usuario['activo']) {
            // Usuario inactivo - devolver error
            return jsonResponse(['ok'=>false,'mensaje'=>'La cuenta está desactivada']);
        }

        // El email existe - generar token de recuperación y enviar email
        $uid = rtrim(strtr(base64_encode((string)$usuario['id_persona']), '+/', '-_'), '=');
        $front = getenv('FRONTEND_URL') ?: 'http://localhost:3000';
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
            return jsonResponse(['ok'=>true,'mensaje'=>'Email de recuperación enviado']);
        } else {
            return jsonResponse(['ok'=>false,'mensaje'=>'Error al enviar el email']);
        }
        
    } catch (Exception $e) {
        error_log("Error en forgot-password: " . $e->getMessage());
        return jsonResponse(['ok'=>false,'mensaje'=>'Error interno del servidor'], 500);
    }
});


/* obtiene perfil del paciente logueado */
$app->get('/pac/perfil', function(Request $req): Response {
  try {
  
    $val = verificarTokenUsuario();
    if ($val === false) {
      return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'],401);
    }
    if ($val['usuario']['rol'] !== 'PACIENTE') {
      return jsonResponse(['ok'=>false,'mensaje'=>'Acceso denegado'], 403);
    }

    $idPaciente = (int)$val['usuario']['id_persona'];
    if ($idPaciente <= 0) {
      error_log("ID de paciente inválido: " . $idPaciente);
      return jsonResponse(['ok'=>false,'mensaje'=>'ID de paciente no válido'], 400);
    }

    $data = getUsuarioDetalle($idPaciente);
    if (!$data) {
      error_log("No se encontraron datos para el paciente ID: " . $idPaciente);
      return jsonResponse(['ok'=>false,'mensaje'=>'No se encontraron datos del paciente'], 404);
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
    return jsonResponse(['ok'=>false,'mensaje'=>'Error al cargar el perfil: ' . $e->getMessage()], 500);
  }
});

/*  actualiza perfil del paciente actual */
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

/* obtiene tareas asignadas al paciente actual */
$app->get('/pac/tareas', function(Request $req): Response {
    try {

        $val = verificarTokenUsuario();
        if ($val === false) {
            return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'], 401);
        }
        
        if ($val['usuario']['rol'] !== 'PACIENTE') {
            return jsonResponse(['ok'=>false,'mensaje'=>'Acceso denegado'], 403);
        }

        $idPaciente = (int)$val['usuario']['id_persona'];
        if ($idPaciente <= 0) {
            error_log("ID de paciente inválido: " . $idPaciente);
            return jsonResponse(['ok'=>false,'mensaje'=>'ID de paciente no válido'], 400);
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
$app->get('/pac/historial', function(Request $req): Response {
    try {

        $val = verificarTokenUsuario();
        if ($val === false) {
            return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'], 401);
        }
        
        if ($val['usuario']['rol'] !== 'PACIENTE') {
            return jsonResponse(['ok'=>false,'mensaje'=>'Acceso denegado'], 403);
        }

        $idPaciente = (int)$val['usuario']['id_persona'];
        if ($idPaciente <= 0) {
            error_log("ID de paciente inválido: " . $idPaciente);
            return jsonResponse(['ok'=>false,'mensaje'=>'ID de paciente no válido'], 400);
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
$app->get('/pac/citas', function(Request $req): Response {
    try {

        $val = verificarTokenUsuario();
        if ($val === false) {
            return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'], 401);
        }
        
        if ($val['usuario']['rol'] !== 'PACIENTE') {
            return jsonResponse(['ok'=>false,'mensaje'=>'Acceso denegado'], 403);
        }

        $idPaciente = (int)$val['usuario']['id_persona'];
        if ($idPaciente <= 0) {
            error_log("ID de paciente inválido: " . $idPaciente);
            return jsonResponse(['ok'=>false,'mensaje'=>'ID de paciente no válido'], 400);
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
$app->post('/pac/citas/{id}/solicitud', function(Request $req, Response $res, array $args): Response {
    try {

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

/* obtener horas disponibles para pacientes */
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

/* buscar profesional por nombre para pacientes */
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
        return jsonResponse(['ok'=>false,'mensaje'=>'Error interno del servidor'], 500);
    }
});

/* solicitar nueva cita para paciente autenticado */
$app->post('/pac/solicitar-cita', function(Request $req): Response {
    try {

        $val = verificarTokenUsuario();
        if ($val === false) {
            return jsonResponse(['ok'=>false,'mensaje'=>'No autorizado'], 401);
        }
        
        if ($val['usuario']['rol'] !== 'PACIENTE') {
            return jsonResponse(['ok'=>false,'mensaje'=>'Acceso denegado'], 403);
        }

        $idPaciente = (int)$val['usuario']['id_persona'];
        $data = $req->getParsedBody();
        
        $profesionalId = (int)($data['profesional_id'] ?? 0);
        $motivo = trim($data['motivo'] ?? '');
        $fecha = trim($data['fecha'] ?? '');
        
        error_log("Solicitud de cita autenticada - Paciente ID: $idPaciente, Profesional ID: $profesionalId, Motivo: $motivo, Fecha: $fecha");

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


/* corre la aplicación */
$app->run();
