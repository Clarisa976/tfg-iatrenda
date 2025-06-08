import React, { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import axios from 'axios';
import { Eye, EyeOff } from 'lucide-react';
import '../styles.css';

export default function CrearContrasena() {
  const [sp] = useSearchParams();
  const uid = sp.get('uid') || '';

  // DEBUG TEMPORAL - agregar esto
  console.log('=== DEBUG CREAR CONTRASEÑA ===');
  console.log('URL completa:', window.location.href);
  console.log('Search params completos:', window.location.search);
  console.log('UID extraído:', uid);
  console.log('Todos los parámetros:', Object.fromEntries(sp.entries()));

  const [pass, setPass] = useState('');
  const [show, setShow] = useState(false);
  const [ok, setOk] = useState(null);
  const [err, setErr] = useState('');

  const enviar = async () => {
    if (pass.length < 8) {
      setErr('La contraseña debe tener al menos 8 caracteres');
      return;
    } 
    
    try {
      console.log('Enviando al backend:', { uid, password: '***' });
      await axios.post(
        `${process.env.REACT_APP_API_URL}/crear-contrasena`,
        { uid, password: pass }
      );
      setOk(true);
    } catch (e) {
      console.error('Error del backend:', e.response?.data || e.message);
      setOk(false);
    }
  };

  if (!uid) {
    console.log('⚠️ UID vacío - mostrando mensaje de enlace incorrecto');
    return <p className="enlace-incorrecto">Enlace incorrecto</p>;
  }

  return (
    <div className="crear-contrasena-container">
      <h2>Crea tu contraseña</h2>

      {ok === null && (<>
        <div className="password-input-container">
          <input
            type={show ? 'text' : 'password'}
            value={pass}
            onChange={e => { setPass(e.target.value); setErr(''); }}
            placeholder="Mínimo 8 caracteres"
            className={`password-input ${err ? 'invalid' : ''}`}
          />
          <button
            type="button"
            className="password-toggle-btn"
            onClick={() => setShow(s => !s)}
            aria-label={show ? 'Ocultar' : 'Mostrar'}
          >
            {show ? <EyeOff size={18} /> : <Eye size={18} />}
          </button>
        </div>
        {err && <p className="error-message">{err}</p>}

        {ok === false && (
          <p className="backend-error-message">
            El enlace ha caducado o ya fue usado.
          </p>
        )}

        <button
          className={`btn-reserva btn-guardar-password ${pass.length < 8 ? 'btn-disabled' : ''}`}
          disabled={pass.length < 8}
          onClick={enviar}
        >
          Guardar
        </button>
      </>
      )}

      {ok === true && (
        <p className="success-message">
          Contraseña guardada correctamente. Ya puedes iniciar sesión.
        </p>
      )}
    </div>
  );
}