import React from 'react';
import { X } from 'lucide-react';

export default function ModalDetallesCita({ cita, onClose, onSolicitar }) {
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

  const obtenerEstadoTexto = (estado) => {
    switch (estado?.toLowerCase()) {
      case 'confirmada': return 'Confirmada';
      case 'pendiente_validacion': return 'Pendiente de validación';
      case 'solicitada': return 'Solicitada';
      case 'cancelada': return 'Cancelada';
      case 'atendida': return 'Atendida';
      default: return estado;
    }
  };

  const puedeModificar = () => {
    const estadosModificables = ['confirmada', 'pendiente_validacion', 'solicitada'];
    return estadosModificables.includes(cita.estado?.toLowerCase());
  };

  return (
    <div className="modal-backdrop" onClick={onClose}>
      <div className="modal" onClick={e => e.stopPropagation()} style={{ maxWidth: '500px' }}>
        <div className="modal-header" style={{ justifyContent: 'center' }}>
          <h3>Detalles cita</h3>
          <button className="modal-close" onClick={onClose}><X /></button>
        </div>
        
        <div className="modal-body" style={{ textAlign: 'center' }}>
          <div style={{ marginBottom: '1.5rem' }}>
            <div style={{ 
              fontSize: '1.2rem', 
              fontWeight: '600',
              marginBottom: '1rem' 
            }}>
              {formatearFecha(cita.fecha_hora)} - {formatearHora(cita.fecha_hora)}
            </div>

            <div style={{ 
              fontSize: '1.1rem',
              marginBottom: '1.5rem',
              color: 'var(--black)'
            }}>
              Estado: {obtenerEstadoTexto(cita.estado)}
            </div>
          </div>
        </div>

        <div className="modal-footer">
          {puedeModificar() ? (
            <>              <button 
                style={{
                  backgroundColor: 'var(--blue)',
                  color: 'var(--black)'
                }}
                className="btn-save"
                onClick={() => onSolicitar('CAMBIAR')}
              >
                Solicitar cambio
              </button>
              <button 
                style={{
                  backgroundColor: 'var(--red)',
                  color: 'var(--black)'
                }}
                className="btn-cancel"
                onClick={() => onSolicitar('CANCELAR')}
              >
                Solicitar cancelación
              </button>
            </>
          ) : (
            <button className="btn-cancel" onClick={onClose}>
              Cerrar
            </button>
          )}
        </div>
      </div>
    </div>
  );
}