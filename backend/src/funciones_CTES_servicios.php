<?php
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
function loginEmailPassword(string $email, string $plainPass): array
{
    try {
        $sql = "SELECT id_persona id,
                       CONCAT(nombre,' ',apellido1) nombre,
                       email,
                       LOWER(rol) rol
                FROM   persona
                WHERE  email = :e
                  AND  password_hash = SHA2(:p,256)
                  AND  activo = 1
                LIMIT  1";
        $st  = conectar()->prepare($sql);
        $st->execute(['e' => $email, 'p' => $plainPass]);

        if ($row = $st->fetch()) {
            return ['ok' => true, 'usuario' => $row];
        }
        return ['ok' => false, 'mensaje' => 'Email o contraseña incorrectos'];
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
function reservarCita(
    string $nombre,
    string $email,
    ?string $tel,
    string $motivo,
    string $fechaIso
): array {
    // 1️⃣ Validación de campos
    if ($nombre === '' || $email === '' || $motivo === '' || $fechaIso === '') {
        return ['ok'=>false, 'mensaje'=>'Faltan campos obligatorios', 'status'=>400];
    }

    // 2️⃣ Parsear fecha y comprobar horario L-V 10–18
    try {
        $fecha = new DateTime($fechaIso);
    } catch (Exception $e) {
        return ['ok'=>false,'mensaje'=>'Fecha inválida','status'=>400];
    }
    $w = (int)$fecha->format('w');    // 0 domingo … 6 sábado
    $h = (int)$fecha->format('G');    // 0–23
    if ($w < 1 || $w > 5 || $h < 10 || $h >= 18) {
        return ['ok'=>false,'mensaje'=>'Fuera de horario laboral','status'=>400];
    }
    $ts = $fecha->format('Y-m-d H:i:s');

    // 3️⃣ Obtener o crear persona
    $idPersona = getOrCreatePersona($nombre, $email, $tel);

    // 4️⃣ Asegurar paciente
    ensurePaciente($idPersona);

    // 5️⃣ Conectar y buscar profesional disponible
    $db = conectar();
    $sql = "
      SELECT p.id_profesional
        FROM profesional p
       WHERE NOT EXISTS (
         SELECT 1 FROM bloque_agenda b
          WHERE b.id_profesional  = p.id_profesional
            AND b.tipo_bloque     IN ('AUSENCIA','VACACIONES')
            AND :ts BETWEEN b.fecha_inicio
                       AND DATE_SUB(b.fecha_fin, INTERVAL 1 SECOND)
       )
         AND NOT EXISTS (
         SELECT 1 FROM cita c
          WHERE c.id_profesional = p.id_profesional
            AND c.estado         IN ('PENDIENTE_VALIDACION','SOLICITADA','CONFIRMADA','ATENDIDA')
            AND c.fecha_hora     = :ts
       )
       ORDER BY (
         SELECT COUNT(*) FROM cita c2
          WHERE c2.id_profesional = p.id_profesional
            AND DATE(c2.fecha_hora)= DATE(:ts)
       ) ASC
       LIMIT 1
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([':ts'=>$ts]);
    $idProf = $stmt->fetchColumn();
    if (!$idProf) {
        return ['ok'=>false,'mensaje'=>'No hay profesionales disponibles','status'=>409];
    }

    // 6️⃣ Insertar cita
    $ins = $db->prepare("
      INSERT INTO cita
        (id_paciente, id_profesional, id_bloque,
         fecha_hora, estado,
         nombre_contacto, telefono_contacto, email_contacto,
         motivo, origen)
      VALUES
        (:pac,:prof, NULL,
         :ts,'PENDIENTE_VALIDACION',
         :nombre, :tel, :email,
         :motivo,'WEB')
    ");
    $ins->execute([
        ':pac'    => $idPersona,
        ':prof'   => $idProf,
        ':ts'     => $ts,
        ':nombre' => $nombre,
        ':tel'    => $tel,
        ':email'  => $email,
        ':motivo' => $motivo,
    ]);

    return ['ok'=>true,'mensaje'=>'Cita reservada correctamente'];
}

/**
 * Busca persona por email o teléfono. Si no existe, la crea.
 * Devuelve id_persona.
 */
function getOrCreatePersona(string $nombre, string $email, ?string $tel): int {
    $db = conectar();
    // 1) Buscar
    $stmt = $db->prepare("
      SELECT id_persona
        FROM persona
       WHERE email = :email
          OR (telefono IS NOT NULL AND telefono = :tel)
       LIMIT 1
    ");
    $stmt->execute([':email'=>$email, ':tel'=>$tel]);
    if ($id = $stmt->fetchColumn()) {
        return (int)$id;
    }
    // 2) Crear (apellido1 = nombre, password_hash de cadena vacía)
    $ins = $db->prepare("
      INSERT INTO persona
        (nombre, apellido1, email, telefono, password_hash, rol)
      VALUES
        (:nom, :ap1, :email, :tel, SHA2('',256), 'PACIENTE')
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
function ensurePaciente(int $idPersona): void {
    $db = conectar();
    $stmt = $db->prepare("SELECT 1 FROM paciente WHERE id_paciente = :id");
    $stmt->execute([':id'=>$idPersona]);
    if ($stmt->fetch()) return;
    // Insertamos con tipo 'ADULTO' por defecto
    $ins = $db->prepare("
      INSERT INTO paciente (id_paciente, tipo_paciente)
      VALUES (:id, 'ADULTO')
    ");
    $ins->execute([':id'=>$idPersona]);
}