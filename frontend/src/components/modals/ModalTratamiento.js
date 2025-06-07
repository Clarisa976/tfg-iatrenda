import React, { useState } from 'react';
import axios from 'axios';
import { X } from 'lucide-react';

// Detecta si la ruta es una imagen
const isImage = (path) => {
  if (!path) return false;
  const extensions = ['.jpg', '.jpeg', '.png', '.webp'];
  return extensions.some(ext => path.toLowerCase().endsWith(ext));
};

// Construye la URL correcta para el archivo
const getFileUrl = (path) => {
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

  const finalUrl = `${baseUrl}/uploads/${fileName}?t=${Date.now()}`;
  return finalUrl;
};

export default function ModalTratamiento({ idPac, treat, onClose, onChange }) {
  const tk = localStorage.getItem('token');
  const [showDeleteModal, setShowDeleteModal] = useState(false);
  const [isDeleting, setIsDeleting] = useState(false);
  const [error, setError] = useState('');

  const del = async () => {
    setIsDeleting(true);
    setError('');
    try {
      await axios.delete(`/prof/pacientes/${idPac}/tareas/${treat.id_tratamiento}`, {
        headers: { Authorization: `Bearer ${tk}` }
      });
      onChange();
      onClose();
    } catch (e) {
      console.error('Error al eliminar:', e);
      setError('Error al eliminar la tarea. Inténtalo de nuevo.');
      setIsDeleting(false);
    }
  };

  return (
    <>
      <div className="modal-backdrop" onClick={onClose}>
        <div className="modal" onClick={e => e.stopPropagation()} style={{ maxWidth: '600px' }}>
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

            {/* Sección de adjuntos múltiples */}
            {treat.documentos && treat.documentos.length > 0 && (
              <div className="tratamiento-attachment" style={{ marginTop: '20px' }}>
                <h4>📎 Archivos adjuntos ({treat.documentos.length})</h4>
                {treat.documentos.map((documento, index) => {
                  const docFileUrl = getFileUrl(documento.ruta);
                  const isDocImage = isImage(documento.ruta);

                  return (
                    <div key={documento.id_documento || index} style={{ marginBottom: '15px', padding: '10px', border: '1px solid #eee', borderRadius: '4px' }}>
                      <h5 style={{ margin: '0 0 10px 0', color: '#333' }}>
                        {documento.nombre_archivo || `Documento ${index + 1}`}
                      </h5>

                      {isDocImage ? (
                        <div className="image-container" style={{ textAlign: 'center' }}>
                          <img
                            src={docFileUrl}
                            alt={`Adjunto ${index + 1} de la tarea`}
                            style={{
                              maxWidth: '100%',
                              maxHeight: '300px',
                              border: '1px solid #ddd',
                              borderRadius: '4px',
                              display: 'block',
                              margin: '10px auto'
                            }}
                            onError={(e) => {
                              console.error('Error al cargar imagen:', docFileUrl);
                              e.target.style.display = 'none';
                              e.target.nextSibling.style.display = 'block';
                            }}
                          />
                          <div style={{
                            display: 'none',
                            border: '1px dashed #f44336',
                            padding: '15px',
                            borderRadius: '4px',
                            backgroundColor: '#ffeaa7'
                          }}>
                            <p>No se pudo visualizar la imagen</p>
                            <p><small>Ruta: {documento.ruta}</small></p>
                          </div>
                        </div>
                      ) : (
                        <div className="file-link" style={{ textAlign: 'center', margin: '15px 0' }}>
                          <a
                            href={docFileUrl}
                            target="_blank"
                            rel="noreferrer"
                            style={{
                              background: '#4a90e2',
                              color: 'white',
                              padding: '8px 15px',
                              borderRadius: '4px',
                              textDecoration: 'none',
                              display: 'inline-block'
                            }}
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

      {/* Modal de confirmación de eliminación - renderizado por separado con z-index mayor */}
      {showDeleteModal && (
        <div className="modal-backdrop" onClick={() => setShowDeleteModal(false)} style={{ zIndex: 10000 }}>
          <div className="modal" onClick={e => e.stopPropagation()} style={{ maxWidth: '400px' }}>
            <div className="modal-header">
              <h3>Confirmar eliminación</h3>
            </div>
            <div className="modal-body">
              <p>¿Estás seguro de que quieres eliminar esta tarea?</p>
              <p><strong>{treat.titulo || 'Sin título'}</strong></p>
              <p style={{ color: '#666', fontSize: '0.9em' }}>Esta acción no se puede deshacer.</p>
              {error && (
                <div style={{
                  background: '#fee',
                  border: '1px solid #fcc',
                  padding: '10px',
                  borderRadius: '4px',
                  marginTop: '10px',
                  color: '#c33'
                }}>
                  {error}
                </div>
              )}
            </div>
            <div className="modal-footer">
              <button
                className="btn-cancel"
                onClick={() => setShowDeleteModal(false)}
                disabled={isDeleting}
              >
                Cancelar
              </button>
              <button
                className="btn-delete"
                onClick={del}
                disabled={isDeleting}
                style={{ opacity: isDeleting ? 0.6 : 1 }}
              >
                {isDeleting ? 'Eliminando...' : 'Eliminar'}
              </button>
            </div>
          </div>
        </div>
      )}
    </>  );
}
