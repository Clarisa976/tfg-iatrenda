<?php

define("SERVIDOR_BD", "localhost");
define("USUARIO_BD", "jose");
define("CLAVE_BD", "josefa");
define("NOMBRE_BD", "bd_iatrenda");


// Login con email y password
function loginEmailPassword($email, $password)
{
    try {
        $conexion = new PDO(
            "mysql:host=" . SERVIDOR_BD . ";dbname=" . NOMBRE_BD,
            USUARIO_BD,
            CLAVE_BD,
            array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'utf8'")
        );
    }  catch (PDOException $e) {
        $respuesta["ok"] = false;
        $respuesta["error"] = "No se pudo conectar a la BD: " . $e->getMessage();
        return $respuesta;
    }

    try {
        $sql = "SELECT * FROM usuarios WHERE email = ? AND password = MD5(?)";
        $sentencia = $conexion->prepare($sql);
        $sentencia->execute([$email, $password]);
    } catch (PDOException $e) {
        $respuesta["error"] = "Error al consultar la BD: " . $e->getMessage();
        return $respuesta;
    }

    if ($sentencia->rowCount() > 0) {
        $usuario = $sentencia->fetch(PDO::FETCH_ASSOC);
        $respuesta["ok"] = true;
        $respuesta["usuario"] = [
            "id_usuario" => $usuario["id_usuario"],
            "nombre"     => $usuario["nombre"],
            "email"      => $usuario["email"]
            // etc
        ];
    } else {
        $respuesta["ok"] = false;
        $respuesta["mensaje"] = "Email o password incorrectos";
    }

    return $respuesta;
}



// Listar de todos los pacientes
function obtener_pacientes() {

    try {
        $conexion = new PDO(
            "mysql:host=" . SERVIDOR_BD . ";dbname=" . NOMBRE_BD,
            USUARIO_BD,
            CLAVE_BD,
            array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'utf8'")
        );
    } catch (PDOException $e) {
        $respuesta["error"] = "No he podido conectarme a la BD: " . $e->getMessage();
        return $respuesta;
    }

    try {
        $consulta = "SELECT * FROM pacientes ORDER BY id_paciente DESC";
        $sentencia = $conexion->prepare($consulta);
        $sentencia->execute();
    } catch (PDOException $e) {
        $sentencia = null;
        $conexion = null;
        $respuesta["error"] = "No he podido realizar la consulta: " . $e->getMessage();
        return $respuesta;
    }

    $respuesta["pacientes"] = $sentencia->fetchAll(PDO::FETCH_ASSOC);

    $sentencia = null;
    $conexion = null;

    return $respuesta;
}
//Listar un paciente por su ID
function obtener_paciente_por_id($id) {
    
    try {
        $conexion = new PDO(
            "mysql:host=" . SERVIDOR_BD . ";dbname=" . NOMBRE_BD,
            USUARIO_BD,
            CLAVE_BD,
            [PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'utf8'"]
        );
        $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
        $respuesta["error"] = "No he podido conectarme a la BD: " . $e->getMessage();
        return $respuesta;
    }

    try {
        $sql = "SELECT * FROM pacientes WHERE id_paciente = ?";
        $sentencia = $conexion->prepare($sql);
        $sentencia->execute([$id]);
    } catch (PDOException $e) {
        $respuesta["error"] = "Error al consultar la BD: " . $e->getMessage();
        return $respuesta;
    }

    if ($sentencia->rowCount() > 0) {
        $respuesta["paciente"] = $sentencia->fetch(PDO::FETCH_ASSOC);
    } else {
        $respuesta["mensaje"] = "No se encontró un paciente con ese ID.";
    }

    $sentencia = null;
    $conexion = null;
    return $respuesta;
}

//Función para crear un paciente nuevo
function crear_paciente($datos_paciente) {

    // Conectamos a la BD
    try {
        $conexion = new PDO(
            "mysql:host=" . SERVIDOR_BD . ";dbname=" . NOMBRE_BD,
            USUARIO_BD,
            CLAVE_BD,
            array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'utf8'")
        );
    } catch (PDOException $e) {
        $respuesta["error"] = "Imposible conectar: " . $e->getMessage();
        return $respuesta;
    }


    // Insertar el paciente
    try {
        $consulta = "INSERT INTO pacientes (nombre, apellidos, fecha_nacimiento, email, telefono)
                     VALUES (?, ?, ?, ?, ?)";
        $sentencia = $conexion->prepare($consulta);
        $sentencia->execute([
            $datos_paciente["nombre"],
            $datos_paciente["apellidos"],
            $datos_paciente["fecha_nacimiento"],
            $datos_paciente["email"],
            $datos_paciente["telefono"]
        ]);

        // Devolvemos mensaje 
        $respuesta["mensaje"] = "Paciente insertado correctamente en la BD";
        $respuesta["id_paciente"] = $conexion->lastInsertId();
    } catch (PDOException $e) {
        $respuesta["error"] = "Imposible realizar el INSERT: " . $e->getMessage();
        return $respuesta;
    }

    // Cerrar
    $sentencia = null;
    $conexion = null;
    return $respuesta;
}

// Función para borrar un paciente por su ID
function borrar_paciente($id) {
    // Conectar
    try {
        $conexion = new PDO(
            "mysql:host=" . SERVIDOR_BD . ";dbname=" . NOMBRE_BD,
            USUARIO_BD,
            CLAVE_BD,
            array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'utf8'")
        );
    } catch (PDOException $e) {
        $respuesta["error"] = "No he podido conectarme a la BD: " . $e->getMessage();
        return $respuesta;
    }

    // Borrar
    try {
        $consulta = "DELETE FROM pacientes WHERE id_paciente = ?";
        $sentencia = $conexion->prepare($consulta);
        $sentencia->execute([$id]);

        // Si rowCount() > 0, se borró un registro
        if ($sentencia->rowCount() > 0) {
            $respuesta["mensaje"] = "Paciente borrado correctamente.";
        } else {
            $respuesta["mensaje"] = "No había ningún paciente con ese ID.";
        }
    } catch (PDOException $e) {
        $respuesta["error"] = "No he podido realizar la consulta: " . $e->getMessage();
    }

    // Cerrar
    $sentencia = null;
    $conexion = null;
    return $respuesta;
}
?>