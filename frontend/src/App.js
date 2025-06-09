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

// Configurar interceptor global de axios para manejar errores de autenticación
let isSessionCleanupInProgress = false;

const setupAxiosInterceptors = (cleanupSessionCallback) => {
  // Interceptor de respuestas para manejar errores de autenticación
  axios.interceptors.response.use(
    (response) => response,
    (error) => {
      // Si es un error 401 (token expirado) y no estamos ya limpiando la sesión
      if (error.response?.status === 401 && !isSessionCleanupInProgress) {
        isSessionCleanupInProgress = true;
        console.warn('Token expirado detectado, limpiando sesión...');
        cleanupSessionCallback();
        // Reset flag después de un breve delay
        setTimeout(() => {
          isSessionCleanupInProgress = false;
        }, 1000);
      }
      return Promise.reject(error);
    }
  );
};

// Componente para rutas protegidas
function ProtectedRoute({ children, requiredRole, user, onUnauthorized, isLoading }) {
  const navigate = useNavigate();
  const [hasRedirected, setHasRedirected] = useState(false);

  useEffect(() => {
    // Si aún se está cargando la sesión, no hacer nada
    if (isLoading) return;

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
  }, [user, requiredRole, navigate, onUnauthorized, hasRedirected, isLoading]);

  useEffect(() => {
    setHasRedirected(false);
  }, [user?.id, requiredRole]);

  // Mostrar loading mientras se verifica la sesión
  if (isLoading) {
    return (
      <div style={{
        display: 'flex',
        justifyContent: 'center',
        alignItems: 'center',
        height: '60vh',
        fontSize: '1.2rem'
      }}>
        Verificando sesión...
      </div>
    );
  }

  if (!user) return null;

  const userRole = user?.rol?.toLowerCase() || user?.role?.toLowerCase();
  if (userRole !== requiredRole.toLowerCase()) return null;

  return children;
}

