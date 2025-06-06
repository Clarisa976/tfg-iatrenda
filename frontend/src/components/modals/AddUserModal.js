// src/components/modals/AddUserModal.js
import React, { useState, useEffect } from 'react';
import axios from 'axios';
import '../../styles.css';

/* ────── validadores básicos ────── */
const reEmail = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const reNum   = /^\d+$/;
const reDni   = /^[0-9]{7,8}[A-Z]$/i;

export default function AddUserModal({ open, toggle, onSuccess, initialUser })
{
  /* ────── estado base ────── */
  const hoy  = new Date().toISOString().split('T')[0];
  const isEdit = !!initialUser;

  const [step, setStep] = useState(isEdit ? 1 : 0);   // 0-elegir | 1-form
  const [tipo, setTipo] = useState('PACIENTE');       // PACIENTE | PROFESIONAL

  /* plantillas */
  const blankP  = { nombre:'', apellido1:'', apellido2:'', fecha_nacimiento:'',
                    nif:'', email:'', telefono:'', tipo_via:'', nombre_calle:'',
                    numero:'', escalera:'', piso:'', puerta:'', codigo_postal:'',
                    ciudad:'', provincia:'', pais:'España', fecha_alta:hoy };

  const blankPr = { num_colegiado:'', especialidad:'', fecha_alta:hoy };
  const blankPa = { tipo_paciente:'ADULTO', observ:'' };
  const blankTu = { nombre:'', apellido1:'', apellido2:'', fecha_nacimiento:'',
                    nif:'', email:'', telefono:'', tel:true, emailM:true };

  /* estados reactivos */
  const [p , setP ] = useState({...blankP});
  const [pr, setPr] = useState({...blankPr});
  const [pa, setPa] = useState({...blankPa});
  const [tu, setTu] = useState({...blankTu});

  const [rgpd, setRgpd] = useState(false);
  const [err , setErr ] = useState({});

  /* util de mutación */
  const mut = (obj, setter, k, v) => setter({ ...obj, [k]:v });

  const esMenor = tipo === 'PACIENTE' && pa.tipo_paciente !== 'ADULTO';

  /* ────── reset local ────── */
  const reset = () => {
    setStep(isEdit ? 1 : 0);
    setTipo('PACIENTE');
    setP ({ ...blankP  });
    setPr({ ...blankPr });
    setPa({ ...blankPa });
    setTu({ ...blankTu });
    setRgpd(false);
    setErr({});
  };

  /* ────── carga del detalle cuando se edita ────── */
  useEffect(() => {
    if (!open) { reset(); return; }

    if (!isEdit) return;                 /* alta → paso 0 */

    const { id } = initialUser;
    const tk = localStorage.getItem('token');
    axios.get(`${process.env.REACT_APP_API_URL}/admin/usuarios/${id}`, {
      headers:{ Authorization:`Bearer ${tk}` }
    })
    .then(r => {
      const { persona, profesional, paciente, tutor } = r.data.data;

      setTipo(persona.rol.toUpperCase());
      setP ({ ...blankP, ...persona, fecha_alta: persona.fecha_alta || hoy });

      if (persona.rol === 'profesional' && profesional) {
        setPr({ ...blankPr,
          num_colegiado : profesional.num_colegiado   || '',
          especialidad  : profesional.especialidad    || '',
          fecha_alta    : profesional.fecha_alta_profesional || hoy
        });
      }

      if (persona.rol === 'paciente' && paciente) {
        setPa({ tipo_paciente: paciente.tipo_paciente || 'ADULTO',
                observ: paciente.observaciones_generales || '' });

        if (paciente.tipo_paciente !== 'ADULTO' && tutor) {
          setTu({ nombre: tutor.nombre,
                  apellido1: tutor.apellido1,
                  apellido2: tutor.apellido2 || '',
                  fecha_nacimiento: tutor.fecha_nacimiento || '',
                  nif: tutor.nif || '',
                  email: tutor.email || '',
                  telefono: tutor.telefono || '',
                  tel: tutor.metodo_contacto_preferido === 'TEL',
                  emailM: tutor.metodo_contacto_preferido === 'EMAIL' });
        }
      }
      setRgpd(true);
      setStep(1);
    })
    .catch(e => console.error('Error cargando usuario', e));
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, initialUser]);

  /* ────── validación ────── */
  const validar = () => {
    const e = {};

    /* profesional */
    if (tipo === 'PROFESIONAL') {
      if (!pr.num_colegiado.trim()) e.num_colegiado = true;
      if (!pr.especialidad.trim())  e.especialidad  = true;
      if (!pr.fecha_alta)           e.fecha_alta    = true;
    }

    /* personales */
    if (!p.nombre.trim())     e.nombre = true;
    if (!p.apellido1.trim())  e.apellido1 = true;
    if (!p.fecha_nacimiento)  e.fecha_nacimiento = true;

    if (tipo === 'PROFESIONAL' ||
        (tipo === 'PACIENTE' && pa.tipo_paciente === 'ADULTO')) {
      if (!p.nif.trim()      || !reDni.test(p.nif))  e.nif = true;
      if (!reEmail.test(p.email))                    e.email = true;
      if (!reNum.test(p.telefono))                   e.telefono = true;
    }

    /* tutor de menor */
    if (esMenor) {
      if (!tu.nombre.trim())        e.tu_nombre = true;
      if (!tu.apellido1.trim())     e.tu_apellido1 = true;
      if (!tu.fecha_nacimiento)     e.tu_fecha_nacimiento = true;
      if (!reEmail.test(tu.email))  e.tu_email = true;
      if (!reNum.test(tu.telefono)) e.tu_telefono = true;
      if (!tu.tel && !tu.emailM)    e.t_metodo = true;
    }

    if (!rgpd) e.rgpd = true;

    setErr(e);
    return Object.keys(e).length === 0;
  };

  /* ────── GUARDAR ────── */
  const guardar = async () => {
    if (!validar()) return;

    const tk = localStorage.getItem('token');

    /* aseguramos que TODOS los campos existen (vacío == '')                *
     * esto permite borrar datos → backend los convertirá en NULL           */
    const fill = (base, tmpl) => {
      const out = {};
      Object.keys(tmpl).forEach(k => { out[k] = base[k] ?? ''; });
      return out;
    };

    const body = {
      tipo,
      persona: fill(p, blankP),
      extra : (tipo === 'PROFESIONAL')
        ? fill(pr, blankPr)
        : { ...fill(pa, blankPa),
            tutor: esMenor ? {
                     ...fill(tu, blankTu),
                     metodo: tu.tel ? 'TEL' : 'EMAIL'
                   } : null }
    };

    const baseURL = process.env.REACT_APP_API_URL || 'http://localhost:8081';
    const url     = isEdit
      ? `${baseURL}/admin/usuarios/${initialUser.id}`
      : `${baseURL}/admin/usuarios`;
    const method  = isEdit ? 'put' : 'post';

    try {
      const res = await axios({
        method, url, data: body,
        headers: { Authorization:`Bearer ${tk}` }
      });

      if (res.data.ok) {
        onSuccess(p.nombre);
        toggle();                    // cerrar modal
      } else {
        onSuccess(null, {
          ok:false, titulo:'Error', mensaje: res.data.mensaje || 'Fallo al guardar'
        });
      }
    } catch (e) {
      const msg = e.response?.data?.mensaje
                || 'Error de comunicación con el servidor.';
      onSuccess(null, { ok:false, titulo:'Error', mensaje:msg });
    }
  };

  /* ────── input helper ────── */
  const input = (obj, setter, k, label, type='text', full=false) => (
    <div className={`field${full ? ' full' : ''}`}>
      <label>{label}</label>
      <input
        type={type}
        className={err[k] ? 'invalid' : ''}
        value={obj[k] || ''}
        onChange={e => mut(obj, setter, k, e.target.value)}
      />
      {err[k] && <span className="error-msg">Obligatorio</span>}
    </div>
  );

  /* ────── bloques UI ────── */
  const bloqueDatosProfesional = () => (
    <>
      <h4>Datos del profesional</h4>
      <div className="form-grid">
        {input(pr, setPr, 'num_colegiado', 'Nº colegiado*')}
        {input(pr, setPr, 'especialidad',  'Especialidad*')}
        {input(pr, setPr, 'fecha_alta',    'Fecha alta*', 'date')}
      </div>
    </>
  );

  const bloqueDatosPaciente = () => (
    <>
      <h4>Datos de paciente</h4>
      <div className="form-grid">
        <div className="field">
          <label>Tipo paciente*</label>
          <select
            value={pa.tipo_paciente}
            onChange={e => mut(pa, setPa, 'tipo_paciente', e.target.value)}
          >
            <option value="ADULTO">Adulto</option>
            <option value="ADOLESCENTE">Adolescente</option>
            <option value="NIÑO">Niño</option>
            <option value="INFANTE">Infante</option>
          </select>
        </div>
        {input(pa, setPa, 'observ', 'Observaciones', 'text', true)}
      </div>
    </>
  );

  const bloqueTutor = () => (
    <>
      <h4>Datos del tutor</h4>
      <div className="form-grid">
        {input(tu, setTu, 'nombre',           'Nombre*')}
        {input(tu, setTu, 'apellido1',        'Primer apellido*')}
        {input(tu, setTu, 'apellido2',        'Segundo apellido')}
        {input(tu, setTu, 'fecha_nacimiento', 'Fecha nacimiento*', 'date')}
        {input(tu, setTu, 'nif',              'DNI')}
        {input(tu, setTu, 'email',            'Email*', 'email')}
        {input(tu, setTu, 'telefono',         'Teléfono*')}
        <div className="field full">
          <label>Método de contacto*</label>
          <div style={{display:'flex',gap:'1rem',alignItems:'center'}}>
            <label className="consent-line">
              <input
                type="checkbox"
                checked={tu.tel}
                onChange={e => mut(tu, setTu, 'tel', e.target.checked)}
              /> Teléfono
            </label>
            <label className="consent-line">
              <input
                type="checkbox"
                checked={tu.emailM}
                onChange={e => mut(tu, setTu, 'emailM', e.target.checked)}
              /> Email
            </label>
          </div>
          {err.t_metodo && <span className="error-msg">Seleccione al menos uno</span>}
        </div>
      </div>
    </>
  );

  const bloqueDatosPersonales = () => (
    <>
      <h4>Datos personales</h4>
      <div className="form-grid">
        {input(p, setP, 'nombre',           'Nombre*')}
        {input(p, setP, 'apellido1',        'Primer apellido*')}
        {input(p, setP, 'apellido2',        'Segundo apellido')}
        {input(p, setP, 'fecha_nacimiento', 'Fecha nacimiento*', 'date')}
        {input(p, setP, 'nif',              'DNI*')}
      </div>
    </>
  );

  const bloqueContacto = () => (
    <>
      <h4>Datos de contacto</h4>
      <div className="form-grid">
        {input(p, setP, 'email',         'Email*', 'email')}
        {input(p, setP, 'telefono',      'Teléfono*')}
        {input(p, setP, 'tipo_via',      'Tipo vía')}
        {input(p, setP, 'nombre_calle',  'Nombre calle', 'text', true)}
        {input(p, setP, 'numero',        'Número')}
        {input(p, setP, 'escalera',      'Escalera')}
        {input(p, setP, 'piso',          'Piso')}
        {input(p, setP, 'puerta',        'Puerta')}
        {input(p, setP, 'codigo_postal', 'Código postal')}
        {input(p, setP, 'ciudad',        'Ciudad')}
        {input(p, setP, 'provincia',     'Provincia')}
        {input(p, setP, 'pais',          'País')}
      </div>
    </>
  );

  /* ────── render ────── */
  if (!open) return null;

  /* backdrop: sólo cierra si estamos en el primer paso de alta */
  const handleBackdrop = () => { if (!isEdit && step === 0) toggle(); };

  return (
    <div className="modal-backdrop" onClick={handleBackdrop}>
      <div
        className="modal add-user-modal"
        onClick={e => e.stopPropagation()}
        style={{ maxWidth:'900px' }}
      >
        {/* PASO 0: elegir tipo */}
        {step === 0 && (
          <>
            <div className="modal-header">
              <h5>Nuevo usuario</h5>
              <button className="modal-close" onClick={toggle}>×</button>
            </div>
            <div className="modal-body" style={{ textAlign:'center' }}>
              <button
                className="btn-reserva"
                onClick={() => { setTipo('PACIENTE'); setStep(1); }}
              >
                Paciente
              </button>
              <button
                className="btn-reserva"
                style={{ marginLeft:'1rem' }}
                onClick={() => { setTipo('PROFESIONAL'); setStep(1); }}
              >
                Profesional
              </button>
            </div>
          </>
        )}

        {/* PASO 1: formulario */}
        {step === 1 && (
          <>
            <div className="modal-header">
              <h5>{isEdit ? 'Editar' : 'Alta de'} {tipo === 'PROFESIONAL' ? 'profesional' : 'paciente'}</h5>
              <button className="modal-close" onClick={toggle}>×</button>
            </div>

            <div className="modal-body">
              {tipo === 'PROFESIONAL' && bloqueDatosProfesional()}
              {tipo === 'PACIENTE'    && bloqueDatosPaciente()}
              {bloqueDatosPersonales()}
              {bloqueContacto()}
              {esMenor && bloqueTutor()}

              <div className="field full">
                <label className="consent-line">
                  <input
                    type="checkbox"
                    checked={rgpd}
                    onChange={e => setRgpd(e.target.checked)}
                  /> He leído y acepto la política de privacidad
                </label>
                {err.rgpd && <span className="error-msg">Requerido</span>}
              </div>
            </div>

            <div className="modal-footer">
              <button className="btn-cancel" onClick={toggle}>Cancelar</button>
              <button className="btn-save"   onClick={guardar}>Guardar</button>
            </div>
          </>
        )}
      </div>
    </div>
  );
}
