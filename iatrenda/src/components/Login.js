import React, { useState } from 'react';
import axios from 'axios';

function Login() {
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [mensaje, setMensaje] = useState("");

  const handleLogin = async (event) => {
    event.preventDefault();
    setMensaje("");

    try {
      const resp = await axios.post("http://localhost/Proyectos/TFG/API/login", {
        email: email,
        password: password
      });
      const data = resp.data;

      if (data.ok) {
        setMensaje("Bienvenido, " + data.usuario.nombre);
      } else {
        // data.ok = false
        setMensaje(data.mensaje || data.error || "Error de credenciales");
      }
    } catch (error) {
      setMensaje("Error de conexión: " + error.message);
    }
  };

  return (
    <div>
      <h1>Iniciar Sesión</h1>
      <form onSubmit={handleLogin}>
        <label>Email:</label>
        <input
          type="email"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
        />
        <br />
        <label>Contraseña:</label>
        <input
          type="password"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
        />
        <br />
        <button type="submit">Login</button>
      </form>
      <p>{mensaje}</p>
    </div>
  );
}

export default Login;
