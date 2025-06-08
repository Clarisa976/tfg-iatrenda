import React, { useState, useEffect } from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { CheckCircle, XCircle } from 'lucide-react';
import axios from 'axios';

import Header from './components/layout/Header';
import Footer from './components/layout/Footer';
import ScrollArriba from './components/layout/ScrollArriba';

import Inicio from './pages/Inicio';
import CrearContrasena from './pages/CrearContrasena';

import Usuarios from './pages/admin/Usuarios'
import Notificaciones from './pages/admin/Notificaciones'
import AgendaGlobal from './pages/admin/AgendaGlobal'
import InformesYLogs from './pages/admin/InformesYLogs'



import LoginModal from './components/modals/LoginModal';
import ReservarCitaModal from './components/modals/ReservarCitaModal';

import './styles.css';
import PerfilProfesional from './pages/profesional/PerfilProfesional';
import PacientesProfesional from './pages/profesional/PacientesProfesional';
import PerfilPacienteProfesional from './pages/profesional/PerfilPacienteProfesional';
import AgendaProfesional from './pages/profesional/AgendaProfesional';

import PerfilPaciente from './pages/paciente/PerfilPaciente';
import CitasPaciente from './pages/paciente/CitasPaciente';

// Páginas legales
import PoliticaPrivacidad from './components/secciones/PoliticaPrivacidad';
import TerminosCondiciones from './components/secciones/TerminosCondiciones';
import PoliticaCookies from './components/secciones/PoliticaCookies';



export default function App() {
  const [user, setUser] = useState(null);
  const [loginOpen, setLoginOpen] = useState(false);
  const [reservarCita, setReservarCita] = useState(false);
  const [toast, setToast] = useState({ show: false, ok: true, msg: '' });

  // Limpiar sesión y token al recargar la página
  useEffect(() => {
    const handlePageReload = (e) => {
      if (e.persisted) {
        // La página se está recargando desde caché (F5)
        setUser(null);
        localStorage.removeItem('token');
      }
    };
    window.addEventListener('pageshow', handlePageReload);
    return () => window.removeEventListener('pageshow', handlePageReload);
  }, []);

  useEffect(() => {
    const cleanupSession = () => {
      localStorage.removeItem('token');
      delete axios.defaults.headers.common['Authorization'];
      setUser(null);
    };

    // Manejar la recarga de la página
    const handleBeforeUnload = () => {
      cleanupSession();
    };

    window.addEventListener('beforeunload', handleBeforeUnload);

    // Verificar token y restaurar sesión al cargar
    const token = localStorage.getItem('token');
    if (!token) {
      cleanupSession();
      return;
    }

    try {
      const payload = JSON.parse(atob(token.split('.')[1]));
      if (payload.exp * 1000 <= Date.now()) {
        cleanupSession();
        return;
      }

      // Configurar axios con el token
      axios.defaults.headers.common['Authorization'] = `Bearer ${token}`;

      // Petición para validar el token 
      axios.get(`${process.env.REACT_APP_API_URL}/status`)
        .then(response => {
          if (response.data.ok) {
            setUser({id: payload.sub, rol: payload.rol, role: payload.rol});
          } else {
            cleanupSession();
          }
        })
        .catch(() => {
          cleanupSession();
        });
    } catch (error) {
      cleanupSession();
    }

    return () => {
      window.removeEventListener('beforeunload', handleBeforeUnload);
    };
  }, []);

  // Oculta el toast tras 5s
  useEffect(() => {
    if (!toast.show) return;
    const id = setTimeout(() => setToast(t => ({ ...t, show: false })), 5000);
    return () => clearTimeout(id);
  }, [toast.show]);

  const handleLoginSuccess = userData => {
    setUser(userData);
    setLoginOpen(false);
  };
  const abrirCita = () => setReservarCita(true);
  const onCitaSuccess = msg => { setReservarCita(false); setToast({ show: true, ok: true, msg }); };
  const onCitaError = msg => { setReservarCita(false); setToast({ show: true, ok: false, msg }); };

  return (
    <BrowserRouter>
      <Header user={user} onAccessClick={() => setLoginOpen(true)} onReservarCita={abrirCita} onLogout={() => setUser(null)} />

      <main className="app-main">
        <Routes>
          <Route path="/" element={<Inicio onReservarCita={abrirCita} />} />
          <Route path="/crear-contrasena" element={<CrearContrasena />} />

          {/* Páginas legales */}
          <Route path="/terminos" element={<TerminosCondiciones />} />
          <Route path="/privacidad" element={<PoliticaPrivacidad />} />
          <Route path="/cookies" element={<PoliticaCookies />} />

          {/* Rutas Admin */}
          {(user?.rol?.toLowerCase() === 'admin' || user?.role?.toLowerCase() === 'admin') && (
            <>
              <Route path="/admin/usuarios" element={<Usuarios />} />
              <Route path="/admin/notificaciones" element={<Notificaciones />} />
              <Route path="/admin/agenda-global" element={<AgendaGlobal />} />
              <Route path="/admin/informes" element={<InformesYLogs />} />
            </>
          )}

          {/* Rutas Profesional */}
          {(user?.rol?.toLowerCase() === 'profesional' || user?.role?.toLowerCase() === 'profesional') && (
            <>
              <Route path="/profesional/mi-perfil" element={<PerfilProfesional />} />
              <Route path="/profesional/pacientes" element={<PacientesProfesional />} />
              <Route path="/profesional/paciente/:id" element={<PerfilPacienteProfesional />} />
              <Route path="/profesional/agenda" element={<AgendaProfesional />} />
            </>
          )}

          {/* Rutas Paciente */}
          {(user?.rol?.toLowerCase() === 'paciente' || user?.role?.toLowerCase() === 'paciente') && (
            <>
              <Route path="/paciente/mi-perfil" element={<PerfilPaciente />} />
              <Route path="/paciente/mis-citas" element={<CitasPaciente />} />
            </>
          )}

          {/* Redirigir si intenta acceder sin permiso */}
          <Route path="/admin/*" element={<Navigate to="/" replace />} />
          <Route path="/profesional/*" element={<Navigate to="/" replace />} />
          <Route path="/paciente/*" element={<Navigate to="/" replace />} />
        </Routes>
      </main>

      {/* Modales */}
      {loginOpen && <LoginModal onClose={() => setLoginOpen(false)} onLoginSuccess={handleLoginSuccess} />}
      {reservarCita && <ReservarCitaModal onClose={() => setReservarCita(false)} onSuccess={onCitaSuccess} onError={onCitaError} />}

      {/* Toast Global */}
      {toast.show && (
        <div className="toast-global centered-toast">
          <div className={`toast-card ${toast.ok ? 'success' : 'error'}`}>
            {toast.ok
              ? <CheckCircle size={48} className="toast-icon success" />
              : <XCircle size={48} className="toast-icon error" />
            }
            <h3 className="toast-title">{toast.ok ? '¡Reserva enviada!' : '¡Lo sentimos!'}</h3>
            <p className="toast-text">
              {toast.ok
                ? 'Te avisaremos cuando el equipo confirme tu cita.'
                : 'El día o la hora que has seleccionado no están disponibles. Elige otra fecha.'
              }
            </p>
          </div>
        </div>
      )}
      <ScrollArriba />
      <Footer />
    </BrowserRouter>
  );
}
