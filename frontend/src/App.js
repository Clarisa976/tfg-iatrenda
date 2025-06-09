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
      
      if (!token) {
        setIsLoading(false);
        return;
      }

      try {
        // Configurar el token en axios
        axios.defaults.headers.common['Authorization'] = `Bearer ${token}`;
        
        // Verificar el token con el backend usando un endpoint existente
        const response = await axios.get('/consentimiento');
        
        if (response.data && response.data.ok) {
          // Necesitamos obtener los datos del usuario. Vamos a usar el token renovado si existe
          if (response.data.token) {
            localStorage.setItem('token', response.data.token);
            axios.defaults.headers.common['Authorization'] = `Bearer ${response.data.token}`;
          }
          
          // Para obtener datos completos del usuario, haremos otra llamada
          // según el tipo de usuario. Primero intentamos como paciente.
          try {
            const perfilResponse = await axios.get('/pac/perfil');
            if (perfilResponse.data && perfilResponse.data.ok) {
              setUser({
                ...perfilResponse.data.datos.persona,
                role: 'paciente',
                rol: 'paciente'
              });
              return;
            }
          } catch (err) {
            // No es paciente, probamos profesional
            try {
              const perfilResponse = await axios.get('/prof/perfil');
              if (perfilResponse.data && perfilResponse.data.ok) {
                setUser({
                  ...perfilResponse.data.data.persona,
                  role: 'profesional',
                  rol: 'profesional'
                });
                return;
              }
            } catch (err2) {
              // No es profesional, probamos admin
              try {
                const adminResponse = await axios.get('/admin/usuarios');
                if (adminResponse.data && adminResponse.data.ok) {
                  // Para admin, necesitamos hacer una llamada adicional para obtener sus datos
                  // pero sabemos que es admin si llegó hasta aquí
                  setUser({
                    id_persona: 1, // temporal
                    role: 'admin',
                    rol: 'admin',
                    nombre: 'Administrador'
                  });
                  return;
                }
              } catch (err3) {
                // Token válido pero no podemos determinar el rol, limpiamos
                cleanupSession();
              }
            }
          }
        } else {
          // Token inválido, limpiar
          cleanupSession();
        }
      } catch (error) {
        console.error('Error al verificar el token:', error);
        // Token inválido o expirado, limpiar
        cleanupSession();
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
    delete axios.defaults.headers.common['Authorization'];
    setUser(null);
  };

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