import React, { useState } from 'react';
import axios from 'axios';
import { X } from 'lucide-react';

export default function ModalConfirmarCancelacion({ cita, onClose, onSuccess, onError }) {
  const [enviando, setEnviando] = useState(false);
  const confirmarCancelacion = async () => {
    setEnviando(true);
    try {
      const { data } = await axios.post(`/pac/citas/${cita.id_cita}/solicitud`, {
        accion: 'CANCELAR'
      });

      if (data.ok) {
        onSuccess('Tu solicitud de cancelación ha sido enviada correctamente');
      } else {
        onError(data.mensaje || 'Error al enviar la solicitud');
      }
    } catch (error) {
      console.error('Error al cancelar cita:', error);
      onError('Error al enviar la solicitud de cancelación');
    } finally {
      setEnviando(false);
    }
  };
  return (
    <div className="modal-backdrop" onClick={onClose}>
      <div className="modal" onClick={e => e.stopPropagation()} style={{ maxWidth: '450px' }}>
        <div className="modal-header" style={{ justifyContent: 'center' }}>
          <h3>Confirmar cancelación</h3>
          <button className="modal-close" onClick={onClose}><X /></button>
        </div>

        <div className="modal-body">
          <div style={{ textAlign: 'center', marginBottom: '1.5rem' }}>
            <p style={{
              color: 'var(--black)',
              fontSize: '1.1rem',
              marginBottom: '1.5rem'
            }}>
              ¿Está seguro de que quiere cancelar la cita?
            </p>
          </div>
        </div>

        <div className="modal-footer">
          <button
            className="btn-cancel"
            onClick={onClose}
            disabled={enviando}
          >
            Volver
          </button>
          <button
            className="btn-delete"
            onClick={confirmarCancelacion}
            disabled={enviando}
            style={{
              backgroundColor: 'var(--red)',
              color: 'var(--black)',
              opacity: enviando ? 0.6 : 1
            }}
          >
            {enviando ? 'Enviando...' : 'Sí, cancelar'}
          </button>
        </div>
      </div>
    </div>
  );
}