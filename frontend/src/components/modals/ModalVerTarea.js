import React from 'react';
import { X } from 'lucide-react';


const isImage = (path) => {
  if (!path) return false;
  const extensions = ['.jpg', '.jpeg', '.png', '.webp'];
  return extensions.some(ext => path.toLowerCase().endsWith(ext));
};


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

export default function ModalVerTarea({ tarea, onClose }) {
  return (    <div className="modal-backdrop" onClick={onClose}>
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
          <p>{tarea.descripcion || 'Sin descripción disponible'}</p>

          {/* Archivos adjuntos */}
          {tarea.documentos && tarea.documentos.length > 0 && (<div className="tratamiento-attachment tarea-info-section">
              <h4>Archivos adjuntos</h4>
              
              {tarea.documentos.map((documento, index) => {
                const docFileUrl = getFileUrl(documento.ruta);
                const isDocImage = isImage(documento.ruta);

                return (
                  <div key={documento.id_documento || index} className="tarea-documento-item">
                    

                    {isDocImage ? (
                      <div className="imagen-container-center">                       
                       <img
                          src={docFileUrl}
                          alt={`Adjunto ${index + 1} de la tarea`}
                          className="tarea-imagen-preview"
                          onError={(e) => {
                            console.error('Error al cargar imagen:', docFileUrl);
                            e.target.style.display = 'none';
                            e.target.nextSibling.style.display = 'block';
                          }}
                        />
                        <div className="tarea-imagen-error">
                          <p>No se pudo visualizar la imagen</p>
                          <p><small>Ruta: {documento.ruta}</small></p>
                        </div>
                      </div>
                    ) : ( <div className="tarea-file-link-container">                        
                    <a
                          href={docFileUrl}
                          target="_blank"
                          rel="noreferrer"
                          className="tarea-download-link"
                        >
                          {documento.nombre_archivo = 'Ver archivo'}
                        </a>
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