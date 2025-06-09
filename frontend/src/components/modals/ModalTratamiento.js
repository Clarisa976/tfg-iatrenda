import React, { useState, useEffect } from 'react';
import axios from 'axios';
import { X } from 'lucide-react';

const isImage = (path) => {
  if (!path) return false;
  const extensions = ['.jpg', '.jpeg', '.png', '.webp', '.gif'];
  return extensions.some(ext => path.toLowerCase().endsWith(ext));
};

export default function ModalTratamiento({ idPac, treat, onClose, onChange }) {
  const API = process.env.REACT_APP_API_URL;
  const tk = localStorage.getItem('token');
  const [showDeleteModal, setShowDeleteModal] = useState(false);
  const [isDeleting, setIsDeleting] = useState(false);
  const [error, setError] = useState('');
  const [documentosConUrls, setDocumentosConUrls] = useState([]);

  // Cargar URLs de documentos cuando se abre el modal
  useEffect(() => {
    const cargarUrls = async () => {
      if (treat.documentos && treat.documentos.length > 0) {
        const docsConUrls = [];
        
        for (const doc of treat.documentos) {
          try {
            // Intentar obtener URL firmada del S3
            const response = await axios.get(
              `${API}/api/s3/documentos/${doc.id_documento}/url`,
              { headers: { Authorization: `Bearer ${tk}` } }
            );
            
            if (response.data.ok && response.data.url) {
              docsConUrls.push({
                ...doc,
                url_visualizacion: response.data.url,
                tiene_url: true
              });
            } else {
              // Si falla S3, usar documento sin URL
              docsConUrls.push({
                ...doc,
                url_visualizacion: null,
                tiene_url: false,
                error_url: 'No se pudo obtener URL'
              });
            }
          } catch (e) {
            console.log(`Error obteniendo URL para documento ${doc.id_documento}:`, e.message);
            docsConUrls.push({
              ...doc,
              url_visualizacion: null,
              tiene_url: false,
              error_url: 'Error de conexión'
            });
          }
        }
        
        setDocumentosConUrls(docsConUrls);
      }
    };

    cargarUrls();
  }, [treat.documentos, API, tk]);

  const del = async () => {
    setIsDeleting(true);
    setError('');

    try {
      // Eliminar todos los documentos asociados usando el endpoint que funciona
      if (treat.documentos && treat.documentos.length > 0) {
        for (const doc of treat.documentos) {
          console.log('Eliminando documento ID:', doc.id_documento);
          try {
            await axios.delete(`${API}/api/s3/documentos/${doc.id_documento}`, {
              headers: { Authorization: `Bearer ${tk}` }
            });
          } catch (docError) {
            console.log(`Error eliminando documento ${doc.id_documento}:`, docError.message);
            // Continuar con otros documentos
          }
        }
      }

      // Eliminar la tarea
      await axios.delete(`${API}/prof/pacientes/${idPac}/tareas/${treat.id_tratamiento}`, {
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
            <div className="tratamiento-info">
              <p><strong>Inicio:</strong>{' '}
                {new Date(treat.fecha_inicio || Date.now()).toLocaleDateString()}</p>
              {treat.fecha_fin && (
                <p><strong>Fin:</strong>{' '}
                  {new Date(treat.fecha_fin).toLocaleDateString()}</p>)}
              {treat.frecuencia_sesiones && (
                <p><strong>Frecuencia:</strong> {treat.frecuencia_sesiones} sesiones</p>)}
            </div>

            <div className="tratamiento-descripcion">
              <h4>Descripción</h4>
              <p>{treat.notas || 'Sin descripción'}</p>
            </div>

            {documentosConUrls.length > 0 && (
              <div className="tratamiento-attachment tratamiento-attachment-section">
                <h4>Archivos adjuntos ({documentosConUrls.length})</h4>
                {documentosConUrls.map((documento, index) => {
                  const isDocImage = isImage(documento.nombre_archivo);

                  return (
                    <div key={documento.id_documento || index} className="documento-item-container">
                      <div className="documento-header">
                        <h5 className="documento-titulo">
                          {documento.nombre_archivo || `Documento ${index + 1}`}
                        </h5>
                        <span className="documento-tipo">{documento.tipo || 'Archivo'}</span>
                      </div>

                      {documento.tiene_url ? (
                        // Si tenemos URL, mostrar contenido
                        isDocImage ? (
                          <div className="image-container image-container-centered">
                            <img
                              src={documento.url_visualizacion}
                              alt={`Adjunto ${index + 1} de la tarea`}
                              className="documento-imagen"
                              onError={(e) => {
                                e.target.style.display = 'none';
                                e.target.parentNode.querySelector('.imagen-error-container').style.display = 'block';
                              }}
                            />
                            <div className="imagen-error-container" style={{ display: 'none' }}>
                              <p>Error al cargar la imagen</p>
                            </div>
                          </div>
                        ) : (
                          <div className="file-link file-link-container">
                            <a
                              href={documento.url_visualizacion}
                              target="_blank"
                              rel="noreferrer"
                              className="file-link-btn"
                            >
                              Descargar {documento.nombre_archivo}
                            </a>
                          </div>
                        )
                      ) : (
                        // Si no tenemos URL, mostrar información del archivo
                        <div className="documento-sin-url">
                          <div className="documento-info">
                            <p><strong>Archivo:</strong> {documento.nombre_archivo}</p>
                            <p><strong>Tipo:</strong> {documento.tipo}</p>
                            <p><strong>Subido:</strong> {new Date(documento.fecha_subida).toLocaleDateString()}</p>
                          </div>
                          <div className="documento-estado">
                            <p className="documento-error-msg">
                              {documento.error_url || 'Archivo almacenado, visualización pendiente'}
                            </p>
                          </div>
                        </div>
                      )}
                    </div>
                  );
                })}
              </div>
            )}

            {treat.documentos && treat.documentos.length === 0 && (
              <div className="sin-documentos">
                <p><em>No hay archivos adjuntos en esta tarea</em></p>
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
              <p className="delete-confirmation-text">Esta acción eliminará la tarea y todos sus archivos adjuntos.</p>
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