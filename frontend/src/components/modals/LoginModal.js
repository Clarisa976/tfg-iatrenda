import React, { useState, useRef } from 'react';
import { Eye, EyeOff, X,  CheckCircle, XCircle } from 'lucide-react';

/* Modal para recuperar pass */
function ModalOlvidarContrasenia({ onClose, onShowToast }) {
  const [email, setEmail] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const inputRef = useRef(null);

  const handleSubmit = async e => {
    e.preventDefault();
    if (!email.trim()) {
      setError('Este campo no puede quedar vacío');
      inputRef.current.focus();
      return;
    }

    setLoading(true);
    try {
      const res = await fetch(`${process.env.REACT_APP_API_URL}/forgot-password`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email }),
      });
      
      const data = await res.json();
      
      if (data.ok) {
        onShowToast({
          ok: true,
          titulo: 'Email enviado',
          msg: 'Si el correo existe, recibirás un email con las instrucciones.'
        });
        onClose();
      } else {
        onShowToast({
          ok: false,
          titulo: 'Error',
          msg: data.mensaje || 'El correo no está registrado en la base de datos.'
        });
      }
    } catch (err) {
      onShowToast({
        ok: false,
        titulo: 'Error',
        msg: 'Error de conexión. Inténtalo de nuevo.'
      });
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="modal-backdrop" onClick={onClose}>
      <div className="modal" onClick={e => e.stopPropagation()}>
        <button className="modal-close" onClick={onClose}><X/></button>

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
          </div>          {/* Botón */}
          <button 
            type="submit" 
            className="btn-reserva btn-full"
            disabled={loading}
          >
            {loading ? 'Enviando...' : 'Recuperar'}
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
  const [showPassword, setShowPassword] = useState(false);
  const [errors, setErrors] = useState({ email: '', pass: '', api: '' });
  const [fpOpen, setFpOpen] = useState(false);
  const [toast, setToast] = useState({ show: false, ok: true, titulo: '', msg: '' });
  const emailRef = useRef(null);

  // Función para mostrar toast
  const showToast = (config) => {
    setToast({ ...config, show: true });
    setTimeout(() => {
      setToast(prev => ({ ...prev, show: false }));
    }, 5000);
  };

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
      console.log('Respuesta login:', data);      if (data.ok) {
        if (data.token) {
          localStorage.setItem('token', data.token);
        }
        onLoginSuccess({ ...data.usuario, role: data.usuario.rol });
      } else {
        setErrors({ ...newErr, api: 'Email o contraseña incorrectos' });
      }
    } catch {
      setErrors({ ...newErr, api: 'Error de conexión' });
    }
  };
  /* Mostrar modal de recuperar? */
  if (fpOpen) {
    return <ModalOlvidarContrasenia onClose={() => setFpOpen(false)} onShowToast={showToast} />;
  }  /* Modal de login */
  return (
    <>
      {/* Toast */}
      {toast.show && (
        <div className="toast-global centered-toast">
          <div className={`toast-card ${toast.ok ? 'success' : 'error'}`}>
            {toast.ok
              ? <CheckCircle size={48} className="toast-icon success" />
              : <XCircle size={48} className="toast-icon error" />
            }
            <h3 className="toast-title">{toast.titulo}</h3>
            <p className="toast-text">{toast.msg}</p>
          </div>
        </div>
      )}
      
      <div className="modal-backdrop" onClick={onClose}>
        <div className="modal" onClick={e => e.stopPropagation()}>
        <button className="modal-close" onClick={onClose}><X/></button>

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
            <div className="password-field">
              <input
                type={showPassword ? "text" : "password"}
                value={pass}
                onChange={e => { setPass(e.target.value); setErrors({ ...errors, pass: '' }); }}
                className={errors.pass ? 'invalid' : ''}
              />
              <button
                type="button"
                className="password-toggle"
                onClick={() => setShowPassword(!showPassword)}
              >
                {showPassword ? <EyeOff size={20} /> : <Eye size={20} />}
              </button>
            </div>
            {errors.pass && <span className="field-error">{errors.pass}</span>}
          </div>
          {/* Acciones */}
          <div className="actions">
            <button
              type="button"
              className="forgot"
              onClick={() => setFpOpen(true)}
            >
              Olvidé mi contraseña
            </button>

            <button type="submit" className="btn-submit btn-full">
              Entrar
            </button>          
            </div>
        </form>
      </div>
    </div>
    </>
  );
}
