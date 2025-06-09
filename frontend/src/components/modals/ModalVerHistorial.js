import React from 'react';
import { X } from 'lucide-react';

export default function ModalVerHistorial({ documentos, onClose }) {
  const API = process.env.REACT_APP_API_URL;
  const tk = localStorage.getItem('token');

  const descargarDocumento = async (documento) => {
    try {
      const downloadUrl = `${API}/api/s3/download/${documento.id_documento}`;

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