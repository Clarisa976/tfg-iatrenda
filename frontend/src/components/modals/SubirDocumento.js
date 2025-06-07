import React, { useState } from 'react';
import { useParams } from 'react-router-dom';
import axios from 'axios';
import '../../styles.css';

export default function SubirDocumento({ onDone }) {
  const { id } = useParams();
  const tk = localStorage.getItem('token');
  
  const [show, setShow] = useState(false);
  const [file, setFile] = useState(null);
  const [diagnosticoPreliminar, setDiagnosticoPreliminar] = useState('');
  
  // Errores específicos para cada campo
  const [fileError, setFileError] = useState('');
  const [diagnosticoPreliminarError, setDiagnosticoPreliminarError] = useState('');
  const [generalError, setGeneralError] = useState('');
  const [isLoading, setIsLoading] = useState(false);

  const subir = async () => {
    // Limpiar todos los errores al inicio
    setFileError('');
    setDiagnosticoPreliminarError('');
    setGeneralError('');

    // Validaciones
    if (!file) {
      setFileError('Debes seleccionar un archivo');
      return;
    }

    if (!diagnosticoPreliminar.trim()) {
      setDiagnosticoPreliminarError('El diagnóstico preliminar es obligatorio');
      return;
    }

    // Validar tamaño del archivo (máximo 10MB)
    if (file.size > 10 * 1024 * 1024) {
      setFileError('El archivo no puede superar los 10MB');
      return;
    }

    // Validar tipos de archivo permitidos
    const allowedTypes = [
      'image/jpeg', 'image/jpg', 'image/png', 'image/gif',
      'application/pdf', 'application/msword',
      'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      'text/plain'
    ];

    if (!allowedTypes.includes(file.type)) {
      setFileError('Tipo de archivo no permitido. Formatos permitidos: JPG, PNG, GIF, PDF, DOC, DOCX, TXT');
      return;
    }

    setIsLoading(true);
    try {
      const fd = new FormData();
      fd.append('file', file);
      fd.append('diagnostico_preliminar', diagnosticoPreliminar);

      await axios.post(`/prof/pacientes/${id}/documentos`, fd, {
        headers: { Authorization: `Bearer ${tk}`, 'Content-Type': 'multipart/form-data' }
      });

      // Limpiar formulario y cerrar modal
      setFile(null);
      setDiagnosticoPreliminar('');
      setFileError('');
      setDiagnosticoPreliminarError('');
      setGeneralError('');
      setShow(false);

      // Actualizar datos
      onDone();
    } catch (e) {
      setGeneralError('Error al subir documento: ' + (e.response?.data?.mensaje || e.message));
    } finally {
      setIsLoading(false);
    }
  };

  if (!show) return <button className="btn-save" onClick={() => setShow(true)}>Añadir documento</button>;
  
  return (
    <div className="modal-backdrop" onClick={() => setShow(false)}>
      <div className="modal" onClick={e => e.stopPropagation()} style={{ maxWidth: '560px' }}>
        <div className="modal-header">
          <h3>Subir documento al historial</h3>
        </div>
        <div className="modal-body">
          {generalError && (
            <div style={{
              background: '#fee',
              border: '1px solid #fcc',
              padding: '10px',
              borderRadius: '4px',
              marginBottom: '15px',
              color: '#c33'
            }}>
              {generalError}
            </div>
          )}
          
          <div className="form-grid">
            <div className="field full">
              <label>Diagnóstico preliminar*</label>
              <textarea
                value={diagnosticoPreliminar}
                onChange={e => {
                  setDiagnosticoPreliminar(e.target.value);
                  // Limpiar el error cuando el usuario empieza a escribir
                  if (e.target.value.trim() && diagnosticoPreliminarError) {
                    setDiagnosticoPreliminarError('');
                  }
                }}
                placeholder="Describa el diagnóstico preliminar del paciente..."
                rows="3"
                required
                style={{
                  border: diagnosticoPreliminarError ? '1px solid #f44336' : '1px solid #ddd'
                }}
              />
              {diagnosticoPreliminarError && (
                <span style={{
                  color: '#f44336',
                  fontSize: '0.8em',
                  display: 'block',
                  marginTop: '5px'
                }}>
                  {diagnosticoPreliminarError}
                </span>
              )}
              <small style={{ color: '#666', fontSize: '0.85em', marginTop: '5px', display: 'block' }}>
                Este campo es obligatorio y se mostrará como identificador del documento
              </small>
            </div>
          </div>
          
          <div className="field full">
            <label>Archivo*</label>
            <input
              type="file"
              onChange={e => {
                setFile(e.target.files[0]);
                // Limpiar el error cuando el usuario selecciona un archivo
                if (e.target.files[0] && fileError) {
                  setFileError('');
                }
              }}
              accept=".pdf,.doc,.docx,.txt,.jpg,.jpeg,.png,.gif"
              style={{
                border: fileError ? '1px solid #f44336' : 'none',
                padding: fileError ? '5px' : '0'
              }}
            />
            {fileError && (
              <span style={{
                color: '#f44336',
                fontSize: '0.8em',
                display: 'block',
                marginTop: '5px'
              }}>
                {fileError}
              </span>
            )}
            <small style={{ color: '#666', fontSize: '0.85em', marginTop: '5px', display: 'block' }}>
              Formatos permitidos: PDF, DOC, DOCX, TXT, JPG, PNG, GIF (máximo 10MB)
            </small>
          </div>
        </div>
        <div className="modal-footer">
          <button className="btn-cancel" onClick={() => setShow(false)} disabled={isLoading}>
            Cancelar
          </button>
          <button
            className="btn-save"
            onClick={subir}
            disabled={isLoading}
            style={{ opacity: isLoading ? 0.6 : 1 }}
          >
            {isLoading ? 'Subiendo...' : 'Subir'}
          </button>
        </div>
      </div>
    </div>
  );
}
