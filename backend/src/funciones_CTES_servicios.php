<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;


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
        
        /* Configurar zona horaria para España */
        $pdo->exec("SET timezone = 'Europe/Madrid'");
        
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
 * Comprueba si el token que envía el usuario es válido
 * Si está todo bien, devuelve los datos del usuario y un token renovado
 * Si algo va mal, simplemente devuelve false
 */
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

    // Verificamos que el usuario realmente existe en nuestra base de datos
    $baseDatos = conectar();
    $consulta = $baseDatos->prepare("SELECT * FROM persona WHERE id_persona = ?");
    $consulta->execute([(int)$datosToken->sub]);
    if ($consulta->rowCount() === 0) return false;
    $datosUsuario = $consulta->fetch(PDO::FETCH_ASSOC);

    // Le damos un token fresquito para que siga navegando
    $contenidoNuevoToken = [
        'sub' => (int)$datosUsuario['id_persona'],
        'rol' => $datosUsuario['rol'],
        'exp' => time() + intval(getenv('JWT_EXPIRACION') ?: 3600)
    ];
    $tokenRenovado = \Firebase\JWT\JWT::encode($contenidoNuevoToken, $claveSecreta, 'HS256');

    return ['usuario' => $datosUsuario, 'token' => $tokenRenovado];
}
/**
 * Busca el último consentimiento que dio este usuario
 * Si nunca ha dado ninguno, devuelve null
 */
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

/**
 * Mira si el usuario tiene un consentimiento que sigue activo
 * Si no lo revocó, significa que está vigente
 */
function tieneConsentimientoVigente(int $idPersona): bool
{
    $consentimiento = obtenerUltimoConsentimiento($idPersona);
    return $consentimiento !== null && $consentimiento['fecha_revocado'] === null;
}


/*──  REGISTRO DE ACTIVIDADES  ─*/
/**
 * Guarda en el registro qué hace cada usuario en el sistema
 * Es útil para saber quién cambió qué y cuándo
 */
