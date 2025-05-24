// src/components/Header.js
import React, { useState, useRef, useEffect, useCallback } from 'react';
import axios from 'axios';
import { Menu, X, User } from 'lucide-react';
import '../styles.css';
import LoginModal from './LoginModal';


function useOutsideClick(refs, when, callback) {
  useEffect(() => {
    if (!when) return;

    function listener(e) {
      const clickedInside = refs.some(
        ref => ref.current && ref.current.contains(e.target)
      );
      if (!clickedInside) callback(e);
    }

    document.addEventListener('mousedown', listener);
    return () => document.removeEventListener('mousedown', listener);
  }, [refs, when, callback]);
}

axios.defaults.baseURL = process.env.REACT_APP_API_URL;

export default function Header() {
  const logoImg = process.env.REACT_APP_LOGO_IMG;

  /* Estado */
  const [navOpen, setNavOpen] = useState(false);  // menú principal (<768 px)
  const [userOpen, setUserOpen] = useState(false);  // menú usuario
  const [loginOpen, setLoginOpen] = useState(false);  // modal login
  const [userRole, setUserRole] = useState(null);   // 'paciente' | 'profesional' | 'admin'
  

  const dropdownRef = useRef(null); // menú usuario móvil
  const sidebarRef = useRef(null); // sidebar tablet+desktop

  /* Cerrar menú usuario al pinchar fuera */
  useOutsideClick(
    [dropdownRef, sidebarRef],
    userOpen,
    () => setUserOpen(false)
  );

  /* Cerrar menú principal móvil al pinchar fuera */
  useOutsideClick(
    [],
    navOpen && window.innerWidth < 768,
    () => setNavOpen(false)
  );

  /* Handlers */
  const handleAccessClick = useCallback(e => {
    e.preventDefault();
    setLoginOpen(true);   // abrir modal
    setUserOpen(false);   // cerrar menú detrás
  }, []);

  const handleLoginSuccess = usuario => {
    setUserRole(usuario.rol);
    setLoginOpen(false);
  };

  /* ir a la sección y cerrar menús */
  const handleNavLink = (e, id) => {
    e.preventDefault();                 // evita que cambie el hash
    const section = document.getElementById(id);
    if (section) section.scrollIntoView({ behavior: 'smooth' });

    setNavOpen(false);                  // cierra menú hamburguesa
    setUserOpen(false);                 // y sidebar si estuviese abierto
  };

  /* Menú según rol */
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
    admin: [
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
          {/* Logo*/}
          <div className="logo">
            <a href="/">
              <img src={logoImg} alt="Petaka" />
            </a>
          </div>

          {/* Iconos */}
          <div className="icons">
            {/* Hamburguesa móvil */}
            <button
              className="icon menu-icon"
              onClick={() => setNavOpen(o => !o)}
              aria-label="Menú principal"
            >
              {navOpen ? <X size={24} /> : <Menu size={24} />}
            </button>

            {/* Usuario */}
            <button
              className={`icon user-icon${userOpen ? ' active' : ''}`}
              onClick={() => setUserOpen(o => !o)}
              aria-label="Acceso usuario"
            >
              <User size={24} />
            </button>
          </div>
        </div>

        {/* Menú principal móvil */}
        <div className={`menu-desplegable${navOpen ? ' open' : ''}`}>
          <nav className="nav-links">
            <a href="#top" onClick={(e) => handleNavLink(e, 'top')}>Inicio</a>
            <a href="#quienes-somos" onClick={(e) => handleNavLink(e, 'quienes-somos')}>Quiénes somos</a>
            <a href="#servicios" onClick={(e) => handleNavLink(e, 'servicios')}>Servicios</a>
            <a href="#reserva" className="btn-reserva">Reserve su cita</a>
          </nav>
        </div>

        {/* Menú de usuario */}
        {userOpen && (
          <>
            {/* Dropdown móvil */}
            <div ref={dropdownRef} className="user-dropdown">
              {userRole
                ? userMenuItems.map(({ href, label }) => (
                  <a key={href} href={href}>{label}</a>
                ))
                : <a href="#" onClick={handleAccessClick}>Acceso</a>
              }
            </div>

            {/* Overlay + sidebar (tablet / desktop) */}
            <div className="overlay show" onClick={() => setUserOpen(false)} />

            <aside ref={sidebarRef} className="sidebar show">
              <button
                className="close-btn"
                onClick={() => setUserOpen(false)}
                aria-label="Cerrar menú usuario"
              >
                <X size={20} />
              </button>

              <div className="sidebar-links">
                {userRole
                  ? userMenuItems.map(({ href, label }) => (
                    <a key={href} href={href}>{label}</a>
                  ))
                  : <a href="#" onClick={handleAccessClick}>Acceso</a>
                }
              </div>
            </aside>
          </>
        )}
      </header>

      {/* Modal de Login */}
      {loginOpen && (
        <LoginModal
          onClose={() => setLoginOpen(false)}
          onLoginSuccess={handleLoginSuccess}
        />
      )}
    </>
  );
}
