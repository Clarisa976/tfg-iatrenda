import React from 'react';
import { X } from 'lucide-react';
import { useS3Documents } from './useS3Documents'; // Ajusta la ruta según tu estructura

const isImage = (tipo) => {
  if (!tipo) return false;
  return tipo.startsWith('image/');
};

export default function ModalVerTarea({ tarea, onClose }) {
  const { 
    getDocumentUrl, 
    isDocumentLoading, 
    hasDocumentError, 
    downloadDocument 
  } = useS3Documents(tarea?.documentos || []);

  return (
    <div className="modal-backdrop" onClick={onClose}>
      <div className="modal modal-ver-tarea-container" onClick={e => e.stopPropagation()}>
        <div className="modal-header">
          <h3>{tarea.titulo}</h3>
          <button className="modal-close" onClick={onClose}><X /></button>
        </div>
        
        <div className="modal-body">
          <p><strong>Asignado por:</strong> {tarea.profesional_nombre}</p>
          <p><strong>Fecha de asignación:</strong>{' '}
            {new Date(tarea.fecha_asignacion || tarea.fecha_inicio).toLocaleDateString('es-ES')}</p>
          
          {tarea.fecha_inicio && (
            <p><strong>Inicio:</strong>{' '}
              {new Date(tarea.fecha_inicio).toLocaleDateString('es-ES')}</p>
          )}
          
          {tarea.fecha_fin && (
            <p><strong>Fin:</strong>{' '}
              {new Date(tarea.fecha_fin).toLocaleDateString('es-ES')}</p>
          )}
          
          {tarea.frecuencia_sesiones && (
            <p><strong>Frecuencia:</strong> {tarea.frecuencia_sesiones} sesiones por semana</p>
          )}

          <h4>Descripción</h4>
          <p>{tarea.descripcion || tarea.notas || 'Sin descripción disponible'}</p>

          {/* Archivos adjuntos */}
          {tarea.documentos && tarea.documentos.length > 0 && (
            <div className="tratamiento-attachment tarea-info-section">
              <h4>Archivos adjuntos</h4>
              
              {tarea.documentos.map((documento, index) => {
                const docId = documento.id_documento;
                const documentUrl = getDocumentUrl(docId);
                const isLoading = isDocumentLoading(docId);
                const hasError = hasDocumentError(docId);
                const isDocImage = isImage(documento.tipo);

                return (
                  <div key={documento.id_documento || index} className="tarea-documento-item">
                    {isDocImage ? (
                      <div className="imagen-container-center">
                        {isLoading ? (
                          <div className="tarea-imagen-loading">
                            <p>Cargando imagen...</p>
                          </div>
                        ) : hasError || !documentUrl ? (
                          <div className="tarea-imagen-error">
                            <p>No se pudo cargar la imagen</p>
                            <button 
                              className="btn-primary btn-small"
                              onClick={() => downloadDocument(documento)}
                              style={{ marginTop: '10px' }}
                            >
                              Descargar archivo
                            </button>
                          </div>
                        ) : (
                          <img
                            src={documentUrl}
                            alt={`Adjunto ${index + 1} de la tarea`}
                            className="tarea-imagen-preview"
                            onError={(e) => {
                              console.error('Error al cargar imagen desde S3:', documentUrl);
                              e.target.style.display = 'none';
                              e.target.nextSibling.style.display = 'block';
                            }}
                          />
                        )}
                        
                        {/* Div de error que se muestra si falla la imagen */}
                        <div className="tarea-imagen-error" style={{ display: 'none' }}>
                          <p>Error al visualizar la imagen</p>
                          <button 
                            className="btn-primary btn-small"
                            onClick={() => downloadDocument(documento)}
                            style={{ marginTop: '10px' }}
                          >
                            Descargar archivo
                          </button>
                        </div>
                      </div>
                    ) : (
                      <div className="tarea-file-link-container">
                        {isLoading ? (
                          <p>Cargando documento...</p>
                        ) : (
                          <div>
                            <p><strong>Archivo:</strong> {documento.nombre_archivo || 'Documento'}</p>
                            <p><strong>Tipo:</strong> {documento.tipo || 'Desconocido'}</p>
                            <button
                              className="tarea-download-link btn-primary"
                              onClick={() => downloadDocument(documento)}
                              disabled={hasError}
                            >
                              {hasError ? 'Error al cargar' : 'Descargar archivo'}
                            </button>
                          </div>
                        )}
                      </div>
                    )}
                  </div>
                );
              })}
            </div>
          )}
          
          {/* Mensaje si no hay archivos */}
          {(!tarea.documentos || tarea.documentos.length === 0) && (
            <div className="tarea-sin-archivos-container">
              <p className="tarea-sin-archivos-texto">
                Esta tarea no tiene materiales adjuntos
              </p>
            </div>
          )}
        </div>

        {/* Footer simplificado - solo cerrar */}
        <div className="modal-footer">
          <button className="btn-cancel" onClick={onClose}>
            Cerrar
          </button>
        </div>
      </div>
    </div>
  );
}