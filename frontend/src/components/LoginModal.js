import React, { useState } from 'react';

export default function LoginModal({ onClose, onLoginSuccess }) {
  const [email, setEmail] = useState('');
  const [pass, setPass]   = useState('');
  const [errors, setErrors] = useState({ email: '', pass: '', api: '' });

  const handleSubmit = async (e) => {
    e.preventDefault();
    let hasError = false;
    const newErr = { email: '', pass: '', api: '' };

    if (!email) {
      newErr.email = 'Este campo no puede quedar vacío';
      hasError = true;
    }
    if (!pass) {
      newErr.pass = 'Este campo no puede quedar vacío';
      hasError = true;
    }
    if (hasError) {
      return setErrors(newErr);
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

  return (
    <div className="modal-backdrop" onClick={onClose}>
      <div className="modal" onClick={e => e.stopPropagation()}>
        <button className="modal-close" onClick={onClose}>×</button>
        <h2 className="modal-title">Inicia sesión</h2>

        {errors.api && <div className="api-error">{errors.api}</div>}

        <form onSubmit={handleSubmit}>
          <div className="field">
            <label>Email</label>
            <input
              type="email"
              value={email}
              onChange={e => { setEmail(e.target.value); setErrors({ ...errors, email: '' }); }}
              className={errors.email ? 'invalid' : ''}
            />
            {errors.email && <span className="field-error">{errors.email}</span>}
          </div>

          <div className="field">
            <label>Contraseña</label>
            <input
              type="password"
              value={pass}
              onChange={e => { setPass(e.target.value); setErrors({ ...errors, pass: '' }); }}
              className={errors.pass ? 'invalid' : ''}
            />
            {errors.pass && <span className="field-error">{errors.pass}</span>}
          </div>

          <div className="actions">
            <a href="/olvide" className="forgot">Olvidé mi contraseña</a>
            <button type="submit" className="btn-submit">Entrar</button>
          </div>
        </form>
      </div>
    </div>
  );
}
