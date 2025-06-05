<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;


function conectar()
{
    try {
        $host = getenv('DB_HOST');
        $db   = getenv('DB_NAME');
        $user = getenv('DB_USER');
        $pass = getenv('DB_PASS');
        
        if (empty($host) || empty($db) || empty($user)) {
            error_log('Error: Faltan variables de entorno para la conexión a la base de datos');
            throw new Exception('Error de configuración en la conexión a la base de datos');
        }
        
        $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]);        
        /* Verificar conexión con una consulta simple */
        $pdo->query('SELECT 1');
        return $pdo;
    } catch (\PDOException $e) {
        error_log('Error de conexión a la base de datos: ' . $e->getMessage());
        throw new Exception('Error al conectar con la base de datos: ' . $e->getMessage());
    } catch (\Exception $e) {
        error_log('Error general en la conexión: ' . $e->getMessage());
        throw $e;
    }
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
    }    /* Comprobar existencia de usuario en BD */
    $db = conectar();
    $st = $db->prepare("SELECT * FROM persona WHERE id_persona = ?");
    $st->execute([(int)$info->sub]);
    if ($st->rowCount() === 0) return false;
    $usuario = $st->fetch(PDO::FETCH_ASSOC);

    /* Renovar token */
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
        /* Guardamos un snapshot de los parámetros; WARNING: puede contener datos sensibles */
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
            /* Generar JWT */
            $payload = [
                'sub' => (int)$row['id'],
                'rol' => $row['rol'],
                'exp' => time() + intval(getenv('JWT_EXPIRACION') ?: 3600)
            ];
            $jwt = \Firebase\JWT\JWT::encode($payload, getenv('JWT_SECRETO') ?: 'CAMBIAR_POR_SECRETO', 'HS256');

            return [
                'ok' => true,
                'token' => $jwt, /* Esto es imprescindible */
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
    error_log("============================================");
    error_log("Iniciando reservarCita - Nombre: $nombre, Email: $email, Fecha: $fecha, Teléfono: " . ($tel === null ? 'NULL' : $tel));
    error_log("============================================");    
    /* No convertimos $tel a cadena vacía, lo dejamos como null si es null */
    /* Validación de campos */
    if ($nombre === '' || $email === '' || $motivo === '' || $fecha === '') {
        error_log("Faltan campos obligatorios en la reserva");
        return ['ok' => false, 'mensaje' => 'Faltan campos obligatorios', 'status' => 400];
    }
    
    try {
        /* Parsear fecha y comprobar horario L-V 10–18 */
        error_log("Validando fecha y hora: $fecha");
        $fecha = new DateTime($fecha);
    } catch (Exception $e) {
        error_log("Error al parsear la fecha: " . $e->getMessage());
        return ['ok' => false, 'mensaje' => 'Fecha inválida', 'status' => 400];
    }    $w = (int)$fecha->format('w');    /* 0 domingo … 6 sábado */
    $h = (int)$fecha->format('G');    /* 0–23 */
    error_log("Día de la semana: $w, Hora: $h");
    
    if ($w < 1 || $w > 5 || $h < 10 || $h >= 18) {
        error_log("Fecha fuera de horario laboral: día $w, hora $h");
        return ['ok' => false, 'mensaje' => 'Fuera de horario laboral. Horario disponible: Lunes a Viernes de 10:00 a 18:00', 'status' => 400];
    }
    $ts = $fecha->format('Y-m-d H:i:s');
    error_log("Fecha validada correctamente: $ts");    /* Obtener o crear persona */
    error_log("Buscando o creando persona con email: $email");
    $idPersona = obtenerCrearPersona($nombre, $email, $tel);
    error_log("ID de persona obtenido/creado: $idPersona");

    /* Asegurar paciente */
    error_log("Asegurando que exista entrada en paciente para ID: $idPersona");    asegurarPaciente($idPersona); /* Conectar y buscar profesional disponible */
    $db = conectar();
    
    /* Verificar si hay profesionales en la base de datos */
    $checkProf = $db->query("SELECT COUNT(*) FROM profesional");
    $profCount = $checkProf->fetchColumn();
    error_log("Número de profesionales en la base de datos: $profCount");
    
    if ($profCount == 0) {
        error_log("No hay profesionales en la base de datos");
        return ['ok'=>false,'mensaje'=>'No hay profesionales registrados en el sistema','status'=>409];
    }
      $sql = "
      SELECT p.id_profesional
        FROM profesional p
       WHERE NOT EXISTS (
         SELECT 1 FROM bloque_agenda b
          WHERE b.id_profesional = p.id_profesional
            AND b.tipo_bloque    IN ('AUSENCIA','VACACIONES')
            AND :ts_bloque BETWEEN b.fecha_inicio
                       AND DATE_SUB(b.fecha_fin,INTERVAL 1 SECOND)
       )
         AND NOT EXISTS (
         SELECT 1 FROM cita c
          WHERE c.id_profesional = p.id_profesional
            AND c.estado        IN ('PENDIENTE_VALIDACION','SOLICITADA',
                                    'CONFIRMADA','ATENDIDA')
            AND c.fecha_hora    = :ts_cita
       )
       ORDER BY (
         SELECT COUNT(*) FROM cita c2
          WHERE c2.id_profesional = p.id_profesional
            AND DATE(c2.fecha_hora) = DATE(:ts_fecha)
       ) ASC
       LIMIT 1";
    error_log("Ejecutando consulta para buscar profesional disponible para la fecha: $ts");
    $st = $db->prepare($sql);
    $st->execute([
        ':ts_bloque' => $ts,
        ':ts_cita'   => $ts,
        ':ts_fecha'  => $ts
    ]);$idProf = $st->fetchColumn();    error_log("Profesional encontrado para la cita: " . ($idProf ? $idProf : "Ninguno"));
    
    if (!$idProf) {
        return ['ok'=>false,'mensaje'=>'No hay profesionales disponibles para esta fecha y hora','status'=>409];
    }    try {        /* Insertar cita (ya tenemos $idPersona y ya hemos asegurado paciente anteriormente) */
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
            [                ':pac'   => $idPersona,
                ':prof'  => $idProf,
                ':ts'    => $ts,
                ':nom'   => $nombre,
                ':tel'   => $tel, /* Mantener null si es null */
                ':email' => $email,
                ':motivo'=> $motivo
            ],            $actor,                /* Quién lo hace */
            'cita'                 /* Tabla auditada */
            /* id_afectado lo ponemos justo después cuando lo sepamos */
        );

        if (!$insertOk) {
            return ['ok'=>false,'mensaje'=>'Error al crear la cita','status'=>500];
        }

        /* completar id_afectado en el log recién creado */
        $idCita = (int)$db->lastInsertId();

        return ['ok'=>true,'mensaje'=>'Cita reservada correctamente'];
    } catch (PDOException $e) {
        error_log("Error de base de datos al crear cita: " . $e->getMessage());
        /* Devolver un mensaje más amigable para el usuario */
        return ['ok'=>false,'mensaje'=>'Error al procesar la solicitud. Por favor, inténtelo de nuevo más tarde.','status'=>500];
    } catch (Exception $e) {
        error_log("Error al crear cita: " . $e->getMessage());
        return ['ok'=>false,'mensaje'=>'Error al procesar la solicitud: ' . $e->getMessage(),'status'=>500];
    }
}

