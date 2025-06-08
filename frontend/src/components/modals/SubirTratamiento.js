import React, { useState } from 'react';
import '../../styles.css';

export default function SubirTratamiento({ onDone, idPaciente }) {
  const tk = localStorage.getItem('token');

  const [show, setShow] = useState(false);
  const [tit, setTit] = useState('');
  const [desc, setDesc] = useState('');
  const [file, setFile] = useState(null);
  const [fechaInicio, setFechaInicio] = useState('');
  const [fechaFin, setFechaFin] = useState('');
  const [frecuencia, setFrecuencia] = useState('');

  // Errores
  const [tituloError, setTituloError] = useState('');
  const [descripcionError, setDescripcionError] = useState('');
  const [fileError, setFileError] = useState('');
  const [fechaError, setFechaError] = useState('');
  const [generalError, setGeneralError] = useState('');
  const [isLoading, setIsLoading] = useState(false);

  const subir = async () => {
    setTituloError('');
    setDescripcionError('');
    setFileError('');
    setFechaError('');
    setGeneralError('');

    // Validaciones
    let hasErrors = false;

    if (!tit.trim()) {
      setTituloError('El título es obligatorio');
      hasErrors = true;
    }

    if (!desc.trim()) {
      setDescripcionError('La descripción es obligatoria');
      hasErrors = true;
    }

    // Validar fechas
    if (fechaInicio && fechaFin && new Date(fechaInicio) > new Date(fechaFin)) {
      setFechaError('La fecha de fin debe ser posterior a la fecha de inicio');
      hasErrors = true;
    }

    // Validar archivo si existe
    if (file && file.size > 10 * 1024 * 1024) {
      setFileError('El archivo no puede superar los 10MB');
      hasErrors = true;
    }

    if (hasErrors) return;

    setIsLoading(true);

    try {
      // Subir todo directamente a AWS S3 incluyendo datos del tratamiento
      const formDataFile = new FormData();
      
      // Datos del tratamiento
      formDataFile.append('titulo', tit);
      formDataFile.append('notas', desc);
      formDataFile.append('fecha_inicio', fechaInicio || '');
      formDataFile.append('fecha_fin', fechaFin || '');
      formDataFile.append('frecuencia_sesiones', frecuencia || '1');
      formDataFile.append('id_paciente', idPaciente);
      formDataFile.append('tipo', 'tratamiento');
      
      // Archivo si existe
      if (file) {
        formDataFile.append('file', file);
      }

      const response = await fetch(`${process.env.REACT_APP_API_URL}/api/s3/upload`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${tk}`
        },
        body: formDataFile
      });

      const result = await response.json();
      if (!result.ok) {
        throw new Error(result.mensaje || 'Error al crear tratamiento');
      }

      // Limpiar formulario y cerrar modal
      setTit('');
      setDesc('');
      setFechaInicio('');
      setFechaFin('');
      setFrecuencia('');
      setFile(null);
      setTituloError('');
      setDescripcionError('');
      setFileError('');
      setFechaError('');
      setGeneralError('');
      setShow(false);

      // Actualizar datos
      onDone();
    } catch (e) {
      setGeneralError('Error al guardar tratamiento: ' + e.message);
    } finally {
      setIsLoading(false);
    }
  };

  if (!show) return <button className="btn-save" onClick={() => setShow(true)}>Añadir tarea</button>;

  return (
    <div className="modal-backdrop" onClick={() => setShow(false)}>
      <div className="modal" onClick={e => e.stopPropagation()} style={{ maxWidth: '600px' }}>
        <div className="modal-header">
          <h3>Nueva tarea</h3>
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
          
          <div className="field full">
            <label>Título*</label>
            <input
              value={tit}
              onChange={e => {
                setTit(e.target.value);
                if (e.target.value.trim() && tituloError) {
                  setTituloError('');
                }
              }}
              style={{
                border: tituloError ? '1px solid #f44336' : '1px solid #ddd'
              }}
            />
            {tituloError && (
              <span style={{
                color: '#f44336',
                fontSize: '0.8em',
                display: 'block',
                marginTop: '5px'
              }}>
                {tituloError}
              </span>
            )}
          </div>

          <div className="field full">
            <label>Descripción*</label>
            <textarea
              rows={4}
              value={desc}
              onChange={e => {
                setDesc(e.target.value);
                if (e.target.value.trim() && descripcionError) {
                  setDescripcionError('');
                }
              }}
              style={{
                border: descripcionError ? '1px solid #f44336' : '1px solid #ddd'
              }}
            />
            {descripcionError && (
              <span style={{
                color: '#f44336',
                fontSize: '0.8em',
                display: 'block',
                marginTop: '5px'
              }}>
                {descripcionError}
              </span>
            )}
          </div>

          <div className="form-grid" style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '15px' }}>
            <div className="field">
              <label>Fecha inicio</label>
              <input
                type="date"
                value={fechaInicio}
                onChange={e => {
                  setFechaInicio(e.target.value);
                  if (fechaError) setFechaError('');
                }}
                style={{
                  border: fechaError ? '1px solid #f44336' : '1px solid #ddd'
                }}
              />
            </div>
            <div className="field">
              <label>Fecha fin</label>
              <input
                type="date"
                value={fechaFin}
                onChange={e => {
                  setFechaFin(e.target.value);
                  if (fechaError) setFechaError('');
                }}
                style={{
                  border: fechaError ? '1px solid #f44336' : '1px solid #ddd'
                }}
              />
            </div>
          </div>

          {fechaError && (
            <span style={{
              color: '#f44336',
              fontSize: '0.8em',
              display: 'block',
              marginTop: '5px',
              marginBottom: '10px'
            }}>
              {fechaError}
            </span>
          )}

          <div className="field full">
            <label>Frecuencia sesiones/semana</label>
            <input
              type="number"
              min="1"
              max="7"
              value={frecuencia}
              onChange={e => setFrecuencia(e.target.value)}
              placeholder="Ej: 2"
            />
          </div>

          <div className="field full">
            <label>Documento (opcional)</label>
            <input
              type="file"
              onChange={e => {
                setFile(e.target.files[0]);
                if (e.target.files[0] && fileError) {
                  setFileError('');
                }
              }}
              accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif"
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
              Formatos: PDF, DOC, DOCX, JPG, PNG, GIF. Tamaño máximo: 10MB
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
            {isLoading ? 'Guardando...' : 'Guardar'}
          </button>
        </div>
      </div>
    </div>
  );
}