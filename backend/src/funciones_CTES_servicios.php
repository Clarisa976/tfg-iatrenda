<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/Services/S3Service.php';
require_once __DIR__ . '/Controllers/DocumentController.php';

function conectar()
{
    try {
        $host = getenv('DB_HOST');
        $baseDatosNombre = getenv('DB_NAME');
        $user = getenv('DB_USER');
        $pass = getenv('DB_PASS');
        $port = getenv('DB_PORT') ?: 5432;

        if (empty($host) || empty($baseDatosNombre) || empty($user)) {
            error_log('Error: Faltan variables de entorno para la conexión a la base de datos');
            throw new Exception('Error de configuración en la conexión a la base de datos');
        }

        $dsn = "pgsql:host=$host;port=$port;dbname=$baseDatosNombre";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]);

        $pdo->exec("SET timezone = 'Europe/Madrid'");

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


function verificarTokenUsuario()
{
    $cabeceras = apache_request_headers();
    if (empty($cabeceras['Authorization'])) return false;
    $partes = explode(' ', $cabeceras['Authorization']);
    if (count($partes) !== 2) return false;
    $tokenRecibido = $partes[1];

    try {
        $claveSecreta = getenv('JWT_SECRETO');
        $datosToken = \Firebase\JWT\JWT::decode(
            $tokenRecibido,
            new \Firebase\JWT\Key($claveSecreta, 'HS256')
        );
    } catch (\Throwable $error) {
        return false;
    }

    $baseDatos = conectar();
    $consulta = $baseDatos->prepare("SELECT * FROM persona WHERE id_persona = ?");
    $consulta->execute([(int)$datosToken->sub]);
    if ($consulta->rowCount() === 0) return false;
    $datosUsuario = $consulta->fetch(PDO::FETCH_ASSOC);

    $contenidoNuevoToken = [
        'sub' => (int)$datosUsuario['id_persona'],
        'rol' => $datosUsuario['rol'],
        'exp' => time() + intval(getenv('JWT_EXPIRACION') ?: 3600)
    ];
    $tokenRenovado = \Firebase\JWT\JWT::encode($contenidoNuevoToken, $claveSecreta, 'HS256');

    return ['usuario' => $datosUsuario, 'token' => $tokenRenovado];
}
/* Función para obtener el último consentimiento dado*/
function obtenerUltimoConsentimiento(int $idPersona): ?array
{
    $baseDatos = conectar();
    $busqueda = $baseDatos->prepare(
        "SELECT id_consentimiento, fecha_otorgado, fecha_revocado, canal
           FROM consentimiento
          WHERE id_persona = :id
          ORDER BY fecha_otorgado DESC
          LIMIT 1"
    );
    $busqueda->execute([':id' => $idPersona]);
    $resultado = $busqueda->fetch(PDO::FETCH_ASSOC);
    return $resultado ?: null;
}

function tieneConsentimientoVigente(int $idPersona): bool
{
    $consentimiento = obtenerUltimoConsentimiento($idPersona);
    return $consentimiento !== null && $consentimiento['fecha_revocado'] === null;
}

/* REGISTRO DE ACTIVIDADES */
/* Función para registrar una actividad*/
function registrarActividad(int $quienLoHace, ?int $aQuienAfecta, string $queTabla, ?string $queCampo, $valorAnterior, $valorNuevo, string $queAccion): bool
{
    try {
        $baseDatos = conectar();
        
        $validActions = ['INSERT', 'UPDATE', 'DELETE', 'SELECT'];
        $accionMapeada = strtoupper($queAccion);
        
        if (!in_array($accionMapeada, $validActions)) {
            $accionMap = [
                'CREATE' => 'INSERT', 'CREAR' => 'INSERT', 'ADD' => 'INSERT',
                'MODIFY' => 'UPDATE', 'EDIT' => 'UPDATE', 'CHANGE' => 'UPDATE',
                'REMOVE' => 'DELETE', 'DROP' => 'DELETE', 'TEST' => 'SELECT'
            ];
            $accionMapeada = $accionMap[$accionMapeada] ?? 'SELECT';
        }

        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        if (empty($ipAddress)) {
            $ipAddress = null;
        }
        
        $consulta = "INSERT INTO log_evento_dato
                (id_actor,id_afectado,tabla_afectada,campo_afectado,
                 valor_antiguo,valor_nuevo,accion,ip)
                VALUES (:a,:af,:t,:c,:v1,:v2,:ac,:ip)";
        return $baseDatos->prepare($consulta)->execute([
            ':a'  => $quienLoHace,
            ':af' => $aQuienAfecta ?: null,
            ':t'  => $queTabla,
            ':c'  => $queCampo,
            ':v1' => $valorAnterior,
            ':v2' => $valorNuevo,
            ':ac' => $accionMapeada,
            ':ip' => $ipAddress
        ]);
    } catch (Throwable $error) {
        error_log('Fallo al registrar actividad: ' . $error->getMessage());
        return false;
    }
}

/* Función para registrar la consulta que se hace y así guardarla para futuras audiotrías */
function execLogged(string $consultaSql, array $parametros, int $actor = 0, ?string $tabla = null, ?int $afect = null): bool
{
    $baseDatos = conectar();
    $consulta = $baseDatos->prepare($consultaSql);
    $ok = $consulta->execute($parametros);

    if ($ok && preg_match('/^\s*(INSERT|UPDATE|DELETE)/i', $consultaSql, $m)) {
        $accion = strtoupper($m[1]);
        registrarActividad(
            $actor,
            $afect ?? 0,
            $tabla ?? 'desconocida',
            null,
            null,
            json_encode($parametros, JSON_UNESCAPED_UNICODE),
            $accion
        );
    }
    return $ok;
}
/* Función para iniciar sesión */
function iniciarSesionConEmail(string $email, string $contraseña): array
{
    try {
        $pdo = conectar();
        
        $consulta = "SELECT id_persona as id,
                       (nombre || ' ' || apellido1) as nombre,
                       email,
                       rol
                FROM   persona                
                WHERE  email = :e
                  AND  password_hash IS NOT NULL
                  AND  password_hash = encode(sha256(:p::bytea), 'hex')
                  AND  activo = true
                LIMIT  1";
        
        $busqueda = $pdo->prepare($consulta);
        $busqueda->execute(['e' => $email, 'p' => $contraseña]);
        
        if ($datosUsuario = $busqueda->fetch()) {
            $contenidoToken = [
                'sub' => (int)$datosUsuario['id'],
                'rol' => $datosUsuario['rol'],
                'exp' => time() + intval(getenv('JWT_EXPIRACION') ?: 3600)
            ];
            
            $tokenSesion = \Firebase\JWT\JWT::encode($contenidoToken, getenv('JWT_SECRETO') ?: 'CAMBIAR_POR_SECRETO', 'HS256');

            return [
                'ok' => true,
                'token' => $tokenSesion,
                'usuario' => $datosUsuario
            ];
        }
        
        return ['ok' => false, 'mensaje' => 'Email o contraseña incorrectos'];
    } catch (Exception $error) {
        error_log("Error in iniciarSesionConEmail: " . $error->getMessage());
        return ['ok' => false, 'error' => $error->getMessage()];
    }
}

/* Función para pedir una cita, busca a la persona, si no existe la crea. Luego busca al profesional libre para el día y la hora */
function pedirCitaNueva(string $nombre, string $email, ?string $telefono, string $motivo, string $fecha, int $quienLoPide = 0): array
{
    if ($nombre === '' || $email === '' || $motivo === '' || $fecha === '') {
        return ['ok' => false, 'mensaje' => 'Faltan campos obligatorios', 'status' => 400];
    }

    try {
        $fecha = new DateTime($fecha);
    } catch (Exception $e) {
        return ['ok' => false, 'mensaje' => 'Fecha inválida', 'status' => 400];
    }
    
    $w = (int)$fecha->format('w');
    $h = (int)$fecha->format('G');

    if ($w < 1 || $w > 5 || $h < 10 || $h >= 18) {
        return ['ok' => false, 'mensaje' => 'Fuera de horario laboral. Horario disponible: Lunes a Viernes de 10:00 a 18:00', 'status' => 400];
    }
    
    $ts = $fecha->format('Y-m-d H:i:s');
    $idPersona = buscarOCrearPersona($nombre, $email, $telefono);
    asegurarPaciente($idPersona);
    
    $baseDatos = conectar();
    $checkProf = $baseDatos->query("SELECT COUNT(*) FROM profesional");
    $profCount = $checkProf->fetchColumn();

    if ($profCount == 0) {
        return ['ok' => false, 'mensaje' => 'No hay profesionales registrados en el sistema', 'status' => 409];
    }

    $consultaSql = "
      SELECT p.id_profesional
        FROM profesional p
       WHERE NOT EXISTS (
         SELECT 1 FROM bloque_agenda b
          WHERE b.id_profesional = p.id_profesional
            AND b.tipo_bloque IN ('AUSENCIA', 'VACACIONES', 'BAJA')
            AND ?::timestamptz BETWEEN b.fecha_inicio AND (b.fecha_fin - INTERVAL '1 second')
       )
         AND NOT EXISTS (
         SELECT 1 FROM cita c
          WHERE c.id_profesional = p.id_profesional
            AND c.estado IN ('PENDIENTE_VALIDACION', 'SOLICITADA', 'CONFIRMADA', 'ATENDIDA')
            AND c.fecha_hora = ?::timestamptz
       )
       ORDER BY (
         SELECT COUNT(*) FROM cita c2
          WHERE c2.id_profesional = p.id_profesional
            AND DATE(c2.fecha_hora) = DATE(?::timestamptz)
       ) ASC
       LIMIT 1";
    
    $consulta = $baseDatos->prepare($consultaSql);
    $consulta->execute([$ts, $ts, $ts]);
    $idProf = $consulta->fetchColumn();

    if (!$idProf) {
        return ['ok' => false, 'mensaje' => 'No hay profesionales disponibles para esta fecha y hora', 'status' => 409];
    }

    try {
        $consultaCita = "INSERT INTO cita
               (id_paciente, id_profesional, id_bloque,
                fecha_hora, estado,
                nombre_contacto, telefono_contacto, email_contacto,
                motivo, origen)
             VALUES
               (?, ?, NULL,
                ?::timestamptz, 'PENDIENTE_VALIDACION',
                ?, ?, ?,
                ?, 'WEB')
             RETURNING id_cita";
        
        $stmtCita = $baseDatos->prepare($consultaCita);
        $citaCreada = $stmtCita->execute([
            $idPersona, $idProf, $ts, $nombre, $telefono, $email, $motivo
        ]);
        
        if (!$citaCreada) {
            return ['ok' => false, 'mensaje' => 'Error al crear la cita', 'status' => 500];
        }
        
        $idCita = $stmtCita->fetchColumn();
        
        registrarActividad(
            $quienLoPide, $idPersona, 'cita', null, null,
            json_encode(['id_cita' => $idCita, 'fecha_hora' => $ts], JSON_UNESCAPED_UNICODE),
            'INSERT'
        );

        return ['ok' => true, 'mensaje' => 'Cita reservada correctamente'];
    } catch (Exception $e) {
        error_log("Error al crear cita: " . $e->getMessage());
        return ['ok' => false, 'mensaje' => 'Error al procesar la solicitud: ' . $e->getMessage(), 'status' => 500];
    }
}

