<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;


function conectar()
{
    $host = getenv('DB_HOST');
    $db   = getenv('DB_NAME');
    $user = getenv('DB_USER');
    $pass = getenv('DB_PASS');
    return new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
}

/**
 * Valida el JWT de la cabecera Authorization.
 * - Devuelve false si no hay o es inválido.
 * - Devuelve ['usuario'=>datos, 'token'=>nuevoToken] si es válido.
 */
function validateToken()
{
    $hdrs = apache_request_headers();
    if (empty($hdrs['Authorization'])) return false;
    $parts = explode(' ', $hdrs['Authorization']);
    if (count($parts) !== 2) return false;
    $token = $parts[1];

    try {
        $secreto = getenv('JWT_SECRETO') ?: 'CAMBIAR_POR_SECRETO';
        $info    = \Firebase\JWT\JWT::decode(
            $token,
            new \Firebase\JWT\Key($secreto, 'HS256')
        );
    } catch (\Throwable $e) {
        return false;
    }

    // Comprobar existencia de usuario en BD
    $db = conectar();
    $st = $db->prepare("SELECT * FROM persona WHERE id_persona = ?");
    $st->execute([(int)$info->sub]);
    if ($st->rowCount() === 0) return false;
    $usuario = $st->fetch(PDO::FETCH_ASSOC);

    // Renovar token
    $nuevoPayload = [
        'sub' => (int)$usuario['id_persona'],
        'rol' => $usuario['rol'],
        'exp' => time() + intval(getenv('JWT_EXPIRACION') ?: 3600)
    ];
    $nuevoToken = \Firebase\JWT\JWT::encode($nuevoPayload, $secreto, 'HS256');

    return ['usuario' => $usuario, 'token' => $nuevoToken];
}
/**
 * Devuelve el consentimiento más reciente (o null si nunca dio).
 */
