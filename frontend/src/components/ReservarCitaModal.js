import React, { useState, useRef } from 'react';
import axios from 'axios';
import DatePicker, { registerLocale } from 'react-datepicker';
import es from 'date-fns/locale/es';
import { setHours, setMinutes, format } from 'date-fns';

import 'react-datepicker/dist/react-datepicker.css';

registerLocale('es', es);

const HORA_INICIO = 9;
const HORA_FIN = 17;

export default function ReservarCitaModal({ onClose, onSuccess, onError }) {
  const [form, setForm] = useState({
    nombre: '', email: '', tel: '', motivo: '', fecha: null, acepto: false,
  });
  const [errs, setErrs] = useState({});
  const nombreRef = useRef(null);

  const set = (k, v) => setForm(p => ({ ...p, [k]: v }));

  const validar = () => {
    const e = {};
    if (!form.nombre.trim()) e.nombre = 'Este campo no puede quedar vacío';
    if (!form.email.trim()) e.email = 'Este campo no puede quedar vacío';
    if (!form.motivo.trim()) e.motivo = 'Este campo no puede quedar vacío';
if (!form.fecha) {
     e.fecha = 'Seleccione un día y hora';
   } else {
     // Validar que no sea anterior a hoy
     const hoy = new Date();
     hoy.setHours(0,0,0,0);
     if (form.fecha < hoy) {
       e.fecha = 'No puedes reservar en una fecha pasada';
     }
   }
    if (!form.acepto) e.acepto = 'Debe aceptar los términos';
    setErrs(e);
    return !Object.keys(e).length;
  };

  const handleSubmit = async ev => {
    ev.preventDefault();
    if (!validar()) {
      nombreRef.current?.focus();
      return;
    }

    try {
      const fechaStr = format(form.fecha, 'yyyy-MM-dd HH:mm:ss');
      await axios.post(`${process.env.REACT_APP_API_URL}/reservar-cita`, {
        nombre: form.nombre,
        email: form.email,
        tel: form.tel,
        motivo: form.motivo,
        fecha: fechaStr,
      });
      onSuccess('¡Reserva enviada! Te avisaremos cuando el equipo confirme tu cita');
    } catch (err) {
      const msg = err.response?.data?.mensaje || 'Error al reservar la cita';
      onError(msg);
    }
  };

  return (
    <div className="modal-backdrop" onClick={onClose}>
      <div className="modal" onClick={e => e.stopPropagation()}>
        <button className="modal-close" onClick={onClose}>×</button>
        <h2 className="appt-title">Reserva tu cita</h2>

        <form onSubmit={handleSubmit}>
          {/* Nombre */}
          <div className="field">
            <label>Nombre*</label>
            <input
              ref={nombreRef}
              value={form.nombre}
              onChange={e => set('nombre', e.target.value)}
              className={errs.nombre ? 'invalid' : ''}
            />
            {errs.nombre && <span className="field-error">{errs.nombre}</span>}
          </div>
          {/* Email */}
          <div className="field">
            <label>Email*</label>
            <input
              type="email"
              value={form.email}
              onChange={e => set('email', e.target.value)}
              className={errs.email ? 'invalid' : ''}
            />
            {errs.email && <span className="field-error">{errs.email}</span>}
          </div>
          {/* Teléfono */}
          <div className="field">
            <label>Teléfono</label>
            <input
              value={form.tel}
              onChange={e => set('tel', e.target.value)}
            />
          </div>
          {/* Motivo */}
          <div className="field">
            <label>Motivo*</label>
            <textarea
              value={form.motivo}
              onChange={e => set('motivo', e.target.value)}
              className={errs.motivo ? 'invalid' : ''}
            />
            {errs.motivo && <span className="field-error">{errs.motivo}</span>}
          </div>
          {/* Fecha/Hora */}
          <div className="field">
            <label>Seleccione un día*</label>
            <DatePicker
              selected={form.fecha}
              onChange={d => set('fecha', d)}
              inline
              locale="es"
              showTimeSelect
              timeIntervals={60}
              dateFormat="dd/MM/yyyy HH:mm"
              minTime={setMinutes(setHours(new Date(), HORA_INICIO), 0)}
              maxTime={setMinutes(setHours(new Date(), HORA_FIN), 0)}
              filterDate={d => {
                const hoy = new Date();
                hoy.setHours(0, 0, 0, 0);
                const diaSemana = d.getDay();
                return diaSemana >= 1
                  && diaSemana <= 5
                  && d >= hoy;
              }}
              filterTime={d => { const h = d.getHours(); return h >= HORA_INICIO && h <= HORA_FIN; }}
              className={errs.fecha ? 'invalid' : ''}
            />
            {errs.fecha && <span className="field-error">{errs.fecha}</span>}
          </div>
          {/* Consentimiento */}
          <div className="field consent-line">
            <input
              type="checkbox"
              checked={form.acepto}
              onChange={e => set('acepto', e.target.checked)}
            />
            <span>
              He leído y acepto los <a href="/terminos">Términos y condiciones de uso</a>
            </span>
          </div>
          {errs.acepto && <span className="field-error">{errs.acepto}</span>}

          <button type="submit" className="btn-submit btn-full">
            Confirmar cita
          </button>
        </form>
      </div>
    </div>
  );
}
