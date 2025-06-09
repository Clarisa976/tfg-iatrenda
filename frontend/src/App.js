import React, { useState, useEffect } from 'react';
import { BrowserRouter, Routes, Route, Navigate, useNavigate } from 'react-router-dom';
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

// Componente para rutas protegidas
function ProtectedRoute({ children, requiredRole, user, onUnauthorized }) {
  const navigate = useNavigate();
  const [hasRedirected, setHasRedirected] = useState(false);

  useEffect(() => {

    if (hasRedirected) return;

    if (!user) {
      onUnauthorized('Debes iniciar sesión para acceder a esta página');
      setHasRedirected(true);
      navigate('/', { replace: true });
      return;
    }

    const userRole = user?.rol?.toLowerCase() || user?.role?.toLowerCase();
    if (userRole !== requiredRole.toLowerCase()) {
      onUnauthorized(`No tienes permisos para acceder a la sección de ${requiredRole}`);
      setHasRedirected(true);
      navigate('/', { replace: true });
    }
  }, [user, requiredRole, navigate, onUnauthorized, hasRedirected]);


  useEffect(() => {
    setHasRedirected(false);
  }, [user, requiredRole]);

  if (!user) return null;

  const userRole = user?.rol?.toLowerCase() || user?.role?.toLowerCase();
  if (userRole !== requiredRole.toLowerCase()) return null;

  return children;
}

