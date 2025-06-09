import React, { useState, useEffect, useCallback } from 'react';
import { X } from 'lucide-react';
import axios from 'axios';
import '../../styles.css';

export default function ModalDocumento({ doc, onClose, onChange }) {
  const API = process.env.REACT_APP_API_URL;
  const tk = localStorage.getItem('token');

  const [showDel, setShowDel] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [delErr, setDelErr] = useState('');

  const [url, setUrl] = useState(null);
  const [urlErr, setUrlErr] = useState('');
  const [loadingUrl, setLoadingUrl] = useState(true);

  const [editMode, setEditMode] = useState(false);
  const [diagnosticoFinal, setDiagnosticoFinal] = useState(doc.diagnostico_final || '');
  const [diagErr, setDiagErr] = useState('');
  const [updating, setUpdating] = useState(false);

  // Determinar si es imagen
  const isImage = (filename) => {
    if (!filename) return false;
    const extension = filename.toLowerCase().split('.').pop();
    return ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(extension);
  };

  // Obtener URL firmada del documento
  const fetchUrl = useCallback(async () => {
    setLoadingUrl(true);
    setUrlErr('');
    
    try {
      console.log('Obteniendo URL para documento ID:', doc.id_documento);
      
      const res = await axios.get(
        `${API}/api/s3/documentos/${doc.id_documento}/url`,
        { 
          headers: { Authorization: `Bearer ${tk}` },
          timeout: 15000
        }
      );
      
      console.log('Respuesta URL:', res.data);
      
      if (res.data.ok && res.data.url) {
        setUrl(res.data.url);
        setUrlErr('');
      } else {
        throw new Error(res.data.mensaje || 'Error al obtener URL del documento');
      }
    } catch (e) {
      console.error('Error obteniendo URL:', e);
      
      let errorMsg = 'No se pudo obtener la URL del archivo';
      
      if (e.response?.status === 404) {
        errorMsg = 'Documento no encontrado en el servidor';
      } else if (e.response?.status === 401) {
        errorMsg = 'No autorizado para acceder al documento';
      } else if (e.response?.data?.mensaje) {
        errorMsg = e.response.data.mensaje;
      } else if (e.code === 'ECONNABORTED') {
        errorMsg = 'Tiempo de espera agotado';
      }
      
      setUrlErr(errorMsg);
    } finally {
      setLoadingUrl(false);
    }
  }, [API, doc.id_documento, tk]);

  useEffect(() => {
    fetchUrl();
  }, [fetchUrl]);

  // Eliminar documento COMPLETO (S3 + BD)
  const handleDelete = async () => {
    setDeleting(true);
    setDelErr('');
    
    try {
      console.log('Eliminando documento ID:', doc.id_documento);
      
      const res = await axios.delete(
        `${API}/api/s3/documentos/${doc.id_documento}`,
        { 
          headers: { Authorization: `Bearer ${tk}` },
          timeout: 30000 // 30 segundos para eliminación
        }
      );
      
      console.log('Respuesta eliminación:', res.data);
      
      if (res.data.ok) {
        console.log('Documento eliminado exitosamente');
        if (onChange) onChange();
        onClose();
      } else {
        throw new Error(res.data.mensaje || 'Error al eliminar documento');
      }
    } catch (e) {
      console.error('Error eliminando documento:', e);
      
      let errorMsg = 'Error al eliminar el documento';
      
      if (e.response?.status === 404) {
        errorMsg = 'Documento no encontrado';
      } else if (e.response?.status === 401) {
        errorMsg = 'No autorizado para eliminar este documento';
      } else if (e.response?.data?.mensaje) {
        errorMsg = e.response.data.mensaje;
      } else if (e.code === 'ECONNABORTED') {
        errorMsg = 'Tiempo de espera agotado al eliminar';
      }
      
      setDelErr(errorMsg);
      setDeleting(false);
    }
  };

  // Guardar diagnóstico final en historial
  const saveDiagnostico = async () => {
    const diagnosticoTrimmed = diagnosticoFinal.trim();
    
    if (!diagnosticoTrimmed) {
      setDiagErr('El diagnóstico final no puede estar vacío');
      return;
    }

    setUpdating(true);
    setDiagErr('');
    
    try {
      console.log('Guardando diagnóstico para historial ID:', doc.id_historial);
      
      const res = await axios.put(
        `${API}/historial/${doc.id_historial}/diagnostico`,
        { diagnostico_final: diagnosticoTrimmed },
        { 
          headers: { 
            'Authorization': `Bearer ${tk}`,
            'Content-Type': 'application/json'
          },
          timeout: 10000
        }
      );
      
      console.log('Respuesta diagnóstico:', res.data);
      
      if (res.data.ok) {
        setEditMode(false);
        // Actualizar el documento local
        doc.diagnostico_final = diagnosticoTrimmed;
        if (onChange) onChange();
        console.log('Diagnóstico guardado exitosamente');
      } else {
        throw new Error(res.data.mensaje || 'Error al guardar diagnóstico');
      }
    } catch (e) {
      console.error('Error guardando diagnóstico:', e);
      
      let errorMsg = 'Error al guardar el diagnóstico';
      
      if (e.response?.status === 404) {
        errorMsg = 'Historial clínico no encontrado';
      } else if (e.response?.status === 401) {
        errorMsg = 'No autorizado para modificar este historial';
      } else if (e.response?.data?.mensaje) {
        errorMsg = e.response.data.mensaje;
      }
      
      setDiagErr(errorMsg);
    } finally {
      setUpdating(false);
    }
  };

  // Cancelar edición
  const cancelEdit = () => {
    setDiagnosticoFinal(doc.diagnostico_final || '');
    setEditMode(false);
    setDiagErr('');
  };

  // Formatear fecha
  const formatDate = (dateString) => {
    try {
      return new Date(dateString).toLocaleString('es-ES', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
      });
    } catch {
      return dateString;
    }
  };

  const isHistorialDoc = !doc.id_tratamiento;
  const isTratamientoDoc = !!doc.id_tratamiento;

  return (
    <>
      <div className="modal-backdrop" onClick={onClose}>
        <div className="modal modal-documento-wide" onClick={e => e.stopPropagation()}>
          <div className="modal-header">
            <h3>
              {doc.nombre_archivo || 'Documento sin nombre'}
              {isTratamientoDoc && (
                <span className="documento-tipo-badge">Tratamiento</span>
              )}
              {isHistorialDoc && (
                <span className="documento-tipo-badge historial">Historial</span>
              )}
            </h3>
            <button className="modal-close" onClick={onClose}>
              <X size={20} />
            </button>
          </div>

          <div className="modal-body">
            <div className="documento-info-basic">
              <p><strong>Fecha de subida:</strong> {formatDate(doc.fecha_subida)}</p>
              {doc.profesional_nombre && (
                <p><strong>Subido por:</strong> {doc.profesional_nombre}</p>
              )}
              {doc.tipo && (
                <p><strong>Tipo de archivo:</strong> {doc.tipo}</p>
              )}
            </div>

            {isTratamientoDoc && doc.tratamiento_titulo && (
              <div className="documento-info-section">
                <h4>Información del Tratamiento</h4>
                <p><strong>Título:</strong> {doc.tratamiento_titulo}</p>
                {doc.tratamiento_notas && (
                  <p><strong>Notas:</strong> {doc.tratamiento_notas}</p>
                )}
              </div>
            )}

            {doc.diagnostico_preliminar && (
              <div className="documento-info-section">
                <h4>Diagnóstico preliminar</h4>
                <p className="diagnostico-texto">{doc.diagnostico_preliminar}</p>
              </div>
            )}

            <div className="documento-info-section">
              <div className="diagnostico-header">
                <h4>Diagnóstico final</h4>
                {!editMode && (
                  <button
                    className="btn-edit-diagnostico"
                    onClick={() => setEditMode(true)}
                  >
                    {doc.diagnostico_final ? 'Editar' : 'Añadir'}
                  </button>
                )}
              </div>
              
              {!editMode ? (
                <div className="diagnostico-display">
                  {doc.diagnostico_final ? (
                    <p className="diagnostico-texto">{doc.diagnostico_final}</p>
                  ) : (
                    <p className="diagnostico-vacio">
                      <em>No se ha especificado un diagnóstico final</em>
                    </p>
                  )}
                </div>
              ) : (
                <div className="diagnostico-edit">
                  <textarea
                    rows={4}
                    className={`diagnostico-textarea ${diagErr ? 'error' : ''}`}
                    value={diagnosticoFinal}
                    onChange={e => {
                      setDiagnosticoFinal(e.target.value);
                      if (diagErr) setDiagErr('');
                    }}
                    placeholder="Escriba el diagnóstico final..."
                  />
                  {diagErr && <span className="diagnostico-error-message">{diagErr}</span>}
                  <div className="diagnostico-botones-container">
                    <button
                      className="btn-cancelar-diagnostico"
                      onClick={cancelEdit}
                      disabled={updating}
                    >
                      Cancelar
                    </button>
                    <button
                      className={`btn-guardar-diagnostico ${updating ? 'loading' : ''}`}
                      onClick={saveDiagnostico}
                      disabled={updating}
                    >
                      {updating ? 'Guardando...' : 'Guardar'}
                    </button>
                  </div>
                </div>
              )}
            </div>

            <div className="documento-preview">
              <h4>Archivo</h4>
              {loadingUrl ? (
                <div className="documento-loading">Obteniendo archivo...</div>
              ) : url ? (
                isImage(doc.nombre_archivo) ? (
                  <div className="image-container">
                    <img 
                      src={url} 
                      alt={doc.nombre_archivo} 
                      className="documento-imagen"
                      onError={() => setUrlErr('Error al cargar la imagen')}
                    />
                  </div>
                ) : (
                  <div className="file-link-container">
                    <a
                      href={url}
                      download={doc.nombre_archivo}
                      className="documento-descarga-archivo"
                      target="_blank"
                      rel="noreferrer"
                    >
                      Descargar {doc.nombre_archivo}
                    </a>
                  </div>
                )
              ) : (
                <div className="documento-error">
                  <p>⚠️ {urlErr}</p>
                  <p><small>Archivo: {doc.nombre_archivo}</small></p>
                  <button 
                    className="btn-retry" 
                    onClick={fetchUrl}
                    disabled={loadingUrl}
                  >
                    Reintentar
                  </button>
                </div>
              )}
            </div>

            {delErr && <div className="error-global">{delErr}</div>}
          </div>

          <div className="modal-footer">
            <button 
              className="btn-delete" 
              onClick={() => setShowDel(true)}
              disabled={deleting}
            >
              Eliminar
            </button>
            <button className="btn-cancel" onClick={onClose}>
              Cerrar
            </button>
          </div>
        </div>
      </div>

      {showDel && (
        <div className="modal-backdrop" onClick={() => setShowDel(false)}>
          <div className="modal modal-confirmacion-eliminar" onClick={e => e.stopPropagation()}>
            <div className="modal-header">
              <h3>Confirmar eliminación</h3>
            </div>
            <div className="modal-body">
              <p>¿Está seguro de que desea eliminar <strong>{doc.nombre_archivo}</strong>?</p>
              <p className="warning-text">Se eliminará tanto de la base de datos como del almacenamiento en la nube.</p>
              <p className="warning-text">Esta acción no se puede deshacer.</p>
              {delErr && <div className="error-eliminacion-container">{delErr}</div>}
            </div>
            <div className="modal-footer">
              <button
                className="btn-cancel"
                onClick={() => setShowDel(false)}
                disabled={deleting}
              >
                Cancelar
              </button>
              <button
                className={`btn-delete ${deleting ? 'loading' : ''}`}
                onClick={handleDelete}
                disabled={deleting}
              >
                {deleting ? 'Eliminando...' : 'Eliminar definitivamente'}
              </button>
            </div>
          </div>
        </div>
      )}
    </>
  );
}