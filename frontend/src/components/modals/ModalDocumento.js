import React, { useState } from 'react';
import { X } from 'lucide-react';
import axios from 'axios';
import '../../styles.css';

const isImage = (filePath) => {
  const imageExtensions = ['.jpg', '.jpeg', '.png', '.webp'];
  return imageExtensions.some(ext =>
    filePath.toLowerCase().includes(ext)
  );
};

export default function ModalDocumento({ doc, onClose, onChange }) {
  const tk = localStorage.getItem('token');

  const [showDeleteModal, setShowDeleteModal] = useState(false);
  const [isDeleting, setIsDeleting] = useState(false);
  const [error, setError] = useState('');
  const [editMode, setEditMode] = useState(false);
  const [diagnosticoFinal, setDiagnosticoFinal] = useState(doc.diagnostico_final || '');
  const [diagnosticoFinalError, setDiagnosticoFinalError] = useState('');
  const [isUpdating, setIsUpdating] = useState(false);

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
      setDiagnosticoFinalError('El diagnóstico final no puede estar vacío');
      return;
    }

    setIsUpdating(true);
    setDiagnosticoFinalError('');
    setError('');

    try {
      const response = await axios.put(
        `/api/s3/documentos/${doc.id_documento}`,
        { diagnostico_final: diagnosticoFinal },
        { headers: { Authorization: `Bearer ${tk}` } }
      );

      if (response.data?.ok) {
        doc.diagnostico_final = diagnosticoFinal;
        setEditMode(false);
        onChange();
      } else {
        throw new Error(response.data?.mensaje || 'Error al actualizar');
      }
    } catch (e) {
      console.error('Error updating diagnostico:', e);
      setDiagnosticoFinalError('Error al actualizar el diagnóstico. Inténtalo de nuevo.');
    } finally {
      setIsUpdating(false);
    }
  };

  const docFileUrl = `${process.env.REACT_APP_API_URL}/${doc.ruta}`;
  const isDocImage = isImage(doc.ruta);

  return (
    <>
      <div className="modal-backdrop" onClick={onClose}>
        <div className="modal modal-documento-wide" onClick={e => e.stopPropagation()}>
          <div className="modal-header">
            <h3>{doc.diagnostico_preliminar || 'Documento sin diagnóstico'}</h3>
            <button className="modal-close" onClick={onClose}><X /></button>
          </div>

          <div className="modal-body">
            <p><strong>Fecha de subida:</strong> {new Date(doc.fecha_subida).toLocaleDateString()}</p>

            {doc.diagnostico_preliminar && (
              <div className="documento-info-section">
                <h4>Diagnóstico preliminar</h4>
                <p className="documento-info-destacado">{doc.diagnostico_preliminar}</p>
              </div>
            )}

            <div className="documento-archivo-container">
              <div className="documento-header-container">
                <h4>Diagnóstico final</h4>
                {!editMode && (
                  <button onClick={() => setEditMode(true)} className="btn-edit-diagnostico">
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
                      if (e.target.value.trim() && diagnosticoFinalError) {
                        setDiagnosticoFinalError('');
                      }
                    }}
                    placeholder="Introduce el diagnóstico final..."
                    rows="4"
                    className={`diagnostico-textarea ${diagnosticoFinalError ? 'error' : ''}`}
                  />
                  {diagnosticoFinalError && (
                    <span className="diagnostico-error-message">{diagnosticoFinalError}</span>
                  )}
                  <div className="diagnostico-botones-container">
                    <button onClick={() => setEditMode(false)} className="btn-cancelar-diagnostico" disabled={isUpdating}>
                      Cancelar
                    </button>
                    <button onClick={updateDiagnostico} className={`btn-guardar-diagnostico ${isUpdating ? 'loading' : ''}`} disabled={isUpdating}>
                      {isUpdating ? 'Guardando...' : 'Guardar'}
                    </button>
                  </div>
                </>
              ) : (
                doc.diagnostico_final ? (
                  <p className="diagnostico-final-existente">{doc.diagnostico_final}</p>
                ) : (
                  <p className="diagnostico-final-vacio">
                    No hay diagnóstico final. Haz clic en "Añadir" para agregarlo.
                  </p>
                )
              )}
            </div>

            <div className="documento-preview">
              <h4>Vista previa</h4>
              {isDocImage ? (
                <div className="documento-imagen-container">
                  <img
                    src={docFileUrl}
                    alt={`Documento ${doc.id_documento}`}
                    className="documento-imagen"
                    onError={e => {
                      e.target.style.display = 'none';
                      e.target.nextSibling.style.display = 'block';
                    }}
                  />
                  <div className="documento-imagen-error" style={{ display: 'none' }}>
                    <p>No se pudo visualizar la imagen</p>
                    <p><small>Ruta: {doc.ruta}</small></p>
                  </div>
                </div>
              ) : (
                <div className="documento-enlace-container">
                  <a href={docFileUrl} target="_blank" rel="noreferrer" className="documento-enlace-archivo">
                    Ver archivo
                  </a>
                </div>
              )}
            </div>
          </div>

          <div className="modal-footer">
            <button className="btn-delete" onClick={() => setShowDeleteModal(true)}>
              Eliminar
            </button>
            <button className="btn-cancel" onClick={onClose}>Cerrar</button>
          </div>
        </div>
      </div>

      {showDeleteModal && (
        <div className="modal-backdrop modal-backdrop-confirmacion" onClick={() => setShowDeleteModal(false)}>
          <div className="modal modal-confirmacion-eliminar" onClick={e => e.stopPropagation()}>
            <div className="modal-header">
              <h3>Confirmar eliminación</h3>
            </div>
            <div className="modal-body">
              <p>¿Estás seguro de que quieres eliminar este documento?</p>
              <p><strong>{doc.diagnostico_preliminar || 'Documento sin diagnóstico'}</strong></p>
              <p className="texto-advertencia-pequeno">Esta acción no se puede deshacer.</p>
              {error && <div className="error-eliminacion-container">{error}</div>}
            </div>
            <div className="modal-footer">
              <button className="btn-cancel" onClick={() => setShowDeleteModal(false)} disabled={isDeleting}>
                Cancelar
              </button>
              <button className={`btn-delete ${isDeleting ? 'loading' : ''}`} onClick={deleteDocument} disabled={isDeleting}>
                {isDeleting ? 'Eliminando...' : 'Eliminar'}
              </button>
            </div>
          </div>
        </div>
      )}
    </>
  );
}
