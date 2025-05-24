import React, { useState, useRef } from 'react';

/* Modal para recuperar pass */
function ModalOlvidarContrasenia({ onClose }) {
  const [email, setEmail] = useState('');
  const [error, setError] = useState('');
  const inputRef = useRef(null);

  const handleSubmit = e => {
    e.preventDefault();
    if (!email.trim()) {
      setError('Este campo no puede quedar vacío');
      inputRef.current.focus();
      return;
    }

    // TODO: llamada fetch/axios a /forgot-password
    // await api.post('/forgot-password', { email })

    setError('');
    alert('Si el correo existe, recibirás un email con las instrucciones.');
    onClose();                      // cerramos modal tras enviar
  };

  return (
    <div className="modal-backdrop" onClick={onClose}>
      <div className="modal" onClick={e => e.stopPropagation()}>
        <button className="modal-close" onClick={onClose}>×</button>

        {/* Título */}
        <h2 className="modal-title">Recuperar contraseña</h2>

        <form onSubmit={handleSubmit}>
          {/* Campo email */}
          <div className="field">
            <label>Email*:</label>
            <input
              ref={inputRef}
              type="email"
              value={email}
              onChange={e => { setEmail(e.target.value); setError(''); }}
              className={error ? 'invalid' : ''}
            />
            {error && (
              <span className="field-error">{error}</span>
            )}
          </div>

          {/* Botón */}
          <button type="submit" className="btn-submit btn-full">
            Recuperar
          </button>
        </form>
      </div>
    </div>
  );
}

/* Modal de Login  */
export default function LoginModal({ onClose, onLoginSuccess }) {
  const [email, setEmail] = useState('');
  const [pass, setPass] = useState('');
  const [errors, setErrors] = useState({ email: '', pass: '', api: '' });
  const [fpOpen, setFpOpen] = useState(false);           // ← NUEVO
  const emailRef = useRef(null);

  const handleSubmit = async e => {
    e.preventDefault();
    const newErr = { email: '', pass: '', api: '' };
    let hasError = false;

    if (!email.trim()) { newErr.email = 'Este campo no puede quedar vacío'; hasError = true; }
    if (!pass.trim()) { newErr.pass = 'Este campo no puede quedar vacío'; hasError = true; }

    if (hasError) {
      setErrors(newErr);
      (!email ? emailRef : null)?.current?.focus();
      return;
    }

    try {
      const res = await fetch(`${process.env.REACT_APP_API_URL}/login`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email, password: pass }),
      });
      const data = await res.json();

      if (data.ok) {
        onLoginSuccess({ ...data.usuario, role: data.usuario.rol_nombre });
      } else {
        setErrors({ ...newErr, api: 'Email o contraseña incorrectos' });
      }
    } catch {
      setErrors({ ...newErr, api: 'Error de conexión' });
    }
  };

  /* Mostrar modal de recuperar? */
  if (fpOpen) {
    return <ModalOlvidarContrasenia onClose={() => setFpOpen(false)} />;
  }

  /* Modal de login */
  return (
    <div className="modal-backdrop" onClick={onClose}>
      <div className="modal" onClick={e => e.stopPropagation()}>
        <button className="modal-close" onClick={onClose}>×</button>

        {/* Título */}
        <h2 className="modal-title">Inicia sesión</h2>

        {/* Error API */}
        {errors.api && <div className="api-error">{errors.api}</div>}

        <form onSubmit={handleSubmit}>
          {/* Email */}
          <div className="field">
            <label>Email*:</label>
            <input
              ref={emailRef}
              type="email"
              value={email}
              onChange={e => { setEmail(e.target.value); setErrors({ ...errors, email: '' }); }}
              className={errors.email ? 'invalid' : ''}
            />
            {errors.email && <span className="field-error">{errors.email}</span>}
          </div>

          {/* Password */}
          <div className="field">
            <label>Contraseña*:</label>
            <input
              type="password"
              value={pass}
              onChange={e => { setPass(e.target.value); setErrors({ ...errors, pass: '' }); }}
              className={errors.pass ? 'invalid' : ''}
            />
            {errors.pass && <span className="field-error">{errors.pass}</span>}
          </div>

          {/* Acciones */}
          <div className="actions">
            <a
              href="#"
              className="forgot"
              onClick={e => { e.preventDefault(); setFpOpen(true); }}
            >
              Olvidé mi contraseña
            </a>

            <button type="submit" className="btn-submit btn-full">
              Entrar
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
