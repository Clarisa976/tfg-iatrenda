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
          {documentos && documentos.length > 0 ? (<div>
              <p><strong>Total de documentos:</strong> {documentos.length}</p>
              
              <div className="ver-historial-documentos-info">
                {documentos.map((documento, index) => {
                  const docFileUrl = getFileUrl(documento.ruta);
                  const isDocImage = isImage(documento.ruta);

                  return (
                  <div 
                      key={documento.id_documento || index} 
                      className="ver-historial-documento-item"
                    >
                      <div className="ver-historial-documento-info">
                        <p><strong>Fecha:</strong> {new Date(documento.fecha_subida).toLocaleDateString('es-ES')}</p>
                        <p><strong>Profesional:</strong> {documento.profesional_nombre}</p>
                        {documento.diagnostico_preliminar && (<p><strong>Diagnóstico:</strong> {documento.diagnostico_preliminar}</p>
                        )}
                      </div>                      
                      {isDocImage ? (
                        <div className="ver-historial-imagen-container">
                          <img
                            src={docFileUrl}
                            alt="Documento del historial"
                            className="ver-historial-documento-imagen"
                            onError={(e) => {
                              console.error('Error al cargar imagen:', docFileUrl);
                              e.target.style.display = 'none';
                            }}
                          />
                        </div>
                      ) : null}                      
                      <div className="ver-historial-descarga-container">
                        <button
                          onClick={() => descargarDocumento(documento)}
                          className="ver-historial-descarga-btn"
                        >Descargar
                        </button>
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>          ) : (
            <div className="ver-historial-vacio">
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