function registrarActividad(int $quienLoHace, int $aQuienAfecta, string $queTabla, ?string $queCampo, $valorAnterior, $valorNuevo, string $queAccion): bool
{
    try {
        $baseDatos = conectar();
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
            ':ac' => strtoupper($queAccion),
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? ''
        ]);
    } catch (Throwable $error) {
        error_log('Fallo al registrar actividad: ' . $error->getMessage());
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
    string $consultaSql,
    array $parametros,
    int $actor = 0,
    ?string $tabla = null,
    ?int $afect = null
): bool {
    $baseDatos   = conectar();
    $consulta = $baseDatos->prepare($consultaSql);
    $ok   = $consulta->execute($parametros);

    if ($ok && preg_match('/^\s*(INSERT|UPDATE|DELETE)/i', $consultaSql, $m)) {
        $accion = strtoupper($m[1]);        /* Guardamos un snapshot de los parámetros; WARNING: puede contener datos sensibles */
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





function iniciarSesionConEmail(string $email, string $contraseña): array
{
    try {        $consulta = "SELECT id_persona id,
                       (nombre || ' ' || apellido1) nombre,
                       email,
                       LOWER(rol::text) rol
                FROM   persona                WHERE  email = :e
                  AND  password_hash IS NOT NULL
                  AND  password_hash = ENCODE(DIGEST(:p, 'sha256'), 'hex')
                  AND  activo = true
                LIMIT  1";
        $busqueda = conectar()->prepare($consulta);
        $busqueda->execute(['e' => $email, 'p' => $contraseña]);

        if ($datosUsuario = $busqueda->fetch()) {
            // Crear el token de sesión para que el usuario pueda navegar
            $contenidoToken = [
                'sub' => (int)$datosUsuario['id'],
                'rol' => $datosUsuario['rol'],
                'exp' => time() + intval(getenv('JWT_EXPIRACION') ?: 3600)
            ];
            $tokenSesion = \Firebase\JWT\JWT::encode($contenidoToken, getenv('JWT_SECRETO') ?: 'CAMBIAR_POR_SECRETO', 'HS256');

            return [
                'ok' => true,
                'token' => $tokenSesion, // Esto es lo que necesita el frontend
                'usuario' => $datosUsuario
            ];
        }
        return [
            'ok' => false,
            'mensaje' => 'Email o contraseña incorrectos'
        ];
    } catch (PDOException $error) {
        return ['ok' => false, 'error' => $error->getMessage()];
    }
}
/**
 * Aquí es donde alguien pide una cita nueva
 * Lo que hace es: busca si ya existe la persona, si no la crea
 * Después ve qué profesional está libre y le asigna la cita
 * Al final devuelve si todo fue bien o si hubo algún problema
 */
function pedirCitaNueva(string $nombre, string $email, ?string $telefono, string $motivo, string $fecha, int $quienLoPide = 0): array
{
    error_log("============================================");
    error_log("Alguien quiere una cita - Nombre: $nombre, Email: $email, Fecha: $fecha, Teléfono: " . ($telefono === null ? 'Sin teléfono' : $telefono));
    error_log("============================================");

    // Comprobamos que nos hayan dado todos los datos importantes
    if ($nombre === '' || $email === '' || $motivo === '' || $fecha === '') {
        error_log("Faltan datos importantes para la cita");
        return ['ok' => false, 'mensaje' => 'Faltan campos obligatorios', 'status' => 400];
    }

    try {
        /* Parsear fecha y comprobar horario L-V 10–18 */
        error_log("Validando fecha y hora: $fecha");
        $fecha = new DateTime($fecha);
    } catch (Exception $e) {
        error_log("Error al parsear la fecha: " . $e->getMessage());
        return ['ok' => false, 'mensaje' => 'Fecha inválida', 'status' => 400];
    }
    $w = (int)$fecha->format('w');    /* 0 domingo … 6 sábado */
    $h = (int)$fecha->format('G');    /* 0–23 */
    error_log("Día de la semana: $w, Hora: $h");

    if ($w < 1 || $w > 5 || $h < 10 || $h >= 18) {
        error_log("Fecha fuera de horario laboral: día $w, hora $h");
        return ['ok' => false, 'mensaje' => 'Fuera de horario laboral. Horario disponible: Lunes a Viernes de 10:00 a 18:00', 'status' => 400];
    }
    $ts = $fecha->format('Y-m-d H:i:s');
    error_log("Fecha validada correctamente: $ts");      // Buscar o crear la persona en nuestro sistema
    error_log("Buscando o creando persona con email: $email");
    $idPersona = buscarOCrearPersona($nombre, $email, $telefono);
    error_log("ID de persona obtenido/creado: $idPersona");

    /* Asegurar paciente */
    error_log("Asegurando que exista entrada en paciente para ID: $idPersona");
    asegurarPaciente($idPersona);    /* Conectar y buscar profesional disponible */
    $baseDatos = conectar();

    /* Verificar si hay profesionales en la base de datos */
    $checkProf = $baseDatos->query("SELECT COUNT(*) FROM profesional");
    $profCount = $checkProf->fetchColumn();
    error_log("Número de profesionales en la base de datos: $profCount");

    if ($profCount == 0) {
        error_log("No hay profesionales en la base de datos");
        return ['ok' => false, 'mensaje' => 'No hay profesionales registrados en el sistema', 'status' => 409];
    }
    $consultaSql = "
      SELECT p.id_profesional
        FROM profesional p
       WHERE NOT EXISTS (        SELECT 1 FROM bloque_agenda b
          WHERE b.id_profesional = p.id_profesional
            AND b.tipo_bloque    IN ('AUSENCIA','VACACIONES','BAJA','EVENTO')
            AND :ts_bloque BETWEEN b.fecha_inicio
                       AND (b.fecha_fin - INTERVAL '1 second')
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
    $consulta = $baseDatos->prepare($consultaSql);
    $consulta->execute([
        ':ts_bloque' => $ts,
        ':ts_cita'   => $ts,
        ':ts_fecha'  => $ts
    ]);
    $idProf = $consulta->fetchColumn();
    error_log("Profesional encontrado para la cita: " . ($idProf ? $idProf : "Ninguno"));

    if (!$idProf) {
        return ['ok' => false, 'mensaje' => 'No hay profesionales disponibles para esta fecha y hora', 'status' => 409];
    }
    try {
        // Crear la cita en el sistema (ya tenemos la persona y sabemos que es paciente)
        $citaCreada = execLogged(
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
                ':tel'   => $telefono, // Puede ser null, no pasa nada
                ':email' => $email,
                ':motivo' => $motivo
            ],
            $quienLoPide,                // Quien está pidiendo la cita
            'cita'                       // Para el registro de actividades
        );
        if (!$citaCreada) {
            return ['ok' => false, 'mensaje' => 'Error al crear la cita', 'status' => 500];
        }        // Guardar el ID de la cita que acabamos de crear
        $idCita = (int)$baseDatos->lastInsertId();

        return ['ok' => true, 'mensaje' => 'Cita reservada correctamente'];
    } catch (PDOException $e) {
        error_log("Error de base de datos al crear cita: " . $e->getMessage());
        /* Devolver un mensaje más amigable para el usuario */
        return ['ok' => false, 'mensaje' => 'Error al procesar la solicitud. Por favor, inténtelo de nuevo más tarde.', 'status' => 500];
    } catch (Exception $e) {
        error_log("Error al crear cita: " . $e->getMessage());
        return ['ok' => false, 'mensaje' => 'Error al procesar la solicitud: ' . $e->getMessage(), 'status' => 500];
    }
}

/**
 * Busca si ya tenemos a esta persona en el sistema por su email o teléfono
 * Si no la encuentra, crea una nueva entrada para ella
 * Al final devuelve el ID de la persona (nueva o existente)
 */
function buscarOCrearPersona(string $nombre, string $email, ?string $telefono): int
{
    $baseDatos = conectar();
    // Primero miramos si ya está registrada por email
    $busqueda = $baseDatos->prepare("
      SELECT id_persona
        FROM persona
       WHERE email = :email
       LIMIT 1
    ");
    $busqueda->execute([':email' => $email]);
    if ($idEncontrado = $busqueda->fetchColumn()) {
        return (int)$idEncontrado;
    }    // Si no la encontramos por email y tenemos teléfono, probamos por teléfono
    if ($telefono !== null && $telefono !== '') {
        $busquedaTel = $baseDatos->prepare("
          SELECT id_persona
            FROM persona
           WHERE telefono = :tel
           LIMIT 1
        ");
        $busquedaTel->execute([':tel' => $telefono]);
        if ($idEncontradoTel = $busquedaTel->fetchColumn()) {
            return (int)$idEncontradoTel;
        }
    }

    // Si llegamos aquí es que no existe, así que la creamos
    error_log("Creando nueva persona con email: $email, teléfono: " . ($telefono === null ? 'Sin teléfono' : $telefono));
    // Intentamos separar el nombre completo en nombre y apellido
    $palabrasNombre = explode(' ', $nombre);
    $nombrePila = $palabrasNombre[0]; // La primera palabra es el nombre
    $apellido = count($palabrasNombre) > 1 ?
        implode(' ', array_slice($palabrasNombre, 1)) : // El resto como apellido
        ''; // Si solo hay una palabra, el apellido queda vacío

    // Manejamos el teléfono para evitar problemas con la base de datos
    $telefonoParaGuardar = $telefono;
    if ($telefonoParaGuardar === null || $telefonoParaGuardar === '') {
        // Si no tiene teléfono, le ponemos un valor único para evitar conflictos
        $telefonoParaGuardar = 'SIN_TEL_' . uniqid();
        error_log("Asignando teléfono temporal para evitar conflictos: $telefonoParaGuardar");
    }

    $nuevaPersona = $baseDatos->prepare("
      INSERT INTO persona
        (nombre, apellido1, email, telefono, rol, password_hash)
      VALUES
        (:nom, :ap1, :email, :tel, 'PACIENTE', :pass)
    ");
    $nuevaPersona->execute([
        ':nom'   => $nombrePila,
        ':ap1'   => $apellido,
        ':email' => $email,
        ':tel'   => $telefonoParaGuardar,
        ':pass'  => password_hash('', PASSWORD_DEFAULT), /* Empty password hash as placeholder */
    ]);
    $idNueva = (int)$baseDatos->lastInsertId();
    error_log("Nueva persona creada con ID: $idNueva");
    return $idNueva;
}

/**
 * Asegura que exista una fila en paciente para id_persona dado.
 */
function asegurarPaciente(int $idPersona): void
{
    $baseDatos = conectar();
    $consulta = $baseDatos->prepare("SELECT 1 FROM paciente WHERE id_paciente = :id");
    $consulta->execute([':id' => $idPersona]);
    if ($consulta->fetch()) return;
    /* Insertamos con tipo 'ADULTO' por defecto */
    $insercion = $baseDatos->prepare("
      INSERT INTO paciente (id_paciente, tipo_paciente)
      VALUES (:id, 'ADULTO')
    ");
    $insercion->execute([':id' => $idPersona]);
}

/**
 * Devuelve solo los pacientes y profesionales activos.
 * Siempre retorna un array (posiblemente vacío).
 */
function obtenerUsuarios(): array
{
    $baseDatos = conectar();
    $consultaSql = "
      SELECT 
        id_persona AS id,
        nombre, 
        apellido1,
        apellido2,
        LOWER(rol::text) AS rol      FROM persona
      WHERE activo = true
        AND rol IN ('PACIENTE','PROFESIONAL')
      ORDER BY nombre, apellido1
    ";
    $consulta = $baseDatos->query($consultaSql);
    return $consulta->fetchAll(PDO::FETCH_ASSOC);
}



/** -------------   AGENDA (admin / profesional) ------------- */
/* === PROFESIONALES ================================================== */
/**
 * Devuelve lista de profesionales.
 * Si $search !== '' aplica filtro (case-insensitive) por nombre/apellidos.
 */
function getProfesionales(string $search = ''): array
{
    $baseDatos  = conectar();    $consultaSql = "
      SELECT id_profesional  AS id,
             (nombre || ' ' || apellido1 || ' ' || COALESCE(apellido2,'')) AS nombre
        FROM profesional p
   LEFT JOIN persona pr ON pr.id_persona = p.id_profesional
       WHERE pr.activo = true
    ";
    $parametros = [];
    if ($search !== '') {
        $consultaSql     .= " AND (nombre || ' ' || apellido1 || ' ' || COALESCE(apellido2,'')) ILIKE :txt";
        $parametros[':txt'] = '%' . $search . '%';
    }
    $consultaSql .= " ORDER BY nombre";
    $consulta = $baseDatos->prepare($consultaSql);
    $consulta->execute($parametros);
    return $consulta->fetchAll(PDO::FETCH_ASSOC);
}

/* === A G E N D A  =================================================== */
/**
 * Aquí sacamos todos los eventos que tiene un profesional entre dos fechas
 * Puede ser de cualquier profesional o de uno en particular si nos lo dicen
 */
function obtenerEventosAgenda(string $desde, string $hasta, ?int $idProfesional = null): array
{
    $baseDatos = conectar();

    /* Bloques */
    $consultaSqlBloques = "
      SELECT b.id_bloque             AS id,
             b.id_profesional        AS recurso,
             (p.nombre || ' ' || p.apellido1) AS nombre_profesional, 
             b.fecha_inicio          AS inicio,
             b.fecha_fin             AS fin,
             b.tipo_bloque           AS tipo,
             b.comentario            AS titulo,
             'bloque'                AS fuente,
             b.id_creador            AS id_creador,
             (c.nombre || ' ' || c.apellido1) AS creador
        FROM bloque_agenda b
        LEFT JOIN persona p ON p.id_persona = b.id_profesional
        LEFT JOIN persona c ON c.id_persona = b.id_creador
       WHERE DATE(b.fecha_inicio) <= :h
         AND DATE(b.fecha_fin)   >= :d
    ";
    $parametros = [':d' => $desde, ':h' => $hasta];
    if ($idProfesional !== null) {
        $consultaSqlBloques .= " AND b.id_profesional = :p";
        $parametros[':p'] = $idProfesional;
    }
    $consultaBloques = $baseDatos->prepare($consultaSqlBloques);
    $consultaBloques->execute($parametros);

    // We're only returning blocks, no appointments anymore
    return $consultaBloques->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Crea un bloque de agenda.  
 * Si $idProfesional == 0 se inserta uno idéntico por **cada** profesional activo.
 */
function crearBloqueAgenda(
    int $idProfesional,
    string $fechaInicio,
    string $fechaFin,
    string $tipoBloque,
    string $comentario = '',
    int $idCreador = 0
): bool {
    /* bloque global → uno por cada profesional */
    if ($idProfesional === 0) {
        foreach (getProfesionales() as $profesional) {
            if (!crearBloqueAgenda((int)$profesional['id'], $fechaInicio, $fechaFin, $tipoBloque, $comentario, $idCreador))
                return false;
        }
        return true;
    }

    // Verificar que el creador sea válido (no puede ser 0 por restricción de llave foránea)
    if ($idCreador <= 0) {
        // Si no hay creador válido, usamos el profesional como creador
        $idCreador = $idProfesional;
        error_log("No se proporcionó un id_creador válido, usando el profesional ($idProfesional) como creador");
    }

    // Mapear tipos adicionales a los valores válidos en la base de datos
    $tipoValido = $tipoBloque;
    // La base de datos solo permite estos valores como enum
    $tiposPermitidos = ['DISPONIBLE', 'AUSENCIA', 'VACACIONES', 'CITA', 'EVENTO', 'REUNION'];

    // Si no es un tipo permitido, mapearlo a uno válido
    if (!in_array($tipoBloque, $tiposPermitidos)) {
        // Mapeo de tipos adicionales a tipos permitidos
        $mapeoTipos = [
            'EVENTO' => 'AUSENCIA',  // Los eventos se mapean como ausencias
            'REUNION' => 'AUSENCIA', // Las reuniones se mapean como ausencias
            'BAJA' => 'AUSENCIA'     // Las bajas se mapean como ausencias
        ];

        // Si hay un mapeo para este tipo, usarlo, sino default a AUSENCIA
        $tipoValido = $mapeoTipos[$tipoBloque] ?? 'AUSENCIA';

        error_log("Tipo de bloque '$tipoBloque' mapeado a '$tipoValido' para compatibilidad con la base de datos");
    }

    error_log("Creando bloque agenda: Profesional=$idProfesional, Tipo=$tipoBloque (Ajustado a $tipoValido), Inicio=$fechaInicio, Fin=$fechaFin, Creador=$idCreador");
    $consultaSql = 'INSERT INTO bloque_agenda (id_profesional,fecha_inicio,fecha_fin,tipo_bloque,comentario,id_creador)
            VALUES (:p,:i,:f,:t,:c,:cr)';
    return execLogged(
        $consultaSql,
        [':p' => $idProfesional, ':i' => $fechaInicio, ':f' => $fechaFin, ':t' => $tipoValido, ':c' => $comentario, ':cr' => $idCreador],
        $idCreador,
        'bloque_agenda'
    );
}
/**
 * Elimina un evento de la agenda (bloque o cita según exista).
 */
function eliminarEvento(int $idEvento, int $idActor = 0): bool
{
    /* Primero intentamos eliminar en la tabla de bloques */
    if (execLogged('DELETE FROM bloque_agenda WHERE id_bloque=:id', [':id' => $idEvento], $idActor, 'bloque_agenda', $idEvento))
        return true;
    /* Si no estaba en bloques, probamos en la tabla de citas */
    return execLogged('DELETE FROM cita WHERE id_cita=:id', [':id' => $idEvento], $idActor, 'cita', $idEvento);
}

/* ─ helper de envío SMTP ─ */
/**
 * Envía un correo electrónico usando PHPMailer con configuración SMTP
 */
function enviarEmail(string $destinatario, string $asunto, string $cuerpoHtml): bool
{
    /* Obtener credenciales SMTP del entorno */
    $servidor = getenv('SMTP_HOST');
    $usuario = getenv('SMTP_USER');
    $contrasena = getenv('SMTP_PASS');

    /* Si no hay configuración, logueamos y continuamos */
    if (!$servidor || !$usuario || !$contrasena) {
        error_log('SMTP no configurado: email NO enviado a ' . $destinatario);
        return true;
    }

    /* Configuración de PHPMailer */
    $correo = new PHPMailer(true);
    try {
        $correo->isSMTP();
        $correo->Host       = $servidor;
        $correo->SMTPAuth   = true;
        $correo->Username   = $usuario;
        $correo->Password   = $contrasena;
        $correo->Port       = intval(getenv('SMTP_PORT') ?: 587);
        $correo->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;

        /* Configuración para manejar tildes y caracteres especiales */
        $correo->CharSet = 'UTF-8';
        $correo->Encoding = 'base64';

        $correo->setFrom(
            getenv('SMTP_FROM') ?: $usuario,
            getenv('SMTP_FROM_NAME') ?: 'Clínica'
        );
        $correo->addAddress($destinatario);

        $correo->isHTML(true);
        $correo->Subject = $asunto;
        $correo->Body    = $cuerpoHtml;

        $correo->send();
        return true;
    } catch (Exception $e) {
        error_log('Error al enviar email: ' . $correo->ErrorInfo);
        return false;
    }
}
/* ====== NOTIFICACIONES ========================================== */

/* lista pendientes */
/**
 * Obtiene las notificaciones pendientes para un usuario según su rol
 */
function obtenerNotificacionesPendientes(int $idUsuario, string $rol): array
{
    $baseDatos = conectar();

    $consulta = "
      SELECT c.id_cita                AS id,
             TO_CHAR(c.fecha_hora,'DD/MM/YYYY HH24:MI') AS fecha,
             c.estado                 AS tipo,             (pa.nombre || ' ' || pa.apellido1) paciente,
             (pr.nombre || ' ' || pr.apellido1) profesional
        FROM cita c
        JOIN persona pa ON pa.id_persona = c.id_paciente
        JOIN persona pr ON pr.id_persona = c.id_profesional
       WHERE c.estado = 'PENDIENTE_VALIDACION'";

    $parametros = [];
    if ($rol === 'admin') {
        /* Solo peticiones hechas por pacientes (web / app) */
        $consulta .= " AND c.origen IN ('WEB','APP')";
    } else { /* Profesional */
        $consulta .= " AND c.id_profesional = :p";
        $parametros[':p'] = $idUsuario;
    }

    $consulta .= " ORDER BY c.fecha_hora";
    $consulta = $baseDatos->prepare($consulta);
    $consulta->execute($parametros);
    return $consulta->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Procesa confirmación o rechazo de una notificación de cita
 */
function procesarNotificacion(int $idCita, string $accion, int $idUsuario, string $rol): bool
{
    error_log("Iniciando procesarNotificacion: ID=$idCita, Acción=$accion, Usuario=$idUsuario, Rol=$rol");
    $nuevoEstado = $accion === 'CONFIRMAR' ? 'CONFIRMADA' : 'CANCELADA';
    $baseDatos = conectar();
    $baseDatos->beginTransaction();
    try {
        /* 1) Bloquear la cita */
        $consulta = $baseDatos->prepare("
            SELECT c.*, p.email pacienteEmail
              FROM cita  c
        INNER JOIN persona p ON p.id_persona = c.id_paciente
             WHERE c.id_cita = ? FOR UPDATE");
        $consulta->execute([$idCita]);
        if (!$fila = $consulta->fetch(PDO::FETCH_ASSOC)) {
            error_log("procesarNotificacion: Cita $idCita no encontrada");
            throw new Exception('Cita inexistente');
        }

        /* Verificar permisos del profesional */
        if ($rol === 'profesional' && (int)$fila['id_profesional'] !== $idUsuario) {
            error_log("procesarNotificacion: Usuario $idUsuario sin permiso para cita $idCita");
            throw new Exception('Prohibido');
        }

        /* Verificar que no esté ya procesada */
        if ($fila['estado'] === 'CONFIRMADA' || $fila['estado'] === 'CANCELADA') {
            error_log("procesarNotificacion: Cita $idCita ya está {$fila['estado']}");
            throw new Exception('Estado inválido');
        }

        /* 2) Actualizar estado de la cita */
        error_log("Actualizando estado de cita $idCita a $nuevoEstado");
        $baseDatos->prepare("UPDATE cita SET estado = ? WHERE id_cita = ?")
            ->execute([$nuevoEstado, $idCita]);        /* 3) Si se confirma, crear bloque en la agenda del profesional */
        if ($accion === 'CONFIRMAR') {
            /* Verificar que no exista ya un bloque para esta cita */
            $consulta = $baseDatos->prepare("SELECT COUNT(*) FROM bloque_agenda WHERE id_profesional = ? AND fecha_inicio = ?");
            $consulta->execute([(int)$fila['id_profesional'], $fila['fecha_hora']]);
            $bloqueExistente = (int)$consulta->fetchColumn();

            error_log("Verificando bloques existentes: " . ($bloqueExistente ? "SI existe" : "NO existe"));

            if ($bloqueExistente === 0) {
                /* Crear un bloque de 1 hora para la cita */
                $fechaInicio = $fila['fecha_hora'];
                $fechaFin = date('Y-m-d H:i:s', strtotime($fechaInicio . ' +1 hour'));

                /* Obtener el nombre completo del paciente */
                $declaracionPaciente = $baseDatos->prepare("
                    SELECT (nombre || ' ' || apellido1 || CASE WHEN apellido2 IS NOT NULL AND apellido2 != '' THEN (' ' || apellido2) ELSE '' END) as nombre_completo
                    FROM persona
                    WHERE id_persona = ?
                ");
                $declaracionPaciente->execute([$fila['id_paciente']]);
                $nombrePaciente = $declaracionPaciente->fetchColumn() ?: 'Paciente';

                error_log("Creando bloque de agenda: Prof={$fila['id_profesional']}, Inicio=$fechaInicio, Fin=$fechaFin");
                $baseDatos->prepare("
                    INSERT INTO bloque_agenda (
                        id_profesional, fecha_inicio, fecha_fin, 
                        tipo_bloque, comentario, id_creador
                    ) VALUES (
                        :p, :i, :f, 'CITA', :c, :cr
                    )
                ")->execute([
                    ':p' => $fila['id_profesional'],
                    ':i' => $fechaInicio,
                    ':f' => $fechaFin,
                    ':c' => "Cita con {$nombrePaciente} (#{$fila['id_paciente']})",
                    ':cr' => $idUsuario
                ]);

                /* Actualizar la cita con el ID del bloque creado */
                $idBloque = $baseDatos->lastInsertId();
                $baseDatos->prepare("UPDATE cita SET id_bloque = ? WHERE id_cita = ?")
                    ->execute([$idBloque, $idCita]);

                error_log("Bloque de agenda creado correctamente y vinculado a la cita");
            } else {
                error_log("No se creó bloque de agenda porque ya existe");
            }
        }        /* 4) Registrar notificación en BD */
        /* Formatear fecha y hora para el mensaje */
        $fechaFormateada = date('d/m/Y', strtotime($fila['fecha_hora']));
        $horaFormateada = date('H:i', strtotime($fila['fecha_hora']));

        /* Crear un mensaje más completo y profesional */
        $asuntoEmail = $accion === 'CONFIRMAR' ? 'Confirmación de su cita' : 'Cancelación de su cita';

        /* HTML para el cuerpo del email */
        $mensaje = '
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #ddd; padding: 20px; border-radius: 5px;">
            <div style="text-align: center; margin-bottom: 20px;">
                <img src="https://iatrenda-petaka.s3.eu-west-3.amazonaws.com/images/petaka.jpg" alt="Clínica Petaka Logo" style="max-width: 150px;" />
                <h2 style="color: #3a6ea5;">Clínica Logopédica Petaka</h2>
            </div>
            
            <p>Estimado/a <strong>' . htmlspecialchars($fila['nombre_contacto']) . '</strong>,</p>
              <p>' . ($accion === 'CONFIRMAR'
            ? 'Nos complace confirmarle que su cita ha sido <strong>confirmada</strong>.'
            : 'Le informamos que su cita ha sido <strong>cancelada</strong>.') . '</p>
            
            <div style="background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0;">
                <p><strong>Fecha:</strong> ' . $fechaFormateada . '</p>
                <p><strong>Hora:</strong> ' . $horaFormateada . '</p>
                <p><strong>Motivo:</strong> ' . htmlspecialchars($fila['motivo']) . '</p>
            </div>
            
            ' . ($accion === 'CONFIRMAR' ? '
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
            ') . '
            
            <p>Gracias por confiar en nuestros servicios.</p>
            
            <p>Atentamente,<br>El equipo de Petaka</p>
            
            <hr style="border: 1px solid #eee; margin: 20px 0;" />
            
            <div style="font-size: 10px; color: #777; text-align: justify;">
                <p><strong>ADVERTENCIA LEGAL:</strong> Este mensaje, junto a la documentación que en su caso se adjunta, se dirige exclusivamente a su destinatario y puede contener información privilegiada o confidencial. Si no es Vd. el destinatario indicado, queda notificado de que la utilización, divulgación y/o copia sin autorización está prohibida en virtud de la legislación vigente. Si ha recibido este mensaje por error, le rogamos que nos lo comunique inmediatamente por esta misma vía y proceda a su destrucción.</p>
                
                <p>Conforme a lo dispuesto en la L.O. 3/2018 de 5 de diciembre, de Protección de Datos Personales y garantía de los derechos digitales, Clínica Petaka, logopedas, le informa que los datos de carácter personal que proporcione serán recogidos en un fichero cuyo responsable es Clínica Petaka, logopedas y serán tratados con la exclusiva finalidad expresada en el mismo. Podrá acceder a sus datos, rectificarlos, cancelarlos y oponerse a su tratamiento, en los términos y en las condiciones previstas en la LOPD, dirigiéndose por escrito a info@clinicapetaka.com.</p>
            </div>
        </div>';
        error_log("Insertando notificación: De=$idUsuario, Para={$fila['id_paciente']}, Cita=$idCita");

        $declaracionNotificacion = $baseDatos->prepare("
            INSERT INTO notificacion(id_emisor,id_destino,id_cita,
                                tipo,asunto,cuerpo)
            VALUES (:e,:d,:c,'EMAIL',:asunto,:b)
        ");
        $declaracionNotificacion->execute([
            ':e' => $idUsuario,
            ':d' => $fila['id_paciente'],
            ':c' => $idCita,
            ':asunto' => $asuntoEmail,
            ':b' => $mensaje
        ]);

        error_log("Notificación insertada correctamente");        /* Commit the transaction first */
        $baseDatos->commit();
        error_log("Transacción confirmada correctamente");

        /* 5) Enviar email (fuera de la transacción) */
        try {
            error_log("Intentando enviar email a {$fila['pacienteEmail']}");
            $emailEnviado = enviarEmail(
                $fila['pacienteEmail'],
                $asuntoEmail,
                $mensaje
            );
            error_log("Resultado del envío de email: " . ($emailEnviado ? "Enviado" : "Falló"));
        } catch (Exception $e) {            /* Solo logueamos el error del email, pero no afecta al resultado */
            error_log("Error enviando email para cita $idCita: " . $e->getMessage());
        }

        return true;
    } catch (Exception $e) {
        /* Rollback en caso de error */
        $baseDatos->rollBack();
        error_log("Error procesando notificación: " . $e->getMessage());
        return false;
    }
}

/**
 * Busca una persona en el sistema por email, teléfono o NIF
 */
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
                 prof.num_colegiado,prof.especialidad,
                 pac.tipo_paciente
            FROM persona p
       LEFT JOIN profesional prof ON prof.id_profesional=p.id_persona
       LEFT JOIN paciente pac     ON pac.id_paciente  =p.id_persona
           WHERE " . implode(' OR ', $condiciones) . " LIMIT 1";
    $consultaPreparada = $baseDatos->prepare($consulta);
    $consultaPreparada->execute($parametros);
    return $consultaPreparada->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Decodifica un UID agregando los caracteres de relleno (=) que falten
 */
function decodificarUid(string $uid): int
{
    $uid = strtr($uid, '-_', '+/');

    /* Agregar relleno necesario */
    $caracteresQFaltan = strlen($uid) % 4;
    if ($caracteresQFaltan) {
        $uid .= str_repeat('=', 4 - $caracteresQFaltan);
    }
    return (int) base64_decode($uid);
}

/* ─ INSERT / UPDATE persona ─ */
/**
 * Inserta o actualiza PERSONA y devuelve id_persona.
 *
 *  $d         → array de campos recibidos (nombre, email, …)
 *  $rolFinal  → 'PACIENTE' | 'PROFESIONAL' | 'TUTOR' | 'ADMIN'
 *  $actor     → id del usuario que hace la operación (para logs)
 *  $forceId   → >0  ⇒ PUT: fuerza a actualizar ese registro
 *
 * Reglas de unicidad EN CÓDIGO (no en MySQL):
 *   · Los roles-login (PACIENTE, PROFESIONAL, ADMIN) no pueden compartir
 *     email, nif ni teléfono con otros roles-login **activos**.
 *   · El rol TUTOR puede repetir o dejar vacíos esos datos.
 *
 * Si es un usuario nuevo con email y rol-login ⇒ email “Crear contraseña”.
 *
 * Lanza Exception con mensaje descriptivo si hay conflicto.
 */
/**
 * Inserta o actualiza PERSONA y devuelve id_persona.
 *
 *  $datos      → array de campos recibidos (nombre, email, …)
 *  $rolFinal   → 'PACIENTE' | 'PROFESIONAL' | 'TUTOR' | 'ADMIN'
 *  $actor      → id del usuario que hace la operación (para logs)
 *  $forzarId   → >0  ⇒ PUT: fuerza a actualizar ese registro
 *
 * Reglas de unicidad EN CÓDIGO (no en MySQL):
 *   · Los roles-login (PACIENTE, PROFESIONAL, ADMIN) no pueden compartir
 *     email, nif ni teléfono con otros roles-login **activos**.
 *   · El rol TUTOR puede repetir o dejar vacíos esos datos.
 *
 * Si es un usuario nuevo con email y rol-login ⇒ email "Crear contraseña".
 *
 * Lanza Exception con mensaje descriptivo si hay conflicto.
 */
function actualizarOInsertarPersona(
    array $datos,
    string $rolFinal,
    int $actor = 0,
    int $forzarId = 0
): int {
    $baseDatos = conectar();
    $rolesLogin = ['PACIENTE', 'PROFESIONAL', 'ADMIN'];
    $esRolLogin = in_array($rolFinal, $rolesLogin, true);

    /* ─ 1. localizar registro previo (forzarId) ─ */
    $registroPrevio = null;
    if ($forzarId > 0) {
        $consulta = $baseDatos->prepare("SELECT * FROM persona WHERE id_persona=?");
        $consulta->execute([$forzarId]);
        $registroPrevio = $consulta->fetch(PDO::FETCH_ASSOC);
        if (!$registroPrevio) throw new Exception("Usuario #$forzarId inexistente");
    }

    /* ─ 2. reactivar si coincidía email/nif inactivo ─ */
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
    }    /* ─ 3. unicidad condicional (solo roles-login) ─ */
    foreach (['email', 'telefono', 'nif'] as $campo) {
        if (empty($datos[$campo])) continue;              // vacíos no cuentan
        $consulta = "SELECT id_persona,rol FROM persona
                WHERE $campo=:v AND activo=true";
        $parametros = [':v' => $datos[$campo]];
        if ($registroPrevio) {
            $consulta .= " AND id_persona<>:yo";
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

    /* ─ 4. SET dinámico (ahora admite NULL/borrado) ─ */
    $camposEditables = [
        'nombre',
        'apellido1',
        'apellido2',
        'fecha_nacimiento',
        'nif',
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
    $asignaciones = [];
    $valores = [];
    foreach ($camposEditables as $campo) {
        if (array_key_exists($campo, $datos)) {           // existe en input, aunque sea ''
            $asignaciones[] = "$campo = :$campo";
            $valores[":$campo"] = ($datos[$campo] === '') ? null : $datos[$campo]; // '' ⇒ NULL
        }
    }    /* ─ 5. UPDATE ─ */
    if ($registroPrevio) {
        $id = (int)$registroPrevio['id_persona'];

        if ($registroPrevio['rol'] !== $rolFinal) {
            $baseDatos->prepare("UPDATE persona SET rol=:r WHERE id_persona=:id")
                ->execute([':r' => $rolFinal, ':id' => $id]);
        }
        if (!empty($registroPrevio['reactivado'])) {
            $baseDatos->prepare("UPDATE persona SET activo=true WHERE id_persona=:id")
                ->execute([':id' => $id]);
        }
        if ($asignaciones) {
            $valores[':id'] = $id;
            $baseDatos->prepare("UPDATE persona SET " . implode(', ', $asignaciones) . " WHERE id_persona=:id")
                ->execute($valores);
        }
        return $id;
    }    /* ─ 6. INSERT ─ */
    $columnas = array_map(fn($p) => substr($p, 1), array_keys($valores));
    $consultaSql  = "INSERT INTO persona (" . implode(',', $columnas) . ",rol,fecha_alta)
             VALUES (" . implode(',', array_keys($valores)) . ",:rol,CURRENT_DATE)";
    $valores[':rol'] = $rolFinal;
    $baseDatos->prepare($consultaSql)->execute($valores);
    $idNuevo = (int)$baseDatos->lastInsertId();

    /* ─ 7. email “crear contraseña” para roles-login ─ */
    if ($esRolLogin && !empty($datos['email'])) {
        $uid   = rtrim(strtr(base64_encode((string)$idNuevo), '+/', '-_'), '=');
        $front = getenv('FRONTEND_URL') ?: 'http://localhost:3000';
        $link  = "$front/crear-contrasena?u=$uid";
        $html  = "
          <p>Hola {$datos['nombre']}:</p>
          <p>Hemos creado tu usuario en <strong>Clínica Petaka</strong>.</p>
          <p>Establece tu contraseña aquí: <a href=\"$link\">Crear contraseña</a></p>";
        enviarEmail($datos['email'], 'Crea tu contraseña – Petaka', $html);
    }    /* ─ 8. registrar en el sistema lo que pasó ─ */
    registrarActividad($actor, $idNuevo, 'persona', null, null, json_encode($datos), 'INSERT');

    return $idNuevo;
}


/* ─ INSERT / UPDATE profesional ─ */
function upsertProfesional(int $id, array $datosProfesional, int $actor = 0): bool
{
    $baseDatos   = conectar();
    $consulta   = $baseDatos->prepare('SELECT 1 FROM profesional WHERE id_profesional=?');
    $consulta->execute([$id]);
    $consultaSql = $consulta->fetch()
        ? 'UPDATE profesional SET num_colegiado=:n, especialidad=:e WHERE id_profesional=:id'
        : 'INSERT INTO profesional SET id_profesional=:id, num_colegiado=:n, especialidad=:e';

    return execLogged(
        $consultaSql,
        [':id' => $id, ':n' => $datosProfesional['num_colegiado'] ?? null, ':e' => $datosProfesional['especialidad'] ?? null],
        $actor,
        'profesional',
        $id
    );
}


/* ------------------------------------------------------------------
   Tutores y pacientes
-------------------------------------------------------------------*/

/**
 * Inserta / actualiza al tutor y devuelve su id_persona.
 *
 * $t = [
 *   'nombre'      => ...,
 *   'apellido1'   => ...,
 *   'apellido2'   => ... (opcional),
 *   'telefono'    => ... (opcional – puede repetirse o ser NULL),
 *   'email'       => ... (opcional – puede repetirse o ser NULL),
 *   'nif'         => ... (opcional – puede repetirse o ser NULL),
 *   'metodo'      => 'TEL' | 'EMAIL'   // preferencia de contacto
 * ]
 */
function upsertTutor(array $datosTutor): int
{
    /* 1) Persona con rol = TUTOR.  Las restricciones únicas sólo
       se aplican a *_login, por lo que email/teléfono/NIF pueden
       repetirse o quedar NULL sin problema. */
    $id = actualizarOInsertarPersona($datosTutor, 'TUTOR');

    /* 2) Tabla tutor (metodo_contacto_preferido) */
    $baseDatos = conectar();
    $consulta = $baseDatos->prepare("SELECT 1 FROM tutor WHERE id_tutor = ?");
    $consulta->execute([$id]);
    $consultaSql = $consulta->fetch()
        ? "UPDATE tutor
              SET metodo_contacto_preferido = :m
            WHERE id_tutor = :id"
        : "INSERT INTO tutor
              SET id_tutor = :id,
                  metodo_contacto_preferido = :m";

    $baseDatos->prepare($consultaSql)->execute([
        ':id' => $id,
        ':m'  => strtoupper($datosTutor['metodo'] ?? 'TEL')
    ]);

    return $id;
}

/**
 * Inserta / actualiza la fila de paciente y enlaza tutor si procede.
 *
 * $x = [
 *   'tipo_paciente'          => 'INFANTE' | 'NIÑO' | 'ADOLESCENTE' | 'ADULTO',
 *   'observaciones_generales'=> ... (opcional),
 *   'tutor' => [ … ]         // mismo formato que en upsertTutor()
 * ]
 */
function upsertPaciente(int $id, array $datosPaciente): bool
{
    $baseDatos = conectar();

    /* ----- 1. Determinar si es menor y procesar tutor (si llega) ----- */
    $tipoPaciente      = strtoupper($datosPaciente['tipo_paciente'] ?? 'ADULTO');
    $esMenor   = $tipoPaciente !== 'ADULTO';
    $idTutor   = null;

    if ($esMenor && !empty($datosPaciente['tutor']) && is_array($datosPaciente['tutor'])) {
        $idTutor = upsertTutor($datosPaciente['tutor']);       // crea / actualiza tutor
    }

    /* ----- 2. Upsert sobre la propia tabla paciente ------------------ */
    $consulta = $baseDatos->prepare("SELECT 1 FROM paciente WHERE id_paciente = ?");
    $consulta->execute([$id]);
    $consultaSql = $consulta->fetch()
        ? "UPDATE paciente
               SET tipo_paciente         = :t,
                   observaciones_generales = :obs,
                   id_tutor               = :tu
             WHERE id_paciente           = :id"
        : "INSERT INTO paciente
               SET id_paciente           = :id,
                   tipo_paciente         = :t,
                   observaciones_generales = :obs,
                   id_tutor               = :tu";

    return $baseDatos->prepare($consultaSql)->execute([
        ':id'  => $id,
        ':t'   => $tipoPaciente,
        ':obs' => $datosPaciente['observaciones_generales'] ?? null,
        ':tu'  => $idTutor                     // puede ser NULL
    ]);
}

/**
 * Devuelve los datos de persona + profesional/paciente (+ tutor si corresponde).
 */
function getUsuarioDetalle(int $id): ?array
{
    $baseDatos = conectar();
    // Persona básica
    $consulta = $baseDatos->prepare("SELECT * FROM persona WHERE id_persona = ?");
    $consulta->execute([$id]);
    $datosPersona = $consulta->fetch(PDO::FETCH_ASSOC);
    if (!$datosPersona) return null;

    // Profesional
    $consulta = $baseDatos->prepare("SELECT num_colegiado, especialidad, fecha_alta_profesional 
                          FROM profesional WHERE id_profesional = ?");
    $consulta->execute([$id]);
    $datosProfesional = $consulta->fetch(PDO::FETCH_ASSOC) ?: null;

    // Paciente + tutor
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
        'persona'      => $datosPersona,
        'profesional'  => $datosProfesional,
        'paciente'     => $datosPaciente,
        'tutor'        => $datosTutor
    ];
}


function citasActivas(int $id): int
{
    $consulta = 'SELECT COUNT(*) FROM cita WHERE estado<>"CANCELADA" AND (id_paciente=:id OR id_profesional=:id)';
    $consultaPreparada  = conectar()->prepare($consulta);
    $consultaPreparada->execute([':id' => $id]);
    return (int)$consultaPreparada->fetchColumn();
}

/* elimina una persona solo si TODAS sus citas están canceladas */
function eliminarUsuario(int $id, int $actor = 0): array
{
    if (citasActivas($id) > 0) {
        return ['ok' => false, 'code' => 409, 'msg' => 'El usuario tiene citas activas'];
    }
    $exito = execLogged('DELETE FROM persona WHERE id_persona=:id', [':id' => $id], $actor, 'persona', $id);
    return $exito ? ['ok' => true] : ['ok' => false, 'code' => 500, 'msg' => 'Error SQL'];
}





/* ── resumen mensual ── */
function getInformeMes(int $año, int $mes): array
{
    $baseDatos  = conectar();
    $fechaInicio = sprintf('%d-%02d-01', $año, $mes);
    $fechaFin = date('Y-m-t', strtotime($fechaInicio));

    /* totales citas */
    $filaDatos = $baseDatos->query("
       SELECT
         COUNT(*)                                      total,
         SUM(estado='CONFIRMADA' OR estado='ATENDIDA') conf,
         SUM(estado='CANCELADA' OR estado='NO_PRESENTADA') canc
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

/*    EXPORTAR LOGS A CSV   
   $año – $mes ⇒ devuelve string CSV (separator = “;”, encabezados ES)
----------------------------------------------------------------*/
function exportLogsCsv(int $año, int $mes): string
{
    $baseDatos = conectar();

    /* Si se pasa 0 como mes, mostrar todos los logs (sin filtro por fecha) */    if ($mes === 0) {
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
       LEFT JOIN persona actor ON actor.id_persona = l.id_actor           ORDER BY l.fecha DESC";
        $consultaPreparada = $baseDatos->prepare($consulta);
        $consultaPreparada->execute();
    } else {
        /* Usar el mes específico */
        $fechaInicio = sprintf('%d-%02d-01 00:00:00', $año, $mes);
        $fechaFin = date('Y-m-d 23:59:59', strtotime("$fechaInicio +1 month -1 day"));        $consulta = "
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
           WHERE l.fecha BETWEEN :d AND :h           ORDER BY l.fecha DESC";

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

/**
 * Devuelve los pacientes asignados a un profesional.
 * Incluye la fecha de la próxima cita para cada paciente.
 * 
 * @param int $idProf ID del profesional
 * @return array Lista de pacientes con sus datos básicos y próxima cita
 */
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
    } elseif (!$nuevo && $hay) {        $baseDatos->prepare("UPDATE consentimiento
                         SET fecha_revocado=CURRENT_TIMESTAMP
                       WHERE id_persona=? AND fecha_revocado IS NULL")
            ->execute([$id]);
        registrarActividad($actor, $id, 'consentimiento', null, 'otorgado', 'revocado', 'UPDATE');
    }
}

/* Tratamientos / tareas ───*/
/**
 * Devuelve todos los tratamientos de un paciente (filtrando por profesional si se pasa).
 */
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
    $filas = $consulta->fetchAll(PDO::FETCH_ASSOC);    // Agrupar documentos por tratamiento
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
        $idHist = $consultaHistorial->fetchColumn();
        if (!$idHist) {
            $baseDatos->prepare("
              INSERT INTO historial_clinico (id_paciente, fecha_inicio)
              VALUES (?, CURRENT_DATE)
            ")->execute([$idPac]);
            $idHist = $baseDatos->lastInsertId();
        }

        /* Verificar que los datos mínimos están presentes */
        if (empty($titulo)) {
            throw new Exception('El título es obligatorio');
        }

        /* Insertar tratamiento con todos los campos */
        $consultaSql = "
          INSERT INTO tratamiento
            (id_historial, id_profesional, fecha_inicio, fecha_fin, 
             frecuencia_sesiones, titulo, notas)
          VALUES
            (?, ?, ?, ?, ?, ?, ?)
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
        $idTratamiento = $baseDatos->lastInsertId();        /* Documento opcional */
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
               h.diagnostico_preliminar,  -- Del historial_clinico
               h.diagnostico_final,       -- Del historial_clinico
               h.id_historial,
               h.fecha_inicio
          FROM documento_clinico d
          JOIN historial_clinico h ON d.id_historial = h.id_historial
         WHERE h.id_paciente = ?
           AND d.id_tratamiento IS NULL  -- Excluir documentos de tratamientos
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
/* crear documento del historial ─*/
function crearDocumentoHistorial(int $idPac, int $idProf, $file = null, string $diagnosticoPreliminar = '', string $diagnosticoFinal = '', string $tipo = 'documento'): array
{
    $baseDatos = conectar();
    $baseDatos->beginTransaction();

    try {
        /* 1) CREAR UNA NUEVA ENTRADA EN EL HISTORIAL --------------------------- */
        /* Cada documento representa una consulta/entrada específica en el historial */
        $baseDatos->prepare("
            INSERT INTO historial_clinico
                   (id_paciente, fecha_inicio, diagnostico_preliminar, diagnostico_final)
            VALUES (?, CURRENT_DATE, ?, ?)
        ")->execute([$idPac, $diagnosticoPreliminar, $diagnosticoFinal ?: null]);

        $idHistorial = (int)$baseDatos->lastInsertId();
        error_log("Nueva entrada en historial creada con ID: $idHistorial para paciente $idPac - Diagnóstico: '$diagnosticoPreliminar'");

        /* 2) Manejo del archivo ------------------------------- */
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
            ");

            $consultaDocumento->execute([
                ':h' => $idHistorial,
                ':p' => $idProf,
                ':r' => $rutaArchivo,
                ':n' => $file->getClientFilename(),
                ':t' => $tipo
            ]);

            $idDocumento = $baseDatos->lastInsertId();
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
        if (!$cita) throw new Exception('Cita no encontrada');        switch ($body['accion']) {
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
                break;            case 'MARCAR_NO_ATENDIDA':
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

    // 2. Validar día de la semana (Lunes=1 a Viernes=5)
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
    error_log("=== INICIO obtenerHorasDisponibles ===");
    error_log("Profesional: $idProfesional");
    error_log("Fecha solicitada: $fecha");

    try {
        $fechaObj = new DateTime($fecha);
    } catch (Exception $e) {
        error_log("Error parseando fecha: $fecha - " . $e->getMessage());
        return [];
    }

    // Validar que sea día laborable
    $diaSemana = (int)$fechaObj->format('N');
    error_log("Día de la semana: $diaSemana (1=lunes, 7=domingo)");

    if ($diaSemana < 1 || $diaSemana > 5) {
        error_log("Día no laborable rechazado");
        return [];
    }
    $baseDatos = conectar();

    // VERIFICAR BLOQUEOS - CON LOGS DETALLADOS
    error_log("Buscando bloqueos para profesional $idProfesional en fecha $fecha...");
    $consultaBloqueo = $baseDatos->prepare("        SELECT 
            id_bloque, 
            tipo_bloque, 
            comentario, 
            fecha_inicio, 
            fecha_fin,
            DATE(fecha_inicio) as inicio_fecha,
            DATE(fecha_fin) as fin_fecha
        FROM bloque_agenda
        WHERE id_profesional = ?
          AND tipo_bloque IN ('AUSENCIA', 'VACACIONES', 'CITA')
          AND DATE(?) BETWEEN DATE(fecha_inicio) AND DATE(fecha_fin)
    ");
    $consultaBloqueo->execute([$idProfesional, $fecha]);
    $bloqueosActivos = $consultaBloqueo->fetchAll(PDO::FETCH_ASSOC);

    error_log("Bloqueos encontrados para esta fecha:");
    foreach ($bloqueosActivos as $bloqueo) {
        error_log("  - ID: {$bloqueo['id_bloque']}");
        error_log("    Tipo: {$bloqueo['tipo_bloque']}");
        error_log("    Desde: {$bloqueo['fecha_inicio']} (fecha: {$bloqueo['inicio_fecha']})");
        error_log("    Hasta: {$bloqueo['fecha_fin']} (fecha: {$bloqueo['fin_fecha']})");
        error_log("    Comentario: {$bloqueo['comentario']}");
    }

    if (!empty($bloqueosActivos)) {
        error_log("FECHA BLOQUEADA - No hay horas disponibles");
        return [];
    }

    error_log("No hay bloqueos para esta fecha");
    
    // Obtener citas existentes
    $consultaCitas = $baseDatos->prepare("
        SELECT TO_CHAR(fecha_hora, 'HH24:MI') as hora, motivo
        FROM cita
        WHERE id_profesional = ?
          AND DATE(fecha_hora) = ?
          AND estado IN ('CONFIRMADA', 'PENDIENTE_VALIDACION', 'SOLICITADA')
    ");
    $consultaCitas->execute([$idProfesional, $fecha]);
    $citasExistentes = $consultaCitas->fetchAll(PDO::FETCH_ASSOC);
    $horasOcupadas = array_column($citasExistentes, 'hora');

    error_log("Citas existentes: " . (empty($horasOcupadas) ? "Ninguna" : implode(', ', $horasOcupadas)));

    // Generar horas disponibles (10:00 a 17:00)
    $horasDisponibles = [];
    for ($hora = 10; $hora <= 17; $hora++) {
        $horaStr = sprintf('%02d:00', $hora);
        if (!in_array($horaStr, $horasOcupadas)) {
            $horasDisponibles[] = $horaStr;
        }
    }

    error_log("Horas disponibles calculadas: " . implode(', ', $horasDisponibles));
    error_log("=== FIN obtenerHorasDisponibles ===");

    return $horasDisponibles;
}

/* Obtiene las fechas en las que el profesional está bloqueado (ausencia, vacaciones, etc.)  */
function obtenerDiasBloqueados(int $idProfesional, string $fechaInicio, string $fechaFin): array
{
    try {
        $baseDatos = conectar();

        // Primero, obtener todos los bloques que podrían afectar al rango de fechas
        $consulta = $baseDatos->prepare("            SELECT fecha_inicio, fecha_fin, tipo_bloque
            FROM bloque_agenda
            WHERE id_profesional = ?
              AND tipo_bloque IN ('AUSENCIA', 'VACACIONES', 'CITA')
              AND (
                  (DATE(fecha_inicio) BETWEEN ? AND ?) OR
                  (DATE(fecha_fin) BETWEEN ? AND ?) OR
                  (DATE(fecha_inicio) <= ? AND DATE(fecha_fin) >= ?)
              )
        ");

        $consulta->execute([
            $idProfesional,
            $fechaInicio,
            $fechaFin,  // Para el primer BETWEEN en fecha_inicio
            $fechaInicio,
            $fechaFin,  // Para el segundo BETWEEN en fecha_fin
            $fechaInicio,
            $fechaFin   // Para la condición de rango completo
        ]);

        $bloques = $consulta->fetchAll(PDO::FETCH_ASSOC);
        $fechasBloquedas = [];

        // Para cada bloque, calcular todos los días entre fecha_inicio y fecha_fin
        foreach ($bloques as $bloque) {
            $fechaInicioBloque = new DateTime(date('Y-m-d', strtotime($bloque['fecha_inicio'])));
            $fechaFinBloque = new DateTime(date('Y-m-d', strtotime($bloque['fecha_fin'])));

            // Asegurarse de que fecha_fin se procesa correctamente para eventos de un solo día
            $fechaFinAjustada = clone $fechaFinBloque;

            // Generar todas las fechas entre inicio y fin (inclusive)
            $intervalo = new DateInterval('P1D');
            $periodo = new DatePeriod($fechaInicioBloque, $intervalo, $fechaFinAjustada->modify('+1 day'));

            foreach ($periodo as $fecha) {
                $fechaStr = $fecha->format('Y-m-d');
                // Solo añadir si está dentro del rango solicitado
                if ($fechaStr >= $fechaInicio && $fechaStr <= $fechaFin) {
                    $fechasBloquedas[] = $fechaStr;
                }
            }
        }

        // Eliminar duplicados y ordenar
        $fechasBloquedas = array_unique($fechasBloquedas);
        sort($fechasBloquedas);

        error_log("Días bloqueados para profesional $idProfesional entre $fechaInicio y $fechaFin: " . implode(', ', $fechasBloquedas));
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
            t.fecha_fin,            t.frecuencia_sesiones,
            DATE(t.fecha_inicio) as fecha_asignacion,
            (p.nombre || ' ' || p.apellido1) as profesional_nombre,
            t.id_profesional,
            h.id_historial,
            h.diagnostico_preliminar,
            h.diagnostico_final,            -- Documentos asociados al tratamiento
            STRING_AGG(
                DISTINCT (
                    dc.id_documento || ':' || 
                    COALESCE(dc.nombre_archivo, 'Sin nombre') || ':' || 
                    dc.ruta || ':' || 
                    dc.tipo
                ), '|'
            ) as documentos
        FROM tratamiento t
        JOIN historial_clinico h ON t.id_historial = h.id_historial
        JOIN persona p ON p.id_persona = t.id_profesional
        LEFT JOIN documento_clinico dc ON dc.id_tratamiento = t.id_tratamiento
        WHERE h.id_paciente = ?
        GROUP BY t.id_tratamiento, t.titulo, t.notas, t.fecha_inicio, t.fecha_fin, 
                 t.frecuencia_sesiones, p.nombre, p.apellido1, t.id_profesional,
                 h.id_historial, h.diagnostico_preliminar, h.diagnostico_final
        ORDER BY t.fecha_inicio DESC
    ";

    $consulta = $baseDatos->prepare($consultaSql);
    $consulta->execute([$idPaciente]);
    $resultados = $consulta->fetchAll(PDO::FETCH_ASSOC);

    // Procesar los resultados para formatear los documentos
    $tareas = [];
    foreach ($resultados as $row) {
        $tarea = [
            'id_tratamiento' => (int)$row['id_tratamiento'],
            'titulo' => $row['titulo'],
            'descripcion' => $row['descripcion'],
            'fecha_inicio' => $row['fecha_inicio'],
            'fecha_fin' => $row['fecha_fin'],
            'fecha_asignacion' => $row['fecha_asignacion'],
            'frecuencia_sesiones' => $row['frecuencia_sesiones'] ? (int)$row['frecuencia_sesiones'] : null,
            'profesional_nombre' => $row['profesional_nombre'],
            'id_profesional' => (int)$row['id_profesional'],
            'id_historial' => (int)$row['id_historial'],
            'diagnostico_preliminar' => $row['diagnostico_preliminar'],
            'diagnostico_final' => $row['diagnostico_final'],
            'documentos' => []
        ];

        // Procesar documentos si existen
        if (!empty($row['documentos'])) {
            $documentosStr = explode('|', $row['documentos']);
            foreach ($documentosStr as $docStr) {
                if (!empty($docStr)) {
                    $partes = explode(':', $docStr, 4); // Límite a 4 para manejar rutas con ':'
                    if (count($partes) >= 4) {
                        $tarea['documentos'][] = [
                            'id_documento' => (int)$partes[0],
                            'nombre_archivo' => $partes[1],
                            'ruta' => $partes[2],
                            'tipo' => $partes[3]
                        ];
                    }
                }
            }
        }

        $tareas[] = $tarea;
    }

    error_log("Obtenidas " . count($tareas) . " tareas para paciente ID: $idPaciente");
    return $tareas;
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
            h.fecha_inicio as fecha_historial,            h.diagnostico_preliminar,
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
    $documentos = $consulta->fetchAll(PDO::FETCH_ASSOC);

    error_log("Obtenidos " . count($documentos) . " documentos del historial para paciente ID: $idPaciente");
    return $documentos;
}

/* Obtiene todas las citas de un paciente específico.*/
function getCitasPaciente(int $idPaciente): array
{
    $baseDatos = conectar();

    $consultaSql = "
        SELECT 
            c.id_cita,
            c.id_profesional,  -- ¡AGREGADO! Este es el campo que faltaba
            c.fecha_hora,
            c.estado,
            c.motivo,            c.origen,
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
    $citas = $consulta->fetchAll(PDO::FETCH_ASSOC);

    error_log("Obtenidas " . count($citas) . " citas para paciente ID: $idPaciente");

    // Debug
    if (!empty($citas)) {
        error_log("Primera cita incluye id_profesional: " . (isset($citas[0]['id_profesional']) ? 'SÍ' : 'NO'));
        if (isset($citas[0]['id_profesional'])) {
            error_log("ID profesional en primera cita: " . $citas[0]['id_profesional']);
        }
    }

    return $citas;
}

/* Procesa una solicitud de cambio o cancelación de cita por parte del paciente. */
function procesarSolicitudCitaPaciente(int $idCita, string $accion, int $idPaciente, ?string $nuevaFecha = null): array
{
    $baseDatos = conectar();

    try {
        $baseDatos->beginTransaction();

        // Verificar que la cita pertenece al paciente
        $consulta = $baseDatos->prepare("
            SELECT * FROM cita 
            WHERE id_cita = ? AND id_paciente = ?
        ");
        $consulta->execute([$idCita, $idPaciente]);
        $cita = $consulta->fetch(PDO::FETCH_ASSOC);

        if (!$cita) {
            throw new Exception('Cita no encontrada');
        }

        // Verificar que la cita se puede modificar
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

                // Validar la nueva fecha
                $fechaObj = new DateTime($nuevaFecha);
                $ahora = new DateTime();

                if ($fechaObj <= $ahora) {
                    throw new Exception('La nueva fecha debe ser futura');
                }

                // Verificar disponibilidad (simplificado)
                $consultaDisponibilidad = $baseDatos->prepare("
                    SELECT COUNT(*) FROM cita 
                    WHERE id_profesional = ? 
                    AND fecha_hora = ? 
                    AND estado IN ('CONFIRMADA', 'PENDIENTE_VALIDACION', 'SOLICITADA')
                    AND id_cita != ?
                ");
                $consultaDisponibilidad->execute([$cita['id_profesional'], $nuevaFecha, $idCita]);

                if ($consultaDisponibilidad->fetchColumn() > 0) {
                    throw new Exception('La fecha seleccionada no está disponible');
                }

                $nuevoEstado = 'CAMBIAR';
                $mensaje = 'Solicitud de cambio enviada correctamente';

                // Actualizar la fecha en notas privadas para referencia
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

        // Actualizar el estado de la cita
        $consulta = $baseDatos->prepare("
            UPDATE cita 
            SET estado = ? 
            WHERE id_cita = ?
        ");
        $consulta->execute([$nuevoEstado, $idCita]);
        // Registrar en el sistema lo que hicimos
        registrarActividad(
            $idPaciente,
            $idPaciente,
            'cita',
            'estado',
            $cita['estado'],
            $nuevoEstado,
            'UPDATE'
        );
        $baseDatos->commit();

        return [
            'ok' => true,
            'mensaje' => $mensaje
        ];
    } catch (Exception $e) {
        $baseDatos->rollBack();
        error_log("Error procesando solicitud de cita: " . $e->getMessage());
        return [
            'ok' => false,
            'mensaje' => $e->getMessage()
        ];
    }
}
