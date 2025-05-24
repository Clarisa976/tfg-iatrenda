<?php
function conectar() {
    $host = getenv('DB_HOST');
    $db   = getenv('DB_NAME');
    $user = getenv('DB_USER');
    $pass = getenv('DB_PASS');
    return new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
}
function loginEmailPassword(string $email,string $plainPass): array
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
        $st->execute(['e'=>$email,'p'=>$plainPass]);

        if ($row = $st->fetch()) {
            return ['ok'=>true,'usuario'=>$row];
        }
        return ['ok'=>false,'mensaje'=>'Email o contraseña incorrectos'];

    } catch (PDOException $e) {
        return ['ok'=>false,'error'=>$e->getMessage()];
    }
}