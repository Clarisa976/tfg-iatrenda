import React from 'react';
import '../../styles.css';

export default function ConfirmacionEliminacionModal({ open, toggle, onConfirm, message }) {
  if (!open) return null;
  return (
    <div className="modal-backdrop" onClick={toggle}>
      <div className="modal" onClick={e=>e.stopPropagation()}>
        <div className="modal-header">
          <h5>Confirmar</h5>
          <button className="modal-close" onClick={toggle}>×</button>
        </div>
        <div className="modal-body">
          <p>{message}</p>
        </div>
        <div className="modal-footer">
          <button className="btn-cancel" onClick={toggle}>Cancelar</button>
          <button className="btn-save"   onClick={()=>{ onConfirm(); }}>Eliminar</button>
        </div>
      </div>
    </div>
  );
}
