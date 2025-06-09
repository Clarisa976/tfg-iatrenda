import React, { useState, useEffect } from 'react';
import axios from 'axios';
import { X } from 'lucide-react';

const isImage = (nombreArchivo) => {
  if (!nombreArchivo) return false;
  const extensions = ['.jpg', '.jpeg', '.png', '.webp', '.gif'];
  return extensions.some(ext => nombreArchivo.toLowerCase().endsWith(ext));
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

    cargarUrls();  }, [tarea.documentos, API, tk]);

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
            <div className="tratamiento-attachment tratamiento-attachment-section">
              <h4>Archivos adjuntos ({documentosConUrls.length})</h4>              {documentosConUrls.map((documento, index) => {
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
                      isDocImage ? (                        <div className="image-container image-container-centered">
                          <img
                            src={documento.url_visualizacion}
                            alt={`Adjunto ${index + 1} de la tarea`}
                            className="documento-imagen"
                            onError={(e) => {
                              e.target.style.display = 'none';
                              e.target.parentNode.querySelector('.imagen-error-container').style.display = 'block';
                            }}
                          />                          <div className="imagen-error-container" style={{ display: 'none' }}>
                            <p>Error al cargar la imagen</p>
                          </div>
                        </div>) : (
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
                        </div>                        <div className="documento-estado">
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
          )}          {tarea.documentos && tarea.documentos.length === 0 && (
            <div className="sin-documentos">
              <p><em>No hay archivos adjuntos en esta tarea</em></p>
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