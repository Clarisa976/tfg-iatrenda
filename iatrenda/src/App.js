import React, { useState } from 'react';
import Login from './components/Login';

function App() {
  const [usuarioLogueado, setUsuarioLogueado] = useState(null);

  const handleLoginSuccess = (usuario) => {
    setUsuarioLogueado(usuario);
  };

  return (
    <div>
      {usuarioLogueado 
        ? <div>
            <h2>Bienvenido, {usuarioLogueado}</h2>
          </div>
        : <Login onLoginSuccess={handleLoginSuccess} />
      }
    </div>
  );
}

export default App;
