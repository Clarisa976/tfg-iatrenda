<?php
// cargamos env
$host = getenv('DB_HOST');
$db   = getenv('DB_NAME');
$user = getenv('DB_USER');
$pass = getenv('DB_PASS');

function conectar() {
    global $host, $db, $user, $pass;
    try {
        return new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

function loginEmailPassword($email, $password) {
    $pdo = conectar();
    $stmt = $pdo->prepare('SELECT * FROM persona p JOIN persona_rol pr ON p.id_persona=pr.id_persona JOIN rol r ON pr.id_rol=r.id_rol WHERE p.email=? AND p.password=MD5(?) AND pr.activo=1');
    $stmt->execute([$email, $password
]);
    if ($u=$stmt->fetch(PDO::FETCH_ASSOC)) {
        return ['ok'=>true,'usuario'=>[
            'id'=>$u['id_persona'],'tipo'=>$u['nombre']
        ]];
    }
    return ['ok'=>false,'mensaje'=>'Email o clave incorrectos'];
}

function obtener_pacientes() {
    $pdo = conectar();
    $stmt = $pdo->query('SELECT * FROM persona p JOIN paciente pa ON p.id_persona=pa.id_persona_paciente');
    return ['pacientes'=>$stmt->fetchAll(PDO::FETCH_ASSOC)];
}

function obtener_paciente_por_id($id) {
    $pdo = conectar();
    $stmt = $pdo->prepare('SELECT * FROM persona p JOIN paciente pa ON p.id_persona=pa.id_persona_paciente WHERE p.id_persona=?');
    $stmt->execute([$id
]);
    return ['paciente'=>$stmt->fetch(PDO::FETCH_ASSOC)];
}

function crear_paciente($d) {
    $pdo = conectar();
    $stmt = $pdo->prepare('INSERT INTO persona (nombre, apellido1, apellido2, email) VALUES (?,?,?,?)');
    $stmt->execute([$d['nombre'], $d['apellidos'], '', $d['email']]);
    $id = $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO paciente (id_persona_paciente) VALUES (?)')->execute([$id
]);
    return ['ok'=>true,'id_paciente'=>$id];
}

function borrar_paciente($id) {
    $pdo = conectar();
    $pdo->prepare('DELETE FROM paciente WHERE id_persona_paciente=?')->execute([$id
]);
    return ['ok'=>true];
}