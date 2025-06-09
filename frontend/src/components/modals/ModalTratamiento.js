import React, { useState } from 'react';
import axios from 'axios';
import { X } from 'lucide-react';

const isImage = (path) => {
  if (!path) return false;
  const extensions = ['.jpg', '.jpeg', '.png', '.webp'];
  return extensions.some(ext => path.toLowerCase().endsWith(ext));
};

const getFileUrl = (documento) => {
  if (documento.url_descarga && documento.url_temporal) {
    return documento.url_descarga;
  }

  const path = documento.ruta;
  if (!path) return '';
  if (path.startsWith('http')) return path;

  const cleanPath = path.startsWith('/') ? path.substring(1) : path;
  const fileName = cleanPath.split('/').pop();

  let baseUrl;
  if (process.env.REACT_APP_API_URL) {
    baseUrl = process.env.REACT_APP_API_URL.replace(/\/$/, '');
  } else if (window.location.hostname === 'localhost') {
    baseUrl = 'http://localhost:8081';
  } else {
    baseUrl = window.location.origin;
  }

  return `${baseUrl}/uploads/${fileName}?t=${Date.now()}`;
};

export default function ModalTratamiento({ idPac, treat, onClose, onChange }) {
  const tk = localStorage.getItem('token');
  const [showDeleteModal, setShowDeleteModal] = useState(false);
  const [isDeleting, setIsDeleting] = useState(false);
  const [error, setError] = useState('');

const del = async () => {
  setIsDeleting(true);
  setError('');
for (const doc of treat.documentos) {
  console.log('Intentando eliminar documento ID:', doc.id_documento);
  await axios.delete(`/api/s3/documentos/${doc.id_documento}`, {
    headers: { Authorization: `Bearer ${tk}` }
  });
}

  try {
    // Eliminar todos los documentos asociados
    if (treat.documentos && treat.documentos.length > 0) {
      for (const doc of treat.documentos) {
        await axios.delete(`/api/s3/documentos/${doc.id_documento}`, {
          headers: { Authorization: `Bearer ${tk}` }
        });
      }
    }

    // Eliminar la tarea
    await axios.delete(`/prof/pacientes/${idPac}/tareas/${treat.id_tratamiento}`, {
      headers: { Authorization: `Bearer ${tk}` }
    });

    onChange();
    onClose();
  } catch (e) {
    console.error('Error al eliminar:', e.response || e);
    setError('Error al eliminar la tarea. Inténtalo de nuevo.');
    setIsDeleting(false);
  }
};


  return (
    <>
      <div className="modal-backdrop" onClick={onClose}>
        <div className="modal modal-tratamiento-wide" onClick={e => e.stopPropagation()}>
          <div className="modal-header">
            <h3>{treat.titulo || 'Sin título'}</h3>
            <button className="modal-close" onClick={onClose}><X /></button>
          </div>
          <div className="modal-body">
            <p><strong>Inicio:</strong>{' '}
              {new Date(treat.fecha_inicio || Date.now()).toLocaleDateString()}</p>
            {treat.fecha_fin && (
              <p><strong>Fin:</strong>{' '}
                {new Date(treat.fecha_fin).toLocaleDateString()}</p>)}
            {treat.frecuencia_sesiones && (
              <p><strong>Frecuencia:</strong> {treat.frecuencia_sesiones} /semana</p>)}
            <h4>Descripción</h4>
            <p>{treat.notas || 'Sin descripción'}</p>

            {treat.documentos && treat.documentos.length > 0 && (
              <div className="tratamiento-attachment tratamiento-attachment-section">
                <h4>Archivos adjuntos</h4>
                {treat.documentos.map((documento, index) => {
                  const docFileUrl = getFileUrl(documento);
                  const isDocImage = isImage(documento.ruta);

                  return (
                    <div key={documento.id_documento || index} className="documento-item-container">
                      <h5 className="documento-titulo">
                        {documento.nombre_archivo || `Documento ${index + 1}`}
                      </h5>

                      {isDocImage ? (
                        <div className="image-container image-container-centered">
                          <img
                            src={docFileUrl}
                            alt={`Adjunto ${index + 1} de la tarea`}
                            className="documento-imagen"
                            onError={(e) => {
                              e.target.style.display = 'none';
                              e.target.parentNode.querySelector('.imagen-error-container').style.display = 'block';
                            }}
                          />
                          <div className="imagen-error-container" style={{ display: 'none' }}>
                            <p>No se pudo visualizar la imagen</p>
                            <p><small>Ruta: {documento.ruta}</small></p>
                          </div>
                        </div>
                      ) : (
                        <div className="file-link file-link-container">
                          <a
                            href={docFileUrl}
                            target="_blank"
                            rel="noreferrer"
                            className="file-link-btn"
                          >Ver archivo: {documento.nombre_archivo || 'Documento'}
                          </a>
                        </div>
                      )}
                    </div>
                  );
                })}
              </div>
            )}
          </div>

          <div className="modal-footer">
            <button className="btn-delete" onClick={() => setShowDeleteModal(true)}>
              Eliminar
            </button>
            <button className="btn-cancel" onClick={onClose}>Cerrar</button>
          </div>
        </div>
      </div>

      {showDeleteModal && (
        <div className="modal-backdrop modal-delete-confirmation" onClick={() => setShowDeleteModal(false)}>
          <div className="modal modal-delete-small" onClick={e => e.stopPropagation()}>
            <div className="modal-header">
              <h3>Confirmar eliminación</h3>
            </div>
            <div className="modal-body">
              <p>¿Estás seguro de que quieres eliminar esta tarea?</p>
              <p><strong>{treat.titulo || 'Sin título'}</strong></p>
              <p className="delete-confirmation-text">Esta acción no se puede deshacer.</p>
              {error && <div className="delete-error-message">{error}</div>}
            </div>
            <div className="modal-footer">
              <button className="btn-cancel" onClick={() => setShowDeleteModal(false)} disabled={isDeleting}>
                Cancelar
              </button>
              <button className={`btn-delete ${isDeleting ? 'btn-deleting' : ''}`} onClick={del} disabled={isDeleting}>
                {isDeleting ? 'Eliminando...' : 'Eliminar'}
              </button>
            </div>
          </div>
        </div>
      )}
    </>
  );
}
