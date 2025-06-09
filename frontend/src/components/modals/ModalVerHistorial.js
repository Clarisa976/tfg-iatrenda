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

  let baseUrl;
  if (process.env.REACT_APP_API_URL) {
    baseUrl = process.env.REACT_APP_API_URL.replace(/\/$/, '');
  } else if (window.location.hostname === 'localhost') {
    baseUrl = 'http://localhost:8081';
  } else {
    baseUrl = window.location.origin;
  }


  const finalUrl = `${baseUrl}/${path}?t=${Date.now()}`;
  return finalUrl;
};

export default function ModalVerHistorial({ documentos, onClose }) {
  const descargarDocumento = async (documento) => {
    try {
      const url = getFileUrl(documento.ruta);
      

      const response = await fetch(url);
      if (!response.ok) throw new Error('Error al descargar el archivo');
      
      const blob = await response.blob();
      
      const downloadUrl = window.URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = downloadUrl;
      link.download = documento.nombre_archivo || 'documento';
      
      document.body.appendChild(link);
      link.click();
      
      document.body.removeChild(link);
      window.URL.revokeObjectURL(downloadUrl);
      
    } catch (error) {
      console.error('Error al descargar:', error);
      window.open(getFileUrl(documento.ruta), '_blank');
    }
  };

  return (
  <div className="modal-backdrop" onClick={onClose}>
      <div className="modal modal-ver-historial-wide" onClick={e => e.stopPropagation()}>
        <div className="modal-header">
          <h3>Mi Historial Clínico</h3>
          <button className="modal-close" onClick={onClose}><X /></button>
        </div>
          <div className="modal-body">
          {documentos && documentos.length > 0 ? (
            <div>
              <p className="historial-summary"><strong>Total de documentos:</strong> {documentos.length}</p>
              
              <div className="historial-cards-container">
                {documentos.map((documento, index) => {
                  const docFileUrl = getFileUrl(documento.ruta);
                  const isDocImage = isImage(documento.ruta);

                  return (
                    <div 
                      key={documento.id_documento || index} 
                      className="historial-document-card"
                    >
                      <div className="historial-card-header">
                        <div className="historial-card-date">
                          {new Date(documento.fecha_subida).toLocaleDateString('es-ES')}
                        </div>
                        <button
                          onClick={() => descargarDocumento(documento)}
                          className="historial-download-btn"
                          title="Descargar documento"
                        >
                          Descargar
                        </button>
                      </div>
                      
                      <div className="historial-card-content">
                        <div className="historial-card-info">
                          <p className="historial-profesional">
                            <strong>Dr/a:</strong> {documento.profesional_nombre}
                          </p>
                          {documento.diagnostico_preliminar && (
                            <p className="historial-diagnostico">
                              <strong>Diagnóstico:</strong> {documento.diagnostico_preliminar}
                            </p>
                          )}
                          <p className="historial-filename">
                            <strong>Archivo:</strong> {documento.nombre_archivo || 'documento.pdf'}
                          </p>
                        </div>
                        
                        {isDocImage && (
                          <div className="historial-image-preview">
                            <img
                              src={docFileUrl}
                              alt="Vista previa del documento"
                              className="historial-preview-img"
                              onError={(e) => {
                                console.error('Error al cargar imagen:', docFileUrl);
                                e.target.style.display = 'none';
                                e.target.nextElementSibling.style.display = 'block';
                              }}
                            />
                            <div className="historial-image-error" style={{ display: 'none' }}>
                              <p>Vista previa no disponible</p>
                            </div>
                          </div>
                        )}
                        
                        {!isDocImage && (
                          <div className="historial-file-placeholder">
                            <div className="file-type-indicator">
                              {documento.nombre_archivo 
                                ? documento.nombre_archivo.split('.').pop().toUpperCase() 
                                : 'DOC'
                              }
                            </div>
                          </div>
                        )}
                      </div>
                      
                      <div className="historial-card-actions">
                        <button
                          onClick={() => descargarDocumento(documento)}
                          className="historial-action-btn primary"
                        >
                          Descargar
                        </button>
                        {isDocImage && (
                          <button
                            onClick={() => window.open(docFileUrl, '_blank')}
                            className="historial-action-btn secondary"
                          >
                            Ver completo
                          </button>
                        )}
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>
          ) : (
            <div className="historial-empty-state">
              <h4>Sin documentos</h4>
              <p>No tienes documentos en tu historial clínico</p>
            </div>
          )}
        </div>

        <div className="modal-footer">
          <button className="btn-cancel" onClick={onClose}>
            Cerrar
          </button>
        </div>
      </div>
    </div>
  );
}