export default function App() {
  const [user, setUser] = useState(null);
  const [loginOpen, setLoginOpen] = useState(false);
  const [reservarCita, setReservarCita] = useState(false);
  const [toast, setToast] = useState({ show: false, ok: true, msg: '', type: 'default' });

  // Función para mostrar mensajes de error de permisos
  const showUnauthorizedMessage = (message) => {

    if (toast.show && toast.type === 'unauthorized') return;

    setToast({
      show: true,
      ok: false,
      msg: message,
      type: 'unauthorized'
    });
  };

  // Limpiar sesión solo en recarga forzada (Ctrl+F5)
  useEffect(() => {
    const handleKeyDown = (e) => {
      // Detectar Ctrl+F5 o Ctrl+Shift+R (recarga forzada)
      if ((e.ctrlKey && e.key === 'F5') || (e.ctrlKey && e.shiftKey && e.key === 'R')) {
        console.log('Recarga forzada detectada - limpiando sesión');
        localStorage.removeItem('token');
        delete axios.defaults.headers.common['Authorization'];
        setUser(null);
      }
    };

    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, []);

  useEffect(() => {
    const cleanupSession = () => {
      localStorage.removeItem('token');
      delete axios.defaults.headers.common['Authorization'];
      setUser(null);
    };

    // Solo restaurar la sesión al cargar la página, no limpiarla
    const token = localStorage.getItem('token');
    if (!token) {
      setUser(null);
      return;
    }

    try {
      const payload = JSON.parse(atob(token.split('.')[1]));
      if (payload.exp * 1000 <= Date.now()) {
        cleanupSession();
        return;
      }

      axios.defaults.headers.common['Authorization'] = `Bearer ${token}`;

      axios.get(`${process.env.REACT_APP_API_URL}/status`)
        .then(response => {
          if (response.data.ok) {
            setUser({ id: payload.sub, rol: payload.rol, role: payload.rol });
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
  }, []);

  // Oculta el toast tras 2s para errores de permisos, 5s para otros
  useEffect(() => {
    if (!toast.show) return;

    // Reduced timeout for unauthorized messages
    const timeout = toast.type === 'unauthorized' ? 2000 : 5000;

    // Use a ref to keep track of the timeout
    const timeoutId = setTimeout(() => {
      setToast(t => ({ ...t, show: false }));
    }, timeout);

    // Clear the timeout when component unmounts or toast changes
    return () => {
      clearTimeout(timeoutId);
    };
  }, [toast.show, toast.type]);

  const handleLoginSuccess = userData => {
    setUser(userData);
    setLoginOpen(false);
  };

  const abrirCita = () => setReservarCita(true);
  const onCitaSuccess = msg => { setReservarCita(false); setToast({ show: true, ok: true, msg, type: 'cita' }); };
  const onCitaError = msg => { setReservarCita(false); setToast({ show: true, ok: false, msg, type: 'cita' }); };

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

          {/* Rutas Admin - Protegidas */}
          <Route
            path="/admin/usuarios"
            element={
              <ProtectedRoute requiredRole="admin" user={user} onUnauthorized={showUnauthorizedMessage}>
                <Usuarios />
              </ProtectedRoute>
            }
          />
          <Route
            path="/admin/notificaciones"
            element={
              <ProtectedRoute requiredRole="admin" user={user} onUnauthorized={showUnauthorizedMessage}>
                <Notificaciones />
              </ProtectedRoute>
            }
          />
          <Route
            path="/admin/agenda-global"
            element={
              <ProtectedRoute requiredRole="admin" user={user} onUnauthorized={showUnauthorizedMessage}>
                <AgendaGlobal />
              </ProtectedRoute>
            }
          />
          <Route
            path="/admin/informes"
            element={
              <ProtectedRoute requiredRole="admin" user={user} onUnauthorized={showUnauthorizedMessage}>
                <InformesYLogs />
              </ProtectedRoute>
            }
          />

          {/* Rutas Profesional - Protegidas */}
          <Route
            path="/profesional/mi-perfil"
            element={
              <ProtectedRoute requiredRole="profesional" user={user} onUnauthorized={showUnauthorizedMessage}>
                <PerfilProfesional />
              </ProtectedRoute>
            }
          />
          <Route
            path="/profesional/pacientes"
            element={
              <ProtectedRoute requiredRole="profesional" user={user} onUnauthorized={showUnauthorizedMessage}>
                <PacientesProfesional />
              </ProtectedRoute>
            }
          />
          <Route
            path="/profesional/paciente/:id"
            element={
              <ProtectedRoute requiredRole="profesional" user={user} onUnauthorized={showUnauthorizedMessage}>
                <PerfilPacienteProfesional />
              </ProtectedRoute>
            }
          />
          <Route
            path="/profesional/agenda"
            element={
              <ProtectedRoute requiredRole="profesional" user={user} onUnauthorized={showUnauthorizedMessage}>
                <AgendaProfesional />
              </ProtectedRoute>
            }
          />

          {/* Rutas Paciente - Protegidas */}
          <Route
            path="/paciente/mi-perfil"
            element={
              <ProtectedRoute requiredRole="paciente" user={user} onUnauthorized={showUnauthorizedMessage}>
                <PerfilPaciente />
              </ProtectedRoute>
            }
          />
          <Route
            path="/paciente/mis-citas"
            element={
              <ProtectedRoute requiredRole="paciente" user={user} onUnauthorized={showUnauthorizedMessage}>
                <CitasPaciente />
              </ProtectedRoute>
            }
          />

          {/* Ruta para páginas no encontradas */}
          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </main>

      {/* Modales */}
      {loginOpen && <LoginModal onClose={() => setLoginOpen(false)} onLoginSuccess={handleLoginSuccess} />}
      {reservarCita && <ReservarCitaModal onClose={() => setReservarCita(false)} onSuccess={onCitaSuccess} onError={onCitaError} />}

      {/* Toast Global*/}
      {toast.show && (
        <div className="toast-global centered-toast">
          <div className={`toast-card ${toast.ok ? 'success' : 'error'}`}>
            {toast.ok
              ? <CheckCircle size={48} className="toast-icon success" />
              : <XCircle size={48} className="toast-icon error" />
            }
            <h3 className="toast-title">
              {toast.type === 'unauthorized' ? '¡Acceso denegado!' :
                toast.type === 'cita' && toast.ok ? '¡Reserva enviada!' :
                  toast.type === 'cita' && !toast.ok ? '¡Lo sentimos!' :
                    toast.ok ? '¡Éxito!' : '¡Error!'
              }
            </h3>
            <p className="toast-text">
              {toast.msg ||
                (toast.type === 'cita' && toast.ok ? 'Te avisaremos cuando el equipo confirme tu cita.' :
                  toast.type === 'cita' && !toast.ok ? 'El día o la hora que has seleccionado no están disponibles. Elige otra fecha.' :
                    toast.type === 'unauthorized' ? 'No tienes permisos para acceder a esta página.' :
                      toast.ok ? 'Operación completada correctamente.' : 'Ha ocurrido un error.'
                )
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