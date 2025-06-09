import React, { useState, useEffect } from 'react';
import axios from 'axios';
import { X } from 'lucide-react';

const isImage = (tipo) => {
  if (!tipo) return false;
  return tipo.startsWith('image/');
};

export default function ModalVerTarea({ tarea, onClose }) {
  const API = process.env.REACT_APP_API_URL;
  const tk = localStorage.getItem('token');
  const [documentosConUrls, setDocumentosConUrls] = useState([]);

  // Cargar URLs de documentos cuando se abre el modal (IGUAL QUE EN ModalTratamiento)
  useEffect(() => {
    const cargarUrls = async () => {
      if (tarea.documentos && tarea.documentos.length > 0) {
        const docsConUrls = [];

        for (const doc of tarea.documentos) {
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
  }, [tarea.documentos, API, tk]);

  const downloadDocument = async (documento) => {
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

          {/* Archivos adjuntos - USANDO LA MISMA LÓGICA QUE ModalTratamiento */}
          {documentosConUrls.length > 0 && (
            <div className="tratamiento-attachment tarea-info-section">
              <h4>Archivos adjuntos ({documentosConUrls.length})</h4>

              {documentosConUrls.map((documento, index) => {
                const isDocImage = isImage(documento.tipo);

                return (
                  <div key={documento.id_documento || index} className="tarea-documento-item">
                    <div className="documento-header">
                      <h5 className="documento-titulo">
                        {documento.nombre_archivo || `Documento ${index + 1}`}
                      </h5>
                      <span className="documento-tipo">{documento.tipo || 'Archivo'}</span>
                    </div>

                    {documento.tiene_url ? (
                      // Si tenemos URL, mostrar contenido
                      isDocImage ? (
                        <div className="imagen-container-center">
                          <img
                            src={documento.url_visualizacion}
                            alt={`Adjunto ${index + 1} de la tarea`}
                            className="tarea-imagen-preview"
                            onError={(e) => {
                              console.error('Error al cargar imagen desde S3:', documento.url_visualizacion);
                              e.target.style.display = 'none';
                              e.target.parentNode.querySelector('.tarea-imagen-error').style.display = 'block';
                            }}
                          />
                          <div className="tarea-imagen-error" style={{ display: 'none' }}>
                            <p>Error al visualizar la imagen</p>
                            <div className="tarea-download-link">
                              <button
                                className="btn-primary btn-small"
                                onClick={() => downloadDocument(documento)}
                              >
                                Descargar archivo original
                              </button>
                            </div>
                          </div>
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
                          <button
                            className="btn-primary btn-small"
                            onClick={() => downloadDocument(documento)}
                          >
                            Intentar descargar
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