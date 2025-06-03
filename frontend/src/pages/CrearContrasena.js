// src/pages/CrearContrasena.js
import React, { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import axios from 'axios';
import '../styles.css';             

export default function CrearContrasena() {
  const [sp] = useSearchParams();
  const uid  = sp.get('uid') || '';      // siempre string

  const [pass, setPass]       = useState('');
  const [show, setShow]       = useState(false);    // mostrar / ocultar
  const [ok,   setOk]         = useState(null);     // null | true | false
  const [err,  setErr]        = useState('');       // mensaje local

  /* envía al backend sólo si cumple longitud */
  const enviar = async () => {
    if (pass.length < 8) {
      setErr('La contraseña debe tener al menos 8 caracteres');
      return;
    }
    try {
      await axios.post(
        `${process.env.REACT_APP_API_URL}/crear-pass`,
        { uid, password: pass }
      );
      setOk(true);
    } catch (e) {
      setOk(false);
    }
  };

  if (!uid) return <p style={{textAlign:'center',marginTop:'3rem'}}>Enlace incorrecto</p>;

  /* JSX ----------------------------------------------------------- */
  return (
    <div style={{maxWidth:360,margin:'4rem auto',textAlign:'center'}}>
      <h2>Crea tu contraseña</h2>

      { ok === null && (
        <>
          <div style={{position:'relative',marginTop:24}}>
            <input
              type={show ? 'text' : 'password'}
              value={pass}
              onChange={e => { setPass(e.target.value); setErr(''); }}
              placeholder="Mínimo 8 caracteres"
              className={err ? 'invalid' : ''}
              style={{
                width:'100%',padding:'10px 40px 10px 10px',
                border:'1px solid #ccc',borderRadius:4
              }}
            />
            {/* botón mostrar/ocultar */}
            <button
              type="button"
              style={{
                position:'absolute',right:6,top:6,border:'none',
                background:'transparent',cursor:'pointer'
              }}
              onClick={()=>setShow(s=>!s)}
              aria-label={show?'Ocultar':'Mostrar'}
            >
              {show ? '🙈' : '👁️'}
            </button>
          </div>

          {/* error local */}
          {err && <p style={{color:'var(--red)',fontSize:14,marginTop:6}}>{err}</p>}

          {/* respuesta del backend */}
          {ok === false && (
            <p style={{color:'var(--red)',marginTop:8}}>
              El enlace ha caducado o ya fue usado.
            </p>
          )}

          <button
            className="btn-reserva"
            style={{marginTop:18,opacity:pass.length<8?0.5:1}}
            disabled={pass.length < 8}
            onClick={enviar}
          >
            Guardar
          </button>
        </>
      )}

      { ok === true && (
        <p style={{color:'var(--green)',marginTop:20}}>
          Contraseña creada. Ya puedes iniciar sesión.
        </p>
      )}
    </div>
  );
}
