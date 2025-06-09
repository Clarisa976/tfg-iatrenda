import React from 'react';
import { X } from 'lucide-react';

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
        </div>        <div className="modal-body">
          {documentos && documentos.length > 0 ? (
            <div>
              <p className="historial-summary"><strong>Total de documentos:</strong> {documentos.length}</p>
              
              <div className="historial-simple-cards">
                {documentos.map((documento, index) => (
                  <div 
                    key={documento.id_documento || index} 
                    className="historial-simple-card"
                  >
                    <div className="historial-simple-info">
                      <div className="historial-simple-date">
                        {new Date(documento.fecha_subida).toLocaleDateString('es-ES')}
                      </div>
                      <div className="historial-simple-doctor">
                        Dr/a: {documento.profesional_nombre}
                      </div>
                    </div>
                    <button
                      onClick={() => descargarDocumento(documento)}
                      className="historial-simple-download"
                    >
                      Descargar
                    </button>
                  </div>
                ))}
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