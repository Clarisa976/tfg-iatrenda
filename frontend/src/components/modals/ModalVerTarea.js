import React from 'react';
import { X } from 'lucide-react';

const isImage = (tipo) => {
  if (!tipo) return false;
  return tipo.startsWith('image/');
};

export default function ModalVerTarea({ tarea, onClose }) {
  
const downloadDocument = async (documento) => {
  try {
    const tk = localStorage.getItem('token');
    const downloadUrl = `${process.env.REACT_APP_API_URL}/api/s3/download/${documento.id_documento}`;
    
    // Hacer fetch con headers de autorización
    const response = await fetch(downloadUrl, {
      method: 'GET',
      headers: {
        'Authorization': `Bearer ${tk}`
      }
    });
    
    if (response.redirected) {
      // Si el servidor redirige a S3, abrir esa URL
      window.open(response.url, '_blank');
    } else if (response.ok) {
      // Si devuelve el archivo directamente, crear blob y descargar
      const blob = await response.blob();
      const url = window.URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = documento.nombre_archivo || 'documento';
      link.click();
      window.URL.revokeObjectURL(url);
    } else {
      throw new Error('Error al acceder al documento');
    }
    
  } catch (error) {
    console.error('Error al descargar documento:', error);
    alert('Error al descargar el documento');
  }
};

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
                const isDocImage = isImage(documento.tipo);
                
                // Usar URL de S3 si está disponible, sino mostrar solo descarga
                const imageUrl = documento.url_descarga && documento.url_temporal ? documento.url_descarga : null;

                return (
                  <div key={documento.id_documento || index} className="tarea-documento-item">
                    {isDocImage ? (
                      <div className="imagen-container-center">
                        {imageUrl ? (
                          <img
                            src={imageUrl}
                            alt={`Adjunto ${index + 1} de la tarea`}
                            className="tarea-imagen-preview"
                            onError={(e) => {
                              console.error('Error al cargar imagen desde S3:', imageUrl);
                              e.target.style.display = 'none';
                              e.target.nextSibling.style.display = 'block';
                            }}
                          />
                        ) : (
                          <div className="tarea-imagen-error">
                            <p>Vista previa no disponible</p>
                          </div>
                        )}
                        
                        {/* Div de error que se muestra si falla la imagen */}
                        <div className="tarea-imagen-error" style={{ display: 'none' }}>
                          <p>Error al visualizar la imagen</p>
                        </div>
                        
                        <button 
                          className="btn-primary btn-small"
                          onClick={() => downloadDocument(documento)}
                          style={{ marginTop: '10px' }}
                        >
                          Descargar archivo
                        </button>
                      </div>
                    ) : (
                      <div className="tarea-file-link-container">
                        <div>
                          <p><strong>Archivo:</strong> {documento.nombre_archivo || 'Documento'}</p>
                          <p><strong>Tipo:</strong> {documento.tipo || 'Desconocido'}</p>
                          <button
                            className="tarea-download-link btn-primary"
                            onClick={() => downloadDocument(documento)}
                          >
                            Descargar archivo
                          </button>
                        </div>
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

        {/* Footer simplificado */}
        <div className="modal-footer">
          <button className="btn-cancel" onClick={onClose}>
            Cerrar
          </button>
        </div>
      </div>
    </div>
  );
}