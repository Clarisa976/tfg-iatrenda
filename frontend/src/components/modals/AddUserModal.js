import React, { useState, useEffect } from 'react';
import axios from 'axios';
import '../../styles.css';          
/*  utilidades de validación simple  */
const reEmail   = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const reNum     = /^\d+$/;
const reDni     = /^[0-9]{7,8}[A-Z]$/i;

export default function AddUserModal ({ open, toggle, onSuccess, initialUser })
{
/* ─────────────────────────── estados ─────────────────────────── */
  const hoy        = new Date().toISOString().split('T')[0];
  const [step,setStep] = useState(initialUser?1:0);     // 0-elegir tipo / 1-form
  const [tipo,setTipo] = useState('PACIENTE');          // PACIENTE | PROFESIONAL

  /* bloques de datos */
  const blankP = { nombre:'', apellido1:'', apellido2:'', fecha_nacimiento:'',
                   nif:'', email:'', telefono:'', tipo_via:'', nombre_calle:'',
                   numero:'', escalera:'', piso:'', puerta:'', codigo_postal:'',
                   ciudad:'', provincia:'', pais:'España', fecha_alta:hoy };

  const blankPr = { num_colegiado:'', especialidad:'', fecha_alta:hoy };
  const blankPa = { tipo_paciente:'ADULTO', observ:'' };
  const blankTu = { nombre:'', apellido1:'', apellido2:'', fecha_nacimiento:'',
                    nif:'', email:'', telefono:'', tel:true, emailM:true };

  /* estados */
  const [p ,setP ] = useState(blankP);   // persona
  const [pr,setPr] = useState(blankPr);  // extra profesional
  const [pa,setPa] = useState(blankPa);  // extra paciente
  const [tu,setTu] = useState(blankTu);  // tutor (si menor)

  const [rgpd,setRgpd] = useState(false);
  const [err ,setErr ] = useState({});

  /* Función para manejar clics en el backdrop - versión corregida */
  const handleBackdropClick = (e) => {
    // Si estamos en el paso inicial (selección de tipo), permitir cerrar
    if (!initialUser && step === 0) {
      toggle();
    }
    // En modo edición o con datos ya ingresados, no hacer nada al hacer clic fuera
    // Esto previene el cierre accidental sin usar alertas
  };

/* ─────────────────── helpers mutación ────────────────────────── */
  const mut = (obj,setter,k,v)=> setter({ ...obj, [k]:v });
  const esMenor = tipo==='PACIENTE' && pa.tipo_paciente!=='ADULTO';

/* ───────────────────── reset/carga inicial ───────────────────── */
  const reset = () => {
    setStep(initialUser ? 1 : 0); 
    setTipo('PACIENTE');
    
    // Asegurarse de que todos los campos tengan valores no nulos
    const safeBlankP = {};
    Object.keys(blankP).forEach(key => {
      safeBlankP[key] = blankP[key] || '';
    });
    
    setP(safeBlankP); 
    setPr({ ...blankPr }); 
    setPa({ ...blankPa }); 
    setTu({ ...blankTu });
    
    setRgpd(false); 
    setErr({});
  };

  useEffect(()=>{
    if(!open){ 
      reset(); 
      return; 
    }
    
    // Si no hay initialUser, simplemente dejamos que se muestre el paso 0
    // NO hacemos return aquí
    if(initialUser) {
      /* edición → cargar datos completos */
      const { id } = initialUser;
      console.log('initialUser recibido:', initialUser);
      const token = localStorage.getItem('token');
      axios.get(`${process.env.REACT_APP_API_URL}/admin/usuarios/${id}`, {
        headers: { Authorization: `Bearer ${token}` }
      })
        .then(r=>{
          console.log('Respuesta API:', r.data);
          const { persona, profesional, paciente, tutor } = r.data.data;
          setTipo(persona.rol.toUpperCase());
          setP ({ ...blankP, ...persona, fecha_alta: persona.fecha_alta || hoy });
          if(persona.rol==='profesional' && profesional){
            setPr({ ...blankPr,
              num_colegiado : profesional.num_colegiado   || '',
              especialidad  : profesional.especialidad    || '',
              fecha_alta    : profesional.fecha_alta_profesional || hoy
            });
          }
          if(persona.rol==='paciente' && paciente){
            setPa({ tipo_paciente:paciente.tipo_paciente||'ADULTO',
                    observ:paciente.observaciones_generales||'' });
            if(paciente.tipo_paciente!=='ADULTO' && tutor){
              setTu({ nombre:tutor.nombre, apellido1:tutor.apellido1,
                      apellido2:tutor.apellido2||'', fecha_nacimiento:tutor.fecha_nacimiento||'',
                      nif:tutor.nif||'', email:tutor.email||'', telefono:tutor.telefono||'',
                      tel:tutor.metodo_contacto_preferido==='TEL',
                      emailM:tutor.metodo_contacto_preferido==='EMAIL' });
            }
          }
          setRgpd(true); setStep(1);
        })
        .catch(error => {
          console.error('Error al cargar usuario:', error);
        });
    }
  // eslint-disable-next-line react-hooks/exhaustive-deps
  },[open,initialUser]);

/* ────────────────────── VALIDACIÓN ───────────────────────────── */
  const validar = ()=>{
    const e={};

    /* profesional */
    if(tipo==='PROFESIONAL'){
      if(!pr.num_colegiado.trim()) e.num_colegiado=true;
      if(!pr.especialidad.trim())  e.especialidad =true;
      if(!pr.fecha_alta)           e.fecha_alta   =true;
    }

    /* datos personales */
    if(!p.nombre.trim())       e.nombre=true;
    if(!p.apellido1.trim())    e.apellido1=true;
    if(!p.fecha_nacimiento)    e.fecha_nacimiento=true;

    /* nif solo obligatorio en adultos o profesionales */
    if(tipo==='PROFESIONAL' || (tipo==='PACIENTE'&&pa.tipo_paciente==='ADULTO')){
      if(!p.nif.trim() || !reDni.test(p.nif)) e.nif=true;
      if(!reEmail.test(p.email))      e.email=true;
      if(!reNum.test(p.telefono))     e.telefono=true;
    }

    /* menor ➜ tutor */
    if(esMenor){
      if(!tu.nombre.trim())            e.tu_nombre=true;
      if(!tu.apellido1.trim())         e.tu_apellido1=true;
      if(!tu.fecha_nacimiento)         e.tu_fecha_nacimiento=true;
      if(!reEmail.test(tu.email))      e.tu_email=true;
      if(!reNum.test(tu.telefono))     e.tu_telefono=true;
      if(!tu.tel && !tu.emailM)        e.t_metodo=true;
    }

    if(!rgpd) e.rgpd=true;

    setErr(e);
    return Object.keys(e).length===0;
  };

/* ────────────────────── GUARDAR ──────────────────────────────── */
  const guardar = async () => {
    if (!validar()) return;

    const tk = localStorage.getItem('token');
    const body = {
      tipo,
      persona: p,
      extra: tipo === 'PROFESIONAL'
        ? pr
        : { ...pa, tutor: esMenor ? tu : null }
    };

    try {
      // URL base de la API
      const baseUrl = process.env.REACT_APP_API_URL || 'http://localhost:8081';
      let url = `${baseUrl}/admin/usuarios`;
      let method = 'post';
      
      // Si es edición, modificar URL y método
      if (initialUser && initialUser.id) {
        url = `${baseUrl}/admin/usuarios/${initialUser.id}`;
        method = 'put';
      }
      
      // Realizar petición con Axios
      const response = await axios({
        method,
        url,
        data: body,
        headers: { 
          'Authorization': `Bearer ${tk}`,
          'Content-Type': 'application/json'
        }
      });

      if (response.data.reactivado) {
        onSuccess(p.nombre, {
          ok: true,
          titulo: 'Usuario reactivado',
          mensaje: `El usuario ${p.nombre} ha sido reactivado en el sistema.`
        });
      } else if (response.data.ok) {
        // Solo llamamos a onSuccess, el toast lo mostrará el componente padre
        onSuccess(p.nombre);
      } else {
        console.error('Error en la respuesta:', response.data);
        onSuccess(null, {
          ok: false,
          titulo: 'Error',
          mensaje: response.data.mensaje || 'Error desconocido al guardar'
        });
      }
    } catch (error) {
      console.error('Error al guardar:', error);
      
      // Mostrar mensaje más amigable en caso de error de NIF duplicado
      const mensaje = error.response && error.response.data && error.response.data.mensaje
        ? error.response.data.mensaje
        : 'Error de comunicación con el servidor. Inténtelo de nuevo.';
      
      // Uso del toast personalizado en lugar de alert
      onSuccess(null, {
        ok: false,
        titulo: 'Error',
        mensaje: mensaje
      });
    }
  };

/* ────────────────────── INPUT helper ─────────────────────────── */
const input = (obj, setter, k, label, type = 'text', full = false) => (
  <div className={`field${full ? ' full' : ''}`}>
    <label>{label}</label>
    <input type={type}
      className={err[k] ? 'invalid' : ''}
      value={obj[k] || ''}
      onChange={e => mut(obj, setter, k, e.target.value)} />
    {err[k] && <span className="error-msg">Obligatorio</span>}
  </div>
);

/* ────────────────────── FORMULARIOS ──────────────────────────── */
  const bloqueDatosProfesional = ()=>(
    <>
      <h4>Datos del profesional</h4>
      <div className="form-grid">
        {input(pr,setPr,'num_colegiado','Nº colegiado*')}
        {input(pr,setPr,'especialidad','Especialidad*')}
        {input(pr,setPr,'fecha_alta','Fecha alta*','date')}
      </div>
    </>
  );

  const bloqueDatosPaciente = ()=>(
    <>
      <h4>Datos de paciente</h4>
      <div className="form-grid">
        <div className="field">
          <label>Tipo paciente*</label>
          <select value={pa.tipo_paciente}
                  onChange={e=>mut(pa,setPa,'tipo_paciente',e.target.value)}>
            <option value="ADULTO">Adulto</option>
            <option value="ADOLESCENTE">Adolescente</option>
            <option value="NIÑO">Niño</option>
            <option value="INFANTE">Infante</option>
          </select>
        </div>
        {input(pa,setPa,'observ','Observaciones','text',true)}
      </div>
    </>
  );

  const bloqueTutor = ()=>(
    <>
      <h4>Datos del tutor</h4>
      <div className="form-grid">
        {input(tu,setTu,'nombre','Nombre*')}
        {input(tu,setTu,'apellido1','Primer apellido*')}
        {input(tu,setTu,'apellido2','Segundo apellido')}
        {input(tu,setTu,'fecha_nacimiento','Fecha nacimiento*','date')}
        {input(tu,setTu,'nif','DNI')}
        {input(tu,setTu,'email','Email*','email')}
        {input(tu,setTu,'telefono','Teléfono*')}
        <div className="field full">
          <label>Método de contacto*</label>
          <div style={{display:'flex',gap:'1rem',alignItems:'center'}}>
            <label className="consent-line">
              <input type="checkbox"
                     checked={tu.tel}
                     onChange={e=>mut(tu,setTu,'tel',e.target.checked)}/>
              Teléfono
            </label>
            <label className="consent-line">
              <input type="checkbox"
                     checked={tu.emailM}
                     onChange={e=>mut(tu,setTu,'emailM',e.target.checked)}/>
              Email
            </label>
          </div>
          {err.t_metodo && <span className="error-msg">Seleccione al menos uno</span>}
        </div>
      </div>
    </>
  );

  const bloqueDatosPersonales = ()=>(
    <>
      <h4>Datos personales</h4>
      <div className="form-grid">
        {input(p,setP,'nombre','Nombre*')}
        {input(p,setP,'apellido1','Primer apellido*')}
        {input(p,setP,'apellido2','Segundo apellido')}
        {input(p,setP,'fecha_nacimiento','Fecha nacimiento*','date')}
        {input(p,setP,'nif','DNI*')}
      </div>
    </>
  );

  const bloqueContacto = ()=>(
    <>
      <h4>Datos de contacto</h4>
      <div className="form-grid">
        {input(p,setP,'email','Email*','email')}
        {input(p,setP,'telefono','Teléfono*')}
        {input(p,setP,'tipo_via','Tipo vía')}
        {input(p,setP,'nombre_calle','Nombre calle',null,true)}
        {input(p,setP,'numero','Número')}
        {input(p,setP,'escalera','Escalera')}
        {input(p,setP,'piso','Piso')}
        {input(p,setP,'puerta','Puerta')}
        {input(p,setP,'codigo_postal','Código postal')}
        {input(p,setP,'ciudad','Ciudad')}
        {input(p,setP,'provincia','Provincia')}
        {input(p,setP,'pais','País')}
      </div>
    </>
  );

/* ────────────────────── RENDER MODAL ─────────────────────────── */
  if(!open) return null;

  return(
    <div className="modal-backdrop" onClick={handleBackdropClick}>
      <div className="modal add-user-modal"
           onClick={e=>e.stopPropagation()}
           style={{maxWidth:'900px'}}>
        {/* ─────────────── PASO 0 ─────────────── */}
        {step===0 && (
          <>
            <div className="modal-header">
              <h5>Nuevo usuario</h5>
              <button className="modal-close" onClick={toggle}>×</button>
            </div>
            <div className="modal-body" style={{textAlign:'center'}}>
              <button className="btn-reserva"
                      onClick={()=>{setTipo('PACIENTE');setStep(1);}}>
                Paciente
              </button>
              <button className="btn-reserva"
                      onClick={()=>{setTipo('PROFESIONAL');setStep(1);}}
                      style={{marginLeft:'1rem'}}>
                Profesional
              </button>
            </div>
          </>
        )}

        {/* ─────────────── PASO 1 ─────────────── */}
        {step===1 && (
          <>
            <div className="modal-header">
              <h5>{initialUser?'Editar':'Alta de'} {tipo==='PROFESIONAL'?'profesional':'paciente'}</h5>
              <button className="modal-close" onClick={toggle}>×</button>
            </div>

            <div className="modal-body">
              {tipo==='PROFESIONAL' && bloqueDatosProfesional()}
              {tipo==='PACIENTE'    && bloqueDatosPaciente()}

              {bloqueDatosPersonales()}
              {bloqueContacto()}
              {esMenor && bloqueTutor()}

              {/* RGPD */}
              <div className="field full">
                <label className="consent-line">
                  <input type="checkbox" checked={rgpd}
                         onChange={e=>setRgpd(e.target.checked)}/>
                  He leído y acepto la política de privacidad
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