export default function App() {
  const [user, setUser] = useState(null);
  const [isLoading, setIsLoading] = useState(true);
  const [loginOpen, setLoginOpen] = useState(false);
  const [reservarCita, setReservarCita] = useState(false);
  const [toast, setToast] = useState({ show: false, ok: true, msg: '', type: 'default' });

  // Recuperar sesión al cargar la app
  useEffect(() => {
    const recoverSession = async () => {
      const token = localStorage.getItem('token');
      const cachedUser = localStorage.getItem('userSession');

      if (!token) {
        setIsLoading(false);
        return;
      }

      // Si tenemos datos de usuario en caché, los usamos temporalmente
      if (cachedUser) {
        try {
          const userData = JSON.parse(cachedUser);
          setUser(userData);
        } catch (e) {
          localStorage.removeItem('userSession');
        }
      }

      try {
        // Configurar el token en axios
        axios.defaults.headers.common['Authorization'] = `Bearer ${token}`;

        // Crear timeout personalizado para evitar cuelgues
        const timeoutPromise = new Promise((_, reject) =>
          setTimeout(() => reject(new Error('TIMEOUT')), 8000)
        );

        // Verificar el token con el backend
        const response = await Promise.race([
          axios.get('/consentimiento'),
          timeoutPromise
        ]);

        if (response.data && response.data.ok) {
          // Actualizar token si viene renovado
          if (response.data.token) {
            localStorage.setItem('token', response.data.token);
            axios.defaults.headers.common['Authorization'] = `Bearer ${response.data.token}`;
          }

          // Intentar obtener datos del usuario en paralelo, con timeouts individuales
          const getUserData = async () => {
            const endpoints = [
              { url: '/pac/perfil', role: 'paciente', dataPath: 'datos.persona' },
              { url: '/prof/perfil', role: 'profesional', dataPath: 'data.persona' },
              { url: '/admin/usuarios', role: 'admin', dataPath: null }
            ];

            const results = await Promise.allSettled(
              endpoints.map(async endpoint => {
                const timeoutPromise = new Promise((_, reject) =>
                  setTimeout(() => reject(new Error('ENDPOINT_TIMEOUT')), 5000)
                );

                const response = await Promise.race([
                  axios.get(endpoint.url),
                  timeoutPromise
                ]);

                return { ...endpoint, response };
              })
            );

            // Buscar el primer resultado exitoso
            for (const result of results) {
              if (result.status === 'fulfilled') {
                const { response, role, dataPath } = result.value;

                if (response.data && response.data.ok) {
                  let userData;

                  if (role === 'admin') {
                    userData = {
                      id_persona: 1,
                      role: 'admin',
                      rol: 'admin',
                      nombre: 'Administrador'
                    };
                  } else {
                    const personData = dataPath.split('.').reduce((obj, key) => obj?.[key], response.data);
                    userData = {
                      ...personData,
                      role: role,
                      rol: role
                    };
                  }

                  // Guardar en caché para futuras recargas
                  localStorage.setItem('userSession', JSON.stringify(userData));
                  setUser(userData);
                  return;
                }
              }
            }

            // Si llegamos aquí, verificar si todos los errores son de autenticación
            const authErrors = results.filter(result =>
              result.status === 'rejected' &&
              result.reason?.response?.status &&
              [401, 403].includes(result.reason.response.status)
            );

            // Solo limpiar sesión si hay errores claros de autenticación
            if (authErrors.length > 0) {
              console.warn('Errores de autenticación detectados, limpiando sesión');
              cleanupSession();
            } else {
              // Si no hay errores de auth, pero tampoco datos válidos, mantener la sesión pero marcar como no cargando
              console.warn('No se pudieron obtener datos de usuario, manteniendo sesión con datos en caché');
            }
          };

          await getUserData();
        } else {
          // Token inválido, limpiar
          cleanupSession();
        }
      } catch (error) {
        console.error('Error al verificar el token:', error);

        // Solo limpiar sesión en casos específicos
        if (error.message === 'TIMEOUT') {
          console.warn('Timeout al verificar token, manteniendo sesión con datos en caché');
        } else if (error.response?.status && [401, 403].includes(error.response.status)) {
          console.warn('Token inválido o expirado, limpiando sesión');
          cleanupSession();
        } else {
          console.warn('Error de red al verificar token, manteniendo sesión con datos en caché');
        }
      } finally {
        setIsLoading(false);
      }
    };

    recoverSession();
  }, []);

  // Función para mostrar mensajes de error de permisos
  const showUnauthorizedMessage = (message) => {
    // Prevenir mensajes duplicados
    if (toast.show && toast.type === 'unauthorized') return;

    setToast({
      show: true,
      ok: false,
      msg: message,
      type: 'unauthorized'
    });
  };

  // Función para limpiar sesión
  const cleanupSession = () => {
    localStorage.removeItem('token');
    localStorage.removeItem('userSession');
    delete axios.defaults.headers.common['Authorization'];
    setUser(null);
  };

  // Configurar interceptores de axios al cargar el componente
  useEffect(() => {
    setupAxiosInterceptors(cleanupSession);
  }, []);

  // Manejar toast
  useEffect(() => {
    if (!toast.show) return;

    const timeout = toast.type === 'unauthorized' ? 3000 : 5000;

    const timeoutId = setTimeout(() => {
      setToast(t => ({ ...t, show: false }));
    }, timeout);

    return () => clearTimeout(timeoutId);
  }, [toast.show, toast.type]);

  const handleLoginSuccess = (userData, token) => {
    setUser(userData);
    setLoginOpen(false);

    // Guardar datos de usuario en caché para futuras recargas
    localStorage.setItem('userSession', JSON.stringify(userData));

    // Guardar token y configurar axios si se proporciona
    if (token) {
      localStorage.setItem('token', token);
      axios.defaults.headers.common['Authorization'] = `Bearer ${token}`;
    }
  };

  const handleLogout = () => {
    cleanupSession();
  };

  const abrirCita = () => setReservarCita(true);
  const onCitaSuccess = msg => {
    setReservarCita(false);
    setToast({ show: true, ok: true, msg, type: 'cita' });
  };
  const onCitaError = msg => {
    setReservarCita(false);
    setToast({ show: true, ok: false, msg, type: 'cita' });
  };

  return (
    <BrowserRouter>
      <Header
        user={user}
        onAccessClick={() => setLoginOpen(true)}
        onReservarCita={abrirCita}
        onLogout={handleLogout}
      />

      <main className="app-main">
        <Routes>
          <Route path="/" element={<Inicio onReservarCita={abrirCita} user={user} />} />
          <Route path="/crear-contrasena" element={<CrearContrasena />} />

          {/* Páginas legales */}
          <Route path="/terminos" element={<TerminosCondiciones />} />
          <Route path="/privacidad" element={<PoliticaPrivacidad />} />
          <Route path="/cookies" element={<PoliticaCookies />} />

          {/* Rutas Admin - Protegidas */}
          <Route
            path="/admin/usuarios"
            element={
              <ProtectedRoute requiredRole="admin" user={user} onUnauthorized={showUnauthorizedMessage} isLoading={isLoading}>
                <Usuarios />
              </ProtectedRoute>
            }
          />
          <Route
            path="/admin/notificaciones"
            element={
              <ProtectedRoute requiredRole="admin" user={user} onUnauthorized={showUnauthorizedMessage} isLoading={isLoading}>
                <Notificaciones />
              </ProtectedRoute>
            }
          />
          <Route
            path="/admin/agenda-global"
            element={
              <ProtectedRoute requiredRole="admin" user={user} onUnauthorized={showUnauthorizedMessage} isLoading={isLoading}>
                <AgendaGlobal />
              </ProtectedRoute>
            }
          />
          <Route
            path="/admin/informes"
            element={
              <ProtectedRoute requiredRole="admin" user={user} onUnauthorized={showUnauthorizedMessage} isLoading={isLoading}>
                <InformesYLogs />
              </ProtectedRoute>
            }
          />

          {/* Rutas Profesional - Protegidas */}
          <Route
            path="/profesional/mi-perfil"
            element={
              <ProtectedRoute requiredRole="profesional" user={user} onUnauthorized={showUnauthorizedMessage} isLoading={isLoading}>
                <PerfilProfesional />
              </ProtectedRoute>
            }
          />
          <Route
            path="/profesional/pacientes"
            element={
              <ProtectedRoute requiredRole="profesional" user={user} onUnauthorized={showUnauthorizedMessage} isLoading={isLoading}>
                <PacientesProfesional />
              </ProtectedRoute>
            }
          />
          <Route
            path="/profesional/paciente/:id"
            element={
              <ProtectedRoute requiredRole="profesional" user={user} onUnauthorized={showUnauthorizedMessage} isLoading={isLoading}>
                <PerfilPacienteProfesional />
              </ProtectedRoute>
            }
          />
          <Route
            path="/profesional/agenda"
            element={
              <ProtectedRoute requiredRole="profesional" user={user} onUnauthorized={showUnauthorizedMessage} isLoading={isLoading}>
                <AgendaProfesional />
              </ProtectedRoute>
            }
          />

          {/* Rutas Paciente - Protegidas */}
          <Route
            path="/paciente/mi-perfil"
            element={
              <ProtectedRoute requiredRole="paciente" user={user} onUnauthorized={showUnauthorizedMessage} isLoading={isLoading}>
                <PerfilPaciente />
              </ProtectedRoute>
            }
          />
          <Route
            path="/paciente/mis-citas"
            element={
              <ProtectedRoute requiredRole="paciente" user={user} onUnauthorized={showUnauthorizedMessage} isLoading={isLoading}>
                <CitasPaciente />
              </ProtectedRoute>
            }
          />

          {/* Ruta para páginas no encontradas */}
          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </main>

      {/* Modales */}
      {loginOpen && (
        <LoginModal
          onClose={() => setLoginOpen(false)}
          onLoginSuccess={handleLoginSuccess}
        />
      )}
      {reservarCita && (
        <ReservarCitaModal
          onClose={() => setReservarCita(false)}
          onSuccess={onCitaSuccess}
          onError={onCitaError}
        />
      )}

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