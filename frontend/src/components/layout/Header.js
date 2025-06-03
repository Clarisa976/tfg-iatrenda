import React, { useState, useRef, useEffect } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Menu, X, User, Bell } from 'lucide-react';
import axios from 'axios';
import '../../styles.css';

export default function Header({ user, onAccessClick, onReservarCita, onLogout }) {

  const logoImg = process.env.REACT_APP_LOGO_IMG;
  const [navOpen, setNavOpen] = useState(false);
  const [userOpen, setUserOpen] = useState(false);
  const [hasPendings, setPend] = useState(false);

  const dropdownRef = useRef(null);
  const sidebarRef = useRef(null);
  const navigate = useNavigate();

  const userRole = user?.rol || user?.role || null;

  /* ------------- cierres automáticos (click fuera) --------------- */
  useEffect(() => {
    const fn = e => {
      if (userOpen &&
        !dropdownRef.current?.contains(e.target) &&
        !sidebarRef.current?.contains(e.target))
        setUserOpen(false);
    };
    document.addEventListener('mousedown', fn);
    return () => document.removeEventListener('mousedown', fn);
  }, [userOpen]);

  useEffect(() => {
    const fn = e => {
      if (navOpen && window.innerWidth < 768 &&
        !e.target.closest('.menu-desplegable') &&
        !e.target.closest('.menu-icon'))
        setNavOpen(false);
    };
    document.addEventListener('mousedown', fn);
    return () => document.removeEventListener('mousedown', fn);
  }, [navOpen]);

  /* ----------- comprobar notificaciones admins cada 30 s ---------- */
  useEffect(() => {
    if (userRole !== 'admin') return;
    const check = async () => {
      try {
        const { data } = await axios.get('/notificaciones');
        setPend((data.data || []).length > 0);
      } catch { }
    };
    check();
    const id = setInterval(check, 30000);
    return () => clearInterval(id);
  }, [userRole]);

  /* ---- sincronizar campana si Notificaciones emite customEvent --- */
  useEffect(() => {
    const handler = e => setPend(e.detail > 0);
    window.addEventListener('noti-count', handler);
    return () => window.removeEventListener('noti-count', handler);
  }, []);

  /* ----------------- helpers UI ---------------------------------- */
  const handleNavLink = (e, id) => {
    e.preventDefault();
    (id === 'top')
      ? window.scrollTo({ top: 0, behavior: 'smooth' })
      : document.getElementById(id)?.scrollIntoView({ behavior: 'smooth' });
    setNavOpen(false); setUserOpen(false);
  };

  const userMenuItems = {
    paciente: [{ label: 'Mi perfil', to: '/perfil' }],
    profesional: [{ label: 'Mi perfil', to: '/profesional/mi-perfil' },
    { label: 'Pacientes', to: '/profesional/pacientes' },
    { label: 'Agenda', to: '/profesional/agenda' }],
    admin: [{ label: 'Usuarios', to: '/admin/usuarios' },
    { label: 'Notificaciones', to: '/admin/notificaciones' },
    { label: 'Agenda global', to: '/admin/agenda-global' },
    { label: 'Informes y Logs', to: '/admin/informes' }],
  }[userRole] || [];

  /* ------------------------------ JSX ----------------------------- */
  return (
    <header className="header">
      {/* TOP BAR ---------------------------------------------------- */}
      <div className="top-bar">
        <div className="logo">
          <Link to="/" onClick={e => handleNavLink(e, 'top')}>
            <img src={logoImg} alt="Petaka" />
          </Link>
        </div>

        <div className="icons">
          {/* hamburguesa móviles */}
          <button className="icon menu-icon"
            onClick={() => setNavOpen(o => !o)}
            aria-label="Menú principal">
            {navOpen ? <X size={24} /> : <Menu size={24} />}
          </button>

          {/* campana (solo admin) */}
          {userRole === 'admin' && (
            <button className={`icon bell-icon ${hasPendings ? 'red' : ''}`}
              aria-label="Notificaciones"
              onClick={() => navigate('/admin/notificaciones')}>
              <Bell size={24} />
            </button>
          )}

          {/* usuario */}
          <button className={`icon user-icon${userOpen ? ' active' : ''}`}
            onClick={() => setUserOpen(o => !o)}
            aria-label="Acceso usuario">
            <User size={24} />
          </button>
        </div>
      </div>

      {/* MENÚ PRINCIPAL -------------------------------------------- */}
      <div className={`menu-desplegable${navOpen ? ' open' : ''}`}>
        <nav className="nav-links">
          <a href="#top" onClick={e => handleNavLink(e, 'top')}>Inicio</a>
          <a href="#quienes-somos" onClick={e => handleNavLink(e, 'quienes-somos')}>Quiénes somos</a>
          <a href="#servicios" onClick={e => handleNavLink(e, 'servicios')}>Servicios</a>
          <a className="btn-reserva"
            href="#"
            onClick={e => { e.preventDefault(); onReservarCita(); }}>
            Reserve su cita
          </a>
        </nav>
      </div>

      {/* MENÚ DE USUARIO ------------------------------------------- */}
      {userOpen && (
        <>
          {/* dropdown desktop */}
          <div ref={dropdownRef} className="user-dropdown">
            {userRole ? (
              <>
                {userMenuItems.map(({ to, label }) => (
                  <Link key={to} to={to} onClick={() => setUserOpen(false)}>{label}</Link>
                ))}
                <Link to="/" onClick={() => { onLogout(); setUserOpen(false); }}>Cerrar sesión</Link>
              </>
            ) : (
              <a href="#" onClick={e => { e.preventDefault(); onAccessClick(); setUserOpen(false); }}>Acceso</a>
            )}
          </div>

          {/* overlay + sidebar móvil */}
          <div className="overlay show" onClick={() => setUserOpen(false)} />
          <aside ref={sidebarRef} className="sidebar show">
            <button className="close-btn" onClick={() => setUserOpen(false)} aria-label="Cerrar">
              <X size={20} />
            </button>
            <div className="sidebar-links">
              {userRole ? (
                <>
                  {userMenuItems.map(({ to, label }) => (
                    <Link key={to} to={to} onClick={() => setUserOpen(false)}>{label}</Link>
                  ))}
                  <Link to="/" onClick={() => { onLogout(); setUserOpen(false); }}>Cerrar sesión</Link>
                </>
              ) : (
                <a href="#" onClick={e => { e.preventDefault(); onAccessClick(); setUserOpen(false); }}>Acceso</a>
              )}
            </div>
          </aside>
        </>
      )}
    </header>
  );
}