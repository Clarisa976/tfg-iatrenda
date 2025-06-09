import React, { useState, useEffect } from 'react';
import axios from 'axios';
import { ChevronRight, Calendar, CheckCircle, XCircle } from 'lucide-react';
import ModalDetallesCita from '../../components/modals/ModalDetallesCita';
import ModalCitaUniversal from '../../components/modals/ModalCambiarCita';
import ModalConfirmarCancelacion from '../../components/modals/ModalConfirmarCancelacion';
import '../../styles.css';

export default function CitasPaciente() {
  const [citas, setCitas] = useState([]);
  const [loading, setLoading] = useState(true);  const [citaSeleccionada, setCitaSeleccionada] = useState(null);
  const [mostrarDetalles, setMostrarDetalles] = useState(false);
  const [mostrarCambiar, setMostrarCambiar] = useState(false);
  const [mostrarCancelar, setMostrarCancelar] = useState(false);
  const [mostrarNuevaCita, setMostrarNuevaCita] = useState(false);
  const [datosUsuario, setDatosUsuario] = useState(null);
  const [toast, setToast] = useState({ show: false, ok: true, titulo: '', msg: '' });


  useEffect(() => {
    axios.defaults.baseURL = process.env.REACT_APP_API_URL;
    const token = localStorage.getItem('token');
    if (token) axios.defaults.headers.common['Authorization'] = `Bearer ${token}`;
  }, []);
  // Cargar citas
  useEffect(() => {
    cargarCitas();
    cargarDatosUsuario();
  }, []);

  // Cargar datos del usuario
  const cargarDatosUsuario = async () => {
    try {
      const { data } = await axios.get('/pac/perfil');
      if (data.ok) {
        setDatosUsuario(data.data);
      }
    } catch (error) {
      console.error('Error al cargar datos del usuario:', error);
    }
  };

  // Ocultar toast
  useEffect(() => {
    if (!toast.show) return;
    const id = setTimeout(() => setToast(t => ({ ...t, show: false })), 5000);
    return () => clearTimeout(id);
  }, [toast.show]);

  const cargarCitas = async () => {
    try {
      setLoading(true);
      const { data } = await axios.get('/pac/citas');
      if (data.ok) {
        setCitas(data.citas || []);
      }
    } catch (error) {
      console.error('Error al cargar citas:', error);
      setToast({
        show: true,
        ok: false,
        titulo: 'Error',
        msg: 'No se pudieron cargar las citas'
      });
    } finally {
      setLoading(false);
    }
  };
  const formatearFecha = (fechaStr) => {
    const fecha = new Date(fechaStr);
    const dia = fecha.getDate().toString().padStart(2, '0');
    const mes = fecha.toLocaleDateString('es-ES', { month: 'long' });
    const año = fecha.getFullYear();
    return `${dia} ${mes} ${año}`;
  };

  const formatearHora = (fechaStr) => {
    const fecha = new Date(fechaStr);
    return fecha.toLocaleTimeString('es-ES', { 
      hour: '2-digit', 
      minute: '2-digit',
      hour12: false
    });
  };
  /*
  const obtenerEstadoColor = (estado) => {
    switch (estado?.toLowerCase()) {
      case 'confirmada': return '#27ae60';
      case 'pendiente_validacion': return '#f39c12';
      case 'solicitada': return '#3498db';
      case 'cancelada': return '#e74c3c';
      case 'atendida': return '#95a5a6';
      default: return '#6c757d';
    }
  };

  const obtenerEstadoTexto = (estado) => {
    switch (estado?.toLowerCase()) {
      case 'confirmada': return 'Confirmada';
      case 'pendiente_validacion': return 'Pendiente';
      case 'solicitada': return 'Solicitada';
      case 'cancelada': return 'Cancelada';
      case 'atendida': return 'Atendida';
      default: return estado;
    }
  };*/
  const abrirDetalles = (cita) => {
    setCitaSeleccionada(cita);
    setMostrarDetalles(true);
  };

  const abrirNuevaCita = () => {
    setMostrarNuevaCita(true);
  };
  const solicitudExitosa = (mensaje) => {
    setMostrarDetalles(false);
    setMostrarCambiar(false);
    setMostrarCancelar(false);
    setMostrarNuevaCita(false);
    setCitaSeleccionada(null);
    cargarCitas(); 
    setToast({
      show: true,
      ok: true,
      titulo: 'Solicitud enviada',
      msg: mensaje
    });
  };
  const solicitudError = (mensaje) => {
    setToast({
      show: true,
      ok: false,
      titulo: 'Error',
      msg: mensaje
    });
  };

  // Filtrar y ordenar citas
  const citasFuturas = citas
    .filter(cita => {
      const fechaCita = new Date(cita.fecha_hora);
      const ahora = new Date();
      return fechaCita >= ahora && cita.estado !== 'CANCELADA';
    })
    .sort((a, b) => new Date(a.fecha_hora) - new Date(b.fecha_hora));
  return (    <div className="usuarios-container" style={{ maxWidth: '800px' }}>
      <h1 className="usuarios-title">Mis Citas</h1>

      {loading ? (
        <div style={{ textAlign: 'center', padding: '2rem' }}>
          <p>Cargando citas...</p>
        </div>
      ) : citasFuturas.length === 0 ? (
        <div style={{ 
          textAlign: 'center', 
          padding: '3rem',
          backgroundColor: '#f8f9fa',
          borderRadius: '8px',
          margin: '2rem 0'
        }}>
          <Calendar size={48} color="#6c757d" style={{ marginBottom: '1rem' }} />
          <h3 style={{ color: '#6c757d', marginBottom: '1rem' }}>No tienes citas programadas</h3>          <p style={{ color: '#6c757d', marginBottom: '1.5rem' }}>
            Reserva tu primera cita para comenzar tu tratamiento
          </p>
          <button 
            onClick={abrirNuevaCita}
            className="btn-reserva" 
            style={{ margin: '0 auto' }}
          >
            Pedir cita
          </button>
        </div>
      ) : (
        <>
          <div style={{ marginBottom: '2rem' }}>
            {citasFuturas.map((cita) => (
              <div
                key={cita.id_cita}
                onClick={() => abrirDetalles(cita)}
                style={{
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'space-between',
                  padding: '1rem 1.5rem',
                  border: '1px solid #e9ecef',
                  borderRadius: '8px',
                  marginBottom: '1rem',
                  backgroundColor: '#fff',
                  cursor: 'pointer',
                  transition: 'all 0.2s ease',
                  boxShadow: '0 2px 4px rgba(0,0,0,0.05)'
                }}
                onMouseEnter={(e) => {
                  e.target.style.transform = 'translateY(-2px)';
                  e.target.style.boxShadow = '0 4px 12px rgba(0,0,0,0.1)';
                }}
                onMouseLeave={(e) => {
                  e.target.style.transform = 'translateY(0)';
                  e.target.style.boxShadow = '0 2px 4px rgba(0,0,0,0.05)';
                }}
              >                
              <div style={{ 
                  fontWeight: '600', 
                  fontSize: '1.1rem',
                  color: '#2c3e50'
                }}>
                  {formatearFecha(cita.fecha_hora)} - {formatearHora(cita.fecha_hora)}
                </div>

                <ChevronRight size={20} color="#6c757d" />
              </div>
            ))}
          </div>          <div style={{ textAlign: 'center', marginTop: '2rem' }}>
            <button 
              onClick={abrirNuevaCita}
              className="btn-reserva"
            >
              Pedir cita
            </button>
          </div>
        </>
      )}

      {/* Modales */}
      {mostrarDetalles && citaSeleccionada && (
        <ModalDetallesCita
          cita={citaSeleccionada}
          onClose={() => {
            setMostrarDetalles(false);
            setCitaSeleccionada(null);
          }}          onSolicitar={(tipo) => {
            if (tipo === 'CAMBIAR') {
              setMostrarDetalles(false);
              setMostrarCambiar(true);
            } else if (tipo === 'CANCELAR') {
              setMostrarDetalles(false);
              setMostrarCancelar(true);
            }
          }}
        />
      )}        {mostrarCambiar && citaSeleccionada && (
        <ModalCitaUniversal
          modo="cambiar"
          cita={citaSeleccionada}
          datosUsuario={datosUsuario}
          onClose={() => {
            setMostrarCambiar(false);
            setCitaSeleccionada(null);
          }}
          onSuccess={solicitudExitosa}
          onError={solicitudError}
        />
      )}      
      {mostrarNuevaCita && (
        <ModalCitaUniversal
          modo="nueva"
          onClose={() => {
            setMostrarNuevaCita(false);
          }}
          onSuccess={solicitudExitosa}
          onError={solicitudError}
        />
      )}

      {mostrarCancelar && citaSeleccionada && (
        <ModalConfirmarCancelacion
          cita={citaSeleccionada}
          onClose={() => {
            setMostrarCancelar(false);
            setCitaSeleccionada(null);
          }}
          onSuccess={solicitudExitosa}
          onError={solicitudError}
        />
      )}

      {/* Toast */}
      {toast.show && (
        <div className="toast-global centered-toast">
          <div className={`toast-card ${toast.ok ? 'success' : 'error'}`}>
            {toast.ok
              ? <CheckCircle size={48} className="toast-icon success" />
              : <XCircle size={48} className="toast-icon error" />
            }
            <h3 className="toast-title">{toast.titulo}</h3>
            <p className="toast-text">{toast.msg}</p>
          </div>
        </div>
      )}
    </div>
  );
}