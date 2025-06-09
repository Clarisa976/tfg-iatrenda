import React, { useState } from 'react';
import { X } from 'lucide-react';
import axios from 'axios';

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

  const baseUrl = process.env.REACT_APP_API_URL?.replace(/\/$/, '') ||
    (window.location.hostname === 'localhost' ? 'http://localhost:8081' : window.location.origin);

  return `${baseUrl}/uploads/${fileName}?t=${Date.now()}`;
};

export default function ModalHistorial({ documentos, onClose, onChange }) {
  const [seleccionados, setSeleccionados] = useState([]);

  const toggleSeleccion = (id) => {
    setSeleccionados(prev => prev.includes(id)
      ? prev.filter(i => i !== id)
      : [...prev, id]);
  };

  const eliminarSeleccionados = async () => {
    if (seleccionados.length === 0) return alert('No hay documentos seleccionados');
    if (!window.confirm(`¿Seguro que deseas eliminar ${seleccionados.length} documento(s)?`)) return;

    const token = localStorage.getItem('token');

    for (const id of seleccionados) {
      try {
        await axios.delete(`/api/s3/documentos/${id}`, {
          headers: { Authorization: `Bearer ${token}` }
        });
      } catch (e) {
        console.error(`Error eliminando documento ${id}:`, e);
      }
    }

    alert('Documentos eliminados');
    setSeleccionados([]);
    if (typeof onChange === 'function') onChange();
  };

  const descargarDocumento = (documento) => {
    const url = getFileUrl(documento.ruta);
    const link = document.createElement('a');
    link.href = url;
    link.download = documento.nombre_archivo || 'documento';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
  };

  return (
    <div className="modal-backdrop" onClick={onClose}>
      <div className="modal modal-historial-wide" onClick={e => e.stopPropagation()}>
        <div className="modal-header">
          <h3>Mi Historial Clínico</h3>
          <button className="modal-close" onClick={onClose}><X /></button>
        </div>

        <div className="modal-body">
          {documentos && documentos.length > 0 ? (
            <div>
              <p><strong>Total de documentos:</strong> {documentos.length}</p>
              <button
                className="btn-delete-multiple danger-btn"
                onClick={eliminarSeleccionados}
                disabled={seleccionados.length === 0}
              > Eliminar seleccionados ({seleccionados.length})
              </button>

              <div className="historial-documentos-section">
                {documentos.map((documento, index) => {
                  const docFileUrl = getFileUrl(documento.ruta);
                  const isDocImage = isImage(documento.ruta);
                  const isSelected = seleccionados.includes(documento.id_documento);

                  return (
                    <div key={documento.id_documento || index} className="historial-documento-item">
                      <div className="historial-documento-select">
                        <input
                          type="checkbox"
                          checked={isSelected}
                          onChange={() => toggleSeleccion(documento.id_documento)}
                        />
                      </div>
                      <div className="historial-documento-info">
                        <p><strong>Fecha:</strong> {new Date(documento.fecha_subida).toLocaleDateString('es-ES')}</p>
                        <p><strong>Profesional:</strong> {documento.profesional_nombre}</p>
                        {documento.diagnostico_preliminar && (
                          <p><strong>Diagnóstico:</strong> {documento.diagnostico_preliminar}</p>
                        )}
                      </div>
                      {isDocImage && (
                        <div className="historial-imagen-container">
                          <img
                            src={docFileUrl}
                            alt="Documento del historial"
                            className="historial-documento-imagen"
                          />
                        </div>
                      )}
                      <div className="historial-download-container">
                        <button onClick={() => descargarDocumento(documento)} className="historial-download-btn">
                          Descargar
                        </button>
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>
          ) : (
            <div style={{ textAlign: 'center', padding: '40px', color: '#666' }}>
              <p>No tienes documentos en tu historial clínico</p>
            </div>
          )}
        </div>

        <div className="modal-footer">
          <button className="btn-cancel" onClick={onClose}>Cerrar</button>
        </div>
      </div>
    </div>
  );
}
