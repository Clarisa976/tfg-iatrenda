import React, { useState } from 'react';
import { X, Trash2 } from 'lucide-react';
import axios from 'axios';
import '../../styles.css';

// Helper function to determine if file is an image
const isImage = (filePath) => {
  const imageExtensions = ['.jpg', '.jpeg', '.png','.webp'];
  return imageExtensions.some(ext => 
    filePath.toLowerCase().includes(ext.toLowerCase())
  );
};

export default function ModalDocumento({ idPac, doc, onClose, onChange }) {
  const tk = localStorage.getItem('token');
  
  const [showDeleteModal, setShowDeleteModal] = useState(false);
  const [isDeleting, setIsDeleting] = useState(false);
  const [error, setError] = useState('');
  const [editMode, setEditMode] = useState(false);
  const [diagnosticoFinal, setDiagnosticoFinal] = useState(doc.diagnostico_final || '');
  const [diagnosticoFinalError, setDiagnosticoFinalError] = useState('');
  const [isUpdating, setIsUpdating] = useState(false);

  const del = async () => {
    setIsDeleting(true);
    setError('');
    try {
      await axios.delete(`/prof/pacientes/${idPac}/documentos/${doc.id_documento}`, {
        headers: { Authorization: `Bearer ${tk}` }
      });
      onChange();
      onClose();
    } catch (e) {
      console.error('Error al eliminar:', e);
      setError('Error al eliminar el documento. Inténtalo de nuevo.');
      setIsDeleting(false);
    }
  };

  const updateDiagnostico = async () => {
    // Realizar validación sin cambiar el estado global de error
    if (!diagnosticoFinal.trim()) {
      setDiagnosticoFinalError('El diagnóstico final no puede estar vacío');
      return;
    }

    setIsUpdating(true);
    setDiagnosticoFinalError('');
    setError('');

    try {
      console.log('Sending update request for document:', doc.id_documento);
      const response = await axios.put(`/prof/pacientes/${idPac}/documentos/${doc.id_documento}`,
        { diagnostico_final: diagnosticoFinal },
        { headers: { Authorization: `Bearer ${tk}` } }
      );

      console.log('Update response:', response.data);

      if (response.data && response.data.ok) {
        // Update the document in the current state with the new diagnosis
        doc.diagnostico_final = diagnosticoFinal;
        setEditMode(false);

        // Refresh the data to show updated information
        console.log('Refreshing data after successful update');
        onChange();

        // Show success message
        setError('');
        setDiagnosticoFinalError('');
      } else {
        throw new Error(response.data?.mensaje || 'Error al actualizar');
      }
    } catch (e) {
      console.error('Error al actualizar:', e);
      console.error('Error details:', e.response?.data);
      setDiagnosticoFinalError('Error al actualizar el diagnóstico. Inténtalo de nuevo.');
    } finally {
      setIsUpdating(false);
    }
  };

  // Determinar si es una imagen para mostrarla directamente
  const docFileUrl = `${process.env.REACT_APP_API_URL}/${doc.ruta}`;
  const isDocImage = isImage(doc.ruta);

  return (
    <>
      <div className="modal-backdrop" onClick={onClose}>
        <div className="modal" onClick={e => e.stopPropagation()} style={{ maxWidth: '600px' }}>
          <div className="modal-header">
            <h3>{doc.diagnostico_preliminar || 'Documento sin diagnóstico'}</h3>
            <button className="modal-close" onClick={onClose}><X /></button>
          </div>
          
          <div className="modal-body">
            <p><strong>Fecha de subida:</strong>{' '}
              {new Date(doc.fecha_subida || Date.now()).toLocaleDateString()}</p>

            {doc.diagnostico_preliminar && (
              <div style={{ marginBottom: '15px' }}>
                <h4>Diagnóstico preliminar</h4>
                <p style={{ padding: '10px', background: '#f5f9ff', border: '1px solid #e6edf7', borderRadius: '4px' }}>
                  {doc.diagnostico_preliminar}
                </p>
              </div>
            )}

            <div style={{ marginBottom: '15px' }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <h4>Diagnóstico final</h4>
                {!editMode && (
                  <button
                    onClick={() => setEditMode(true)}
                    className="btn-small"
                    style={{
                      background: 'var(--blue)',
                      color: 'white',
                      border: 'none',
                      borderRadius: '4px',
                      padding: '5px 10px',
                      cursor: 'pointer',
                      fontSize: '0.8em'
                    }}
                  >
                    {doc.diagnostico_final ? 'Editar' : 'Añadir'}
                  </button>
                )}
              </div>
              {editMode ? (
                <div>
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
                    style={{
                      width: '100%',
                      padding: '8px',
                      borderRadius: '4px',
                      border: diagnosticoFinalError ? '1px solid #f44336' : '1px solid #ddd',
                      marginBottom: diagnosticoFinalError ? '5px' : '10px'
                    }}
                  />
                  {diagnosticoFinalError && (
                    <span style={{
                      color: '#f44336',
                      fontSize: '0.8em',
                      display: 'block',
                      marginBottom: '10px'
                    }}>
                      {diagnosticoFinalError}
                    </span>
                  )}
                  <div style={{ display: 'flex', gap: '10px', justifyContent: 'flex-end' }}>
                    <button
                      onClick={() => setEditMode(false)}
                      className="btn-small"
                      disabled={isUpdating}
                      style={{
                        background: '#f0f0f0',
                        border: 'none',
                        borderRadius: '4px',
                        padding: '5px 10px',
                        cursor: 'pointer'
                      }}
                    >
                      Cancelar
                    </button>
                    <button
                      onClick={updateDiagnostico}
                      className="btn-small"
                      disabled={isUpdating}
                      style={{
                        background: 'var(--blue)',
                        color: 'white',
                        border: 'none',
                        borderRadius: '4px',
                        padding: '5px 10px',
                        cursor: 'pointer',
                        opacity: isUpdating ? 0.7 : 1
                      }}
                    >
                      {isUpdating ? 'Guardando...' : 'Guardar'}
                    </button>
                  </div>
                </div>
              ) : (
                doc.diagnostico_final ? (
                  <p style={{ padding: '10px', background: '#f0f9f0', border: '1px solid #e0eee0', borderRadius: '4px' }}>
                    {doc.diagnostico_final}
                  </p>
                ) : (
                  <p style={{ padding: '10px', background: '#f9f9f9', border: '1px dashed #ddd', borderRadius: '4px', color: '#666' }}>
                    No hay diagnóstico final. Haz clic en "Añadir" para agregarlo.
                  </p>
                )
              )}
            </div>

            <div className="documento-preview" style={{ marginTop: '20px' }}>
              <h4>Vista previa</h4>

              {isDocImage ? (
                <div className="image-container" style={{ textAlign: 'center' }}>
                  <img
                    src={docFileUrl}
                    alt={`Documento ${doc.id_documento}`}
                    style={{
                      maxWidth: '100%',
                      maxHeight: '300px',
                      border: '1px solid #ddd',
                      borderRadius: '4px',
                      display: 'block',
                      margin: '10px auto'
                    }}
                    onError={(e) => {
                      console.error('Error al cargar imagen:', docFileUrl);
                      e.target.style.display = 'none';
                      e.target.nextSibling.style.display = 'block';
                    }}
                  />
                  <div style={{
                    display: 'none',
                    border: '1px dashed #f44336',
                    padding: '15px',
                    borderRadius: '4px',
                    backgroundColor: '#ffeaa7'
                  }}>
                    <p>⚠️ No se pudo visualizar la imagen</p>
                    <p><small>Ruta: {doc.ruta}</small></p>
                  </div>
                </div>
              ) : (
                <div className="file-link" style={{ textAlign: 'center', margin: '15px 0' }}>
                  <a
                    href={docFileUrl}
                    target="_blank"
                    rel="noreferrer"
                    style={{
                      background: '#4a90e2',
                      color: 'white',
                      padding: '8px 15px',
                      borderRadius: '4px',
                      textDecoration: 'none',
                      display: 'inline-block'
                    }}
                  >
                    📄 Ver archivo
                  </a>
                </div>
              )}
            </div>
          </div>
          <div className="modal-footer">
            <button className="btn-delete" onClick={() => setShowDeleteModal(true)}>
              <Trash2 size={18} /> Eliminar
            </button>
            <button className="btn-cancel" onClick={onClose}>Cerrar</button>
          </div>
        </div>
      </div>

      {/* Modal de confirmación de eliminación */}
      {showDeleteModal && (
        <div className="modal-backdrop" onClick={() => setShowDeleteModal(false)} style={{ zIndex: 10000 }}>
          <div className="modal" onClick={e => e.stopPropagation()} style={{ maxWidth: '400px' }}>
            <div className="modal-header">
              <h3>Confirmar eliminación</h3>
            </div>
            <div className="modal-body">
              <p>¿Estás seguro de que quieres eliminar este documento?</p>
              <p><strong>{doc.diagnostico_preliminar || 'Documento sin diagnóstico'}</strong></p>
              <p style={{ color: '#666', fontSize: '0.9em' }}>Esta acción no se puede deshacer.</p>
              {error && (
                <div style={{
                  background: '#fee',
                  border: '1px solid #fcc',
                  padding: '10px',
                  borderRadius: '4px',
                  marginTop: '10px',
                  color: '#c33'
                }}>
                  {error}
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
                className="btn-delete"
                onClick={del}
                disabled={isDeleting}
                style={{ opacity: isDeleting ? 0.6 : 1 }}
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
