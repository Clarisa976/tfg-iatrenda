import React, { useState, useRef, useEffect } from 'react';
import { Link, useNavigate, useLocation } from 'react-router-dom';
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
  const location = useLocation();

  const userRole = user?.rol || user?.role || null;
  const isLoggedIn = !!userRole; // Si tiene rol, está logueado

  /* cierres automáticos */
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

  /* comprobar notificaciones admins cada 30 s*/
  useEffect(() => {
    if (userRole !== 'admin') return;
    const check = async () => {
      try {
        const token = localStorage.getItem('token');
        if (token) axios.defaults.headers.common['Authorization'] = `Bearer ${token}`;
        const { data } = await axios.get('/notificaciones');
        setPend((data.data || []).length > 0);
      } catch { }
    };
    check();
    const id = setInterval(check, 30000);
    return () => clearInterval(id);
  }, [userRole]);

  /* sincronizar campana si Notificaciones emite customEvent */
  useEffect(() => {
    const handler = e => setPend(e.detail > 0);
    window.addEventListener('noti-count', handler);
    return () => window.removeEventListener('noti-count', handler);
  }, []);
  // Función mejorada para navegación interna
  const handleNavLink = (e, sectionId) => {
    e.preventDefault();
    setNavOpen(false);
    setUserOpen(false);

    // Si no estamos en la página principal, navegar primero a ella
    if (location.pathname !== '/') {
      navigate('/');
      // Esperar a que se cargue la página principal y luego hacer scroll
      setTimeout(() => {
        if (sectionId === 'top') {
          window.scrollTo({ top: 0, behavior: 'smooth' });
        } else {
          const element = document.getElementById(sectionId);
          if (element) {
            element.scrollIntoView({ behavior: 'smooth' });
          }
        }
      }, 100);
    } else {
      // Ya estamos en la página principal, hacer scroll directo
      if (sectionId === 'top') {
        window.scrollTo({ top: 0, behavior: 'smooth' });
      } else {
        const element = document.getElementById(sectionId);
        if (element) {
          element.scrollIntoView({ behavior: 'smooth' });
        }
      }
    }
  };

  // Función para manejar clic en el logo/inicio
  const handleLogoClick = (e) => {
    e.preventDefault();
    setNavOpen(false);
    setUserOpen(false);

    // Si el usuario está logueado, redirigir a su página principal según el rol
    if (userRole) {
      switch (userRole) {
        case 'admin':
          navigate('/admin/usuarios');
          break;
        case 'profesional':
          navigate('/profesional/mi-perfil');
          break;
        case 'paciente':
          navigate('/paciente/mi-perfil');
          break;
        default:
          navigate('/');
      }
    } else {
      // Si no está logueado, ir a la página de inicio pública
      if (location.pathname !== '/') {
        navigate('/');
      } else {
        window.scrollTo({ top: 0, behavior: 'smooth' });
      }
    }
  };

  // Función para navegar a secciones específicas del perfil de paciente
  const navigateToProfileSection = (section) => {
    navigate(`/paciente/mi-perfil?section=${section}`);
    setUserOpen(false);
  };

  const userMenuItems = {
    paciente: [
      { label: 'Mi perfil', action: () => navigateToProfileSection('perfil') },
      { label: 'Tareas para casa', action: () => navigateToProfileSection('tareas') },
      { label: 'Historial clínico', action: () => navigateToProfileSection('historial') },
      { label: 'Mis citas', to: '/paciente/mis-citas' }
    ],
    profesional: [
      { label: 'Mi perfil', to: '/profesional/mi-perfil' },
      { label: 'Pacientes', to: '/profesional/pacientes' },
      { label: 'Agenda', to: '/profesional/agenda' }
    ],
    admin: [
      { label: 'Usuarios', to: '/admin/usuarios' },
      { label: 'Notificaciones', to: '/admin/notificaciones' },
      { label: 'Agenda global', to: '/admin/agenda-global' },
      { label: 'Informes y Logs', to: '/admin/informes' }
    ],
  }[userRole] || [];

  return (
    <header className="header">
      {/* TOP BAR */}
      <div className="top-bar">
        <div className="logo">
          <Link to="/" onClick={handleLogoClick}>
            <img src={logoImg} alt="Iatrenda" />
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
      {/* MENÚ PRINCIPAL */}
      <div className={`menu-desplegable${navOpen ? ' open' : ''}`}>
        <nav className="nav-links">
          <a href="/" onClick={handleLogoClick}>Inicio</a>
          <a href="/#quienes-somos" onClick={e => handleNavLink(e, 'quienes-somos')}>Quiénes somos</a>
          <a href="/#servicios" onClick={e => handleNavLink(e, 'servicios')}>Servicios</a>

          {/* Solo mostrarsi NO está logueado */}
          {!isLoggedIn && (
            <a className="btn-reserva"
              href="/reservar"
              onClick={e => { e.preventDefault(); onReservarCita(); }}>
              Reserve su cita
            </a>
          )}
        </nav>
      </div>
      {/* MENÚ DE USUARIO */}
      {userOpen && (
        <>
          {/* dropdown móvil */}
          <div ref={dropdownRef} className="user-dropdown">
            {userRole ? (
              <>
                {userMenuItems.map((item, index) => (
                  item.to ? (
                    <Link key={index} to={item.to} onClick={() => setUserOpen(false)}>
                      {item.label}
                    </Link>
                  ) : (
                    <a key={index}
                      href={item.label === 'Mi perfil' ? '/paciente/mi-perfil' :
                        item.label === 'Tareas para casa' ? '/paciente/mi-perfil?section=tareas' :
                          item.label === 'Historial clínico' ? '/paciente/mi-perfil?section=historial' : '/'
                      }
                      onClick={e => { e.preventDefault(); item.action(); }}>
                      {item.label}
                    </a>
                  )
                ))}
                <Link to="/" onClick={() => { onLogout(); setUserOpen(false); }}>Cerrar sesión</Link>
              </>
            ) : (
              <a href="/acceso" onClick={e => { e.preventDefault(); onAccessClick(); setUserOpen(false); }}>Acceso</a>
            )}
          </div>

          {/* overlay + sidebar tablet/desktop */}
          <div className={`overlay ${userOpen ? 'show' : ''}`} onClick={() => setUserOpen(false)} />
          <aside ref={sidebarRef} className={`sidebar ${userOpen ? 'show' : ''}`}>
            <button className="close-btn" onClick={() => setUserOpen(false)} aria-label="Cerrar">
              <X size={20} />
            </button>
            <div className="sidebar-links">
              {userRole ? (
                <>
                  {userMenuItems.map((item, index) => (
                    item.to ? (
                      <Link key={index} to={item.to} onClick={() => setUserOpen(false)}>
                        {item.label}
                      </Link>
                    ) : (
                      <a key={index}
                        href={item.label === 'Mi perfil' ? '/paciente/mi-perfil' :
                          item.label === 'Tareas para casa' ? '/paciente/mi-perfil?section=tareas' :
                            item.label === 'Historial clínico' ? '/paciente/mi-perfil?section=historial' : '/'
                        }
                        onClick={e => { e.preventDefault(); item.action(); }}>
                        {item.label}
                      </a>
                    )
                  ))}
                  <Link to="/" onClick={() => { onLogout(); setUserOpen(false); }}>Cerrar sesión</Link>
                </>
              ) : (
                <a href="/acceso" onClick={e => { e.preventDefault(); onAccessClick(); setUserOpen(false); }}>Acceso</a>
              )}
            </div>
          </aside>
        </>
      )}
    </header>
  );
}