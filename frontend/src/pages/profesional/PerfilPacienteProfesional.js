/* ===================================================================
   src/pages/profesional/PerfilPacienteProfesional.jsx
   Perfil completo de un paciente visto por su profesional
   =================================================================== */
import React, { useState, useEffect, useCallback } from 'react';
import axios from 'axios';
import { useParams } from 'react-router-dom';
import {
  CheckCircle, XCircle, EllipsisVertical, Trash2, X
} from 'lucide-react';
import '../../styles.css';

/* ----------  helpers ---------- */
const TabBtn = ({ label, sel, onClick }) => (
  <button className="tab-btn"
    style={{ background: sel ? 'var(--blue)' : '#d9d9d9', color: sel ? '#fff' : '#000' }}
    onClick={onClick}>{label}</button>
);

// Detecta si la ruta es una imagen
const isImage = (path) => {
  if (!path) return false;
  const extensions = ['.jpg', '.jpeg', '.png', '.gif', '.webp', '.bmp', '.svg'];
  return extensions.some(ext => path.toLowerCase().endsWith(ext));
};

// Construye la URL correcta para el archivo usando el path simplificado /uploads/
const getFileUrl = (path) => {
  if (!path) return '';
  if (path.startsWith('http')) return path;

  // Eliminamos cualquier barra inicial si existe
  const cleanPath = path.startsWith('/') ? path.substring(1) : path;

  // Extraemos solo el nombre del archivo de la ruta
  const fileName = cleanPath.split('/').pop();

  // Determinamos la URL base del servidor de archivos
  let baseUrl;
  if (process.env.REACT_APP_API_URL) {
    // Si está definida la variable de entorno, la usamos
    baseUrl = process.env.REACT_APP_API_URL.replace(/\/$/, '');
  } else if (window.location.hostname === 'localhost') {
    // En desarrollo local con XAMPP
    baseUrl = 'http://localhost:8081';
  } else {
    // En producción, asumimos que los archivos están en el mismo dominio
    baseUrl = window.location.origin;
  }    // Construimos la URL usando el path simplificado que maneja el servidor PHP
  // Añadimos un timestamp para evitar problemas de caché del navegador
  const finalUrl = `${baseUrl}/uploads/${fileName}?t=${Date.now()}`;

  /* console.log('Path original:', path);
   console.log('Nombre del archivo:', fileName);
   console.log('Base URL:', baseUrl);
   console.log('URL final construida:', finalUrl);*/

  return finalUrl;
};

const estadoColor = {
  CONFIRMADA: '#6fcf97', ATENDIDA: '#6fcf97',
  PENDIENTE_VALIDACION: '#f2c94c',
  NO_PRESENTADA: '#9b51e0',
  CANCELADA: '#f04b4b',
  CAMBIO_SOLICITADO: 'var(--blue)',
  CANCELACION_SOLICITADA: '#eb5757'
};