/**
 * Busca persona por email o teléfono. Si no existe, la crea.
 * Devuelve id_persona.
 */
function obtenerCrearPersona(string $nombre, string $email, ?string $tel): int
{      $db = conectar();
    /* Buscar primero por email (prioridad) */
    $stmt = $db->prepare("
      SELECT id_persona
        FROM persona
       WHERE email = :email
       LIMIT 1
    ");
    $stmt->execute([':email' => $email]);
    if ($id = $stmt->fetchColumn()) {
        return (int)$id;
    }    
    /* Si no hay por email y tenemos teléfono, buscar por teléfono exacto */
    if ($tel !== null && $tel !== '') {
        $stmt = $db->prepare("
          SELECT id_persona
            FROM persona
           WHERE telefono = :tel
           LIMIT 1
        ");
        $stmt->execute([':tel' => $tel]);        if ($id = $stmt->fetchColumn()) {
            return (int)$id;
        }
    }
      /* Crear nuevo usuario si no existe */
    error_log("Creando nueva persona con email: $email, teléfono: " . ($tel === null ? 'NULL' : $tel));
      /* Separar nombre y apellido (si es posible) */
    $nombreCompleto = explode(' ', $nombre);
    $nombrePila = $nombreCompleto[0]; /* Primera palabra como nombre */
    $apellido = count($nombreCompleto) > 1 ? 
               implode(' ', array_slice($nombreCompleto, 1)) : /* Resto como apellido */
               ''; /* Si solo hay una palabra, apellido vacío */
      /* Manejar teléfono vacío para evitar violación de la restricción de unicidad */
    $telefono = $tel;
    if ($telefono === null || $telefono === '') {
        /* Si el teléfono está vacío, usamos un valor único para evitar colisiones */
        $telefono = 'NO_TEL_' . uniqid();
        error_log("Usando teléfono temporal para cumplir con restricción de unicidad: $telefono");
    }
    
    $ins = $db->prepare("
      INSERT INTO persona
        (nombre, apellido1, email, telefono, rol, password_hash)
      VALUES
        (:nom, :ap1, :email, :tel, 'PACIENTE', :pass)
    ");
    $ins->execute([
        ':nom'   => $nombrePila,
        ':ap1'   => $apellido,
        ':email' => $email,
        ':tel'   => $telefono,
        ':pass'  => password_hash('', PASSWORD_DEFAULT), /* Empty password hash as placeholder */
    ]);
    $newId = (int)$db->lastInsertId();
    error_log("Nueva persona creada con ID: $newId");
    return $newId;
}

/**
 * Asegura que exista una fila en paciente para id_persona dado.
 */
function asegurarPaciente(int $idPersona): void
{    $db = conectar();
    $stmt = $db->prepare("SELECT 1 FROM paciente WHERE id_paciente = :id");
    $stmt->execute([':id' => $idPersona]);
    if ($stmt->fetch()) return;
    /* Insertamos con tipo 'ADULTO' por defecto */
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
 * Devuelve bloques + citas confirmadas entre fechas. * Si $profId no es null filtra solo ese profesional.
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
{    /* Primero intentamos en bloque */
    if (execLogged('DELETE FROM bloque_agenda WHERE id_bloque=:id', [':id'=>$id], $actor, 'bloque_agenda', $id))
        return true;
    /* Si no estaba, probamos en cita */
    return execLogged('DELETE FROM cita WHERE id_cita=:id', [':id'=>$id], $actor, 'cita', $id);
}

/* ─────────────────────── helper de envío SMTP ─────────────────────── */
function enviarEmail(string $to, string $subject, string $htmlBody): bool
{    /* Obtener credenciales SMTP del entorno */
    $host = getenv('SMTP_HOST');
    $user = getenv('SMTP_USER');
    $pass = getenv('SMTP_PASS');

    /* Si no hay configuración, logueamos y continuamos */
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

        /* AÑADIR ESTAS LÍNEAS PARA MANEJAR TILDES Y CARACTERES ESPECIALES */
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
      SELECT c.id_cita                AS id,
             DATE_FORMAT(c.fecha_hora,'%d/%m/%Y %H:%i') AS fecha,
             c.estado                 AS tipo,
             CONCAT(pa.nombre,' ',pa.apellido1) paciente,
             CONCAT(pr.nombre,' ',pr.apellido1) profesional
        FROM cita c
        JOIN persona pa ON pa.id_persona = c.id_paciente
        JOIN persona pr ON pr.id_persona = c.id_profesional
       WHERE c.estado = 'PENDIENTE_VALIDACION'";

    $params = [];    if ($rol === 'admin') {
        /* Solo peticiones hechas por pacientes (web / app) */
        $sql .= " AND c.origen IN ('WEB','APP')";
    } else { /* Profesional */
        $sql .= " AND c.id_profesional = :p";
        $params[':p'] = $uid;
    }

    $sql .= " ORDER BY c.fecha_hora";

    $st = $db->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/* procesa confirmación / rechazo */
function procesarNotificacion(int $id, string $acc, int $uid, string $rol): bool
{
    error_log("Iniciando procesarNotificacion: ID=$id, Acción=$acc, Usuario=$uid, Rol=$rol");
    $nuevo = $acc === 'CONFIRMAR' ? 'CONFIRMADA' : 'CANCELADA';
    $db = conectar();
    $db->beginTransaction();
      try {
        /* 1) Bloquear la cita */
        $stmt = $db->prepare("
            SELECT c.*, p.email pacienteEmail
              FROM cita  c
        INNER JOIN persona p ON p.id_persona = c.id_paciente
             WHERE c.id_cita = ? FOR UPDATE");
        $stmt->execute([$id]);
        if (!$row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            error_log("procesarNotificacion: Cita $id no encontrada");
            throw new Exception('Cita inexistente');
        }

        /* Permiso profesional */
        if ($rol === 'profesional' && (int)$row['id_profesional'] !== $uid) {
            error_log("procesarNotificacion: Usuario $uid sin permiso para cita $id");
            throw new Exception('Prohibido');
        }
        
        /* Verificar que no esté ya procesada */
        if ($row['estado'] === 'CONFIRMADA' || $row['estado'] === 'CANCELADA') {
            error_log("procesarNotificacion: Cita $id ya está {$row['estado']}");
            throw new Exception('Estado inválido');
        }

        /* 2) Actualizar estado */
        error_log("Actualizando estado de cita $id a $nuevo");
        $db->prepare("UPDATE cita SET estado = ? WHERE id_cita = ?")
            ->execute([$nuevo, $id]);

        /* 3) Si se confirma, crear bloque en la agenda del profesional */
        if ($acc === 'CONFIRMAR') {
            /* Verificar que no exista ya un bloque para esta cita */
            $stmt = $db->prepare("SELECT COUNT(*) FROM bloque_agenda WHERE id_profesional = ? AND fecha_inicio = ?");
            $stmt->execute([(int)$row['id_profesional'], $row['fecha_hora']]);
            $bloqueExistente = (int)$stmt->fetchColumn();
            
            error_log("Verificando bloques existentes: " . ($bloqueExistente ? "SI existe" : "NO existe"));
            
            if ($bloqueExistente === 0) {
                /* Crear un bloque de 1 hora para la cita */
                $inicio = $row['fecha_hora'];
                $fin = date('Y-m-d H:i:s', strtotime($inicio . ' +1 hour'));
                
                error_log("Creando bloque de agenda: Prof={$row['id_profesional']}, Inicio=$inicio, Fin=$fin");
                
                $db->prepare("
                    INSERT INTO bloque_agenda (
                        id_profesional, fecha_inicio, fecha_fin, 
                        tipo_bloque, comentario
                    ) VALUES (
                        :p, :i, :f, 'CITA', :c
                    )
                ")->execute([
                    ':p' => $row['id_profesional'],
                    ':i' => $inicio,
                    ':f' => $fin,
                    ':c' => "Cita con paciente #{$row['id_paciente']}"
                ]);
                error_log("Bloque de agenda creado correctamente");
            } else {
                error_log("No se creó bloque de agenda porque ya existe");
            }
        }          /* 4) Registrar notificación en BD */
        /* Formatear fecha y hora para el mensaje */
        $fechaFormateada = date('d/m/Y', strtotime($row['fecha_hora']));
        $horaFormateada = date('H:i', strtotime($row['fecha_hora']));
        
        /* Crear un mensaje más completo y profesional */
        $asuntoEmail = $acc === 'CONFIRMAR' ? 'Confirmación de su cita' : 'Cancelación de su cita';
        
        /* HTML para el cuerpo del email */
        $mensaje = '
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #ddd; padding: 20px; border-radius: 5px;">
            <div style="text-align: center; margin-bottom: 20px;">
                <img src="https://iatrenda-petaka.s3.eu-west-3.amazonaws.com/images/petaka.jpg" alt="Clínica Petaka Logo" style="max-width: 150px;" />
                <h2 style="color: #3a6ea5;">Clínica Logopédica Petaka</h2>
            </div>
            
            <p>Estimado/a <strong>'.htmlspecialchars($row['nombre_contacto']).'</strong>,</p>
            
            <p>'.($acc === 'CONFIRMAR' 
                ? 'Nos complace confirmarle que su cita ha sido <strong>confirmada</strong>.' 
                : 'Le informamos que su cita ha sido <strong>cancelada</strong>.').'</p>
            
            <div style="background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0;">
                <p><strong>Fecha:</strong> '.$fechaFormateada.'</p>
                <p><strong>Hora:</strong> '.$horaFormateada.'</p>
                <p><strong>Motivo:</strong> '.htmlspecialchars($row['motivo']).'</p>
            </div>
            
            '.($acc === 'CONFIRMAR' ? '
            <p>Por favor, recuerde llegar 10 minutos antes de la hora de su cita. Si necesita cancelar o reprogramar, contáctenos con al menos 24 horas de antelación.</p>
            
            <div style="background-color: #e8f4fc; padding: 15px; border-radius: 5px; margin: 20px 0;">
                <p><strong>Ubicación:</strong></p>
                <p>Av. Ejemplo 123</p>
                <p>29680 Estepona</p>
                <p>Tel: +34 123 456 789</p>
                <p>Email: info@clinicapetaka.com</p>
            </div>
            ' : '
            <p>Si desea programar una nueva cita, puede hacerlo a través de nuestra página web o contactándonos directamente.</p>
            ').'
            
            <p>Gracias por confiar en nuestros servicios.</p>
            
            <p>Atentamente,<br>El equipo de Petaka</p>
            
            <hr style="border: 1px solid #eee; margin: 20px 0;" />
            
            <div style="font-size: 10px; color: #777; text-align: justify;">
                <p><strong>ADVERTENCIA LEGAL:</strong> Este mensaje, junto a la documentación que en su caso se adjunta, se dirige exclusivamente a su destinatario y puede contener información privilegiada o confidencial. Si no es Vd. el destinatario indicado, queda notificado de que la utilización, divulgación y/o copia sin autorización está prohibida en virtud de la legislación vigente. Si ha recibido este mensaje por error, le rogamos que nos lo comunique inmediatamente por esta misma vía y proceda a su destrucción.</p>
                
                <p>Conforme a lo dispuesto en la L.O. 3/2018 de 5 de diciembre, de Protección de Datos Personales y garantía de los derechos digitales, Clínica Petaka, logopedas, le informa que los datos de carácter personal que proporcione serán recogidos en un fichero cuyo responsable es Clínica Petaka, logopedas y serán tratados con la exclusiva finalidad expresada en el mismo. Podrá acceder a sus datos, rectificarlos, cancelarlos y oponerse a su tratamiento, en los términos y en las condiciones previstas en la LOPD, dirigiéndose por escrito a info@clinicapetaka.com.</p>
            </div>
        </div>';

        error_log("Insertando notificación: De=$uid, Para={$row['id_paciente']}, Cita=$id");
        
        $stmt = $db->prepare("
            INSERT INTO notificacion(id_emisor,id_destino,id_cita,
                                tipo,asunto,cuerpo)
            VALUES (:e,:d,:c,'EMAIL',:asunto,:b)
        ");
        $stmt->execute([
            ':e' => $uid,
            ':d' => $row['id_paciente'],
            ':c' => $id,
            ':asunto' => $asuntoEmail,
            ':b' => $mensaje
        ]);
        
        error_log("Notificación insertada correctamente");        /* Commit the transaction first */
        $db->commit();
        error_log("Transacción confirmada correctamente");        
        
        /* 5) Enviar email (fuera de la transacción) */
        try {
            error_log("Intentando enviar email a {$row['pacienteEmail']}");
            $emailEnviado = enviarEmail(
                $row['pacienteEmail'],
                $asuntoEmail,
                $mensaje
            );
            error_log("Resultado del envío de email: " . ($emailEnviado ? "Enviado" : "Falló"));
        } catch (Exception $e) {            /* Solo logueamos el error del email, pero no afecta al resultado */
            error_log("Error enviando email para cita $id: " . $e->getMessage());
        }

        return true;    } catch (Exception $e) {
        /* Rollback en caso de error */
        $db->rollBack();
        error_log("Error procesando notificación: " . $e->getMessage());
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

/* decodifica añadiendo los = que falten */
function uidDecode(string $uid): int
{    $uid = strtr($uid, '-_', '+/');

    /* Padding */
    $falta = strlen($uid) % 4;
    if ($falta) {
        $uid .= str_repeat('=', 4 - $falta);
    }
    return (int) base64_decode($uid);
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
    /* Primero buscar si hay un usuario inactivo con el mismo email o NIF */
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
    
    /* Si encontramos un usuario inactivo, lo reactivamos */
    if ($inactivo) {
        $idReactivado = (int)$inactivo['id_persona'];
          /* Preparar campos a actualizar */
        $set = ['activo = 1']; /* Reactivar el usuario */
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
      /* Si llegamos aquí, continuamos con la lógica normal de upsert */
    /* (el resto de la función queda igual) */
    
    /* Si se proporciona un ID forzado para actualización */
    if ($forceUpdateId > 0) {
        $st = $db->prepare("SELECT * FROM persona WHERE id_persona = ?");
        $st->execute([$forceUpdateId]);
        $prev = $st->fetch(PDO::FETCH_ASSOC);
        
        if (!$prev) {
            throw new Exception("No se encontró el usuario con ID $forceUpdateId");
        }
    } else {
        /* Búsqueda normal por email/teléfono (solo usuarios activos) */
        $st = $db->prepare("
            SELECT * FROM persona 
            WHERE (email = :email OR (telefono IS NOT NULL AND telefono = :tel))
            AND activo = 1
            LIMIT 1
        ");
        $st->execute([':email' => $d['email'] ?? '', ':tel' => $d['telefono'] ?? '']);
        $prev = $st->fetch(PDO::FETCH_ASSOC);
    }
      /* Preparar campos a actualizar */
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

    /* Si se pasa 0 como mes, mostrar todos los logs (sin filtro por fecha) */
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
           ORDER BY l.fecha DESC";        $st = $db->prepare($sql);
        $st->execute();
    } else {
        /* Usar el mes específico */
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

        $st = $db->prepare($sql);        $st->execute([':d' => $ini, ':h' => $fin]);
    }

    /* Crear el archivo CSV */    $out = fopen('php://temp', 'r+');

    /* Escribir cabeceras */    $hdr = ['Fecha', 'Actor', 'Acción', 'Tabla', 'Campo', 'Valor antiguo', 'Valor nuevo', 'IP'];
    fputcsv($out, $hdr, ';');

    /* Contar filas obtenidas */    $rowCount = 0;

    /* Agregar datos al CSV */
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
    $db->beginTransaction();    try {
        /* Obtener o crear historial */
        $h = $db->prepare("
          SELECT id_historial
            FROM historial_clinico
           WHERE id_paciente = ?
           LIMIT 1
        ");
        $h->execute([$idPac]);        $idHist = $h->fetchColumn();
        if (!$idHist) {
            $db->prepare("
              INSERT INTO historial_clinico (id_paciente, fecha_inicio)
              VALUES (?, CURDATE())
            ")->execute([$idPac]);
            $idHist = $db->lastInsertId();
        }

        /* Verificar que los datos mínimos están presentes */        if (empty($titulo)) {
            throw new Exception('El título es obligatorio');
        }

        /* Insertar tratamiento con todos los campos */
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
            $frecuencia ?: null,            $titulo, 
            $desc
        ]);        
        /* Obtener el ID del tratamiento recién creado */        $idTratamiento = $db->lastInsertId();

        /* Documento opcional */        if ($file && $file->getError() === UPLOAD_ERR_OK) {
            /* Verificar que el directorio existe y tiene permisos */
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
              $file->getClientMediaType()            ]);
        }

        /* Confirmar transacción */
        $db->commit();
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log('Error al crear tratamiento: ' . $e->getMessage());
        throw $e; // Re-lanzar para manejo en capa superior
    }
}
/*────────────────── documentos del historial ─────────*/function getDocsPaciente(int $idPac, int $idProf = null): array {
  $db  = conectar();
  
  $sql = "
    SELECT d.id_documento,
           d.ruta,
           d.tipo,
           d.fecha_subida,
           h.diagnostico_preliminar,
           h.diagnostico_final,           ''  AS titulo,        /* Placeholder para el front */
           ''  AS descripcion    /* Placeholder para el front */
      FROM documento_clinico d
      JOIN historial_clinico h ON d.id_historial = h.id_historial
     WHERE d.id_historial IN(
            SELECT id_historial
              FROM historial_clinico
             WHERE id_paciente = ?)
       AND d.id_tratamiento IS NULL";  /* Excluir documentos de tratamientos */
  if ($idProf) $sql .= " AND d.id_profesional = " . intval($idProf);
  $st = $db->prepare($sql); $st->execute([$idPac]);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

/*────────────────── crear documento del historial ─────────*/
function crearDocumentoHistorial(int $idPac,int $idProf,$file = null,string $diagnosticoPreliminar = '',string $diagnosticoFinal= '',string $tipo= 'documento'): array {
    $db = conectar();
    $db->beginTransaction();

    try {
        /* 1)  CREAMOS SIEMPRE UN NUEVO EPISODIO --------------------------- */
        $db->prepare("
            INSERT INTO historial_clinico
                   (id_paciente, fecha_inicio,
                    diagnostico_preliminar, diagnostico_final)
            VALUES   (?,CURDATE(),?,?)
        ")->execute([$idPac, $diagnosticoPreliminar, $diagnosticoFinal ?: null]);

        $idHistorial = (int)$db->lastInsertId();

        /* 2)  Manejo del archivo (opcional) ------------------------------- */
        $rutaArchivo = null;

        if ($file && $file->getError() === UPLOAD_ERR_OK) {
            /* Directorio destino (‘public/uploads/historial/’) */
            $dir = __DIR__ . '/../public/uploads/historial/';
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException('No se pudo crear el directorio de subida');
            }

            $ext     = strtolower(pathinfo($file->getClientFilename(), PATHINFO_EXTENSION));
            $slug    = $diagnosticoPreliminar
                         ? preg_replace('/[^a-z0-9]+/i', '_',
                             substr($diagnosticoPreliminar, 0, 30))
                         : 'historial';
            $nombre  = sprintf('%s_%d_%d.%s', $slug, $idPac, time(), $ext);
            $destino = $dir . $nombre;
            $rutaArchivo = 'uploads/historial/' . $nombre;

            /* Mover fichero */
            $file->moveTo($destino);

            /* Deducir tipo si no se pasó */
            if ($tipo === 'documento' || $tipo === '') {
                $tipo = in_array($ext, ['jpg','jpeg','png','gif','webp']) ? 'imagen'
                      : ($ext === 'pdf'                                 ? 'pdf'
                      : 'documento');
            }

            /* Registrar en documento_clinico */
            $db->prepare("
                INSERT INTO documento_clinico
                       (id_historial, id_profesional,
                        ruta, tipo, fecha_subida, id_tratamiento)
                VALUES (:h, :p, :r, :t, NOW(), NULL)
            ")->execute([
                ':h' => $idHistorial,
                ':p' => $idProf,
                ':r' => $rutaArchivo,
                ':t' => $tipo
            ]);
        }

        $db->commit();

        return [
            'ok'                  => true,
            'mensaje'             => 'Documento subido al historial correctamente',
            'id_historial'        => $idHistorial,
            'ruta'                => $rutaArchivo,
            'diagnostico_final'   => $diagnosticoFinal,
            'diagnostico_preliminar' => $diagnosticoPreliminar,
            'tipo'                => $tipo
        ];

    } catch (Throwable $e) {
        $db->rollBack();

        /* si hubo fichero ya subido lo eliminamos para no dejar huérfanos */
        if (isset($rutaArchivo) && $rutaArchivo !== null) {
            $f = __DIR__ . '/../public/' . $rutaArchivo;
            if (file_exists($f)) unlink($f);
        }
        throw $e;   /* Se manejará donde se llame a la función */
    }
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
