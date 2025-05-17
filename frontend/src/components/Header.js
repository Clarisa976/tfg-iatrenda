import React, { useState, useEffect } from 'react';
import axios from 'axios';
import { Menu, X, User } from 'lucide-react';
import '../styles.css';


export default function Header() {
  const [menuOpen, setMenuOpen] = useState(false);
  const [userMenuOpen, setUserMenuOpen] = useState(false);
  const [role, setRole] = useState(null);

  useEffect(() => {
    axios.get('/api/session.php')
      .then(res => setRole(res.data.role))
      .catch(() => setRole(null));
  }, []);

  const toggleMenu = () => {
    setMenuOpen(open => !open);
    if (userMenuOpen) setUserMenuOpen(false);
  };
  const toggleUser = () => {
    setUserMenuOpen(open => !open);
    if (menuOpen) setMenuOpen(false);
  };

  return (
    <header className="site-header">
      <div className="header-content">
        {/* Logo izquierda */}
        <a href="/">
          <img
            src="https://iatrenda-petaka.s3.eu-west-3.amazonaws.com/images/logo_petaka.webp"
            alt="Petaka logo"
            className="logo"
          />
        </a>

        {/* Iconos derecha */}
        <div className="right-icons">
          <button className="icon-btn" onClick={toggleMenu}>
            {menuOpen ? <X /> : <Menu />}
          </button>
          <button className="icon-btn" onClick={toggleUser}>
            <User />
          </button>
        </div>

        {/* Menú hamburguesa */}
        {menuOpen && (
          <nav className="mobile-nav">
            <a href="/" className="nav-link">Inicio</a>
            <a href="/quienes-somos" className="nav-link">Quiénes somos</a>
            <a href="/servicios" className="nav-link">Servicios</a>
            <a href="/reservar" className="btn-reserve">Reserve su cita</a>
          </nav>
        )}

        {/* Menú usuario */}
        {userMenuOpen && (
          <nav className="user-nav">
            {role === 'paciente' && (
              <>
                <a href="/perfil" className="nav-link">Mi perfil</a>
                <a href="/tareas" className="nav-link">Tareas para casa</a>
                <a href="/historial" className="nav-link">Historial clínico</a>
                <a href="/citas" className="nav-link">Mis citas</a>
                <a href="/logout" className="nav-link">Cerrar sesión</a>
              </>
            )}
            {role === 'profesional' && (
              <>
                <a href="/perfil" className="nav-link">Mi perfil</a>
                <a href="/pacientes" className="nav-link">Pacientes</a>
                <a href="/agenda" className="nav-link">Agenda</a>
                <a href="/logout" className="nav-link">Cerrar sesión</a>
              </>
            )}
            {role === 'administrador' && (
              <>
                <a href="/usuarios" className="nav-link">Usuarios</a>
                <a href="/informes" className="nav-link">Informes</a>
                <a href="/agenda-global" className="nav-link">Agenda global</a>
                <a href="/logout" className="nav-link">Cerrar sesión</a>
              </>
            )}
            {role === null && (
              <a href="/acceso" className="nav-link">Acceso</a>
            )}
          </nav>
        )}
      </div>
    </header>
  );
}
