// src/pages/profesional/PerfilProfesional.jsx
import React, { useState, useEffect } from 'react';
import axios from 'axios';
import { CheckCircle, XCircle } from 'lucide-react';
import '../../styles.css';

export default function PerfilProfesional() {
  const hoy = new Date().toISOString().split('T')[0];

  // Modo edición / solo lectura
  const [editMode, setEditMode] = useState(false);

  // Datos de persona (editable)
  const [form, setForm] = useState({
    nombre: '',
    apellido1: '',
    apellido2: '',
    email: '',
    telefono: '',
    fecha_nacimiento: '',
    tipo_via: '',
    nombre_calle: '',
    numero: '',
    escalera: '',
    piso: '',
    puerta: '',
    codigo_postal: '',
    ciudad: '',
    provincia: '',
    pais: 'España'
  });

  // Datos de profesional (solo lectura)
  const [profData, setProfData] = useState({
    num_colegiado: '',
    especialidad: '',
    fecha_alta: ''
  });

  // Estado de consentimiento
  const [consent, setConsent] = useState(false);

  const [toast, setToast] = useState({ show:false, ok:true, titulo:'', msg:'' });
  const [errors, setErrors] = useState({});

  // Configuración global axios
  useEffect(() => {
    axios.defaults.baseURL = process.env.REACT_APP_API_URL;
    const token = localStorage.getItem('token');
    if (token) axios.defaults.headers.common['Authorization'] = `Bearer ${token}`;
  }, []);

  // Carga inicial del perfil + consentimiento
  useEffect(() => {
    async function cargar() {
      try {
        // 1) perfil
        const { data } = await axios.get('/prof/perfil');
        if (!data.ok) throw new Error(data.mensaje);
        const { persona, profesional } = data.data;
        setForm(persona);
        setProfData({
          num_colegiado: profesional.num_colegiado,
          especialidad: profesional.especialidad,
          fecha_alta: profesional.fecha_alta_profesional?.split('T')[0] || hoy
        });
        if (data.token) localStorage.setItem('token', data.token);
      } catch {
        setToast({ show:true, ok:false, titulo:'Error', msg:'No se pudo cargar el perfil' });
      }
      try {
        // 2) consentimiento
        const { data } = await axios.get('/consentimiento');
        if (data.ok) setConsent(data.consentimiento && !data.revocado);
      } catch {
        // ignorar
      }
    }
    cargar();
  }, [hoy]);

  // Ocultar toast
  useEffect(() => {
    if (!toast.show) return;
    const id = setTimeout(() => setToast(t=>({...t,show:false})), 5000);
    return () => clearTimeout(id);
  }, [toast.show]);

  // Validación básica antes de enviar
  const validar = () => {
    const errs = {};
    ['nombre','apellido1','email','fecha_nacimiento'].forEach(k => {
      if (!form[k]?.toString().trim()) errs[k] = true;
    });
    setErrors(errs);
    return Object.keys(errs).length === 0;
  };

  // Manejador de cambios
  const handleChange = e => {
    const { name, value } = e.target;
    setForm(f => ({ ...f, [name]: value }));
  };

  // Cancelar edición (recarga datos originales)
  const handleCancel = () => {
    setEditMode(false);
    setErrors({});
    axios.get('/prof/perfil')
      .then(({ data }) => {
        if (data.ok) setForm(data.data.persona);
      })
      .catch(()=>{/* silently */});
  };

  // Enviar cambios + consentimientos
  const handleSubmit = async e => {
    e.preventDefault();
    if (!validar()) return;
    try {
      // 1) actualizar persona
      const { data } = await axios.put('/prof/perfil', { persona: form });
      if (!data.ok) throw new Error();
      if (data.token) localStorage.setItem('token', data.token);

      // 2) si el consentimiento cambió, llamar a la ruta correspondiente
      if (consent) {
        await axios.post('/consentimiento', { canal:'WEB' });
      } else {
        await axios.post('/consentimiento/revocar', {});
      }

      setToast({ show:true, ok:true, titulo:'Éxito', msg:'Perfil y consentimiento actualizados' });
      setEditMode(false);
    } catch {
      setToast({ show:true, ok:false, titulo:'Error', msg:'No se pudo guardar' });
    }
  };

  // Render de un campo editable
  const input = (key, label, type='text', full=false) => (
    <div className={`field${full?' full':''}`} key={key}>
      <label>{label}</label>
      <input
        type={type}
        name={key}
        value={form[key]||''}
        onChange={editMode ? handleChange : undefined}
        readOnly={!editMode}
        className={editMode ? (errors[key]?'invalid':'') : 'readonly-input'}
      />
      {editMode && errors[key] && <span className="error-msg">Obligatorio</span>}
    </div>
  );

  // Render de un campo solo lectura
  const readOnlyField = (key, label, type='text') => (
    <div className="field" key={key}>
      <label>{label}</label>
      <input
        type={type}
        value={profData[key]||''}
        readOnly
        className="readonly-input"
      />
    </div>
  );

  return (
    <div className="usuarios-container perfil-profesional-container">
      <h2 className="usuarios-title">Mi Perfil</h2>
      <div className="modal-body">
        <form onSubmit={handleSubmit}>
          {/* Sección profesional */}
          <h4>Datos del profesional</h4>
          <div className="form-grid">
            {readOnlyField('num_colegiado','Nº colegiado')}
            {readOnlyField('especialidad','Especialidad')}
            {readOnlyField('fecha_alta','Fecha alta','date')}
          </div>

          {/* Datos personales */}
          <h4>Datos personales</h4>
          <div className="form-grid">
            {input('nombre','Nombre*')}
            {input('apellido1','Primer apellido*')}
            {input('apellido2','Segundo apellido')}
            {input('fecha_nacimiento','Fecha nacimiento*','date')}
          </div>

          {/* Datos de contacto */}
          <h4>Datos de contacto</h4>
          <div className="form-grid">
            {input('email','Email*','email')}
            {input('telefono','Teléfono')}
            {input('tipo_via','Tipo vía')}
            {input('nombre_calle','Nombre calle','text',true)}
            {input('numero','Número')}
            {input('escalera','Escalera')}
            {input('piso','Piso')}
            {input('puerta','Puerta')}
            {input('codigo_postal','Código postal')}
            {input('ciudad','Ciudad')}
            {input('provincia','Provincia')}
            {input('pais','País')}
          </div>          {/* Consentimiento */}
          <h4>Consentimiento de datos</h4>
          <div className="field checkbox-field">
            <label>
              <input
                type="checkbox"
                checked={consent}
                onChange={e=>setConsent(e.target.checked)}
                disabled={!editMode}
              />{' '}
              Acepto el uso y tratamiento de mis datos personales según la{' '}
              <a 
                href="/politica-privacidad" 
                target="_blank" 
                rel="noopener noreferrer"
                style={{ color: 'var(--blue)', textDecoration: 'underline' }}
              >
                Política de Privacidad
              </a>
            </label>
          </div>

          {/* Botones */}
          <div className="modal-footer">
            {editMode ? (
              <>
                <button type="button" className="btn-cancel" onClick={handleCancel}>
                  Cancelar
                </button>
                <button type="submit" className="btn-save">Guardar</button>
              </>
            ) : (
              <button type="button" className="btn-save" onClick={()=>setEditMode(true)}>
                Editar
              </button>
            )}
          </div>
        </form>
      </div>

      {/* Toast */}
      {toast.show && (
<div className="toast-global centered-toast">
    <div className={`toast-card ${toast.ok ? 'success' : 'error'}`}>
      {toast.ok
        ? <CheckCircle size={48} className="toast-icon success" />
        : <XCircle      size={48} className="toast-icon error" />
      }
      <h3 className="toast-title">{toast.titulo}</h3>
      <p className="toast-text">{toast.msg}</p>
    </div>
  </div>
      )}
    </div>
  );
}
