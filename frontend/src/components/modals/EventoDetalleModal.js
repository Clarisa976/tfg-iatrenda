import React from 'react';
import '../../styles.css';

export default function EventoDetalleModal({ open, toggle, event, onDelete }) {
  if (!open || !event) return null;

  return (
    <div className="modal-backdrop" onClick={toggle}>
      <div className="modal" onClick={e => e.stopPropagation()}>
        <div className="modal-header">
          <h5>Detalle del evento</h5>
          <button type="button" className="modal-close" onClick={toggle}>×</button>
        </div>

        <div className="modal-body">
          <p><strong>Tipo:</strong> {event.tipo}</p>
          <p><strong>Inicio:</strong> {event.start.toLocaleString()}</p>
          <p><strong>Fin:</strong> {event.end.toLocaleString()}</p>
          <p><strong>Profesional:</strong> {event.profNombre || 'Todos'}</p>
          <p><strong>Creado por:</strong> {event.creadorNombre || '—'}</p>
          <p><strong>Comentario:</strong> {event.nota || '—'}</p>
        </div>

        <div className="modal-footer">
          <button className="btn-primary" onClick={() => onDelete(event.id)}>Eliminar</button>
          <button className="btn-secondary" onClick={toggle}>Cerrar</button>
        </div>
      </div>
    </div>
  );
}
