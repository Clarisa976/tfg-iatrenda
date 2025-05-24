// src/components/Header.js
import React, { useState, useRef, useEffect } from 'react';
import axios from 'axios';
import { Menu, X, User } from 'lucide-react';
import '../styles.css';

// Asegúrate de tener en tu .env:
// REACT_APP_API_URL=http://localhost/Proyectos/TFG/backend
axios.defaults.baseURL = process.env.REACT_APP_API_URL;

export default function Header() {
  const logoImg = process.env.REACT_APP_LOGO_IMG;
  const [navOpen, setNavOpen]       = useState(false);
  const [userOpen, setUserOpen]     = useState(false);
  const [loginOpen, setLoginOpen]   = useState(false);
  const [email, setEmail]           = useState('');
  const [password, setPassword]     = useState('');
  const [fieldError, setFieldError] = useState('');
  const [apiError, setApiError]     = useState('');
  const [userRole, setUserRole]     = useState(null); // 'paciente'|'profesional'|'administrador'

  const userRef  = useRef(null);
  const modalRef = useRef(null);

  // Cerrar menú usuario al hacer click fuera
  useEffect(() => {
    function onClickOutside(e) {
      if (
        userOpen &&
        userRef.current &&
        !userRef.current.contains(e.target) &&
        !e.target.closest('.user-icon')
      ) {
        setUserOpen(false);
      }
    }
    document.addEventListener('mousedown', onClickOutside);
    return () => document.removeEventListener('mousedown', onClickOutside);
  }, [userOpen]);

  // Cerrar modal al hacer click fuera
  useEffect(() => {
    function onClickOutside(e) {
      if (loginOpen && modalRef.current && !modalRef.current.contains(e.target)) {
        setLoginOpen(false);
      }
    }
    document.addEventListener('mousedown', onClickOutside);
    return () => document.removeEventListener('mousedown', onClickOutside);
  }, [loginOpen]);

  const handleAccessClick = (e) => {
    e.preventDefault();
    setUserOpen(false);
    setLoginOpen(true);
  };

 const handleLoginSubmit = async (e) => {
    e.preventDefault();
    setFieldError('');
    setApiError('');
    if (!email || !password) {
      setFieldError('Este campo no puede quedar vacío');
      return;
    }
    try {
      console.log("🔐 Enviando login con:", { email, password });
      const { data } = await axios.post(`${process.env.REACT_APP_API_URL}/login`, { email, password });
      console.log("📝 Respuesta del backend:", data);
      if (!data.ok) {
        setApiError(data.mensaje || 'Email o contraseña incorrectos');
        return;
      }
      setUserRole(data.usuario.rol); // "paciente", "profesional", etc
      setLoginOpen(false);
    } catch (err) {
      setApiError('Error de conexión');
      if (err.response) {
        console.error("❗ Error de respuesta del servidor:", err.response.data);
        setApiError(err.response.data.mensaje || 'Error al iniciar sesión');
      }else if (err.request) {
        console.error("❗ Error de solicitud:", err.request);
        setApiError('No se pudo conectar con el servidor');
      } else {
        setApiError('Error desconocido');
        console.log("⚠️ Error en fetch/axios:", err);
      }
      
    }
  };


  // Menú según rol
  const userMenuItems = {
    paciente: [
      { label: 'Mi perfil', href: '/perfil' },
      { label: 'Tareas para casa', href: '/tareas' },
      { label: 'Historial clínico', href: '/historial' },
      { label: 'Mis citas', href: '/citas' },
      { label: 'Cerrar sesión', href: '/logout' },
    ],
    profesional: [
      { label: 'Mi perfil', href: '/perfil' },
      { label: 'Pacientes', href: '/pacientes' },
      { label: 'Agenda', href: '/agenda' },
      { label: 'Cerrar sesión', href: '/logout' },
    ],
    administrador: [
      { label: 'Usuarios', href: '/usuarios' },
      { label: 'Informes', href: '/informes' },
      { label: 'Agenda global', href: '/agenda-global' },
      { label: 'Cerrar sesión', href: '/logout' },
    ],
  }[userRole] || [];

  return (
    <>
      <header className="header">
        <div className="top-bar">
          <div className="logo">
            <a href="/"><img src={logoImg} alt="Petaka" /></a>
          </div>
          <div className="icons">
            <button
              className="icon menu-icon"
              onClick={() => setNavOpen(o => !o)}
              aria-label="Menú principal"
            >
              {navOpen ? <X size={24}/> : <Menu size={24}/>}
            </button>
            <button
              className={`icon user-icon${userOpen ? ' active' : ''}`}
              onClick={() => setUserOpen(o => !o)}
              aria-label="Acceso usuario"
            >
              <User size={24}/>
            </button>
          </div>
        </div>

        {/* Menú principal */}
        <div className={`dropdown-menu${navOpen ? ' open' : ''}`}>
          <nav className="nav-links">
            <a href="#inicio">Inicio</a>
            <a href="#about">Quiénes somos</a>
            <a href="#servicios">Servicios</a>
            <a href="#reserva" className="btn-reserva">Reserve su cita</a>
          </nav>
        </div>

        {/* Menú de usuario */}
        {userOpen && (
          <>
            {/* Dropdown móvil */}
            <div ref={userRef} className="user-dropdown">
              {userRole
                ? userMenuItems.map((it,i)=><a key={i} href={it.href}>{it.label}</a>)
                : <a href="#" onClick={handleAccessClick}>Acceso</a>
              }
            </div>
            {/* Overlay + Sidebar tablet+desktop */}
            <div className="overlay show" onClick={()=>setUserOpen(false)}/>
            <aside className="sidebar show">
              <button className="close-btn" onClick={()=>setUserOpen(false)} aria-label="Cerrar menú usuario">
                <X size={20}/>
              </button>
              <div className="sidebar-links">
                {userRole
                  ? userMenuItems.map((it,i)=><a key={i} href={it.href}>{it.label}</a>)
                  : <a href="#" onClick={handleAccessClick}>Acceso</a>
                }
              </div>
            </aside>
          </>
        )}
      </header>

      {/* Modal de Login */}
      {loginOpen && (
        <div className="modal-backdrop">
          <div className="modal" ref={modalRef}>
            <button className="modal-close" onClick={()=>setLoginOpen(false)}>×</button>
            <h2 className="modal-title">Inicia sesión</h2>
            {apiError && <div className="api-error">{apiError}</div>}
            <form onSubmit={handleLoginSubmit}>
              <div className="field">
                <label htmlFor="email">Email</label>
                <input
                  id="email"
                  type="email"
                  value={email}
                  onChange={e => setEmail(e.target.value)}
                  className={fieldError && !email ? 'invalid' : ''}
                />
                {fieldError && !email && <span className="field-error">Este campo no puede quedar vacío</span>}
              </div>
              <div className="field">
                <label htmlFor="password">Contraseña</label>
                <input
                  id="password"
                  type="password"
                  value={password}
                  onChange={e => setPassword(e.target.value)}
                  className={fieldError && !password ? 'invalid' : ''}
                />
                {fieldError && !password && <span className="field-error">Este campo no puede quedar vacío</span>}
              </div>
              <div className="actions">
                <a href="/reset-password" className="forgot">¿Olvidé mi contraseña?</a>
                <button type="submit" className="btn-submit">Entrar</button>
              </div>
            </form>
          </div>
        </div>
      )}
    </>
  );
}
