import React from 'react';
import { X } from 'lucide-react';

const isImage = tipo => tipo?.startsWith('image/');

export default function ModalVerTarea({ tarea, onClose, onDone }) {
  const downloadDocument = async doc => {
    const tk = localStorage.getItem('token');
    const res = await fetch(`${process.env.REACT_APP_API_URL}/api/s3/download/${doc.id_documento}`, {
      method: 'GET',
      headers: { 'Authorization': `Bearer ${tk}` }
    });
    if (res.redirected) return window.open(res.url, '_blank');
    if (res.ok) {
      const blob = await res.blob();
      const url = window.URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = doc.nombre_archivo || 'documento';
      link.click();
      URL.revokeObjectURL(url);
    } else {
      throw new Error('No se pudo descargar');
    }
  };

  const handleDelete = async id => {
    if (!window.confirm('¿Eliminar este documento?')) return;
    try {
      const tk = localStorage.getItem('token');
      const res = await fetch(`${process.env.REACT_APP_API_URL}/api/s3/documentos/${id}`, {
        method: 'DELETE',
        headers: { 'Authorization': `Bearer ${tk}` }
      });
      const body = await res.json();
      if (!res.ok) throw new Error(body.mensaje || res.statusText);
      onDone(); // refresca lista
    } catch (e) {
      alert('Error al eliminar: ' + e.message);
    }
  };

  return (
    <div className="modal-backdrop" onClick={onClose}>
      <div className="modal modal-ver-tarea-container" onClick={e=>e.stopPropagation()}>
        <div className="modal-header">
          <h3>{tarea.titulo}</h3>
          <button onClick={onClose}><X /></button>
        </div>
        <div className="modal-body">
          {/* … tus campos de fecha, descripción, etc. … */}

          {tarea.documentos?.length > 0 ? (
            <div className="tratamiento-attachment tarea-info-section">
              <h4>Archivos adjuntos</h4>
              {tarea.documentos.map(doc => (
                <div key={doc.id_documento} className="tarea-documento-item">
                  {isImage(doc.tipo) ? (
                    <>
                      {doc.url_descarga ? (
                        <img
                          src={doc.url_descarga}
                          alt={doc.nombre_archivo}
                          className="tarea-imagen-preview"
                          onError={e => e.currentTarget.style.display = 'none'}
                        />
                      ) : (
                        <p>Vista previa no disponible</p>
                      )}
                    </>
                  ) : (
                    <p>{doc.nombre_archivo}</p>
                  )}
                  <button onClick={() => downloadDocument(doc)}>Descargar</button>
                  <button onClick={() => handleDelete(doc.id_documento)}>Eliminar</button>
                </div>
              ))}
            </div>
          ) : (
            <p>Esta tarea no tiene materiales adjuntos</p>
          )}
        </div>
        <div className="modal-footer">
          <button onClick={onClose}>Cerrar</button>
        </div>
      </div>
    </div>
  );
}
