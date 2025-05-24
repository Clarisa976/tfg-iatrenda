import React, { useState, useRef } from 'react';
import axios from 'axios';
import DatePicker, { registerLocale } from 'react-datepicker';
import es from 'date-fns/locale/es';
import { setHours, setMinutes } from 'date-fns';
import { Toast, ToastBody } from 'reactstrap';

import 'react-datepicker/dist/react-datepicker.css';
import 'bootstrap/dist/css/bootstrap.min.css'; 

registerLocale('es', es);

const HORA_INICIO = 10;   // 10 h
const HORA_FIN    = 17;   // última cita 17-18 h

export default function ReservarCitaModal({ onClose }) {
  /* ───────── estado ───────── */
  const [form, setForm] = useState({
    nombre:'', email:'', tel:'', motivo:'',
    fecha:null, acepto:false,
  });
  const [errs, setErrs]   = useState({});
  const [toast,setToast]  = useState({show:false,msg:'',ok:true});
  const nombreRef         = useRef(null);

  const set = (k,v)=>setForm(p=>({...p,[k]:v}));

  /* ───────── validación ───────── */
  const validar = () => {
    const e = {};
    if (!form.nombre.trim())  e.nombre = 'Este campo no puede quedar vacío';
    if (!form.email.trim())   e.email  = 'Este campo no puede quedar vacío';
    if (!form.motivo.trim())  e.motivo = 'Este campo no puede quedar vacío';
    if (!form.fecha)          e.fecha  = 'Seleccione un día y hora';
    if (!form.acepto)         e.acepto = 'Debe aceptar los términos';
    setErrs(e);
    return !Object.keys(e).length;
  };

  /* ───────── envío ───────── */
  const handleSubmit = async ev=>{
    ev.preventDefault();
    if(!validar()){ nombreRef.current?.focus(); return; }

    try{
      await axios.post(`${process.env.REACT_APP_API_URL}/citas`,{
        ...form,
        fecha:form.fecha.toISOString(),
        origen:'WEB',
      });
      setToast({show:true,ok:true,
        msg:'¡Reserva enviada! Te avisaremos cuando el equipo confirme tu cita'});
      onClose();                                           // cierra modal
    }catch(e){
      const msg = e.response?.data?.mensaje || 'Error al reservar la cita';
      setToast({show:true,ok:false,msg});
    }
  };

  /* ───────── restricciones fecha/hora ───────── */
  const esDiaLaboral = d => { const w=d.getDay(); return w>=1 && w<=5; };
  const esHoraLaboral= d => {
    const h=d.getHours(); return h>=HORA_INICIO && h<=HORA_FIN;
  };

  return (
    <>
      {/* TOAST (fuera del modal para que se vea tras cerrarlo) */}
      {toast.show && (
        <div className="toast-container">
          <Toast fade isOpen={toast.show}
                 className={toast.ok?'toast-success':'toast-error'}
                 onAnimationEnd={()=>setToast(t=>({...t,show:false}))}>
            <ToastBody>{toast.msg}</ToastBody>
          </Toast>
        </div>
      )}

      {/* MODAL */}
      <div className="modal-backdrop" onClick={onClose}>
        <div className="modal" onClick={e=>e.stopPropagation()}>
          <button className="modal-close" onClick={onClose}>×</button>

          <h2 className="appt-title">Reserva tu cita</h2>

          <form onSubmit={handleSubmit}>
            {/* Nombre */}
            <div className="field">
              <label>Nombre*</label>
              <input
                ref={nombreRef}
                value={form.nombre}
                onChange={e=>set('nombre',e.target.value)}
                className={errs.nombre?'invalid':''}
              />
              {errs.nombre && <span className="field-error">{errs.nombre}</span>}
            </div>

            {/* Email */}
            <div className="field">
              <label>Email*</label>
              <input
                type="email"
                value={form.email}
                onChange={e=>set('email',e.target.value)}
                className={errs.email?'invalid':''}
              />
              {errs.email && <span className="field-error">{errs.email}</span>}
            </div>

            {/* Tel */}
            <div className="field">
              <label>Teléfono</label>
              <input
                value={form.tel}
                onChange={e=>set('tel',e.target.value)}
              />
            </div>

            {/* Motivo */}
            <div className="field">
              <label>Motivo*</label>
              <textarea
                value={form.motivo}
                onChange={e=>set('motivo',e.target.value)}
                className={errs.motivo?'invalid':''}
              />
              {errs.motivo && <span className="field-error">{errs.motivo}</span>}
            </div>

            {/* Calendario inline */}
            <div className="field">
              <label>Seleccione un día*</label>
              <DatePicker
                selected={form.fecha}
                onChange={d=>set('fecha',d)}
                inline
                locale="es"
                showTimeSelect
                timeIntervals={60}
                dateFormat="dd/MM/yyyy HH:mm"
                minTime={setMinutes(setHours(new Date(),HORA_INICIO),0)}
                maxTime={setMinutes(setHours(new Date(),HORA_FIN),0)}
                filterDate={esDiaLaboral}
                filterTime={esHoraLaboral}
                className={errs.fecha?'invalid':''}
              />
              {errs.fecha && <span className="field-error">{errs.fecha}</span>}
            </div>

            {/* Consentimiento */}
            <div className="field consent-line">
              <input
                type="checkbox"
                checked={form.acepto}
                onChange={e=>set('acepto',e.target.checked)}
              />
              <span>
                He leído y acepto los <a href="/terminos" target="_blank"
                rel="noopener noreferrer">Términos y condiciones de uso</a>
              </span>
            </div>
            {errs.acepto && <span className="field-error">{errs.acepto}</span>}

            <button type="submit" className="btn-submit btn-full">
              Confirmar cita
            </button>
          </form>
        </div>
      </div>
    </>
  );
}