/* Función para buscar o crear a una persona */
function buscarOCrearPersona(string $nombre, string $email, ?string $telefono): int
{
    $baseDatos = conectar();
    
    $busqueda = $baseDatos->prepare("SELECT id_persona FROM persona WHERE email = :email LIMIT 1");
    $busqueda->execute([':email' => $email]);
    if ($idEncontrado = $busqueda->fetchColumn()) {
        return (int)$idEncontrado;
    }

    if ($telefono !== null && $telefono !== '') {
        $busquedaTel = $baseDatos->prepare("SELECT id_persona FROM persona WHERE telefono = :tel LIMIT 1");
        $busquedaTel->execute([':tel' => $telefono]);
        if ($idEncontradoTel = $busquedaTel->fetchColumn()) {
            return (int)$idEncontradoTel;
        }
    }

    $palabrasNombre = explode(' ', $nombre);
    $nombrePila = $palabrasNombre[0];
    $apellido = count($palabrasNombre) > 1 ? implode(' ', array_slice($palabrasNombre, 1)) : '';

    $telefonoParaGuardar = $telefono;
    if ($telefonoParaGuardar === null || $telefonoParaGuardar === '') {
        $telefonoParaGuardar = 'SIN_TEL_' . uniqid();
    }

    $nuevaPersona = $baseDatos->prepare("
      INSERT INTO persona (nombre, apellido1, email, telefono, rol)
      VALUES (:nom, :ap1, :email, :tel, 'PACIENTE')
      RETURNING id_persona
    ");
    $nuevaPersona->execute([
        ':nom' => $nombrePila,
        ':ap1' => $apellido,
        ':email' => $email,
        ':tel' => $telefonoParaGuardar
    ]);
    return (int)$nuevaPersona->fetchColumn();
}

function asegurarPaciente(int $idPersona): void
{
    $baseDatos = conectar();
    $consulta = $baseDatos->prepare("SELECT 1 FROM paciente WHERE id_paciente = :id");
    $consulta->execute([':id' => $idPersona]);
    if ($consulta->fetch()) return;
    
    $insercion = $baseDatos->prepare("
      INSERT INTO paciente (id_paciente, tipo_paciente)
      VALUES (?, 'ADULTO')
    ");
    $insercion->execute([$idPersona]);
}

/* Función para obtener a los profesionales y pacientes activos*/
function obtenerUsuarios(): array
{
    $baseDatos = conectar();
    $consultaSql = "
      SELECT 
        id_persona AS id,
        nombre, 
        apellido1,
        apellido2,
        rol AS rol 
      FROM persona
      WHERE activo = true
        AND rol IN ('PACIENTE', 'PROFESIONAL')
      ORDER BY nombre, apellido1
    ";
    $consulta = $baseDatos->query($consultaSql);
    return $consulta->fetchAll(PDO::FETCH_ASSOC);
}

/* Función que te devuelve solo a los profesionales */
function getProfesionales(string $search = ''): array
{
    $baseDatos = conectar();
    $consultaSql = "
      SELECT p.id_profesional AS id,
             (pr.nombre || ' ' || pr.apellido1 || ' ' || COALESCE(pr.apellido2,'')) AS nombre
        FROM profesional p
   LEFT JOIN persona pr ON pr.id_persona = p.id_profesional
       WHERE pr.activo = true
    ";
    $parametros = [];
    if ($search !== '') {
        $consultaSql .= " AND (pr.nombre || ' ' || pr.apellido1 || ' ' || COALESCE(pr.apellido2,'')) ILIKE :txt";
        $parametros[':txt'] = '%' . $search . '%';
    }
    $consultaSql .= " ORDER BY pr.nombre";
    $consulta = $baseDatos->prepare($consultaSql);
    $consulta->execute($parametros);
    return $consulta->fetchAll(PDO::FETCH_ASSOC);
}


/*Función para obtener los eventos de la agenda*/
function obtenerEventosAgenda(string $desde, string $hasta, ?int $idProfesional = null): array
{
    $baseDatos = conectar();

    $consultaSqlBloques = "
      SELECT b.id_bloque AS id,
             b.id_profesional AS recurso,
             (p.nombre || ' ' || p.apellido1) AS nombre_profesional, 
             b.fecha_inicio AS inicio,
             b.fecha_fin AS fin,
             b.tipo_bloque AS tipo,
             COALESCE(b.comentario, 'Bloque ' || b.tipo_bloque) AS titulo,
             'bloque' AS fuente,
             b.id_creador AS id_creador,
             COALESCE((c.nombre || ' ' || c.apellido1), 'Sistema') AS creador
        FROM bloque_agenda b
        LEFT JOIN persona p ON p.id_persona = b.id_profesional
        LEFT JOIN persona c ON c.id_persona = b.id_creador
       WHERE DATE(b.fecha_inicio) <= ?::date
         AND DATE(b.fecha_fin) >= ?::date
    ";
    
    $consultaSqlCitas = "
      SELECT ct.id_cita AS id,
             ct.id_profesional AS recurso,
             (p.nombre || ' ' || p.apellido1) AS nombre_profesional,
             ct.fecha_hora AS inicio,
             (ct.fecha_hora + INTERVAL '1 hour') AS fin,
             ct.estado AS tipo,
             (COALESCE(ct.motivo, 'Cita') || ' - ' || COALESCE(ct.nombre_contacto, pac.nombre || ' ' || pac.apellido1)) AS titulo,
             'cita' AS fuente,
             NULL AS id_creador,
             NULL AS creador
        FROM cita ct
        LEFT JOIN persona p ON p.id_persona = ct.id_profesional  
        LEFT JOIN persona pac ON pac.id_persona = ct.id_paciente
       WHERE DATE(ct.fecha_hora) BETWEEN ?::date AND ?::date
         AND ct.estado IN ('CONFIRMADA', 'ATENDIDA', 'PENDIENTE_VALIDACION')
    ";

    $parametros = [$hasta, $desde];
    $parametrosCitas = [$desde, $hasta];
    
    if ($idProfesional !== null) {
        $consultaSqlBloques .= " AND b.id_profesional = ?";
        $consultaSqlCitas .= " AND ct.id_profesional = ?";
        $parametros[] = $idProfesional;
        $parametrosCitas[] = $idProfesional;
    }

    $consultaBloques = $baseDatos->prepare($consultaSqlBloques);
    $consultaBloques->execute($parametros);
    $bloques = $consultaBloques->fetchAll(PDO::FETCH_ASSOC);

    $consultaCitas = $baseDatos->prepare($consultaSqlCitas);
    $consultaCitas->execute($parametrosCitas);
    $citas = $consultaCitas->fetchAll(PDO::FETCH_ASSOC);

    return array_merge($bloques, $citas);
}
/* Función para crear un bloque agenda */
function crearBloqueAgenda(int $idProfesional, string $fechaInicio, string $fechaFin, string $tipoBloque, string $comentario = '', int $idCreador = 0): bool 
{
    if ($idProfesional === 0) {
        foreach (getProfesionales() as $profesional) {
            if (!crearBloqueAgenda((int)$profesional['id'], $fechaInicio, $fechaFin, $tipoBloque, $comentario, $idCreador))
                return false;
        }
        return true;
    }

    if ($idCreador <= 0) {
        $idCreador = $idProfesional;
    }

    $tiposValidos = ['DISPONIBLE', 'AUSENCIA', 'VACACIONES', 'CITA', 'EVENTO', 'BAJA', 'OTRO'];
    $tipoValido = in_array($tipoBloque, $tiposValidos) ? $tipoBloque : 'AUSENCIA';

    $consultaSql = 'INSERT INTO bloque_agenda (id_profesional, fecha_inicio, fecha_fin, tipo_bloque, comentario, id_creador)
            VALUES (?, ?::timestamptz, ?::timestamptz, ?, ?, ?)';
    
    return execLogged($consultaSql, [
        $idProfesional, $fechaInicio, $fechaFin, 
        $tipoValido, $comentario, $idCreador
    ], $idCreador, 'bloque_agenda');
}

/* Función para eliminar un evento*/
function eliminarEvento(int $idEvento, int $idActor = 0): bool
{
    if (execLogged('DELETE FROM bloque_agenda WHERE id_bloque=?', [$idEvento], $idActor, 'bloque_agenda', $idEvento))
        return true;
    return execLogged('DELETE FROM cita WHERE id_cita=?', [$idEvento], $idActor, 'cita', $idEvento);
}

/*Envía un correo electrónico usando PHPMailer*/
function enviarEmail(string $destinatario, string $asunto, string $cuerpoHtml): bool
{
    $servidor = getenv('SMTP_HOST');
    $usuario = getenv('SMTP_USER');
    $contrasena = getenv('SMTP_PASS');

    if (!$servidor || !$usuario || !$contrasena) {
        error_log('SMTP no configurado: email NO enviado a ' . $destinatario);
        return true;
    }

    $correo = new PHPMailer(true);
    try {
        $correo->isSMTP();
        $correo->Host = $servidor;
        $correo->SMTPAuth = true;
        $correo->Username = $usuario;
        $correo->Password = $contrasena;
        $correo->Port = intval(getenv('SMTP_PORT') ?: 587);
        $correo->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $correo->CharSet = 'UTF-8';
        $correo->Encoding = 'base64';

        $correo->setFrom(
            getenv('SMTP_FROM') ?: $usuario,
            getenv('SMTP_FROM_NAME') ?: 'Clínica'
        );
        $correo->addAddress($destinatario);

        $correo->isHTML(true);
        $correo->Subject = $asunto;
        $correo->Body = $cuerpoHtml;

        $correo->send();
        return true;
    } catch (Exception $e) {
        error_log('Error al enviar email: ' . $correo->ErrorInfo);
        return false;
    }
}

function obtenerNotificacionesPendientes(int $idUsuario, string $rol): array
{
    $baseDatos = conectar();

    $consulta = "
      SELECT c.id_cita AS id,
             TO_CHAR(c.fecha_hora, 'DD/MM/YYYY HH24:MI') AS fecha,
             c.estado AS tipo, 
             (pa.nombre || ' ' || pa.apellido1) as paciente,
             (pr.nombre || ' ' || pr.apellido1) as profesional
        FROM cita c
        JOIN persona pa ON pa.id_persona = c.id_paciente
        JOIN persona pr ON pr.id_persona = c.id_profesional
       WHERE c.estado = 'PENDIENTE_VALIDACION'";

    $parametros = [];
    if ($rol === 'admin') {
        $consulta .= " AND c.origen IN ('WEB', 'APP')";
    } else {
        $consulta .= " AND c.id_profesional = :p";
        $parametros[':p'] = $idUsuario;
    }

    $consulta .= " ORDER BY c.fecha_hora";
    $consultaPreparada = $baseDatos->prepare($consulta);
    $consultaPreparada->execute($parametros);
    return $consultaPreparada->fetchAll(PDO::FETCH_ASSOC);
}

function procesarNotificacion(int $idCita, string $accion, int $idUsuario, string $rol): bool
{
    $nuevoEstado = $accion === 'CONFIRMAR' ? 'CONFIRMADA' : 'CANCELADA';
    $baseDatos = conectar();
    $baseDatos->beginTransaction();
    
    try {
        $consulta = $baseDatos->prepare("
            SELECT c.*, p.email as pacienteEmail
              FROM cita c
        INNER JOIN persona p ON p.id_persona = c.id_paciente
             WHERE c.id_cita = ? FOR UPDATE");
        $consulta->execute([$idCita]);
        
        if (!$fila = $consulta->fetch(PDO::FETCH_ASSOC)) {
            throw new Exception('Cita inexistente');
        }

        if ($rol === 'profesional' && (int)$fila['id_profesional'] !== $idUsuario) {
            throw new Exception('Prohibido');
        }

        if (in_array($fila['estado'], ['CONFIRMADA', 'CANCELADA'])) {
            throw new Exception('Estado inválido');
        }

        $baseDatos->prepare("UPDATE cita SET estado = ? WHERE id_cita = ?")
            ->execute([$nuevoEstado, $idCita]);

        if ($accion === 'CONFIRMAR') {
            $consulta = $baseDatos->prepare("SELECT COUNT(*) FROM bloque_agenda WHERE id_profesional = ? AND fecha_inicio = ?::timestamptz");
            $consulta->execute([(int)$fila['id_profesional'], $fila['fecha_hora']]);
            $bloqueExistente = (int)$consulta->fetchColumn();

            if ($bloqueExistente === 0) {
                $fechaInicio = $fila['fecha_hora'];
                $fechaFin = date('Y-m-d H:i:s', strtotime($fechaInicio . ' +1 hour'));

                $declaracionPaciente = $baseDatos->prepare("
                    SELECT (nombre || ' ' || apellido1 || CASE WHEN apellido2 IS NOT NULL AND apellido2 != '' THEN (' ' || apellido2) ELSE '' END) as nombre_completo
                    FROM persona WHERE id_persona = ?
                ");
                $declaracionPaciente->execute([$fila['id_paciente']]);
                $nombrePaciente = $declaracionPaciente->fetchColumn() ?: 'Paciente';

                $stmt = $baseDatos->prepare("
                    INSERT INTO bloque_agenda (
                        id_profesional, fecha_inicio, fecha_fin, 
                        tipo_bloque, comentario, id_creador
                    ) VALUES (
                        ?, ?::timestamptz, ?::timestamptz, 'CITA', ?, ?
                    ) RETURNING id_bloque
                ");
                $stmt->execute([
                    $fila['id_profesional'],
                    $fechaInicio,
                    $fechaFin,
                    "Cita con {$nombrePaciente}",
                    $idUsuario
                ]);

                $idBloque = (int)$stmt->fetchColumn();
                $baseDatos->prepare("UPDATE cita SET id_bloque = ? WHERE id_cita = ?")
                    ->execute([$idBloque, $idCita]);
            }
        }

        $fechaFormateada = date('d/m/Y', strtotime($fila['fecha_hora']));
        $horaFormateada = date('H:i', strtotime($fila['fecha_hora']));
        $asuntoEmail = $accion === 'CONFIRMAR' ? 'Confirmación de su cita' : 'Cancelación de su cita';

        $mensaje = "Su cita del $fechaFormateada a las $horaFormateadas ha sido " . ($accion === 'CONFIRMAR' ? 'confirmada' : 'cancelada');

        $declaracionNotificacion = $baseDatos->prepare("
            INSERT INTO notificacion(id_emisor, id_destino, id_cita, tipo, asunto, cuerpo)
            VALUES (?, ?, ?, 'EMAIL', ?, ?)
        ");
        $declaracionNotificacion->execute([
            $idUsuario,
            $fila['id_paciente'],
            $idCita,
            $asuntoEmail,
            $mensaje
        ]);

        $baseDatos->commit();

        try {
            enviarEmail($fila['pacienteEmail'], $asuntoEmail, $mensaje);
        } catch (Exception $e) {           
            error_log("Error enviando email para cita $idCita: " . $e->getMessage());
        }

        return true;
    } catch (Exception $e) {
        $baseDatos->rollBack();
        error_log("Error procesando notificación: " . $e->getMessage());
        return false;
    }
}

/* Función para buscar a una persona*/
function buscarPersona(string $email, string $telefono = '', string $nif = ''): ?array
{
    $baseDatos = conectar();
    $condiciones = [];
    $parametros = [];

    if ($email !== '') {
        $condiciones[] = 'p.email = :e';
        $parametros[':e'] = $email;
    }
    if ($telefono !== '') {
        $condiciones[] = 'p.telefono = :t';
        $parametros[':t'] = $telefono;
    }
    if ($nif !== '') {
        $condiciones[] = 'p.nif = :n';
        $parametros[':n'] = $nif;
    }

    if (!$condiciones) return null;

    $consulta = "SELECT p.*,
                 prof.num_colegiado, prof.especialidad,
                 pac.tipo_paciente
            FROM persona p
       LEFT JOIN profesional prof ON prof.id_profesional=p.id_persona
       LEFT JOIN paciente pac ON pac.id_paciente=p.id_persona
           WHERE " . implode(' OR ', $condiciones) . " LIMIT 1";
    $consultaPreparada = $baseDatos->prepare($consulta);
    $consultaPreparada->execute($parametros);
    return $consultaPreparada->fetch(PDO::FETCH_ASSOC) ?: null;
}

/*Decodifica un UID agregando los caracteres de relleno (=) que falten*/
function decodificarUid(string $uid): int
{
    $uid = strtr($uid, '-_', '+/');
    $caracteresQFaltan = strlen($uid) % 4;
    if ($caracteresQFaltan) {
        $uid .= str_repeat('=', 4 - $caracteresQFaltan);
    }
    return (int) base64_decode($uid);
}

/* Función para actualizar o insertar una persona*/
function actualizarOInsertarPersona(array $datos, string $rolFinal, int $actor = 0, int $forzarId = 0): int 
{
    $baseDatos = conectar();
    $rolesLogin = ['PACIENTE', 'PROFESIONAL', 'ADMIN'];
    $esRolLogin = in_array($rolFinal, $rolesLogin, true);

    $registroPrevio = null;
    if ($forzarId > 0) {
        $consulta = $baseDatos->prepare("SELECT * FROM persona WHERE id_persona=?");
        $consulta->execute([$forzarId]);
        $registroPrevio = $consulta->fetch(PDO::FETCH_ASSOC);
        if (!$registroPrevio) throw new Exception("Usuario #$forzarId inexistente");
    }

    if (!$registroPrevio && (!empty($datos['email']) || !empty($datos['nif']))) {
        $condiciones = [];
        $parametros = [];
        if (!empty($datos['email'])) {
            $condiciones[] = 'email=:e';
            $parametros[':e'] = $datos['email'];
        }
        if (!empty($datos['nif'])) {
            $condiciones[] = 'nif=:n';
            $parametros[':n'] = $datos['nif'];
        }
        $consulta = "SELECT * FROM persona WHERE (" . implode(' OR ', $condiciones) . ") AND activo=false LIMIT 1";
        $resultado = $baseDatos->prepare($consulta);
        $resultado->execute($parametros);
        if ($fila = $resultado->fetch(PDO::FETCH_ASSOC)) {
            $registroPrevio = $fila;
            $registroPrevio['reactivado'] = true;
        }
    }
    
    foreach (['email', 'telefono', 'nif'] as $campo) {
        if (empty($datos[$campo])) continue; 
        $consulta = "SELECT id_persona, rol FROM persona
                WHERE $campo=:v AND activo=true";
        $parametros = [':v' => $datos[$campo]];
        if ($registroPrevio) {
            $consulta .= " AND id_persona != :yo";
            $parametros[':yo'] = $registroPrevio['id_persona'];
        }
        $sentencia = $baseDatos->prepare($consulta);
        $sentencia->execute($parametros);
        foreach ($sentencia->fetchAll(PDO::FETCH_ASSOC) as $duplicado) {
            if ($esRolLogin && in_array($duplicado['rol'], $rolesLogin, true)) {
                throw new Exception(ucfirst($campo) . " '{$datos[$campo]}' ya está registrado");
            }
        }
    }

    $camposEditables = [
        'nombre', 'apellido1', 'apellido2', 'fecha_nacimiento', 'nif',
        'email', 'telefono', 'tipo_via', 'nombre_calle', 'numero',
        'escalera', 'piso', 'puerta', 'codigo_postal', 'ciudad',
        'provincia', 'pais'
    ];
    $asignaciones = [];
    $valores = [];
    foreach ($camposEditables as $campo) {
        if (array_key_exists($campo, $datos)) {
            $asignaciones[] = "$campo = :$campo";
            $valores[":$campo"] = ($datos[$campo] === '') ? null : $datos[$campo];
        }
    }

    if ($registroPrevio) {
        $id = (int)$registroPrevio['id_persona'];

        if ($registroPrevio['rol'] !== $rolFinal) {
            $baseDatos->prepare("UPDATE persona SET rol=? WHERE id_persona=?")
                ->execute([$rolFinal, $id]);
        }
        
        if (!empty($registroPrevio['reactivado'])) {
            $baseDatos->prepare("UPDATE persona SET activo=true WHERE id_persona=?")
                ->execute([$id]);
        }
        
        if ($asignaciones) {
            $valores[':id'] = $id;
            $baseDatos->prepare("UPDATE persona SET " . implode(', ', $asignaciones) . " WHERE id_persona=:id")
                ->execute($valores);
        }
        return $id;
    }

    $columnas = array_map(fn($p) => substr($p, 1), array_keys($valores));
    $consultaSql = "INSERT INTO persona (" . implode(',', $columnas) . ",rol,fecha_alta)
             VALUES (" . implode(',', array_keys($valores)) . ",?,CURRENT_DATE)
             RETURNING id_persona";
    $valores[] = $rolFinal;
    $stmt = $baseDatos->prepare($consultaSql);
    $stmt->execute(array_values($valores));
    $idNuevo = (int)$stmt->fetchColumn();

    if ($esRolLogin && !empty($datos['email'])) {
        $uid = rtrim(strtr(base64_encode((string)$idNuevo), '+/', '-_'), '=');
        $front = getenv('FRONTEND_URL') ?: 'http://localhost:3000';
        $link = "$front/crear-contrasena?uid=$uid";
        $html = "
          <p>Hola {$datos['nombre']}:</p>
          <p>Hemos creado tu usuario en <strong>Clínica Petaka</strong>.</p>
          <p>Establece tu contraseña aquí: <a href=\"$link\">Crear contraseña</a></p>";
        enviarEmail($datos['email'], 'Crea tu contraseña – Petaka', $html);
    }

    registrarActividad($actor, $idNuevo, 'persona', null, null, json_encode($datos), 'INSERT');
    return $idNuevo;
}


function actualizarOInsertarProfesional(int $id, array $datosProfesional, int $actor = 0): bool
{
    $baseDatos = conectar();
    $consulta = $baseDatos->prepare('SELECT 1 FROM profesional WHERE id_profesional=?');
    $consulta->execute([$id]);
    
    if ($consulta->fetch()) {
        $consultaSql = 'UPDATE profesional SET num_colegiado=?, especialidad=? WHERE id_profesional=?';
        $parametros = [$datosProfesional['num_colegiado'] ?? null, $datosProfesional['especialidad'] ?? null, $id];
    } else {
        $consultaSql = 'INSERT INTO profesional (id_profesional, num_colegiado, especialidad) VALUES (?, ?, ?)';
        $parametros = [$id, $datosProfesional['num_colegiado'] ?? null, $datosProfesional['especialidad'] ?? null];
    }

    return execLogged($consultaSql, $parametros, $actor, 'profesional', $id);
}
function actualizarOInsertarTutor(array $datosTutor): int
{
    $id = actualizarOInsertarPersona($datosTutor, 'TUTOR');

    $baseDatos = conectar();
    $consulta = $baseDatos->prepare("SELECT 1 FROM tutor WHERE id_tutor = ?");
    $consulta->execute([$id]);
    
    if ($consulta->fetch()) {
        $consultaSql = "UPDATE tutor SET metodo_contacto_preferido = ? WHERE id_tutor = ?";
        $parametros = [strtoupper($datosTutor['metodo'] ?? 'TEL'), $id];
    } else {
        $consultaSql = "INSERT INTO tutor (id_tutor, metodo_contacto_preferido) VALUES (?, ?)";
        $parametros = [$id, strtoupper($datosTutor['metodo'] ?? 'TEL')];
    }

    $baseDatos->prepare($consultaSql)->execute($parametros);
    return $id;
}

function actualizarOInsertarPaciente(int $id, array $datosPaciente): bool
{
    $baseDatos = conectar();

    $tipoPaciente = strtoupper($datosPaciente['tipo_paciente'] ?? 'ADULTO');
    $esMenor = $tipoPaciente !== 'ADULTO';
    $idTutor = null;

    if ($esMenor && !empty($datosPaciente['tutor']) && is_array($datosPaciente['tutor'])) {
        $idTutor = actualizarOInsertarTutor($datosPaciente['tutor']);
    }

    $consulta = $baseDatos->prepare("SELECT 1 FROM paciente WHERE id_paciente = ?");
    $consulta->execute([$id]);
    
    if ($consulta->fetch()) {
        $consultaSql = "UPDATE paciente
               SET tipo_paciente = ?,
                   observaciones_generales = ?,
                   id_tutor = ?
             WHERE id_paciente = ?";
        $parametros = [$tipoPaciente, $datosPaciente['observaciones_generales'] ?? null, $idTutor, $id];
    } else {
        $consultaSql = "INSERT INTO paciente
               (id_paciente, tipo_paciente, observaciones_generales, id_tutor)
               VALUES (?, ?, ?, ?)";
        $parametros = [$id, $tipoPaciente, $datosPaciente['observaciones_generales'] ?? null, $idTutor];
    }

    try {
        $statement = $baseDatos->prepare($consultaSql);
        return $statement->execute($parametros);
    } catch (Exception $e) {
        error_log("Error en actualizarOInsertarPaciente: " . $e->getMessage());
        return false;
    }
}

/* Función para obtener los detalles del usuario*/
function getUsuarioDetalle(int $id): ?array
{
    $baseDatos = conectar();
    
    $consulta = $baseDatos->prepare("SELECT * FROM persona WHERE id_persona = ?");
    $consulta->execute([$id]);
    $datosPersona = $consulta->fetch(PDO::FETCH_ASSOC);
    if (!$datosPersona) return null;

    $consulta = $baseDatos->prepare("SELECT num_colegiado, especialidad, fecha_alta_profesional 
                          FROM profesional WHERE id_profesional = ?");
    $consulta->execute([$id]);
    $datosProfesional = $consulta->fetch(PDO::FETCH_ASSOC) ?: null;

    $consulta = $baseDatos->prepare("SELECT tipo_paciente, observaciones_generales, id_tutor 
                          FROM paciente WHERE id_paciente = ?");
    $consulta->execute([$id]);
    $datosPaciente = $consulta->fetch(PDO::FETCH_ASSOC) ?: null;

    $datosTutor = null;
    if ($datosPaciente && $datosPaciente['id_tutor']) {
        $consulta = $baseDatos->prepare("SELECT p2.*, t.metodo_contacto_preferido 
                              FROM persona p2 
                              JOIN tutor t ON t.id_tutor = p2.id_persona
                             WHERE p2.id_persona = ?");
        $consulta->execute([$datosPaciente['id_tutor']]);
        $datosTutor = $consulta->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    return [
        'persona' => $datosPersona,
        'profesional' => $datosProfesional,
        'paciente' => $datosPaciente,
        'tutor' => $datosTutor
    ];
}


function citasActivas(int $id): int
{
    $consulta = 'SELECT COUNT(*) FROM cita WHERE estado != \'CANCELADA\' AND (id_paciente=? OR id_profesional=?)';
    $consultaPreparada = conectar()->prepare($consulta);
    $consultaPreparada->execute([$id, $id]);
    return (int)$consultaPreparada->fetchColumn();
}

/* elimina una persona solo si TODAS sus citas están canceladas */
function eliminarUsuario(int $id, int $actor = 0): array
{
    if (citasActivas($id) > 0) {
        return ['ok' => false, 'code' => 409, 'msg' => 'El usuario tiene citas activas'];
    }
    $exito = execLogged('DELETE FROM persona WHERE id_persona=?', [$id], $actor, 'persona', $id);
    return $exito ? ['ok' => true] : ['ok' => false, 'code' => 500, 'msg' => 'Error SQL'];
}

/* marca una persona como inactiva solo si NO tiene citas activas */
function marcarUsuarioInactivo(int $id, int $actor = 0): array
{
    if (citasActivas($id) > 0) {
        return ['ok' => false, 'code' => 409, 'msg' => 'El usuario tiene citas activas'];
    }
    $exito = execLogged('UPDATE persona SET activo=false WHERE id_persona=?', [$id], $actor, 'persona', $id);
    return $exito ? ['ok' => true] : ['ok' => false, 'code' => 500, 'msg' => 'Error SQL'];
}


function getInformeMes(int $año, int $mes): array
{
    $baseDatos  = conectar();
    $fechaInicio = sprintf('%d-%02d-01', $año, $mes);
    $fechaFin = date('Y-m-t', strtotime($fechaInicio));

    /* totales citas */
    $filaDatos = $baseDatos->query("
       SELECT
         COUNT(*) as total,
         SUM(CASE WHEN estado='CONFIRMADA' OR estado='ATENDIDA' THEN 1 ELSE 0 END) as conf,
         SUM(CASE WHEN estado='CANCELADA' OR estado='NO_PRESENTADA' THEN 1 ELSE 0 END) as canc
       FROM cita
       WHERE DATE(fecha_hora) BETWEEN '$fechaInicio' AND '$fechaFin'")
        ->fetch(PDO::FETCH_NUM);

    /* usuarios activos */
    $usuariosActivos = $baseDatos->query("SELECT COUNT(*) FROM persona WHERE activo=true")
        ->fetchColumn();

    return [
        'total_citas'      => (int)$filaDatos[0],
        'citas_confirmadas' => (int)$filaDatos[1],
        'citas_canceladas' => (int)$filaDatos[2],
        'usuarios_activos' => (int)$usuariosActivos
    ];
}


function exportLogsCsv(int $año, int $mes): string
{
    $baseDatos = conectar();

    /* Si se pasa 0 como mes, mostrar todos los logs (sin filtro por fecha) */
    if ($mes === 0) {
        $consulta = "
          SELECT TO_CHAR(l.fecha,'DD/MM/YYYY HH24:MI')   AS fecha,
                 COALESCE((actor.nombre || ' ' || actor.apellido1), 'Sistema') AS actor,
                 l.accion,
                 l.tabla_afectada,
                 COALESCE(l.campo_afectado, '-') AS campo_afectado,
                 COALESCE(l.valor_antiguo, '-') AS valor_antiguo,
                 COALESCE(l.valor_nuevo, '-') AS valor_nuevo,
                 COALESCE(l.ip, '-') AS ip
            FROM log_evento_dato l
       LEFT JOIN persona actor ON actor.id_persona = l.id_actor ORDER BY l.fecha DESC";
        $consultaPreparada = $baseDatos->prepare($consulta);
        $consultaPreparada->execute();
    } else {
        /* Usar el mes específico */
        $fechaInicio = sprintf('%d-%02d-01 00:00:00', $año, $mes);
        $fechaFin = date('Y-m-d 23:59:59', strtotime("$fechaInicio +1 month -1 day"));
        $consulta = "
          SELECT TO_CHAR(l.fecha,'DD/MM/YYYY HH24:MI')   AS fecha,
                 COALESCE((actor.nombre || ' ' || actor.apellido1), 'Sistema') AS actor,
                 l.accion,
                 l.tabla_afectada,
                 COALESCE(l.campo_afectado, '-') AS campo_afectado,
                 COALESCE(l.valor_antiguo, '-') AS valor_antiguo,
                 COALESCE(l.valor_nuevo, '-') AS valor_nuevo,
                 COALESCE(l.ip, '-') AS ip
            FROM log_evento_dato l
       LEFT JOIN persona actor ON actor.id_persona = l.id_actor
           WHERE l.fecha BETWEEN :d AND :h ORDER BY l.fecha DESC";

        $consultaPreparada = $baseDatos->prepare($consulta);
        $consultaPreparada->execute([':d' => $fechaInicio, ':h' => $fechaFin]);
    }

    /* Crear el archivo CSV */
    $archivo = fopen('php://temp', 'r+');

    /* Escribir cabeceras */
    $encabezados = ['Fecha', 'Actor', 'Acción', 'Tabla', 'Campo', 'Valor antiguo', 'Valor nuevo', 'IP'];
    fputcsv($archivo, $encabezados, ';');

    /* Contar filas obtenidas */
    $contadorFilas = 0;    /* Agregar datos al CSV */
    while ($fila = $consultaPreparada->fetch(PDO::FETCH_NUM)) {
        fputcsv($archivo, $fila, ';');
        $contadorFilas++;
    }

    // Si no hay registros, agregar mensaje informativo
    if ($contadorFilas === 0) {
        $mensaje = $mes === 0
            ? "No hay registros disponibles en el sistema"
            : "No hay registros disponibles para " . date('F Y', strtotime($fechaInicio));
        $filaSinDatos = [$mensaje, "", "", "", "", "", "", ""];
        fputcsv($archivo, $filaSinDatos, ';');
    }

    // Obtener resultado como string
    rewind($archivo);
    $csv = stream_get_contents($archivo);
    fclose($archivo);

    return $csv;
}

function getPacientesProfesional(int $idProf): array
{
    $baseDatos = conectar();
    $consultaSql = "
      SELECT DISTINCT
        pe.id_persona   AS id,
        pe.nombre, pe.apellido1, pe.apellido2,
        MIN(ci.fecha_hora) proxima_cita
      FROM persona pe
      JOIN cita ci ON ci.id_paciente = pe.id_persona
      WHERE ci.id_profesional = :pr
        AND pe.activo = true
      GROUP BY pe.id_persona
      ORDER BY pe.nombre, pe.apellido1";
    $consulta = $baseDatos->prepare($consultaSql);
    $consulta->execute([':pr' => $idProf]);
    return $consulta->fetchAll(PDO::FETCH_ASSOC);
}

/* CONSENTIMIENTO */
function tieneConsentimientoActivo(int $id): bool
{
    $baseDatos = conectar();
    $consulta = $baseDatos->prepare("SELECT 1 FROM consentimiento
                      WHERE id_persona=? AND fecha_revocado IS NULL");
    $consulta->execute([$id]);
    return (bool)$consulta->fetchColumn();
}
function registrarConsentimiento(int $id, bool $nuevo, int $actor = 0): void
{
    $baseDatos = conectar();
    $hay = tieneConsentimientoActivo($id);

    if ($nuevo && !$hay) {
        $baseDatos->prepare("INSERT INTO consentimiento(id_persona,fecha_otorgado,canal)                      VALUES(?,CURRENT_TIMESTAMP,'WEB')")->execute([$id]);
        registrarActividad($actor, $id, 'consentimiento', null, null, 'otorgado', 'INSERT');
    } elseif (!$nuevo && $hay) {
        $baseDatos->prepare("UPDATE consentimiento
                         SET fecha_revocado=CURRENT_TIMESTAMP
                       WHERE id_persona=? AND fecha_revocado IS NULL")
            ->execute([$id]);
        registrarActividad($actor, $id, 'consentimiento', null, 'otorgado', 'revocado', 'UPDATE');
    }
}

/*Devuelve todos los tratamientos de un paciente*/
function getTratamientosPaciente(int $idPac, int $idProf = null): array
{
    $baseDatos = conectar();
    $consultaSql = "
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
        $consultaSql .= " AND t.id_profesional = " . intval($idProf);
    }
    $consultaSql .= " ORDER BY t.id_tratamiento, dc.id_documento";

    $consulta = $baseDatos->prepare($consultaSql);
    $consulta->execute([$idPac]);
    $filas = $consulta->fetchAll(PDO::FETCH_ASSOC);   
    $tratamientos = [];
    foreach ($filas as $fila) {
        $idTratamiento = $fila['id_tratamiento'];

        if (!isset($tratamientos[$idTratamiento])) {
            $tratamientos[$idTratamiento] = [
                'id_tratamiento' => $fila['id_tratamiento'],
                'fecha_inicio' => $fila['fecha_inicio'],
                'fecha_fin' => $fila['fecha_fin'],
                'frecuencia_sesiones' => $fila['frecuencia_sesiones'],
                'titulo' => $fila['titulo'],
                'notas' => $fila['notas'],
                'documentos' => []
            ];
        }

        // Agregar documento si existe
        if ($fila['documento_ruta']) {
            $tratamientos[$idTratamiento]['documentos'][] = [
                'id_documento' => $fila['documento_id'],
                'ruta' => $fila['documento_ruta'],
                'nombre_archivo' => $fila['documento_nombre']
            ];
        }
    }

    return array_values($tratamientos);
}


function crearTratamiento( int $idPac, int $idProf, string $titulo, string $desc, $file = null, ?string $fechaInicio = null,  ?string $fechaFin = null, ?int $frecuencia = null ): void {
    $baseDatos = conectar();
    $baseDatos->beginTransaction();
    try {
        /* Obtener o crear historial */
        $consultaHistorial = $baseDatos->prepare("
          SELECT id_historial
            FROM historial_clinico
           WHERE id_paciente = ?
           LIMIT 1
        ");
        $consultaHistorial->execute([$idPac]);
        $idHist = $consultaHistorial->fetchColumn();        if (!$idHist) {
            $stmt = $baseDatos->prepare("
              INSERT INTO historial_clinico (id_paciente, fecha_inicio)
              VALUES (?, CURRENT_DATE)
              RETURNING id_historial
            ");
            $stmt->execute([$idPac]);
            $idHist = (int)$stmt->fetchColumn();
        }

        /* Verificar que los datos mínimos están presentes */
        if (empty($titulo)) {
            throw new Exception('El título es obligatorio');
        }        /* Insertar tratamiento con todos los campos */
        $consultaSql = "
          INSERT INTO tratamiento
            (id_historial, id_profesional, fecha_inicio, fecha_fin, 
             frecuencia_sesiones, titulo, notas)
          VALUES
            (?, ?, ?, ?, ?, ?, ?)
          RETURNING id_tratamiento
        ";

        $consultaTratamiento = $baseDatos->prepare($consultaSql);
        $consultaTratamiento->execute([
            $idHist,
            $idProf,
            $fechaInicio ?: date('Y-m-d'),
            $fechaFin ?: null,
            $frecuencia ?: null,
            $titulo,
            $desc
        ]);
        /* Obtener el ID del tratamiento recién creado */
        $idTratamiento = (int)$consultaTratamiento->fetchColumn();/* Documento opcional */
        if ($file && $file->getError() === UPLOAD_ERR_OK) {
            /* Verificar que el directorio existe y tiene permisos */
            $uploadDir = __DIR__ . '/../public/uploads/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $nombre = uniqid() . '_' . $file->getClientFilename();
            $destino = $uploadDir . $nombre;
            $file->moveTo($destino);

            $baseDatos->prepare("
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

        /* Confirmar transacción */
        $baseDatos->commit();
    } catch (Exception $e) {
        $baseDatos->rollBack();
        error_log('Error al crear tratamiento: ' . $e->getMessage());
        throw $e; // Re-lanzar para manejo en capa superior
    }
}
/* documentos del historial ─*/
function getDocsPaciente(int $idPac, int $idProf = null): array
{
    $baseDatos = conectar();

    $consultaSql = "
        SELECT d.id_documento,
               d.ruta,
               d.tipo,
               d.fecha_subida,
               d.nombre_archivo,
               h.diagnostico_preliminar,  
               h.diagnostico_final,      
               h.id_historial,
               h.fecha_inicio
          FROM documento_clinico d
          JOIN historial_clinico h ON d.id_historial = h.id_historial
         WHERE h.id_paciente = ?
           AND d.id_tratamiento IS NULL  
    ";

    $parametros = [$idPac];

    if ($idProf) {
        $consultaSql .= " AND d.id_profesional = ?";
        $parametros[] = $idProf;
    }

    $consultaSql .= " ORDER BY h.fecha_inicio DESC, d.fecha_subida DESC";

    $consulta = $baseDatos->prepare($consultaSql);
    $consulta->execute($parametros);

    $documentos = $consulta->fetchAll(PDO::FETCH_ASSOC);

    error_log("Obtenidos " . count($documentos) . " documentos del historial para paciente $idPac");

    return $documentos;
}
/* crear documento del historial */
function crearDocumentoHistorial(int $idPac, int $idProf, $file = null, string $diagnosticoPreliminar = '', string $diagnosticoFinal = '', string $tipo = 'documento'): array
{
    $baseDatos = conectar();
    $baseDatos->beginTransaction();

    try {        /* Cada documento representa una consulta/entrada específica en el historial */
        $stmt = $baseDatos->prepare("
            INSERT INTO historial_clinico
                   (id_paciente, fecha_inicio, diagnostico_preliminar, diagnostico_final)
            VALUES (?, CURRENT_DATE, ?, ?)
            RETURNING id_historial
        ");
        $stmt->execute([$idPac, $diagnosticoPreliminar, $diagnosticoFinal ?: null]);

        $idHistorial = (int)$stmt->fetchColumn();
        error_log("Nueva entrada en historial creada con ID: $idHistorial para paciente $idPac - Diagnóstico: '$diagnosticoPreliminar'");

        $rutaArchivo = null;
        $idDocumento = null;

        if ($file && $file->getError() === UPLOAD_ERR_OK) {
            /* Directorio destino */
            $dir = __DIR__ . '/../public/uploads/historial/';
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException('No se pudo crear el directorio de subida');
            }

            $ext = strtolower(pathinfo($file->getClientFilename(), PATHINFO_EXTENSION));
            $slug = $diagnosticoPreliminar
                ? preg_replace('/[^a-z0-9]+/i', '_', substr($diagnosticoPreliminar, 0, 30))
                : 'documento';

            // Generar nombre único basado en el diagnóstico
            $timestamp = time();
            $random = mt_rand(1000, 9999);
            $nombre = sprintf('%s_%d_%d_%d.%s', $slug, $idPac, $timestamp, $random, $ext);

            $destino = $dir . $nombre;
            $rutaArchivo = 'uploads/historial/' . $nombre;

            /* Verificar que el archivo no existe */
            $contador = 1;
            while (file_exists($destino)) {
                $nombre = sprintf('%s_%d_%d_%d_%d.%s', $slug, $idPac, $timestamp, $random, $contador, $ext);
                $destino = $dir . $nombre;
                $rutaArchivo = 'uploads/historial/' . $nombre;
                $contador++;
            }

            /* Mover fichero */
            $file->moveTo($destino);
            error_log("Archivo guardado en: $rutaArchivo");

            /* Deducir tipo si no se pasó */
            if ($tipo === 'documento' || $tipo === '') {
                $tipo = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']) ? 'imagen'
                    : ($ext === 'pdf' ? 'pdf' : 'documento');
            }            /* Registrar documento (SIN diagnósticos - esos están en historial_clinico) */            $consultaDocumento = $baseDatos->prepare("
                INSERT INTO documento_clinico
                       (id_historial, id_profesional, ruta, nombre_archivo, tipo, fecha_subida, id_tratamiento)
                VALUES (:h, :p, :r, :n, :t, CURRENT_TIMESTAMP, NULL)
                RETURNING id_documento
            ");

            $consultaDocumento->execute([
                ':h' => $idHistorial,
                ':p' => $idProf,
                ':r' => $rutaArchivo,
                ':n' => $file->getClientFilename(),
                ':t' => $tipo
            ]);

            $idDocumento = (int)$consultaDocumento->fetchColumn();
            error_log("Documento registrado con ID: $idDocumento vinculado a entrada de historial: $idHistorial");
        }

        $baseDatos->commit();
        error_log("Nueva entrada añadida correctamente al historial del paciente");

        return [
            'ok' => true,
            'mensaje' => 'Documento subido al historial correctamente',
            'id_historial' => $idHistorial,
            'id_documento' => $idDocumento,
            'ruta' => $rutaArchivo,
            'diagnostico_final' => $diagnosticoFinal,
            'diagnostico_preliminar' => $diagnosticoPreliminar,
            'tipo' => $tipo
        ];
    } catch (Throwable $e) {
        $baseDatos->rollBack();
        error_log("Error en crearDocumentoHistorial: " . $e->getMessage());

        /* Limpiar archivo huérfano */
        if (isset($rutaArchivo) && $rutaArchivo !== null) {
            $f = __DIR__ . '/../public/' . $rutaArchivo;
            if (file_exists($f)) {
                unlink($f);
                error_log("Archivo huérfano eliminado: $f");
            }
        }
        throw $e;
    }
}

/* citas del paciente */
function getCitasPacienteProfesional(int $idPac, int $idProf): array
{
    $consulta = conectar()->prepare("
      SELECT 
        id_cita,
        id_profesional,
        fecha_hora,
        estado,
        motivo,
        origen,
        notas_privadas
      FROM cita
      WHERE id_paciente = ? AND id_profesional = ?
      ORDER BY fecha_hora DESC
    ");
    $consulta->execute([$idPac, $idProf]);
    return $consulta->fetchAll(PDO::FETCH_ASSOC);
}

/* procesamiento de acciones */
function procesarAccionCitaProfesional(int $idCita, array $body): void
{
    $baseDatos = conectar();
    $baseDatos->beginTransaction();

    try {
        $consultaCita = $baseDatos->prepare("SELECT * FROM cita WHERE id_cita=? FOR UPDATE");
        $consultaCita->execute([$idCita]);
        $cita = $consultaCita->fetch(PDO::FETCH_ASSOC);
        if (!$cita) throw new Exception('Cita no encontrada');
        switch ($body['accion']) {
            case 'CONFIRMAR':
            case 'CONFIRMAR_CITA':
                $nuevo = 'CONFIRMADA';
                break;
            case 'RECHAZAR':
            case 'RECHAZAR_CITA':
                $nuevo = 'CANCELADA';
                break;
            case 'ACEPTAR_CAMBIO':
                $nuevo = 'CONFIRMADA';
                break;
            case 'CANCELAR':
            case 'ACEPTAR_CANCELACION':
                $nuevo = 'CANCELADA';
                break;
            case 'MANTENER':
            case 'MANTENER_ESTADO_PREVIO':
            case 'MANTENER_CITA':
                $nuevo = 'CONFIRMADA';
                break;
            case 'MARCAR_ATENDIDA':
                $nuevo = 'ATENDIDA';
                break;
            case 'MARCAR_NO_ATENDIDA':
            case 'MARCAR_NO_PRESENTADA':
                $nuevo = 'NO_ATENDIDA';
                break;
            case 'REPROGRAMAR':
                $fechaNueva = $body['fecha'] ?? null;
                if (!$fechaNueva) {
                    throw new Exception('Fecha requerida para reprogramar');
                }

                // Validar la nueva fecha
                $resultadoValidacion = validarFechaReprogramacion(
                    $fechaNueva,
                    (int)$cita['id_profesional']
                );

                if (!$resultadoValidacion['valida']) {
                    throw new Exception($resultadoValidacion['mensaje']);
                }                // Actualizar la cita con la nueva fecha
                $baseDatos->prepare("UPDATE cita 
                             SET fecha_hora = ?, estado = 'CONFIRMADA' 
                             WHERE id_cita = ?")
                    ->execute([$fechaNueva, $idCita]);

                // Actualizar el bloque de agenda si existe
                if ($cita['id_bloque']) {
                    $nuevaFechaFin = date('Y-m-d H:i:s', strtotime($fechaNueva . ' +1 hour'));
                    $baseDatos->prepare("UPDATE bloque_agenda 
                                 SET fecha_inicio = ?, fecha_fin = ? 
                                 WHERE id_bloque = ?")
                        ->execute([$fechaNueva, $nuevaFechaFin, $cita['id_bloque']]);
                }

                $baseDatos->commit();
                return;

            default:
                throw new Exception('Acción no válida');
        }        // Para el resto de acciones (no reprogramar)
        $baseDatos->prepare("UPDATE cita SET estado=? WHERE id_cita=?")
            ->execute([$nuevo, $idCita]);

        $baseDatos->commit();
    } catch (Exception $e) {
        $baseDatos->rollBack();
        throw $e;
    }
}

/* Valida la fecha de reprogramación de una cita. */
function validarFechaReprogramacion(string $fecha, int $idProfesional): array
{
    try {
        // Convertir fecha a objeto DateTime
        $fechaObj = new DateTime($fecha);
        error_log("Validando reprogramación para fecha: " . $fechaObj->format('Y-m-d H:i:s') . " - Profesional: $idProfesional");
    } catch (Exception $e) {
        return [
            'valida' => false,
            'mensaje' => 'Fecha inválida'
        ];
    }

    // 1. Validar que no sea en el pasado
    $ahora = new DateTime();
    if ($fechaObj <= $ahora) {
        return [
            'valida' => false,
            'mensaje' => 'No se puede programar una cita en el pasado'
        ];
    }

    //   // 2. Validar día de la semana (Lunes=1 a Viernes=5)
    $diaSemana = (int)$fechaObj->format('N'); // 1=Lunes, 7=Domingo
    if ($diaSemana < 1 || $diaSemana > 5) {
        return [
            'valida' => false,
            'mensaje' => 'Solo se pueden programar citas de lunes a viernes'
        ];
    }

    // 3. Validar horario (10:00 a 17:00 - última cita a las 17:00)
    $hora = (int)$fechaObj->format('G'); // 0-23
    $minutos = (int)$fechaObj->format('i');

    if ($hora < 10 || $hora > 17 || ($hora === 17 && $minutos > 0)) {
        return [
            'valida' => false,
            'mensaje' => 'El horario de atención es de 10:00 a 17:00. La última cita disponible es a las 17:00'
        ];
    }    // 4. Validar que el profesional no tenga bloqueos (ausencia, vacaciones, etc.)
    $baseDatos = conectar();
    $fechaStr = $fechaObj->format('Y-m-d H:i:s');
    $consultaBloqueo = $baseDatos->prepare("
        SELECT tipo_bloque, comentario
        FROM bloque_agenda        WHERE id_profesional = ?
          AND tipo_bloque IN ('AUSENCIA', 'VACACIONES')
          AND ? BETWEEN fecha_inicio AND (fecha_fin - INTERVAL '1 second')
        LIMIT 1
    ");
    $consultaBloqueo->execute([$idProfesional, $fechaStr]);
    $bloqueo = $consultaBloqueo->fetch(PDO::FETCH_ASSOC);

    if ($bloqueo) {
        $tipoBloqueo = strtolower($bloqueo['tipo_bloque']);
        return [
            'valida' => false,
            'mensaje' => "El profesional no está disponible en esa fecha ({$tipoBloqueo})"
        ];
    }    // 5. Validar que no haya otra cita confirmada en esa hora
    $consultaCita = $baseDatos->prepare("
        SELECT id_cita
        FROM cita
        WHERE id_profesional = ?
          AND fecha_hora = ?
          AND estado IN ('CONFIRMADA', 'PENDIENTE_VALIDACION', 'SOLICITADA')
        LIMIT 1
    ");
    $consultaCita->execute([$idProfesional, $fechaStr]);
    $citaExistente = $consultaCita->fetch(PDO::FETCH_ASSOC);

    if ($citaExistente) {
        return [
            'valida' => false,
            'mensaje' => 'Ya existe una cita programada en esa fecha y hora'
        ];
    }

    // 6. Validar que la hora sea exacta (sin minutos)
    if ($minutos !== 0) {
        return [
            'valida' => false,
            'mensaje' => 'Las citas deben programarse en horas exactas (ej: 10:00, 11:00, etc.)'
        ];
    }

    // Si llegamos aquí, la fecha es válida
    error_log("Fecha de reprogramación válida: " . $fechaObj->format('Y-m-d H:i:s'));
    return [
        'valida' => true,
        'mensaje' => 'Fecha válida para reprogramación'
    ];
}

/* Función auxiliar para obtener horas disponibles de un profesiona en una fecha en concreto. */
function obtenerHorasDisponibles(int $idProfesional, string $fecha): array
{
    try {
        $fechaObj = new DateTime($fecha);
    } catch (Exception $e) {
        return [];
    }

    $diaSemana = (int)$fechaObj->format('N');
    if ($diaSemana < 1 || $diaSemana > 5) {
        return [];
    }

    $baseDatos = conectar();

    $consultaBloqueo = $baseDatos->prepare("        
        SELECT COUNT(*) as bloqueado
        FROM bloque_agenda
        WHERE id_profesional = ?
          AND tipo_bloque IN ('AUSENCIA', 'VACACIONES', 'CITA')
          AND DATE(?::date) BETWEEN DATE(fecha_inicio) AND DATE(fecha_fin)
    ");
    $consultaBloqueo->execute([$idProfesional, $fecha]);
    $bloqueado = $consultaBloqueo->fetchColumn();

    if ($bloqueado > 0) {
        return [];
    }

    $consultaCitas = $baseDatos->prepare("
        SELECT TO_CHAR(fecha_hora, 'HH24:MI') as hora
        FROM cita
        WHERE id_profesional = ?
          AND DATE(fecha_hora) = ?::date
          AND estado IN ('CONFIRMADA', 'PENDIENTE_VALIDACION', 'SOLICITADA')
    ");
    $consultaCitas->execute([$idProfesional, $fecha]);
    $citasExistentes = $consultaCitas->fetchAll(PDO::FETCH_ASSOC);
    $horasOcupadas = array_column($citasExistentes, 'hora');

    $horasDisponibles = [];
    for ($hora = 10; $hora <= 17; $hora++) {
        $horaStr = sprintf('%02d:00', $hora);
        if (!in_array($horaStr, $horasOcupadas)) {
            $horasDisponibles[] = $horaStr;
        }
    }

    return $horasDisponibles;
}
/* Obtiene las fechas en las que el profesional está bloqueado */
function obtenerDiasBloqueados(int $idProfesional, string $fechaInicio, string $fechaFin): array
{
    try {
        $baseDatos = conectar();

        $consulta = $baseDatos->prepare("
            SELECT fecha_inicio, fecha_fin, tipo_bloque
            FROM bloque_agenda
            WHERE id_profesional = ?
              AND tipo_bloque IN ('AUSENCIA', 'VACACIONES', 'CITA')
              AND (
                  (DATE(fecha_inicio) BETWEEN ?::date AND ?::date) OR
                  (DATE(fecha_fin) BETWEEN ?::date AND ?::date) OR
                  (DATE(fecha_inicio) <= ?::date AND DATE(fecha_fin) >= ?::date)
              )
        ");

        $consulta->execute([
            $idProfesional,
            $fechaInicio, $fechaFin,
            $fechaInicio, $fechaFin,
            $fechaInicio, $fechaFin
        ]);

        $bloques = $consulta->fetchAll(PDO::FETCH_ASSOC);
        $fechasBloquedas = [];

        foreach ($bloques as $bloque) {
            $fechaInicioBloque = new DateTime(date('Y-m-d', strtotime($bloque['fecha_inicio'])));
            $fechaFinBloque = new DateTime(date('Y-m-d', strtotime($bloque['fecha_fin'])));
            $fechaFinAjustada = clone $fechaFinBloque;

            $intervalo = new DateInterval('P1D');
            $periodo = new DatePeriod($fechaInicioBloque, $intervalo, $fechaFinAjustada->modify('+1 day'));

            foreach ($periodo as $fecha) {
                $fechaStr = $fecha->format('Y-m-d');
                if ($fechaStr >= $fechaInicio && $fechaStr <= $fechaFin) {
                    $fechasBloquedas[] = $fechaStr;
                }
            }
        }

        $fechasBloquedas = array_unique($fechasBloquedas);
        sort($fechasBloquedas);

        return $fechasBloquedas;
    } catch (Exception $e) {
        error_log("Error obteniendo días bloqueados: " . $e->getMessage());
        return [];
    }
}

/* Verifica si un paciente pertenece a un profesional (si tiene al menos una cita con él). */
function verificarPacienteProfesional(int $idPac, int $idProf): bool
{
    $baseDatos = conectar();
    $consulta = $baseDatos->prepare("SELECT 1 FROM cita
                      WHERE id_paciente = :p AND id_profesional = :pr LIMIT 1");
    $consulta->execute([':p' => $idPac, ':pr' => $idProf]);
    return $consulta->fetch() !== false;
}

/*Obtiene datos completos de un paciente para un profesional, incluyendo tratamientos,documentos, citas y estado del consentimiento.*/
function getDetallesPacienteProfesional(int $idPac, int $idProf): array
{
    $det = getUsuarioDetalle($idPac);
    return [
        'persona'      => $det['persona'],
        'paciente'     => $det['paciente'],
        'tutor'        => $det['tutor'],
        'tratamientos' => getTratamientosPaciente($idPac, $idProf),
        'documentos'   => getDocsPaciente($idPac, $idProf),
        'citas'        => getCitasPacienteProfesional($idPac, $idProf),
        'consentimiento_activo' => tieneConsentimientoActivo($idPac)
    ];
}


/* Elimina un tratamiento y sus documentos asociados. */
function eliminarTratamiento(int $idTratamiento, int $idProf, int $idPac): void
{
    $baseDatos = conectar();
    try {
        $baseDatos->beginTransaction();

        // 1. Obtener el historial clínico del tratamiento
        $consulta = $baseDatos->prepare("
            SELECT id_historial FROM tratamiento WHERE id_tratamiento = ?
        ");
        $consulta->execute([$idTratamiento]);
        $idHistorial = $consulta->fetchColumn();

        // 2. Buscar documentos asociados al historial y al tratamiento
        $consultaDocs = $baseDatos->prepare("
            SELECT id_documento, ruta 
            FROM documento_clinico 
            WHERE id_historial = ? AND id_profesional = ?
        ");
        $consultaDocs->execute([$idHistorial, $idProf]);
        $documentos = $consultaDocs->fetchAll(PDO::FETCH_ASSOC);

        // 3. Eliminar documentos asociados y sus archivos físicos
        foreach ($documentos as $doc) {
            // Eliminar el archivo físico si existe
            if (!empty($doc['ruta']) && file_exists(__DIR__ . '/../' . $doc['ruta'])) {
                unlink(__DIR__ . '/../' . $doc['ruta']);
            }

            // Eliminar el registro del documento
            $baseDatos->prepare("
                DELETE FROM documento_clinico
                WHERE id_documento = ?
            ")->execute([$doc['id_documento']]);
        }

        // 4. Eliminar el tratamiento
        $consulta = $baseDatos->prepare("
            DELETE FROM tratamiento
            WHERE id_tratamiento = ? AND id_profesional = ?
        ");
        $result = $consulta->execute([$idTratamiento, $idProf]);

        $baseDatos->commit(); // Registrar en el sistema lo que hicimos
        registrarActividad(
            $idProf,
            $idPac,
            'tratamiento',
            'id_tratamiento',
            $idTratamiento,
            null,
            'DELETE'
        );
    } catch (Throwable $e) {
        $baseDatos->rollBack();
        throw $e; // Re-lanzar la excepción para manejo en la capa superior
    }
}


/* Elimina un documento del historial clínico y su archivo físico. */
function eliminarDocumentoHistorial(int $idDoc, int $idPac, int $idProf): bool
{
    $baseDatos = conectar();
    try {
        $baseDatos->beginTransaction();

        // 1. Obtener información del documento y su entrada de historial
        $consultaDocumento = $baseDatos->prepare("
            SELECT d.*, h.id_paciente, h.id_historial
            FROM documento_clinico d
            JOIN historial_clinico h ON d.id_historial = h.id_historial
            WHERE d.id_documento = ? AND h.id_paciente = ?
        ");
        $consultaDocumento->execute([$idDoc, $idPac]);
        $documento = $consultaDocumento->fetch(PDO::FETCH_ASSOC);

        if (!$documento) {
            throw new Exception('Documento no encontrado');
        }

        // 2. Verificar si hay otros documentos en esta entrada del historial
        $consultaConteo = $baseDatos->prepare("
            SELECT COUNT(*) 
            FROM documento_clinico 
            WHERE id_historial = ? AND id_documento != ?
        ");
        $consultaConteo->execute([$documento['id_historial'], $idDoc]);
        $otrosDocumentos = (int)$consultaConteo->fetchColumn();

        // 3. Eliminar documento de la BD
        $consultaEliminar = $baseDatos->prepare("DELETE FROM documento_clinico WHERE id_documento = ?");
        $consultaEliminar->execute([$idDoc]);

        // 4. Si no hay otros documentos en esta entrada, eliminar también la entrada del historial
        if ($otrosDocumentos === 0) {
            $consultaEliminarHistorial = $baseDatos->prepare("DELETE FROM historial_clinico WHERE id_historial = ?");
            $consultaEliminarHistorial->execute([$documento['id_historial']]);
            error_log("Entrada de historial {$documento['id_historial']} eliminada (no tenía otros documentos)");
        }

        // 5. Eliminar archivo físico si existe
        if (!empty($documento['ruta'])) {
            $rutaCompleta = __DIR__ . '/../public/' . $documento['ruta'];
            if (file_exists($rutaCompleta)) {
                unlink($rutaCompleta);
                error_log("Archivo físico eliminado: $rutaCompleta");
            }
        }        // Registrar en el sistema lo que hicimos
        registrarActividad(
            $idProf,
            $idPac,
            'documento_clinico',
            (string)$idDoc,
            null,
            'Documento eliminado del historial',
            'DELETE'
        );
        $baseDatos->commit();
        return true;
    } catch (Throwable $e) {
        $baseDatos->rollBack();
        error_log("Error eliminando documento: " . $e->getMessage());
        throw $e;
    }
}


/* Obtiene las tareas/tratamientos asignados a un paciente específico. */

function getTareasPaciente(int $idPaciente): array
{
    $baseDatos = conectar();
    $consultaSql = "
        SELECT 
            t.id_tratamiento,
            t.titulo,
            t.notas as descripcion,
            t.fecha_inicio,
            t.fecha_fin, 
            t.frecuencia_sesiones,
            DATE(t.fecha_inicio) as fecha_asignacion,
            (p.nombre || ' ' || p.apellido1) as profesional_nombre,
            t.id_profesional,
            h.id_historial,
            h.diagnostico_preliminar,
            h.diagnostico_final
        FROM tratamiento t
        JOIN historial_clinico h ON t.id_historial = h.id_historial
        JOIN persona p ON p.id_persona = t.id_profesional
        WHERE h.id_paciente = ?
        ORDER BY t.fecha_inicio DESC
    ";

    $consulta = $baseDatos->prepare($consultaSql);
    $consulta->execute([$idPaciente]);
    return $consulta->fetchAll(PDO::FETCH_ASSOC);
}
/* Obtiene todos los documentos del historial clínico de un paciente específico. */
function getHistorialPaciente(int $idPaciente): array
{
    $baseDatos = conectar();
    $consultaSql = "
        SELECT 
            dc.id_documento,
            dc.ruta,
            dc.nombre_archivo,
            dc.tipo,
            dc.fecha_subida,
            h.fecha_inicio as fecha_historial, 
            h.diagnostico_preliminar,
            h.diagnostico_final,
            (p.nombre || ' ' || p.apellido1) as profesional_nombre
        FROM documento_clinico dc
        JOIN historial_clinico h ON dc.id_historial = h.id_historial
        JOIN persona p ON p.id_persona = dc.id_profesional
        WHERE h.id_paciente = ?
          AND dc.id_tratamiento IS NULL
        ORDER BY dc.fecha_subida DESC
    ";

    $consulta = $baseDatos->prepare($consultaSql);
    $consulta->execute([$idPaciente]);
    return $consulta->fetchAll(PDO::FETCH_ASSOC);
}

/* Obtiene todas las citas de un paciente específico.*/
function getCitasPaciente(int $idPaciente): array
{
    $baseDatos = conectar();

    $consultaSql = "
        SELECT 
            c.id_cita,
            c.id_profesional,  
            c.fecha_hora,
            c.estado,
            c.motivo,
            c.origen,
            c.notas_privadas,
            (p.nombre || ' ' || p.apellido1) as profesional_nombre,
            pr.especialidad as profesional_especialidad
        FROM cita c
        JOIN persona p ON p.id_persona = c.id_profesional
        LEFT JOIN profesional pr ON pr.id_profesional = c.id_profesional
        WHERE c.id_paciente = ?
        ORDER BY c.fecha_hora DESC
    ";

    $consulta = $baseDatos->prepare($consultaSql);
    $consulta->execute([$idPaciente]);
    return $consulta->fetchAll(PDO::FETCH_ASSOC);
}


/* Procesa una solicitud de cambio o cancelación de cita por parte del paciente. */
function procesarSolicitudCitaPaciente(int $idCita, string $accion, int $idPaciente, ?string $nuevaFecha = null): array
{
    $baseDatos = conectar();

    try {
        $baseDatos->beginTransaction();

        $consulta = $baseDatos->prepare("SELECT * FROM cita WHERE id_cita = ? AND id_paciente = ?");
        $consulta->execute([$idCita, $idPaciente]);
        $cita = $consulta->fetch(PDO::FETCH_ASSOC);

        if (!$cita) {
            throw new Exception('Cita no encontrada');
        }

        if (in_array($cita['estado'], ['CANCELADA', 'ATENDIDA'])) {
            throw new Exception('Esta cita no se puede modificar');
        }

        $nuevoEstado = '';
        $mensaje = '';

        switch (strtoupper($accion)) {
            case 'CANCELAR':
                $nuevoEstado = 'CANCELAR';
                $mensaje = 'Solicitud de cancelación enviada correctamente';
                break;

            case 'CAMBIAR':
                if (!$nuevaFecha) {
                    throw new Exception('Se requiere una nueva fecha para el cambio');
                }

                $fechaObj = new DateTime($nuevaFecha);
                $ahora = new DateTime();

                if ($fechaObj <= $ahora) {
                    throw new Exception('La nueva fecha debe ser futura');
                }

                $consultaDisponibilidad = $baseDatos->prepare("
                    SELECT COUNT(*) FROM cita 
                    WHERE id_profesional = ? 
                    AND fecha_hora = ?::timestamptz 
                    AND estado IN ('CONFIRMADA', 'PENDIENTE_VALIDACION', 'SOLICITADA')
                    AND id_cita != ?
                ");
                $consultaDisponibilidad->execute([$cita['id_profesional'], $nuevaFecha, $idCita]);

                if ($consultaDisponibilidad->fetchColumn() > 0) {
                    throw new Exception('La fecha seleccionada no está disponible');
                }

                $nuevoEstado = 'CAMBIAR';
                $mensaje = 'Solicitud de cambio enviada correctamente';

                $baseDatos->prepare("
                    UPDATE cita 
                    SET notas_privadas = (
                        COALESCE(notas_privadas, '') || 
                        E'\\nSolicitud cambio a: ' || ?
                    )
                    WHERE id_cita = ?
                ")->execute([$nuevaFecha, $idCita]);

                break;

            default:
                throw new Exception('Acción no válida');
        }

        $consulta = $baseDatos->prepare("UPDATE cita SET estado = ? WHERE id_cita = ?");
        $consulta->execute([$nuevoEstado, $idCita]);

        $baseDatos->commit();
        
        return ['ok' => true, 'mensaje' => $mensaje];
    } catch (Exception $e) {
        $baseDatos->rollBack();
        error_log("Error procesando solicitud de cita: " . $e->getMessage());
        return ['ok' => false, 'mensaje' => $e->getMessage()];
    }
}