function getConsentimiento(int $idPersona): ?array {
    $db = conectar();
    $st = $db->prepare(
        "SELECT id_consentimiento, fecha_otorgado, fecha_revocado, canal
           FROM consentimiento
          WHERE id_persona = :id
          ORDER BY fecha_otorgado DESC
          LIMIT 1"
    );
    $st->execute([':id'=>$idPersona]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Comprueba si existe un consentimiento vigente (fecha_revocado IS NULL).
 */
function hasConsent(int $idPersona): bool {
    $c = getConsentimiento($idPersona);
    return $c !== null && $c['fecha_revocado'] === null;
}


/*────────────────────────  AUDITORÍA  ───────────────────────*/
/**
 * Inserta una línea en log_evento_dato.
 * Devuelve true/false (no lanza).
 */
function logEvento( int $actor, int $afectado, string $tabla, ?string $campo, $old, $new, string $accion): bool {
    try {
        $db = conectar();
        $sql = "INSERT INTO log_evento_dato
                (id_actor,id_afectado,tabla_afectada,campo_afectado,
                 valor_antiguo,valor_nuevo,accion,ip)
                VALUES (:a,:af,:t,:c,:v1,:v2,:ac,:ip)";
        return $db->prepare($sql)->execute([
            ':a'  => $actor,
            ':af' => $afectado ?: null,
            ':t'  => $tabla,
            ':c'  => $campo,
            ':v1' => $old,
            ':v2' => $new,
            ':ac' => strtoupper($accion),
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? ''
        ]);
    } catch (Throwable $e) {
        error_log('logEvento: ' . $e->getMessage());
        return false;
    }
}

/**
 * Ejecuta una sentencia SQL y, si es INSERT/UPDATE/DELETE, se autopista al log.
 * - $tabla  → nombre de la tabla (para auditoría)
 * - $afect  → id principal afectado (FK)
 *
 * Devuelve true/false.
 */
function execLogged(
    string $sql,
    array $params,
    int $actor = 0,
    ?string $tabla = null,
    ?int $afect = null
): bool {
    $db   = conectar();
    $stmt = $db->prepare($sql);
    $ok   = $stmt->execute($params);

    if ($ok && preg_match('/^\s*(INSERT|UPDATE|DELETE)/i', $sql, $m)) {
        $accion = strtoupper($m[1]);
        // Guardamos un snapshot de los parámetros; WARNING: puede contener datos sensibles
        logEvento(
            $actor,
            $afect ?? 0,
            $tabla ?? 'desconocida',
            null,
            null,
            json_encode($params, JSON_UNESCAPED_UNICODE),
            $accion
        );
    }
    return $ok;
}





function loginEmailPassword(string $email, string $plainPass): array
{
    try {
        $sql = "SELECT id_persona id,
                       CONCAT(nombre,' ',apellido1) nombre,
                       email,
                       LOWER(rol) rol
                FROM   persona
                WHERE  email = :e
                  AND  password_hash IS NOT NULL
                  AND  password_hash = SHA2(:p,256)
                  AND  activo = 1
                LIMIT  1";
        $st  = conectar()->prepare($sql);
        $st->execute(['e' => $email, 'p' => $plainPass]);

        if ($row = $st->fetch()) {
            // Generar JWT
            $payload = [
                'sub' => (int)$row['id'],
                'rol' => $row['rol'],
                'exp' => time() + intval(getenv('JWT_EXPIRACION') ?: 3600)
            ];
            $jwt = \Firebase\JWT\JWT::encode($payload, getenv('JWT_SECRETO') ?: 'CAMBIAR_POR_SECRETO', 'HS256');

            return [
                'ok' => true,
                'token' => $jwt, // ← esto es imprescindible
                'usuario' => $row
            ];
        }
        return [
            'ok' => false,
            'mensaje' => 'Email o contraseña incorrectos'
        ];
    } catch (PDOException $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
/**
 * Reserva una cita:
 *  - Busca o crea persona según email/teléfono
 *  - Asegura la entrada en paciente
 *  - Encuentra profesional libre
 *  - Inserta la cita
 * Devuelve ['ok'=>bool,'mensaje'=>string,'status'=>httpCode?]
 */
function reservarCita(string $nombre, string $email, ?string $tel, string $motivo, string $fecha, int $actor = 0): array
{
    // Validación de campos
    if ($nombre === '' || $email === '' || $motivo === '' || $fecha === '') {
        return ['ok' => false, 'mensaje' => 'Faltan campos obligatorios', 'status' => 400];
    }

    // Parsear fecha y comprobar horario L-V 10–18
    try {
        $fecha = new DateTime($fecha);
    } catch (Exception $e) {
        return ['ok' => false, 'mensaje' => 'Fecha inválida', 'status' => 400];
    }
    $w = (int)$fecha->format('w');    // 0 domingo … 6 sábado
    $h = (int)$fecha->format('G');    // 0–23
    if ($w < 1 || $w > 5 || $h < 10 || $h >= 18) {
        return ['ok' => false, 'mensaje' => 'Fuera de horario laboral', 'status' => 400];
    }
    $ts = $fecha->format('Y-m-d H:i:s');

    // Obtener o crear persona
    $idPersona = obtenerCrearPersona($nombre, $email, $tel);

    // Asegurar paciente
    asegurarPaciente($idPersona);

    // Conectar y buscar profesional disponible
    $db = conectar();
    $sql = "
      SELECT p.id_profesional
        FROM profesional p
       WHERE NOT EXISTS (
         SELECT 1 FROM bloque_agenda b
          WHERE b.id_profesional = p.id_profesional
            AND b.tipo_bloque    IN ('AUSENCIA','VACACIONES')
            AND :ts BETWEEN b.fecha_inicio
                       AND DATE_SUB(b.fecha_fin,INTERVAL 1 SECOND)
       )
         AND NOT EXISTS (
         SELECT 1 FROM cita c
          WHERE c.id_profesional = p.id_profesional
            AND c.estado        IN ('PENDIENTE_VALIDACION','SOLICITADA',
                                    'CONFIRMADA','ATENDIDA')
            AND c.fecha_hora    = :ts
       )
       ORDER BY (
         SELECT COUNT(*) FROM cita c2
          WHERE c2.id_profesional = p.id_profesional
            AND DATE(c2.fecha_hora) = DATE(:ts)
       ) ASC
       LIMIT 1";
    $st = $db->prepare($sql);
    $st->execute([':ts'=>$ts]);

    $idProf = $st->fetchColumn();
    if (!$idProf) {
        return ['ok'=>false,'mensaje'=>'No hay profesionales disponibles','status'=>409];
    }

    // Insertar cita
    $insertOk = execLogged(
        "INSERT INTO cita
           (id_paciente,id_profesional,id_bloque,
            fecha_hora,estado,
            nombre_contacto,telefono_contacto,email_contacto,
            motivo,origen)
         VALUES
           (:pac,:prof,NULL,
            :ts,'PENDIENTE_VALIDACION',
            :nom,:tel,:email,
            :motivo,'WEB')",
        [
            ':pac'   => $idPersona,
            ':prof'  => $idProf,
            ':ts'    => $ts,
            ':nom'   => $nombre,
            ':tel'   => $tel,
            ':email' => $email,
            ':motivo'=> $motivo
        ],
        $actor,                // ← quién lo hace
        'cita'                 // tabla auditada
        // id_afectado lo ponemos justo después cuando lo sepamos
    );

    if (!$insertOk) {
        return ['ok'=>false,'mensaje'=>'Error al crear la cita','status'=>500];
    }

    /* completar id_afectado en el log recién creado */
    $idCita = (int)$db->lastInsertId();

    return ['ok'=>true,'mensaje'=>'Cita reservada correctamente'];
}

/**
 * Busca persona por email o teléfono. Si no existe, la crea.
 * Devuelve id_persona.
 */
function obtenerCrearPersona(string $nombre, string $email, ?string $tel): int
{
    $db = conectar();
    // 1) Buscar
    $stmt = $db->prepare("
      SELECT id_persona
        FROM persona
       WHERE email = :email
          OR (telefono IS NOT NULL AND telefono = :tel)
       LIMIT 1
    ");
    $stmt->execute([':email' => $email, ':tel' => $tel]);
    if ($id = $stmt->fetchColumn()) {
        return (int)$id;
    }
    // 2) Crear (apellido1 = nombre, password_hash de cadena vacía)
    $ins = $db->prepare("
      INSERT INTO persona
        (nombre, apellido1, email, telefono, rol)
      VALUES
        (:nom, :ap1, :email, :tel, 'PACIENTE')
    ");
    $ins->execute([
        ':nom'   => $nombre,
        ':ap1'   => $nombre,
        ':email' => $email,
        ':tel'   => $tel,
    ]);
    return (int)$db->lastInsertId();
}

/**
 * Asegura que exista una fila en paciente para id_persona dado.
 */
function asegurarPaciente(int $idPersona): void
{
    $db = conectar();
    $stmt = $db->prepare("SELECT 1 FROM paciente WHERE id_paciente = :id");
    $stmt->execute([':id' => $idPersona]);
    if ($stmt->fetch()) return;
    // Insertamos con tipo 'ADULTO' por defecto
    $ins = $db->prepare("
      INSERT INTO paciente (id_paciente, tipo_paciente)
      VALUES (:id, 'ADULTO')
    ");
    $ins->execute([':id' => $idPersona]);
}

/**
 * Devuelve solo los pacientes y profesionales activos.
 * Siempre retorna un array (posiblemente vacío).
 */
function obtenerUsuarios(): array
{
    $db = conectar();
    $sql = "
      SELECT 
        id_persona AS id,
        nombre, 
        apellido1,
        apellido2,
        LOWER(rol) AS rol
      FROM persona
      WHERE activo = 1
        AND rol IN ('PACIENTE','PROFESIONAL')
      ORDER BY nombre, apellido1
    ";
    $stmt = $db->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}



/** -------------   AGENDA (admin / profesional) ------------- */
/* === PROFESIONALES ================================================== */
/**
 * Devuelve lista de profesionales.
 * Si $search !== '' aplica filtro (case-insensitive) por nombre/apellidos.
 */
function getProfesionales(string $search = ''): array
{
    $db  = conectar();
    $sql = "
      SELECT id_profesional  AS id,
             CONCAT(nombre,' ',apellido1,' ',COALESCE(apellido2,'')) AS nombre
        FROM profesional p
   LEFT JOIN persona pr ON pr.id_persona = p.id_profesional
       WHERE pr.activo = 1
    ";
    $params = [];
    if ($search !== '') {
        $sql     .= " AND CONCAT_WS(' ',nombre,apellido1,apellido2) LIKE :txt";
        $params[':txt'] = '%' . $search . '%';
    }
    $sql .= " ORDER BY nombre";
    $st = $db->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/* === A G E N D A  =================================================== */
/**
 * Devuelve bloques + citas confirmadas entre fechas.
 * Si $profId no es null filtra sólo ese profesional.
 */
function getEventosAgenda(string $desde, string $hasta, ?int $profId = null): array
{
    $db = conectar();

    /* Bloques */
    $bloqSQL = "
      SELECT b.id_bloque             AS id,
             b.id_profesional        AS recurso,
             CONCAT(p.nombre, ' ', p.apellido1) AS nombre_profesional, 
             b.fecha_inicio          AS inicio,
             b.fecha_fin             AS fin,
             b.tipo_bloque           AS tipo,
             b.comentario            AS titulo,
             'bloque'                AS fuente
        FROM bloque_agenda b
        LEFT JOIN persona p ON p.id_persona = b.id_profesional
       WHERE DATE(b.fecha_inicio) <= :h
         AND DATE(b.fecha_fin)   >= :d
    ";
    $params = [':d' => $desde, ':h' => $hasta];

    if ($profId !== null) {
        $bloqSQL .= " AND b.id_profesional = :p";
        $params[':p'] = $profId;
    }
    $bloques = $db->prepare($bloqSQL);
    $bloques->execute($params);

    /* Citas confirmadas/atendidas → 1 h de duración */
    $citSQL = "
      SELECT c.id_cita               AS id,
             c.id_profesional        AS recurso,
             CONCAT(p.nombre, ' ', p.apellido1) AS nombre_profesional,
             c.fecha_hora            AS inicio,
             DATE_ADD(c.fecha_hora,INTERVAL 1 HOUR) AS fin,
             'cita'                  AS tipo,
             c.motivo                AS titulo,
             'cita'                  AS fuente
        FROM cita c
        LEFT JOIN persona p ON p.id_persona = c.id_profesional
       WHERE c.estado IN ('CONFIRMADA','ATENDIDA')
         AND DATE(c.fecha_hora) BETWEEN :d AND :h
    ";
    if ($profId !== null) $citSQL .= " AND c.id_profesional = :p";
    $citas = $db->prepare($citSQL);
    $citas->execute($params);

    return array_merge(
        $bloques->fetchAll(PDO::FETCH_ASSOC),
        $citas->fetchAll(PDO::FETCH_ASSOC)
    );
}

/**
 * Crea un bloque.  
 * Si $prof == 0 se inserta uno idéntico por **cada** profesional activo.
 */
function crearBloqueAgenda(
    int $prof, string $ini, string $fin, string $tipo, string $com = '',int $actor = 0 ): bool {
    /* bloque global → uno por cada profesional */
    if ($prof === 0) {
        foreach (getProfesionales() as $p) {
            if (!crearBloqueAgenda((int)$p['id'], $ini, $fin, $tipo, $com, $actor))
                return false;
        }
        return true;
    }

    $sql = 'INSERT INTO bloque_agenda (id_profesional,fecha_inicio,fecha_fin,tipo_bloque,comentario)
            VALUES (:p,:i,:f,:t,:c)';
    return execLogged(
        $sql,
        [':p' => $prof, ':i' => $ini, ':f' => $fin, ':t' => $tipo, ':c' => $com],
        $actor,
        'bloque_agenda'
    );
}
/**
 * Elimina un bloque o una cita según exista.
 */
function eliminarEvento(int $id, int $actor = 0): bool
{
    // Primero intentamos en bloque
    if (execLogged('DELETE FROM bloque_agenda WHERE id_bloque=:id', [':id'=>$id], $actor, 'bloque_agenda', $id))
        return true;
    // Si no estaba, probamos en cita
    return execLogged('DELETE FROM cita WHERE id_cita=:id', [':id'=>$id], $actor, 'cita', $id);
}

/* ─────────────────────── helper de envío SMTP ─────────────────────── */
function enviarEmail(string $to, string $subject, string $htmlBody): bool
{
    // Obtener credenciales SMTP del entorno
    $host = getenv('SMTP_HOST');
    $user = getenv('SMTP_USER');
    $pass = getenv('SMTP_PASS');

    // Si no hay configuración, logueamos y continuamos
    if (!$host || !$user || !$pass) {
        error_log('SMTP no configurado: email NO enviado a ' . $to);
        return true;
    }

    /* 2) PHPMailer */
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->SMTPAuth   = true;
        $mail->Username   = $user;
        $mail->Password   = $pass;
        $mail->Port       = intval(getenv('SMTP_PORT') ?: 587);
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;

        // AÑADIR ESTAS LÍNEAS PARA MANEJAR TILDES Y CARACTERES ESPECIALES
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';

        $mail->setFrom(
            getenv('SMTP_FROM') ?: $user,
            getenv('SMTP_FROM_NAME') ?: 'Clínica'
        );
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Email error: ' . $mail->ErrorInfo);
        return false;
    }
}
/* ====== NOTIFICACIONES ========================================== */

/* lista pendientes */
function getNotificacionesPendientes(int $uid, string $rol): array
{
    $db  = conectar();
    $sql = "
      SELECT c.id_cita id,
             DATE_FORMAT(c.fecha_hora,'%d/%m/%Y %H:%i') fecha,
             c.estado tipo,
             CONCAT(pa.nombre,' ',pa.apellido1) paciente,
             CONCAT(pr.nombre,' ',pr.apellido1) profesional
        FROM cita c
   LEFT JOIN persona pa ON pa.id_persona = c.id_paciente
   LEFT JOIN persona pr ON pr.id_persona = c.id_profesional
       WHERE c.estado IN ('PENDIENTE_VALIDACION',
                          'CAMBIO_SOLICITADO',
                          'CANCELACION_SOLICITADA')";
    $p = [];
    if ($rol === 'profesional') {
        $sql .= " AND c.id_profesional = :p";
        $p[':p'] = $uid;
    }
    $sql .= " ORDER BY c.fecha_hora";
    $st = $db->prepare($sql);
    $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/* procesa confirmación / rechazo */
function procesarNotificacion(int $id, string $acc, int $uid, string $rol): bool
{
    $nuevo = $acc === 'CONFIRMAR' ? 'CONFIRMADA' : 'CANCELADA';

    $db = conectar();
    $db->beginTransaction();
    try {
        /* 1) bloquear la cita */
        $stmt = $db->prepare("
            SELECT c.*, p.email pacienteEmail
              FROM cita  c
        INNER JOIN persona p ON p.id_persona = c.id_paciente
             WHERE c.id_cita = ? FOR UPDATE");
        $stmt->execute([$id]);
        if (!$row = $stmt->fetch(PDO::FETCH_ASSOC))
            throw new Exception('Cita inexistente');

        /* permiso profesional */
        if ($rol === 'profesional' && (int)$row['id_profesional'] !== $uid)
            throw new Exception('Prohibido');

        /* 2) actualizar estado */
        $db->prepare("UPDATE cita SET estado = ? WHERE id_cita = ?")
            ->execute([$nuevo, $id]);

        /* 3) Si se confirma, crear bloque en la agenda del profesional */
        if ($acc === 'CONFIRMAR') {
            // Crear un bloque de 1 hora para la cita
            $inicio = $row['fecha_hora'];
            $fin = date('Y-m-d H:i:s', strtotime($inicio) + 3600); // +1 hora

            // Insertar en bloque_agenda
            $db->prepare("
                INSERT INTO bloque_agenda 
                (id_profesional, fecha_inicio, fecha_fin, tipo_bloque, comentario)
                VALUES (:prof, :inicio, :fin, 'CITA', :comentario)
            ")->execute([
                ':prof' => $row['id_profesional'],
                ':inicio' => $inicio,
                ':fin' => $fin,
                ':comentario' => "Cita con paciente: " . $row['id_paciente']
            ]);

            // Actualizar la cita con referencia al bloque creado
            $idBloque = $db->lastInsertId();
            $db->prepare("UPDATE cita SET id_bloque = ? WHERE id_cita = ?")
                ->execute([$idBloque, $id]);
        }

        /* 4) registrar notificación en BD */
        $mensaje = $acc === 'CONFIRMAR'
            ? 'Tu cita ha sido confirmada'
            : 'Tu cita ha sido cancelada';

        $db->prepare("
          INSERT INTO notificacion(id_emisor,id_destino,id_cita,
                                   tipo,asunto,cuerpo)
          VALUES (:e,:d,:c,'EMAIL','Estado de tu cita',:b)
        ")->execute([
            ':e' => $uid,
            ':d' => $row['id_paciente'],
            ':c' => $id,
            ':b' => $mensaje
        ]);

        /* 5) enviar email inmediatamente */
        enviarEmail(
            $row['pacienteEmail'],
            'Estado de tu cita',
            "<p>Hola,<br>$mensaje.<br><br>Fecha: " .
                date('d/m/Y H:i', strtotime($row['fecha_hora'])) . '</p>'
        );

        $db->commit();
        return true;
    } catch (Throwable $ex) {
        $db->rollBack();
        error_log('procesarNotificacion: ' . $ex->getMessage());
        return false;
    }
}

function buscarPersona(string $email, string $tel = '', string $nif = ''): ?array
{
    $db = conectar();
    $cond = [];
    $par = [];
    if ($email !== '') {
        $cond[] = 'p.email = :e';
        $par[':e'] = $email;
    }
    if ($tel  !== '') {
        $cond[] = 'p.telefono = :t';
        $par[':t'] = $tel;
    }
    if ($nif  !== '') {
        $cond[] = 'p.nif = :n';
        $par[':n'] = $nif;
    }
    if (!$cond) return null;

    $sql = "SELECT p.*,
                 prof.num_colegiado,prof.especialidad,
                 pac.tipo_paciente
            FROM persona p
       LEFT JOIN profesional prof ON prof.id_profesional=p.id_persona
       LEFT JOIN paciente pac     ON pac.id_paciente  =p.id_persona
           WHERE " . implode(' OR ', $cond) . " LIMIT 1";
    $st = $db->prepare($sql);
    $st->execute($par);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
/* codifica id_persona a URL-safe base64 */
/* codifica id_persona a URL-safe base64 (sin =) */
function uidEncode(int $id): string
{
    return rtrim(strtr(base64_encode((string)$id), '+/', '-_'), '=');
}

/* decodifica añadiendo los = que falten */
function uidDecode(string $uid): int
{
    $uid = strtr($uid, '-_', '+/');

    /* padding */
    $falta = strlen($uid) % 4;
    if ($falta) {
        $uid .= str_repeat('=', 4 - $falta);
    }
    return (int) base64_decode($uid);
}

/* envía correo con enlace al formulario crear-contraseña */
function enviarFormularioPass(int $id, string $email, string $nombre): void
{
    $uid   = uidEncode($id);

    /* 1) dominio frontend --------------------------------------------------- */
    $base  = rtrim(getenv('FRONT_URL') ?: 'http://localhost:3000', '/');

    /* 2) slug solo ASCII  --------------------------------------------------- */
    $link  = $base . '/crear-contrasena?uid=' . $uid;

    /* 3) e-mail ------------------------------------------------------------- */
    $html  = "<p>Hola $nombre,<br>
              Has sido dado de alta en la clínica logopédica Petaka.<br>
              Para crear tu contraseña haz clic <a href=\"$link\">aquí</a>.</p>";

    enviarEmail($email, 'Crea tu contraseña', $html);
}


/* ───── INSERT / UPDATE persona ───── */
/**
 * Inserta o actualiza la tabla persona y devuelve id_persona.
 *  $d   → array con los campos (nombre, email, etc.)
 *  $rolFinal → 'PROFESIONAL' | 'PACIENTE' | 'TUTOR' | 'ADMIN'
 *  - Si la persona ya existía (por email o teléfono) se actualiza y/o
 *    cambia el rol.
 *  - Si es nueva y trae email se envía un enlace para crear contraseña.
 */
function upsertPersona(array $d, string $rolFinal, int $actor = 0, int $forceUpdateId = 0)
{
    $db = conectar();
    
    // Primero buscar si hay un usuario inactivo con el mismo email o NIF
    $inactivo = null;
    if (isset($d['email']) && $d['email']) {
        $st = $db->prepare("SELECT * FROM persona WHERE email = ? AND activo = 0");
        $st->execute([$d['email']]);
        $inactivo = $st->fetch(PDO::FETCH_ASSOC);
    }
    
    if (!$inactivo && isset($d['nif']) && $d['nif']) {
        $st = $db->prepare("SELECT * FROM persona WHERE nif = ? AND activo = 0");
        $st->execute([$d['nif']]);
        $inactivo = $st->fetch(PDO::FETCH_ASSOC);
    }
    
    // Si encontramos un usuario inactivo, lo reactivamos
    if ($inactivo) {
        $idReactivado = (int)$inactivo['id_persona'];
        
        // Preparar campos a actualizar
        $set = ['activo = 1']; // Reactivar el usuario
        $vals = [];
        
        // Lista de campos que se pueden actualizar
        $campos = ['nombre', 'apellido1', 'apellido2', 'fecha_nacimiento', 
                   'nif', 'email', 'telefono', 'tipo_via', 'nombre_calle', 'numero',
                   'escalera', 'piso', 'puerta', 'codigo_postal', 'ciudad', 
                   'provincia', 'pais'];
        
        // Filtrar solo los campos que vienen en $d
        foreach ($campos as $c) {
            if (isset($d[$c]) && $d[$c] !== '') {
                $set[] = "$c = :$c";
                $vals[":$c"] = $d[$c];
            }
        }
        
        // Actualizar rol si es necesario
        if ($inactivo['rol'] !== $rolFinal) {
            $set[] = "rol = :rol";
            $vals[':rol'] = $rolFinal;
        }
        
        // Ejecutar la actualización
        $sql = "UPDATE persona SET " . implode(', ', $set) . " WHERE id_persona = :id";
        $vals[':id'] = $idReactivado;
        $db->prepare($sql)->execute($vals);
        
        // Registrar la reactivación
        logEvento(
            $actor,
            $idReactivado,
            'persona',
            'activo',
            '0',
            '1',
            'UPDATE'
        );
        
        return $idReactivado;
    }
    
    // Si llegamos aquí, continuamos con la lógica normal de upsert
    // (el resto de la función queda igual)
    
    // Si se proporciona un ID forzado para actualización
    if ($forceUpdateId > 0) {
        $st = $db->prepare("SELECT * FROM persona WHERE id_persona = ?");
        $st->execute([$forceUpdateId]);
        $prev = $st->fetch(PDO::FETCH_ASSOC);
        
        if (!$prev) {
            throw new Exception("No se encontró el usuario con ID $forceUpdateId");
        }
    } else {
        // Búsqueda normal por email/teléfono (solo usuarios activos)
        $st = $db->prepare("
            SELECT * FROM persona 
            WHERE (email = :email OR (telefono IS NOT NULL AND telefono = :tel))
            AND activo = 1
            LIMIT 1
        ");
        $st->execute([':email' => $d['email'] ?? '', ':tel' => $d['telefono'] ?? '']);
        $prev = $st->fetch(PDO::FETCH_ASSOC);
    }
    
    // Preparar campos a actualizar
    $set = [];
    $vals = [];
    
    // Lista de campos que se pueden actualizar
    $campos = ['nombre', 'apellido1', 'apellido2', 'fecha_nacimiento', 
               'nif', 'email', 'telefono', 'tipo_via', 'nombre_calle', 'numero',
               'escalera', 'piso', 'puerta', 'codigo_postal', 'ciudad', 
               'provincia', 'pais'];
    
    // Filtrar solo los campos que vienen en $d
    foreach ($campos as $c) {
        if (isset($d[$c]) && $d[$c] !== '') {
            $set[] = "$c = :$c";
            $vals[":$c"] = $d[$c];
        }
    }
    
    /* ──────────── UPDATE ──────────── */
    if ($prev) {
        $id = (int)$prev['id_persona'];
        
        // Si estamos actualizando un usuario existente, verificar si el NIF 
        // ya existe para otro usuario diferente
        if (isset($d['nif']) && $d['nif'] !== $prev['nif']) {
            $st = $db->prepare("SELECT id_persona FROM persona WHERE nif = ? AND id_persona != ?");
            $st->execute([$d['nif'], $id]);
            if ($st->fetch()) {
                throw new Exception("El NIF {$d['nif']} ya está registrado para otro usuario");
            }
        }
        
        /* actualiza rol si ha cambiado */
        if (isset($prev['rol']) && $prev['rol'] !== $rolFinal) {
            $db->prepare("UPDATE persona SET rol = :r WHERE id_persona = :id")
               ->execute([':r' => $rolFinal, ':id' => $id]);
        }
        
        /* actualiza columnas que vengan en $d */
        if ($set) {
            $sql = "UPDATE persona SET " . implode(', ', $set)
                 . " WHERE id_persona = :id";
            $vals[':id'] = $id;
            $db->prepare($sql)->execute($vals);
        }
        
        return $id;
    }
    
    /* ──────────── INSERT ──────────── */
    // Para inserciones, verificar si el NIF ya existe
    if (isset($d['nif']) && $d['nif']) {
        $st = $db->prepare("SELECT id_persona FROM persona WHERE nif = ?");
        $st->execute([$d['nif']]);
        if ($st->fetch()) {
            throw new Exception("El NIF {$d['nif']} ya está registrado en el sistema");
        }
    }
    
    // Resto del código para inserción...
    // (mantener el código original de inserción)
}
/* ───── INSERT / UPDATE profesional ───── */
function upsertProfesional(int $id, array $x, int $actor = 0): bool
{
    $db   = conectar();
    $ex   = $db->prepare('SELECT 1 FROM profesional WHERE id_profesional=?');
    $ex->execute([$id]);

    $sql = $ex->fetch()
        ? 'UPDATE profesional SET num_colegiado=:n, especialidad=:e WHERE id_profesional=:id'
        : 'INSERT INTO profesional SET id_profesional=:id, num_colegiado=:n, especialidad=:e';

    return execLogged(
        $sql,
        [':id' => $id, ':n' => $x['num_colegiado'] ?? null, ':e' => $x['especialidad'] ?? null],
        $actor,
        'profesional',
        $id
    );
}


/* ------------------------------------------------------------------
   Tutores y pacientes
-------------------------------------------------------------------*/

/**
 * Crea o actualiza el tutor y devuelve su id_persona.
 * $t debe traer al menos: nombre, apellido1, email, telefono, metodo (TEL|EMAIL)
 */
function upsertTutor(array $t): int
{
    /* 1) upsert en persona con rol TUTOR */
    $id = upsertPersona($t, 'TUTOR');

    /* 2) upsert en tabla tutor */
    $db = conectar();
    $ex = $db->prepare("SELECT 1 FROM tutor WHERE id_tutor = ?");
    $ex->execute([$id]);

    if ($ex->fetch()) {
        $db->prepare("
            UPDATE tutor
               SET metodo_contacto_preferido = :m
             WHERE id_tutor = :id
        ")->execute([
            ':m' => $t['metodo'] ?? 'TEL',
            ':id' => $id
        ]);
    } else {
        $db->prepare("
            INSERT INTO tutor
                   SET id_tutor = :id,
                       metodo_contacto_preferido = :m
        ")->execute([
            ':id' => $id,
            ':m' => $t['metodo'] ?? 'TEL'
        ]);
    }
    return $id;
}

/**
 * Crea o actualiza la fila de paciente.
 * Si $x incluye la clave 'tutor' y el tipo_paciente ≠ ADULTO,
 * se crea/actualiza también el tutor y se enlaza.
 */
function upsertPaciente(int $id, array $x): bool
{
    $db = conectar();

    /* 1) tutor opcional para menores */
    $idTutor = null;
    $esMenor = isset($x['tipo_paciente']) && $x['tipo_paciente'] !== 'ADULTO';
    if ($esMenor && !empty($x['tutor'])) {
        $idTutor = upsertTutor($x['tutor']);
    }

    /* 2) upsert paciente */
    $ex  = $db->prepare("SELECT 1 FROM paciente WHERE id_paciente = ?");
    $ex->execute([$id]);

    $sql = $ex->fetch()
        ? "UPDATE paciente
               SET tipo_paciente = :t,
                   id_tutor      = :tu
             WHERE id_paciente   = :id"
        : "INSERT INTO paciente
               SET id_paciente   = :id,
                   tipo_paciente = :t,
                   id_tutor      = :tu";

    return $db->prepare($sql)->execute([
        ':id' => $id,
        ':t'  => $x['tipo_paciente'] ?? 'ADULTO',
        ':tu' => $idTutor
    ]);
}


/**
 * Devuelve los datos de persona + profesional/paciente (+ tutor si corresponde).
 */
function getUsuarioDetalle(int $id): ?array
{
    $db = conectar();
    // Persona básica
    $st = $db->prepare("SELECT * FROM persona WHERE id_persona = ?");
    $st->execute([$id]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) return null;

    // Profesional
    $st = $db->prepare("SELECT num_colegiado, especialidad, fecha_alta_profesional 
                          FROM profesional WHERE id_profesional = ?");
    $st->execute([$id]);
    $prof = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    // Paciente + tutor
    $st = $db->prepare("SELECT tipo_paciente, observaciones_generales, id_tutor 
                          FROM paciente WHERE id_paciente = ?");
    $st->execute([$id]);
    $pac = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    $tutor = null;
    if ($pac && $pac['id_tutor']) {
        $st = $db->prepare("SELECT p2.*, t.metodo_contacto_preferido 
                              FROM persona p2 
                              JOIN tutor t ON t.id_tutor = p2.id_persona
                             WHERE p2.id_persona = ?");
        $st->execute([$pac['id_tutor']]);
        $tutor = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    return [
        'persona'      => $p,
        'profesional'  => $prof,
        'paciente'     => $pac,
        'tutor'        => $tutor
    ];
}


function citasActivas(int $id): int
{
    $sql = 'SELECT COUNT(*) FROM cita WHERE estado<>"CANCELADA" AND (id_paciente=:id OR id_profesional=:id)';
    $st  = conectar()->prepare($sql);
    $st->execute([':id'=>$id]);
    return (int)$st->fetchColumn();
}

/* elimina una persona solo si TODAS sus citas están canceladas */
function eliminarUsuario(int $id, int $actor = 0): array
{
    if (citasActivas($id) > 0) {
        return ['ok'=>false,'code'=>409,'msg'=>'El usuario tiene citas activas'];
    }
    $ok = execLogged('DELETE FROM persona WHERE id_persona=:id', [':id'=>$id], $actor, 'persona', $id);
    return $ok ? ['ok'=>true] : ['ok'=>false,'code'=>500,'msg'=>'Error SQL'];
}





/* ────────── resumen mensual ────────── */
function getInformeMes(int $y, int $m): array
{
    $db  = conectar();
    $ini = sprintf('%d-%02d-01', $y, $m);
    $fin = date('Y-m-t', strtotime($ini));

    /* totales citas */
    $row = $db->query("
       SELECT
         COUNT(*)                                      total,
         SUM(estado='CONFIRMADA' OR estado='ATENDIDA') conf,
         SUM(estado='CANCELADA' OR estado='NO_PRESENTADA') canc
       FROM cita
       WHERE DATE(fecha_hora) BETWEEN '$ini' AND '$fin'")
        ->fetch(PDO::FETCH_NUM);

    /* usuarios activos */
    $uAct = $db->query("SELECT COUNT(*) FROM persona WHERE activo=1")
        ->fetchColumn();

    return [
        'total_citas'      => (int)$row[0],
        'citas_confirmadas' => (int)$row[1],
        'citas_canceladas' => (int)$row[2],
        'usuarios_activos' => (int)$uAct
    ];
}

/* ──────────────────   EXPORTAR LOGS A CSV   ──────────────────
   $año – $mes ⇒ devuelve string CSV (separator = “;”, encabezados ES)
----------------------------------------------------------------*/
function exportLogsCsv(int $year, int $month): string
{
    $db = conectar();

    // Si se pasa 0 como mes, mostrar todos los logs (sin filtro por fecha)
    if ($month === 0) {
        $sql = "
          SELECT DATE_FORMAT(l.fecha,'%d/%m/%Y %H:%i')   AS fecha,
                 IFNULL(CONCAT(actor.nombre,' ',actor.apellido1), 'Sistema') AS actor,
                 l.accion,
                 l.tabla_afectada,
                 IFNULL(l.campo_afectado, '-') AS campo_afectado,
                 IFNULL(l.valor_antiguo, '-') AS valor_antiguo,
                 IFNULL(l.valor_nuevo, '-') AS valor_nuevo,
                 IFNULL(l.ip, '-') AS ip
            FROM log_evento_dato l
       LEFT JOIN persona actor ON actor.id_persona = l.id_actor
           ORDER BY l.fecha DESC";

        $st = $db->prepare($sql);
        $st->execute();
    } else {
        // Usar el mes específico
        $ini = sprintf('%d-%02d-01 00:00:00', $year, $month);
        $fin = date('Y-m-d 23:59:59', strtotime("$ini +1 month -1 day"));

        $sql = "
          SELECT DATE_FORMAT(l.fecha,'%d/%m/%Y %H:%i')   AS fecha,
                 IFNULL(CONCAT(actor.nombre,' ',actor.apellido1), 'Sistema') AS actor,
                 l.accion,
                 l.tabla_afectada,
                 IFNULL(l.campo_afectado, '-') AS campo_afectado,
                 IFNULL(l.valor_antiguo, '-') AS valor_antiguo,
                 IFNULL(l.valor_nuevo, '-') AS valor_nuevo,
                 IFNULL(l.ip, '-') AS ip
            FROM log_evento_dato l
       LEFT JOIN persona actor ON actor.id_persona = l.id_actor
           WHERE l.fecha BETWEEN :d AND :h
           ORDER BY l.fecha DESC";

        $st = $db->prepare($sql);
        $st->execute([':d' => $ini, ':h' => $fin]);
    }

    // Crear el archivo CSV
    $out = fopen('php://temp', 'r+');

    // Escribir cabeceras
    $hdr = ['Fecha', 'Actor', 'Acción', 'Tabla', 'Campo', 'Valor antiguo', 'Valor nuevo', 'IP'];
    fputcsv($out, $hdr, ';');

    // Contar filas obtenidas
    $rowCount = 0;

    // Agregar datos al CSV
    while ($row = $st->fetch(PDO::FETCH_NUM)) {
        fputcsv($out, $row, ';');
        $rowCount++;
    }

    // Si no hay registros, agregar mensaje informativo
    if ($rowCount === 0) {
        $message = $month === 0
            ? "No hay registros disponibles en el sistema"
            : "No hay registros disponibles para " . date('F Y', strtotime($ini));
        $noDataRow = [$message, "", "", "", "", "", "", ""];
        fputcsv($out, $noDataRow, ';');
    }

    // Obtener resultado como string
    rewind($out);
    $csv = stream_get_contents($out);
    fclose($out);

    return $csv;
}




/**
 * Devuelve los pacientes que tienen (o han tenido) alguna cita con el
 * profesional indicado, junto con la próxima cita si existe.
 */
function getPacientesProfesional(int $idProf): array
{
    $sql="
      SELECT p.id_persona                           AS id,
             p.nombre,
             p.apellido1,
             p.apellido2,
             p.email,
             p.telefono,
             MIN(CASE
                   WHEN c.estado IN ('CONFIRMADA','PENDIENTE_VALIDACION','SOLICITADA')
                        AND c.fecha_hora >= NOW()
                   THEN c.fecha_hora END)           AS proxima_cita
        FROM persona  p
   INNER JOIN cita     c ON c.id_paciente = p.id_persona
       WHERE c.id_profesional = :prof
         AND p.activo = 1
         AND p.rol    = 'PACIENTE'
    GROUP BY p.id_persona
    ORDER BY p.nombre, p.apellido1";
    $st=conectar()->prepare($sql);
    $st->execute([':prof'=>$idProf]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/*────────────────── CONSENTIMIENTO ──────────────────*/
function tieneConsentimientoActivo(int $id): bool{
    $db=conectar();
    $q=$db->prepare("SELECT 1 FROM consentimiento
                      WHERE id_persona=? AND fecha_revocado IS NULL");
    $q->execute([$id]);
    return (bool)$q->fetchColumn();
}
function registrarConsentimiento(int $id,bool $nuevo,int $actor=0):void{
    $db=conectar();
    $hay=tieneConsentimientoActivo($id);

    if($nuevo && !$hay){
        $db->prepare("INSERT INTO consentimiento(id_persona,fecha_otorgado,canal)
                      VALUES(?,NOW(),'WEB')")->execute([$id]);
        logEvento($actor,$id,'consentimiento',null,null,'otorgado','INSERT');
    }elseif(!$nuevo && $hay){
        $db->prepare("UPDATE consentimiento
                         SET fecha_revocado=NOW()
                       WHERE id_persona=? AND fecha_revocado IS NULL")
           ->execute([$id]);
        logEvento($actor,$id,'consentimiento',null,'otorgado','revocado','UPDATE');
    }
}

/*────────────────── Tratamientos / tareas ───────────*//**
 * Devuelve todos los tratamientos de un paciente (filtrando por profesional si se pasa).
 */
function getTratamientosPaciente(int $idPac, int $idProf = null): array {
    $db = conectar();    $sql = "
      SELECT
        t.id_tratamiento,
        t.fecha_inicio,
        t.fecha_fin,
        t.frecuencia_sesiones,
        t.titulo,
        t.notas,
        dc.ruta as documento_ruta,
        dc.nombre_archivo as documento_nombre,
        dc.id_documento as documento_id
      FROM tratamiento t
      JOIN historial_clinico h ON h.id_historial = t.id_historial
      LEFT JOIN documento_clinico dc ON dc.id_tratamiento = t.id_tratamiento
      WHERE h.id_paciente = ?
    ";
    if ($idProf !== null) {
        $sql .= " AND t.id_profesional = " . intval($idProf);
    }
    $sql .= " ORDER BY t.id_tratamiento, dc.id_documento";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$idPac]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Agrupar documentos por tratamiento
    $tratamientos = [];
    foreach ($rows as $row) {
        $idTratamiento = $row['id_tratamiento'];
        
        if (!isset($tratamientos[$idTratamiento])) {
            $tratamientos[$idTratamiento] = [
                'id_tratamiento' => $row['id_tratamiento'],
                'fecha_inicio' => $row['fecha_inicio'],
                'fecha_fin' => $row['fecha_fin'],
                'frecuencia_sesiones' => $row['frecuencia_sesiones'],
                'titulo' => $row['titulo'],
                'notas' => $row['notas'],
                'documentos' => []
            ];
        }
        
        // Agregar documento si existe
        if ($row['documento_ruta']) {
            $tratamientos[$idTratamiento]['documentos'][] = [
                'id_documento' => $row['documento_id'],
                'ruta' => $row['documento_ruta'],
                'nombre_archivo' => $row['documento_nombre']
            ];
        }
    }
    
    return array_values($tratamientos);
}

/**
 * Crea un tratamiento con título y descripción, y opcionalmente sube un archivo
 * al historial (creándolo si aún no existe).
 */
function crearTratamiento(
    int $idPac,
    int $idProf,
    string $titulo,
    string $desc,
    $file = null,
    ?string $fechaInicio = null,
    ?string $fechaFin = null,
    ?int $frecuencia = null
): void {
    $db = conectar();
    $db->beginTransaction();

    try {
        // 1) Obtener o crear historial
        $h = $db->prepare("
          SELECT id_historial
            FROM historial_clinico
           WHERE id_paciente = ?
           LIMIT 1
        ");
        $h->execute([$idPac]);
        $idHist = $h->fetchColumn();
        if (!$idHist) {
            $db->prepare("
              INSERT INTO historial_clinico (id_paciente, fecha_inicio)
              VALUES (?, CURDATE())
            ")->execute([$idPac]);
            $idHist = $db->lastInsertId();
        }

        // 2) Verificar que los datos mínimos están presentes
        if (empty($titulo)) {
            throw new Exception('El título es obligatorio');
        }

        // 3) Insertar tratamiento con todos los campos
        $sql = "
          INSERT INTO tratamiento
            (id_historial, id_profesional, fecha_inicio, fecha_fin, 
             frecuencia_sesiones, titulo, notas)
          VALUES
            (?, ?, ?, ?, ?, ?, ?)
        ";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $idHist, 
            $idProf, 
            $fechaInicio ?: date('Y-m-d'), 
            $fechaFin ?: null,
            $frecuencia ?: null,
            $titulo, 
            $desc
        ]);        // Obtener el ID del tratamiento recién creado
        $idTratamiento = $db->lastInsertId();

        // 4) Documento opcional
        if ($file && $file->getError() === UPLOAD_ERR_OK) {
            // Verificar que el directorio existe y tiene permisos
            $uploadDir = __DIR__ . '/../public/uploads/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $nombre = uniqid() . '_' . $file->getClientFilename();
            $destino = $uploadDir . $nombre;
            $file->moveTo($destino);

            $db->prepare("
              INSERT INTO documento_clinico
                (id_historial, id_profesional, id_tratamiento, ruta, tipo)
              VALUES (?, ?, ?, ?, ?)
            ")->execute([
              $idHist,
              $idProf,
              $idTratamiento,
              'public/uploads/' . $nombre,
              $file->getClientMediaType()
            ]);
        }

        // Confirmar transacción
        $db->commit();
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log('Error al crear tratamiento: ' . $e->getMessage());
        throw $e; // Re-lanzar para manejo en capa superior
    }
}
/*────────────────── documentos del historial ─────────*/function getDocsPaciente(int $idPac, int $idProf = null): array {
  $db  = conectar();
  /*  ──  NO existe la columna descripcion en documento_clinico  ──
      Devolvemos solo lo que sí hay y,                   si luego
      quieres guardar título/desc,  añádelo vía ALTER TABLE    */
  $sql = "
    SELECT id_documento,
           ruta,
           tipo,
           ''  AS titulo,        -- placeholder para el front
           ''  AS descripcion    -- «»
      FROM documento_clinico
     WHERE id_historial IN(
            SELECT id_historial
              FROM historial_clinico
             WHERE id_paciente = ?)";
  if ($idProf) $sql .= " AND id_profesional = " . intval($idProf);
  $st = $db->prepare($sql); $st->execute([$idPac]);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

/*────────────────── citas del paciente ──────────────*/
function getCitasPaciente(int $idPac,int $idProf):array{
    $st=conectar()->prepare("
      SELECT id_cita,fecha_hora,estado
        FROM cita
       WHERE id_paciente=? AND id_profesional=?
       ORDER BY fecha_hora DESC");
    $st->execute([$idPac,$idProf]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/* procesamiento de acciones */
function procesarAccionCitaProfesional(int $idCita,array $body):void{
    $db=conectar(); $db->beginTransaction();
    $c=$db->prepare("SELECT * FROM cita WHERE id_cita=? FOR UPDATE");
    $c->execute([$idCita]);
    $cita=$c->fetch(PDO::FETCH_ASSOC);
    if(!$cita) throw new Exception('cita');

    switch($body['accion']){
      case 'CONFIRMAR'            : $nuevo='CONFIRMADA';      break;
      case 'RECHAZAR'             : $nuevo='CANCELADA';       break;
      case 'ACEPTAR_CAMBIO'       : $nuevo='CONFIRMADA';      break;
      case 'CANCELAR'             : $nuevo='CANCELADA';       break;
      case 'MANTENER'             : $nuevo='CONFIRMADA';      break;
      case 'MARCAR_ATENDIDA'      : $nuevo='ATENDIDA';        break;
      case 'MARCAR_NO_PRESENTADA' : $nuevo='NO_PRESENTADA';   break;
      case 'REPROGRAMAR':
           $fecha=$body['fecha']??null;
           if(!$fecha) throw new Exception('fecha');
           $db->prepare("UPDATE cita
                           SET fecha_hora=?,estado='PENDIENTE_VALIDACION'
                         WHERE id_cita=?")
              ->execute([$fecha,$idCita]);
           $db->commit(); return;
      default: throw new Exception('accion');
    }
    $db->prepare("UPDATE cita SET estado=? WHERE id_cita=?")
       ->execute([$nuevo,$idCita]);
    $db->commit();
}
