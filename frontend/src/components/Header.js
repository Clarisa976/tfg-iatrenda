import React, { useState, useRef, useEffect } from 'react';
import { Menu, X, User } from 'lucide-react';
import '../styles.css';

const Header = () => {
  const [navOpen, setNavOpen] = useState(false);
  const [userOpen, setUserOpen] = useState(false);
  const userRef = useRef(null);

  useEffect(() => {
    const handleClickOutside = e => {
      if (userOpen && userRef.current && !userRef.current.contains(e.target) && !e.target.closest('.user-icon')) {
        setUserOpen(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, [userOpen]);

  const userRole = null; // 'paciente'|'profesional'|'administrador'|null
  const userMenuItems = userRole === 'paciente'
    ? ['Mi perfil','Tareas para casa','Historial clínico','Mis citas','Cerrar sesión']
    : userRole === 'profesional'
      ? ['Mi perfil','Pacientes','Agenda','Cerrar sesión']
      : userRole === 'administrador'
        ? ['Usuarios','Informes','Agenda global','Cerrar sesión']
        : [];

  return (
    <header className="header">
      <div className="top-bar">
        <div className="logo">
          <a href="/"><img src="https://iatrenda-petaka.s3.eu-west-3.amazonaws.com/images/logo_petaka.webp" alt="Logo" /></a>
        </div>
        <div className="icons">
          <button
            className="icon menu-icon"
            onClick={() => setNavOpen(!navOpen)}
          >
            {navOpen ? <X size={24}/> : <Menu size={24}/>}          
          </button>
          <button
            className={`icon user-icon ${userOpen ? 'active' : ''}`}
            onClick={() => setUserOpen(!userOpen)}
          >
            <User size={24}/>
          </button>
        </div>
      </div>

      <div className={`dropdown-menu ${navOpen ? 'open' : ''}`}>  
        <nav className="nav-links">
          <a href="/">Inicio</a>
          <a href="/quienes">Quiénes somos</a>
          <a href="/servicios">Servicios</a>
          <a href="/reservar" className="btn-reserva">Reserve su cita</a>
        </nav>
      </div>

      {userOpen && (
        <>
          <div ref={userRef} className="user-dropdown">
            {userRole
              ? userMenuItems.map((item,i) => <a key={i} href="#">{item}</a>)
              : <a href="/login">Acceso</a>
            }
          </div>

          <div className="overlay show" onClick={()=> setUserOpen(false)}/>
          <div className="sidebar show">
            <button className="close-btn" onClick={() => setUserOpen(false)}><X size={20}/></button>
            <div className="sidebar-links">
              {userRole
                ? userMenuItems.map((item,i) => <a key={i} href="#">{item}</a>)
                : <a href="/login">Acceso</a>
              }
            </div>
          </div>
        </>
      )}
    </header>
  );
}

export default Header;
