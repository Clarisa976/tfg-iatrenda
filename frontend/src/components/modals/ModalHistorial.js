import React from 'react';
import { X, Download } from 'lucide-react';

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

export default function ModalHistorial({ documentos, onClose }) {
  const descargarDocumento = (documento) => {
    const url = getFileUrl(documento.ruta);
    const link = document.createElement('a');
    link.href = url;
    link.download = documento.nombre_archivo || 'documento';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
  };

  return (    <div className="modal-backdrop" onClick={onClose}>
      <div className="modal modal-historial-wide" onClick={e => e.stopPropagation()}>
        <div className="modal-header">
          <h3>Mi Historial Clínico</h3>
          <button className="modal-close" onClick={onClose}><X /></button>
        </div>
        
        <div className="modal-body">
          {documentos && documentos.length > 0 ? (
            <div>
              <p><strong>Total de documentos:</strong> {documentos.length}</p>
                <div className="historial-documentos-section">
                {documentos.map((documento, index) => {
                  const docFileUrl = getFileUrl(documento.ruta);
                  const isDocImage = isImage(documento.ruta);

                  return (
                    <div 
                      key={documento.id_documento || index} 
                      className="historial-documento-item"
                    >
                      <div className="historial-documento-info">
                        <p><strong>Fecha:</strong> {new Date(documento.fecha_subida).toLocaleDateString('es-ES')}</p>
                        <p><strong>Profesional:</strong> {documento.profesional_nombre}</p>
                        {documento.diagnostico_preliminar && (
                          <p><strong>Diagnóstico:</strong> {documento.diagnostico_preliminar}</p>
                        )}
                      </div>                      {isDocImage ? (
                        <div className="historial-imagen-container">
                          <img
                            src={docFileUrl}
                            alt="Documento del historial"
                            className="historial-documento-imagen"
                            onError={(e) => {
                              console.error('Error al cargar imagen:', docFileUrl);
                              e.target.style.display = 'none';
                            }}
                          />
                        </div>
                      ) : null}

                      <div className="historial-download-container">
                        <button
                          onClick={() => descargarDocumento(documento)}
                          className="historial-download-btn"
                        >
                          <Download size={16} />
                          Descargar
                        </button>
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>
          ) : (
            <div style={{ 
              textAlign: 'center', 
              padding: '40px',
              color: '#666'
            }}>
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