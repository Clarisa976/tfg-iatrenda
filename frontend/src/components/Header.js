import React, { useState, useRef, useEffect, useCallback } from 'react';
import { Menu, X, User } from 'lucide-react';
import axios from 'axios';
import '../styles.css';

import LoginModal from './LoginModal';
import ReservarCitaModal from './ReservarCitaModal'; // ya no lo usaremos aquí

function useOutsideClick(refs, when, callback) {
  useEffect(() => {
    if (!when) return;
    function listener(e) {
      const clickedInside = refs.some(
        ref => ref.current && ref.current.contains(e.target)
      );
      if (!clickedInside) callback();
    }
    document.addEventListener('mousedown', listener);
    return () => document.removeEventListener('mousedown', listener);
  }, [refs, when, callback]);
}

axios.defaults.baseURL = process.env.REACT_APP_API_URL;

export default function Header({ user, onAccessClick, onReservarCita }) {
  const logoImg = process.env.REACT_APP_LOGO_IMG;

  const [navOpen,  setNavOpen]  = useState(false);
  const [userOpen, setUserOpen] = useState(false);
  const [userRole, setUserRole] = useState(user?.rol || null);

  const dropdownRef = useRef(null);
  const sidebarRef  = useRef(null);

  useOutsideClick([dropdownRef, sidebarRef], userOpen, () => setUserOpen(false));
  useOutsideClick([], navOpen && window.innerWidth < 768, () => setNavOpen(false));

  const handleAccessClick = e => {
    e.preventDefault();
    console.log('🔐 abrir LoginModal');
    onAccessClick();
    setUserOpen(false);
  };

  const handleNavLink = (e, id) => {
    e.preventDefault();
    if (id === 'top') {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    } else {
      const sec = document.getElementById(id);
      sec && sec.scrollIntoView({ behavior: 'smooth' });
    }
    setNavOpen(false);
    setUserOpen(false);
  };

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
    <header className="header">
      <div className="top-bar">
        <div className="logo">
          <a href="/" onClick={e => handleNavLink(e, 'top')}>
            <img src={logoImg} alt="Petaka" />
          </a>
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

      <div className={`menu-desplegable${navOpen ? ' open' : ''}`}>
        <nav className="nav-links">
          <a href="#top" onClick={e => handleNavLink(e, 'top')}>Inicio</a>
          <a href="#quienes-somos" onClick={e => handleNavLink(e, 'quienes-somos')}>Quiénes somos</a>
          <a href="#servicios" onClick={e => handleNavLink(e, 'servicios')}>Servicios</a>
          <a
            href="#reserva"
            className="btn-reserva"
            onClick={e => { e.preventDefault(); console.log('📅 abrir ReservarCitaModal'); onReservarCita(); }}
          >
            Reserve su cita
          </a>
        </nav>
      </div>

      {userOpen && (
        <>
          <div ref={dropdownRef} className="user-dropdown">
            {userRole
              ? userMenuItems.map(({ href, label }) => (
                  <a key={href} href={href}>{label}</a>
                ))
              : <a href="#" onClick={handleAccessClick}>Acceso</a>
            }
          </div>

          <div className="overlay show" onClick={() => setUserOpen(false)} />
          <aside ref={sidebarRef} className="sidebar show">
            <button className="close-btn" onClick={() => setUserOpen(false)}>
              <X size={20}/>
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
  );
}