/* =================================================================== */
export default function PerfilPacienteProfesional() {
  const { id } = useParams();

  /* ---------------- estado ---------------- */  const [data, setData] = useState(null);      // payload completo
  const [tab, setTab] = useState('perfil');
  const [edit, setEdit] = useState(false);
  const [drop, setDrop] = useState(null);      // id_cita con dropdown
  const [repro, setRepro] = useState({ show: false, id: null, fecha: '' }); const [selDoc, setSelDoc] = useState(null);
  const [selT, setSelT] = useState(null);
  const [toast, setToast] = useState({ show: false, ok: true, t: '', m: '' });

  /* formularios perfil */
  const [pPer, setPPer] = useState({});
  const [pPac, setPPac] = useState({});
  const [pTut, setPTut] = useState({});
  const [rgpd, setRgpd] = useState(false);

  /* ---------- axios base ---------- */
  useEffect(() => {
    axios.defaults.baseURL = process.env.REACT_APP_API_URL;
    const tk = localStorage.getItem('token');
    if (tk) axios.defaults.headers.common.Authorization = `Bearer ${tk}`;
  }, []); const fetchData = useCallback(async () => {
    try {
      console.log('Fetching updated patient data...');
      const r = await axios.get(`/prof/pacientes/${id}`);
      if (!r.data?.ok) throw new Error(r.data?.mensaje || 'Error API');
      const d = r.data.data;
      console.log('Patient data updated:', d);
      setData(d);
      setPPer(d.persona || {});
      setPPac(d.paciente || {});
      setPTut(d.tutor || {});
      setRgpd(d.consentimiento_activo || false);
      if (r.data.token) localStorage.setItem('token', r.data.token);
    } catch (e) {
      console.error(e);
      msg(false, 'Error', e.message);
      setData({ tratamientos: [], documentos: [], citas: [] });
    }
  }, [id]);

  /* ---------- carga paciente ---------- */
  useEffect(() => {
    fetchData();
  }, [fetchData]);

  /* ---------- toast auto-hide ---------- */
  useEffect(() => {
    if (toast.show) {
      const t = setTimeout(() => setToast(s => ({ ...s, show: false })), 2500);
      return () => clearTimeout(t);
    }
  }, [toast.show]);

  /* ---------- click fuera dropdown ---------- */
  useEffect(() => {
    if (drop !== null) {
      const h = e => {
        if (!e.target.closest('.acciones-dropdown') && !e.target.closest('.dropdown-toggle'))
          setDrop(null);
      };
      document.addEventListener('mousedown', h);
      return () => document.removeEventListener('mousedown', h);
    }
  }, [drop]);

  const msg = (ok, t, m) => setToast({ show: true, ok, t, m });
  const upd = setter => (k, v) => setter(s => ({ ...s, [k]: v }));
  const hPer = upd(setPPer); const hPac = upd(setPPac); const hTut = upd(setPTut);

  const input = (obj, setter, k, l, t = 'text', full = false) => (
    <div className={`field${full ? ' full' : ''}`}>
      <label>{l}</label>
      <input type={t} readOnly={!edit} className={!edit ? 'readonly-input' : ''}
        value={obj[k] || ''} onChange={e => setter(k, e.target.value)} />
    </div>
  );

  /* ---------- guardar perfil ---------- */
  const savePerfil = async () => {
    try {
      await axios.put(`/prof/pacientes/${id}`, {
        persona: pPer,
        paciente: { ...pPac, tutor: pPac.tipo_paciente !== 'ADULTO' ? pTut : null },
        rgpd
      });
      msg(true, 'OK', 'Perfil guardado');
      setEdit(false);
    } catch (e) { msg(false, 'Error', e.response?.data?.mensaje || 'No guardado'); }
  };
  /* ---------- acciones citas ---------- */
  const doAccion = async (idCita, accion, fecha = null) => {
    try {
      await axios.post(`/prof/citas/${idCita}/accion`, { accion, ...(fecha ? { fecha } : {}) });
      await fetchData(); // Recargar datos sin reload de página
    } catch (e) { msg(false, 'Error', 'No se pudo cambiar la cita'); }
  };

  if (data === null) return <div style={{ padding: '2rem', textAlign: 'center' }}>Cargando…</div>;

  /* ---------------- formularios ---------------- */
  const BloquePaciente = () => (
    <>
      <h4>Datos de paciente</h4>
      <div className="form-grid">
        <div className="field">
          <label>Tipo paciente*</label>
          <select disabled={!edit}
            className={!edit ? 'readonly-input' : ''}
            value={pPac.tipo_paciente || 'ADULTO'}
            onChange={e => hPac('tipo_paciente', e.target.value)}>
            <option value="ADULTO">Adulto</option>
            <option value="ADOLESCENTE">Adolescente</option>
            <option value="NIÑO">Niño</option>
            <option value="INFANTE">Infante</option>
          </select>
        </div>
        {input(pPac, hPac, 'observaciones_generales', 'Observaciones', 'text', true)}
      </div>
    </>
  );

  const BloqueTutor = () => (
    <>
      <h4>Datos del tutor</h4>
      <div className="form-grid">
        {input(pTut, hTut, 'nombre', 'Nombre*')}
        {input(pTut, hTut, 'apellido1', 'Primer apellido*')}
        {input(pTut, hTut, 'apellido2', 'Segundo apellido')}
        {input(pTut, hTut, 'fecha_nacimiento', 'F. nacimiento*', 'date')}
        {input(pTut, hTut, 'nif', 'DNI')}
        {input(pTut, hTut, 'email', 'Email*', 'email')}
        {input(pTut, hTut, 'telefono', 'Teléfono*')}
        <div className="field full">
          <label>Método contacto*</label>
          <div style={{ display: 'flex', gap: '1rem' }}>
            <label><input type="checkbox" disabled={!edit}
              checked={pTut.metodo_contacto_preferido === 'TEL'}
              onChange={e => hTut('metodo_contacto_preferido',
                e.target.checked ? 'TEL' : '')} /> Teléfono</label>
            <label><input type="checkbox" disabled={!edit}
              checked={pTut.metodo_contacto_preferido === 'EMAIL'}
              onChange={e => hTut('metodo_contacto_preferido',
                e.target.checked ? 'EMAIL' : '')} /> Email</label>
          </div>
        </div>
      </div>
    </>
  );

  const BloquePersona = () => (
    <>
      <h4>Datos personales</h4>
      <div className="form-grid">
        {input(pPer, hPer, 'nombre', 'Nombre*')}
        {input(pPer, hPer, 'apellido1', 'Primer apellido*')}
        {input(pPer, hPer, 'apellido2', 'Segundo apellido')}
        {input(pPer, hPer, 'fecha_nacimiento', 'F. nacimiento*', 'date')}
        {input(pPer, hPer, 'nif', 'DNI*')}
      </div>
    </>
  );

  const BloqueContacto = () => (
    <>
      <h4>Datos de contacto</h4>
      <div className="form-grid">
        {input(pPer, hPer, 'email', 'Email*', 'email')}
        {input(pPer, hPer, 'telefono', 'Teléfono*')}
        {input(pPer, hPer, 'tipo_via', 'Tipo vía')}
        {input(pPer, hPer, 'nombre_calle', 'Nombre calle', 'text', true)}
        {input(pPer, hPer, 'numero', 'Número')}
        {input(pPer, hPer, 'escalera', 'Escalera')}
        {input(pPer, hPer, 'piso', 'Piso')}
        {input(pPer, hPer, 'puerta', 'Puerta')}
        {input(pPer, hPer, 'codigo_postal', 'CP')}
        {input(pPer, hPer, 'ciudad', 'Ciudad')}
        {input(pPer, hPer, 'provincia', 'Provincia')}
        {input(pPer, hPer, 'pais', 'País')}
      </div>
    </>
  );

  /* ------------------- TABS ------------------- */
  const Perfil = (
    <>
      <BloquePaciente />
      {pPac.tipo_paciente !== 'ADULTO' && <BloqueTutor />}
      <BloquePersona />
      <BloqueContacto />
      <div className="field full">
        <label><input type="checkbox" disabled={!edit}
          checked={rgpd} onChange={e => setRgpd(e.target.checked)} /> Acepto la política de privacidad</label>
      </div>
      <div className="modal-footer">
        {!edit
          ? <button className="btn-save" onClick={() => setEdit(true)}>Editar datos</button>
          : <>
            <button className="btn-cancel" onClick={() => setEdit(false)}>Cancelar</button>
            <button className="btn-save" onClick={savePerfil}>Guardar</button>
          </>
        }
      </div>
    </>
  );
  const Trat = (
    <>
      <h4>Tareas</h4>
      {(data.tratamientos || []).length === 0
        ? <p>No hay tareas aún.</p>
        : <ul className="lista-simple tratamientos-lista">
          {data.tratamientos.map(t => (
            <li key={t.id_tratamiento}
              className="tratamiento-item"
              onClick={() => setSelT(t)}>
              <h5>{t.titulo || 'Sin título'}</h5>
              <p>{t.notas?.substring(0, 120) || 'Sin descripción'}</p>
              {t.documentos && t.documentos.length > 0 && (
                <span className="badge-archivo">
                  📎 {t.documentos.length} archivo{t.documentos.length > 1 ? 's' : ''}
                </span>
              )}
            </li>
          ))}
        </ul>}      <SubirTratamiento onDone={fetchData} />
      {selT && (
        <>
          <ModalTratamiento idPac={id} treat={selT}
            onClose={() => setSelT(null)}
            onChange={fetchData} />
        </>
      )}
    </>
  );
  const Docs = (
    <>      <h4>Documentos en historial</h4>
      {(data.documentos || []).length === 0
        ? <p>No hay documentos.</p>
        : <div className="documentos-grid">
          {data.documentos.map(d => (
            <div key={d.id_documento} className="documento-card" onClick={() => setSelDoc(d)}>
              <div className="documento-cabecera">
                <div className="documento-titulo">
                  {d.diagnostico_preliminar ? d.diagnostico_preliminar : 'Documento sin diagnóstico'}
                </div>
                <div className="documento-fecha">
                  {d.fecha_subida ? new Date(d.fecha_subida).toLocaleDateString() : ''}
                </div>
              </div>
              <div className="documento-footer">
                {d.diagnostico_final ?
                  <span className="diagnostico-completo">Diagnóstico final disponible</span> :
                  <span className="diagnostico-pendiente">Diagnóstico final pendiente</span>
                }
              </div>
            </div>
          ))}
        </div>}
      <SubirDocumento onDone={fetchData} />
      {selDoc && (
        <ModalDocumento
          idPac={id}
          doc={selDoc}
          onClose={() => setSelDoc(null)}
          onChange={fetchData}
        />
      )}
    </>
  );

  const Citas = (
    <>
      <h4>Citas del paciente</h4>
      <table className="usuarios-table">
        <thead><tr><th>Fecha</th><th>Estado</th><th>Acciones</th></tr></thead>
        <tbody>
          {(data.citas || []).map(c => (
            <tr key={c.id_cita}>
              <td>{new Date(c.fecha_hora).toLocaleString('es-ES')}</td>
              <td style={{
                background: estadoColor[c.estado] || '#ddd',
                color: '#000', textAlign: 'center'
              }}>{c.estado}</td>
              <td className="acciones-col">
                <EllipsisVertical size={20} className="dropdown-toggle"
                  style={{ cursor: 'pointer' }}
                  onClick={() => setDrop(drop === c.id_cita ? null : c.id_cita)} />
                <div className={`acciones-dropdown ${drop === c.id_cita ? 'show' : ''}`}>
                  {c.estado === 'CONFIRMADA' && <>
                    <a href="#!" onClick={e => { e.preventDefault(); doAccion(c.id_cita, 'MARCAR_ATENDIDA'); }}>Atendida</a>
                    <a href="#!" onClick={e => { e.preventDefault(); doAccion(c.id_cita, 'MARCAR_NO_PRESENTADA'); }}>No presentada</a>
                    <a href="#!" onClick={e => { e.preventDefault(); setRepro({ show: true, id: c.id_cita, fecha: '' }); setDrop(null); }}>Reprogramar</a>
                  </>}
                  {c.estado === 'PENDIENTE_VALIDACION' && <>
                    <a href="#!" onClick={e => { e.preventDefault(); doAccion(c.id_cita, 'CONFIRMAR'); }}>Confirmar</a>
                    <a href="#!" onClick={e => { e.preventDefault(); doAccion(c.id_cita, 'RECHAZAR'); }}>Rechazar</a>
                  </>}
                  {c.estado === 'CAMBIO_SOLICITADO' && <>
                    <a href="#!" onClick={e => { e.preventDefault(); doAccion(c.id_cita, 'ACEPTAR_CAMBIO'); }}>Aceptar cambio</a>
                    <a href="#!" onClick={e => { e.preventDefault(); doAccion(c.id_cita, 'CANCELAR'); }}>Cancelar</a>
                  </>}
                  {c.estado === 'CANCELACION_SOLICITADA' && <>
                    <a href="#!" onClick={e => { e.preventDefault(); doAccion(c.id_cita, 'MANTENER'); }}>Mantener</a>
                    <a href="#!" onClick={e => { e.preventDefault(); doAccion(c.id_cita, 'RECHAZAR'); }}>Rechazar</a>
                  </>}
                </div>
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      {repro.show && (
        <div className="modal-backdrop" onClick={() => setRepro({ show: false, id: null, fecha: '' })}>
          <div className="modal" onClick={e => e.stopPropagation()} style={{ maxWidth: '460px' }}>
            <div className="modal-header"><h3>Reprogramar cita</h3></div>
            <div className="modal-body">
              <div className="field full">
                <label>Nueva fecha y hora</label>
                <input type="datetime-local" value={repro.fecha}
                  onChange={e => setRepro(r => ({ ...r, fecha: e.target.value }))} />
              </div>
            </div>
            <div className="modal-footer">
              <button className="btn-cancel" onClick={() => setRepro({ show: false, id: null, fecha: '' })}>Cancelar</button>
              <button className="btn-save" disabled={!repro.fecha}
                onClick={() => { doAccion(repro.id, 'REPROGRAMAR', repro.fecha); setRepro({ show: false, id: null, fecha: '' }); }}>
                Guardar</button>
            </div>
          </div>
        </div>
      )}
    </>
  );

  /* ---------------- render ---------------- */
  return (
    <div className="usuarios-container" style={{ maxWidth: '950px' }}>
      <h2 className="usuarios-title">{pPer.nombre} {pPer.apellido1}</h2>
      <div className="tab-bar">
        <TabBtn label="Perfil" sel={tab === 'perfil'} onClick={() => setTab('perfil')} />
        <TabBtn label="Tareas" sel={tab === 'trat'} onClick={() => setTab('trat')} />
        <TabBtn label="Historial" sel={tab === 'docs'} onClick={() => setTab('docs')} />
        <TabBtn label="Citas" sel={tab === 'citas'} onClick={() => setTab('citas')} />
      </div>
      <div className="modal-body">
        {tab === 'perfil' && Perfil}
        {tab === 'trat' && Trat}
        {tab === 'docs' && Docs}
        {tab === 'citas' && Citas}
      </div>

      {toast.show && (
        <div className="toast-global centered-toast">
          <div className={`toast-card ${toast.ok ? 'success' : 'error'}`}>
            {toast.ok ? <CheckCircle size={48} className="toast-icon success" /> :
              <XCircle size={48} className="toast-icon error" />}
            <h3 className="toast-title">{toast.t}</h3>
            <p className="toast-text">{toast.m}</p>
          </div>
        </div>
      )}
    </div>
  );
}

/* ===================================================================
   ModalTratamiento  (detalle + eliminar)
   =================================================================== */
function ModalTratamiento({ idPac, treat, onClose, onChange }) {
  const tk = localStorage.getItem('token');
  const [showDeleteModal, setShowDeleteModal] = useState(false);
  const [isDeleting, setIsDeleting] = useState(false);
  const [error, setError] = useState('');

  const del = async () => {
    setIsDeleting(true);
    setError('');
    try {
      await axios.delete(`/prof/pacientes/${idPac}/tareas/${treat.id_tratamiento}`, {
        headers: { Authorization: `Bearer ${tk}` }
      });
      onChange();
      onClose();
    } catch (e) {
      console.error('Error al eliminar:', e);
      setError('Error al eliminar la tarea. Inténtalo de nuevo.');
      setIsDeleting(false);
    }
  };

  return (
    <>
      <div className="modal-backdrop" onClick={onClose}>
        <div className="modal" onClick={e => e.stopPropagation()} style={{ maxWidth: '600px' }}>
          <div className="modal-header">
            <h3>{treat.titulo || 'Sin título'}</h3>
            <button className="modal-close" onClick={onClose}><X /></button>
          </div>
          <div className="modal-body">
            <p><strong>Inicio:</strong>{' '}
              {new Date(treat.fecha_inicio || Date.now()).toLocaleDateString()}</p>
            {treat.fecha_fin && (
              <p><strong>Fin:</strong>{' '}
                {new Date(treat.fecha_fin).toLocaleDateString()}</p>)}
            {treat.frecuencia_sesiones && (
              <p><strong>Frecuencia:</strong> {treat.frecuencia_sesiones} /semana</p>)}
            <h4>Descripción</h4>
            <p>{treat.notas || 'Sin descripción'}</p>

            {/* Sección de adjuntos múltiples */}
            {treat.documentos && treat.documentos.length > 0 && (
              <div className="tratamiento-attachment" style={{ marginTop: '20px' }}>
                <h4>📎 Archivos adjuntos ({treat.documentos.length})</h4>
                {treat.documentos.map((documento, index) => {
                  const docFileUrl = getFileUrl(documento.ruta);
                  const isDocImage = isImage(documento.ruta);

                  return (
                    <div key={documento.id_documento || index} style={{ marginBottom: '15px', padding: '10px', border: '1px solid #eee', borderRadius: '4px' }}>
                      <h5 style={{ margin: '0 0 10px 0', color: '#333' }}>
                        {documento.nombre_archivo || `Documento ${index + 1}`}
                      </h5>

                      {isDocImage ? (
                        <div className="image-container" style={{ textAlign: 'center' }}>
                          <img
                            src={docFileUrl}
                            alt={`Adjunto ${index + 1} de la tarea`}
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
                            <p><small>Ruta: {documento.ruta}</small></p>
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
                            📄 Ver archivo: {documento.nombre_archivo || 'Documento'}
                          </a>
                        </div>
                      )}
                    </div>
                  );
                })}
              </div>
            )}
          </div>
          <div className="modal-footer">
            <button className="btn-delete" onClick={() => setShowDeleteModal(true)}>
              <Trash2 size={18} /> Eliminar
            </button>
            <button className="btn-cancel" onClick={onClose}>Cerrar</button>
          </div>
        </div>
      </div>

      {/* Modal de confirmación de eliminación - renderizado por separado con z-index mayor */}
      {showDeleteModal && (
        <div className="modal-backdrop" onClick={() => setShowDeleteModal(false)} style={{ zIndex: 10000 }}>
          <div className="modal" onClick={e => e.stopPropagation()} style={{ maxWidth: '400px' }}>
            <div className="modal-header">
              <h3>Confirmar eliminación</h3>
            </div>
            <div className="modal-body">
              <p>¿Estás seguro de que quieres eliminar esta tarea?</p>
              <p><strong>{treat.titulo || 'Sin título'}</strong></p>
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

/* ===================================================================
   ModalDocumento  (detalle + eliminar)
   =================================================================== */
function ModalDocumento({ idPac, doc, onClose, onChange }) {
  const tk = localStorage.getItem('token'); const [showDeleteModal, setShowDeleteModal] = useState(false);
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
  }; const updateDiagnostico = async () => {
    // Realizar validación sin cambiar el estado global de error
    if (!diagnosticoFinal.trim()) {
      // En lugar de usar setError, ahora usaremos un estado específico para este campo
      setDiagnosticoFinalError('El diagnóstico final no puede estar vacío');
      return;
    }

    setIsUpdating(true);
    setDiagnosticoFinalError(''); // Limpiar error específico
    setError(''); // Limpiar error general por si acaso

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
      // Usar error específico para el campo, no el error general
      setDiagnosticoFinalError('Error al actualizar el diagnóstico. Inténtalo de nuevo.');
    } finally {
      setIsUpdating(false);
    }
  };

  // Determinar si es una imagen para mostrarla directamente
  const docFileUrl = `${process.env.REACT_APP_API_URL}/${doc.ruta}`;
  const isDocImage = isImage(doc.ruta);

  return (
    <>      <div className="modal-backdrop" onClick={onClose}>
      <div className="modal" onClick={e => e.stopPropagation()} style={{ maxWidth: '600px' }}>
        <div className="modal-header">
          <h3>{doc.diagnostico_preliminar || 'Documento sin diagnóstico'}</h3>
          <button className="modal-close" onClick={onClose}><X /></button>
        </div>          <div className="modal-body">
          {/* Error general eliminado - ahora usamos mensajes de error específicos por campo */}

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
                    // Limpiar el error cuando el usuario empieza a escribir
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

      {/* Modal de confirmación de eliminación - renderizado por separado con z-index mayor */}
      {showDeleteModal && (
        <div className="modal-backdrop" onClick={() => setShowDeleteModal(false)} style={{ zIndex: 10000 }}>
          <div className="modal" onClick={e => e.stopPropagation()} style={{ maxWidth: '400px' }}>
            <div className="modal-header">
              <h3>Confirmar eliminación</h3>
            </div>            <div className="modal-body">
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

/* ===================================================================
   SubirTratamiento  (creación)
   =================================================================== */
function SubirTratamiento({ onDone }) {
  const { id } = useParams();
  const tk = localStorage.getItem('token');

  const [show, setShow] = useState(false);
  const [tit, setTit] = useState('');
  const [desc, setDesc] = useState('');
  const [file, setFile] = useState(null);
  const [fechaInicio, setFechaInicio] = useState('');
  const [fechaFin, setFechaFin] = useState('');
  const [frecuencia, setFrecuencia] = useState('');

  // Errores específicos para cada campo
  const [tituloError, setTituloError] = useState('');
  const [descripcionError, setDescripcionError] = useState('');
  const [fileError, setFileError] = useState('');
  const [fechaError, setFechaError] = useState('');
  const [generalError, setGeneralError] = useState('');
  const [isLoading, setIsLoading] = useState(false);

  const subir = async () => {
    // Limpiar todos los errores al inicio
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
      const fd = new FormData();
      fd.append('titulo', tit);
      fd.append('descripcion', desc);
      if (fechaInicio) fd.append('fecha_inicio', fechaInicio);
      if (fechaFin) fd.append('fecha_fin', fechaFin);
      if (frecuencia) fd.append('frecuencia', frecuencia);
      if (file) fd.append('file', file);

      await axios.post(`/prof/pacientes/${id}/tareas`, fd, {
        headers: { Authorization: `Bearer ${tk}`, 'Content-Type': 'multipart/form-data' }
      });

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
      setGeneralError('Error al guardar tratamiento: ' + (e.response?.data?.mensaje || e.message));
    } finally {
      setIsLoading(false);
    }
  };
  if (!show) return <button className="btn-save" onClick={() => setShow(true)}>Añadir tarea</button>;

  return (
    <div className="modal-backdrop" onClick={() => setShow(false)}>      <div className="modal" onClick={e => e.stopPropagation()} style={{ maxWidth: '600px' }}>
      <div className="modal-header"><h3>Nueva tarea</h3></div>
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
            Tamaño máximo: 10MB
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

/* ===================================================================
   SubirDocumento  (historial)
   =================================================================== */
function SubirDocumento({ onDone }) {
  const { id } = useParams();
  const tk = localStorage.getItem('token'); const [show, setShow] = useState(false);
  const [file, setFile] = useState(null);
  const [diagnosticoPreliminar, setDiagnosticoPreliminar] = useState('');
  // Errores específicos para cada campo
  const [fileError, setFileError] = useState('');
  const [diagnosticoPreliminarError, setDiagnosticoPreliminarError] = useState('');
  const [generalError, setGeneralError] = useState('');
  const [isLoading, setIsLoading] = useState(false); const subir = async () => {
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
  return (<div className="modal-backdrop" onClick={() => setShow(false)}>
    <div className="modal" onClick={e => e.stopPropagation()} style={{ maxWidth: '560px' }}>
      <div className="modal-header"><h3>Subir documento al historial</h3></div>        <div className="modal-body">
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
        )}            <div className="form-grid">
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
            )}              <small style={{ color: '#666', fontSize: '0.85em', marginTop: '5px', display: 'block' }}>
              Este campo es obligatorio y se mostrará como identificador del documento
            </small>
          </div>
        </div>            <div className="field full">
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
