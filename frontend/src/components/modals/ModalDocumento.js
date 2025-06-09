import React, { useState, useEffect, useCallback } from 'react';
import { X } from 'lucide-react';
import axios from 'axios';
import '../../styles.css';

export default function ModalDocumento({ doc, onClose, onChange }) {
  const API = process.env.REACT_APP_API_URL;
  const tk  = localStorage.getItem('token');

  const [showDeleteModal, setShowDeleteModal] = useState(false);
  const [isDeleting,       setIsDeleting]       = useState(false);
  const [error,            setError]            = useState('');
  const [editMode,         setEditMode]         = useState(false);
  const [diagnosticoFinal, setDiagnosticoFinal] = useState(doc.diagnostico_final || '');
  const [diagError,        setDiagError]        = useState('');
  const [isUpdating,       setIsUpdating]       = useState(false);
  const [signedUrl,        setSignedUrl]        = useState(null);
  const [urlError,         setUrlError]         = useState('');

  const fetchSignedUrl = useCallback(async () => {
    try {
      const res = await axios.get(
        `${API}/api/s3/documentos/${doc.id_documento}/url`,
        { headers: { Authorization: `Bearer ${tk}` } }
      );
      if (res.data.ok) {
        setSignedUrl(res.data.url);
        setUrlError('');
        return;
      }
      throw new Error(res.data.mensaje);
    } catch (e) {
      console.error('Error fetching signed URL:', e);
      setUrlError('No se pudo obtener la URL de descarga');
    }
  }, [API, doc.id_documento, tk]);

  useEffect(() => {
    fetchSignedUrl();
  }, [fetchSignedUrl]);

  const deleteDocument = async () => {
    setIsDeleting(true);
    setError('');
    try {
      await axios.delete(`/api/s3/documentos/${doc.id_documento}`, {
        headers: { Authorization: `Bearer ${tk}` }
      });
      onChange();
      onClose();
    } catch (e) {
      console.error('Error deleting document:', e);
      setError('Error al eliminar el documento. Inténtalo de nuevo.');
      setIsDeleting(false);
    }
  };

  const updateDiagnostico = async () => {
    if (!diagnosticoFinal.trim()) {
      setDiagError('El diagnóstico final no puede estar vacío');
      return;
    }
    setIsUpdating(true);
    setDiagError('');
    setError('');
    try {
      const res = await axios.put(
        `/api/s3/documentos/${doc.id_documento}`,
        { diagnostico_final: diagnosticoFinal },
        { headers: { Authorization: `Bearer ${tk}` } }
      );
      if (res.data.ok) {
        setEditMode(false);
        onChange();
      } else {
        throw new Error(res.data.mensaje || 'Error al actualizar');
      }
    } catch (e) {
      console.error('Error updating diagnóstico:', e);
      setDiagError('Error al actualizar el diagnóstico. Inténtalo de nuevo.');
    } finally {
      setIsUpdating(false);
    }
  };

  return (
    <>
      <div className="modal-backdrop" onClick={onClose}>
        <div className="modal modal-documento-wide" onClick={e => e.stopPropagation()}>
          <div className="modal-header">
            <h3>{doc.diagnostico_preliminar || 'Documento sin diagnóstico'}</h3>
            <button className="modal-close" onClick={onClose}><X /></button>
          </div>

          <div className="modal-body">
            <p>
              <strong>Fecha de subida:</strong>{' '}
              {new Date(doc.fecha_subida).toLocaleDateString()}
            </p>

            {doc.diagnostico_preliminar && (
              <div className="documento-info-section">
                <h4>Diagnóstico preliminar</h4>
                <p className="documento-info-destacado">
                  {doc.diagnostico_preliminar}
                </p>
              </div>
            )}

            <div className="documento-archivo-container">
              <div className="documento-header-container">
                <h4>Diagnóstico final</h4>
                {!editMode && (
                  <button
                    onClick={() => setEditMode(true)}
                    className="btn-edit-diagnostico"
                  >
                    {doc.diagnostico_final ? 'Editar' : 'Añadir'}
                  </button>
                )}
              </div>

              {editMode ? (
                <>
                  <textarea
                    value={diagnosticoFinal}
                    onChange={e => {
                      setDiagnosticoFinal(e.target.value);
                      if (e.target.value.trim()) setDiagError('');
                    }}
                    placeholder="Introduce el diagnóstico final..."
                    rows={4}
                    className={`diagnostico-textarea ${diagError ? 'error' : ''}`}
                  />
                  {diagError && (
                    <span className="diagnostico-error-message">{diagError}</span>
                  )}
                  <div className="diagnostico-botones-container">
                    <button
                      onClick={() => setEditMode(false)}
                      className="btn-cancelar-diagnostico"
                      disabled={isUpdating}
                    >
                      Cancelar
                    </button>
                    <button
                      onClick={updateDiagnostico}
                      className={`btn-guardar-diagnostico ${isUpdating ? 'loading' : ''}`}
                      disabled={isUpdating}
                    >
                      {isUpdating ? 'Guardando...' : 'Guardar'}
                    </button>
                  </div>
                </>
              ) : (
                doc.diagnostico_final ? (
                  <p className="diagnostico-final-existente">
                    {doc.diagnostico_final}
                  </p>
                ) : (
                  <p className="diagnostico-final-vacio">
                    No hay diagnóstico final. Haz clic en "Añadir" para agregarlo.
                  </p>
                )
              )}
            </div>

            <div className="documento-preview">
              <h4>Descargar archivo</h4>
              {signedUrl ? (
                <a
                  href={signedUrl}
                  download={doc.nombre_archivo || 'documento'}
                  className="documento-descarga-archivo"
                  onClick={onClose}
                >
                  Descargar
                </a>
              ) : (
                <button
                  className="documento-descarga-archivo loading"
                  onClick={fetchSignedUrl}
                >
                  {urlError || 'Cargando...'}
                </button>
              )}
            </div>
          </div>

          <div className="modal-footer">
            <button
              className="btn-delete"
              onClick={() => setShowDeleteModal(true)}
            >
              Eliminar
            </button>
            <button className="btn-cancel" onClick={onClose}>
              Cerrar
            </button>
          </div>
        </div>
      </div>

      {showDeleteModal && (
        <div
          className="modal-backdrop modal-backdrop-confirmacion"
          onClick={() => setShowDeleteModal(false)}
        >
          <div className="modal modal-confirmacion-eliminar" onClick={e => e.stopPropagation()}>
            <div className="modal-header">
              <h3>Confirmar eliminación</h3>
            </div>
            <div className="modal-body">
              <p>¿Estás seguro de que quieres eliminar este documento?</p>
              <p><strong>{doc.diagnostico_preliminar || doc.nombre_archivo}</strong></p>
              <p className="texto-advertencia-pequeno">
                Esta acción no se puede deshacer.
              </p>
              {error && <div className="error-eliminacion-container">{error}</div>}
            </div>
            <div className="modal-footer">
              <button
                className="btn-cancel"
                onClick={() => setShowDeleteModal(false)}
                disabled={isDeleting}
              >
                Cancelar
              </button>
              <button
                className={`btn-delete ${isDeleting ? 'loading' : ''}`}
                onClick={deleteDocument}
                disabled={isDeleting}
              >
                {isDeleting ? 'Eliminando...' : 'Eliminar'}
              </button>
            </div>
          </div>
        </div>
      )}
    </>
  );
}
