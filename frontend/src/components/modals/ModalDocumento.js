import React, { useState, useEffect, useCallback } from 'react';
import { X } from 'lucide-react';
import axios from 'axios';
import '../../styles.css';

export default function ModalDocumento({ doc, onClose, onChange }) {
  const API = process.env.REACT_APP_API_URL;
  const tk = localStorage.getItem('token');

  const [showDeleteModal, setShowDeleteModal] = useState(false);
  const [isDeleting, setIsDeleting] = useState(false);
  const [deleteError, setDeleteError] = useState('');
  const [signedUrl, setSignedUrl] = useState(null);
  const [urlError, setUrlError] = useState('');

  const [editMode, setEditMode] = useState(false);
  const [diagnosticoFinal, setDiagnosticoFinal] = useState(doc.diagnostico_final || '');
  const [diagError, setDiagError] = useState('');
  const [isUpdating, setIsUpdating] = useState(false);


  const isImage = path => /\.(jpe?g|png|webp)$/i.test(path);


  const fetchSignedUrl = useCallback(async () => {
    try {
      const res = await axios.get(
        `${API}/api/s3/documentos/${doc.id_documento}/url`,
        { headers: { Authorization: `Bearer ${tk}` } }
      );
      if (res.data.ok) {
        setSignedUrl(res.data.url);
        setUrlError('');
      } else {
        throw new Error(res.data.mensaje);
      }
    } catch (e) {
      console.error(e);
      setUrlError('No se pudo cargar el archivo');
    }
  }, [API, doc.id_documento, tk]);

  useEffect(() => { fetchSignedUrl() }, [fetchSignedUrl]);


  const deleteDocument = async () => {
    setIsDeleting(true);
    setDeleteError('');
    try {
      await axios.delete(
        `/api/s3/documentos/${doc.id_documento}`,
        { headers: { Authorization: `Bearer ${tk}` } }
      );
      onChange();
      onClose();
    } catch (e) {
      console.error(e);
      setDeleteError('No se pudo eliminar el documento');
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
    try {
      const res = await axios.put(
        `/api/s3/documentos/${doc.id_documento}`,
        { diagnostico_final: diagnosticoFinal },
        { headers: { Authorization: `Bearer ${tk}` } }
      );
      if (!res.data.ok) throw new Error(res.data.mensaje);
      setEditMode(false);
      onChange();
    } catch (e) {
      console.error(e);
      setDiagError('Error al guardar el diagnóstico');
    } finally {
      setIsUpdating(false);
    }
  };

  return (
    <>
      <div className="modal-backdrop" onClick={onClose}>
        <div className="modal modal-documento-wide"
          onClick={e => e.stopPropagation()}>
          <div className="modal-header">
            <h3>{doc.nombre_archivo || 'Documento'}</h3>
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

            <div className="documento-info-section">
              <h4>Diagnóstico final</h4>
              {!editMode ? (
                <>
                  <p>
                    {doc.diagnostico_final ||
                      <em>No hay diagnóstico final</em>}
                  </p>
                  <button
                    className="btn-edit-diagnostico"
                    onClick={() => setEditMode(true)}
                  >
                    {doc.diagnostico_final ? 'Editar' : 'Añadir'}
                  </button>
                </>
              ) : (
                <>
                  <textarea
                    rows={4}
                    className={`diagnostico-textarea ${diagError ? 'error' : ''}`}
                    value={diagnosticoFinal}
                    onChange={e => {
                      setDiagnosticoFinal(e.target.value);
                      if (diagError) setDiagError('');
                    }}
                  />
                  {diagError && (
                    <span className="diagnostico-error-message">{diagError}</span>
                  )}
                  <div className="diagnostico-botones-container">
                    <button
                      className="btn-cancelar-diagnostico"
                      onClick={() => setEditMode(false)}
                      disabled={isUpdating}
                    >
                      Cancelar
                    </button>
                    <button
                      className={`btn-guardar-diagnostico ${isUpdating ? 'loading' : ''}`}
                      onClick={updateDiagnostico}
                      disabled={isUpdating}
                    >
                      {isUpdating ? 'Guardando...' : 'Guardar'}
                    </button>
                  </div>
                </>
              )}
            </div>

            <div className="documento-preview">
              <h4>Archivo</h4>
              {signedUrl ? (
                isImage(doc.ruta) ? (
                  <img
                    src={signedUrl}
                    alt={doc.nombre_archivo}
                    className="documento-imagen"
                  />
                ) : (
                  <a
                    href={signedUrl}
                    download={doc.nombre_archivo}
                    className="documento-descarga-archivo"
                  >
                    Descargar fichero
                  </a>
                )
              ) : (
                <div className="documento-error">
                  {urlError || 'Cargando…'}
                </div>
              )}
            </div>

            {deleteError && (
              <div className="error-global">{deleteError}</div>
            )}
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
        <div className="modal-backdrop"
          onClick={() => setShowDeleteModal(false)}>
          <div className="modal modal-confirmacion-eliminar"
            onClick={e => e.stopPropagation()}>
            <div className="modal-header">
              <h3>Confirmar eliminación</h3>
            </div>
            <div className="modal-body">
              <p>
                ¿Estás seguro de que quieres eliminar this fichero?
              </p>
              <p><strong>{doc.nombre_archivo}</strong></p>
              {deleteError && (
                <div className="error-eliminacion-container">
                  {deleteError}
                </div>
              )}
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
