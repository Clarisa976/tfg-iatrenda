import React, { useState, useRef } from 'react';
import DatePicker, { registerLocale } from 'react-datepicker';
import { setHours, setMinutes, format } from 'date-fns';
import es from 'date-fns/locale/es';
registerLocale('es', es);

const HORA_INICIO = 10;
const HORA_FIN = 17;

export default function ReservarCitaModal({ onClose, onSuccess, onError }) {
  const [form, setForm] = useState({
    nombre: '', email: '', tel: '', motivo: '', fecha: null, acepto: false,
  });
  const [errs, setErrs] = useState({});
  const [loading, setLoading] = useState(false);
  const nombreRef = useRef(null);

  const set = (k, v) => setForm(p => ({ ...p, [k]: v }));
  const validar = () => {
    const e = {};
    if (!form.nombre.trim()) e.nombre = 'Este campo no puede quedar vacío';

    // Validación de email
    if (!form.email.trim()) {
      e.email = 'Este campo no puede quedar vacío';
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email.trim())) {
      e.email = 'Introduce un email válido';
    }

    // Validación de teléfono
    if (form.tel && form.tel.trim() && !/^[0-9]{9}$/.test(form.tel.trim())) {
      e.tel = 'Introduce un número de teléfono';
    }

    if (!form.motivo.trim()) e.motivo = 'Este campo no puede quedar vacío';

    if (!form.fecha) {
      e.fecha = 'Seleccione un día y hora';
    } else {
      // Validar que no sea anterior a hoy
      const hoy = new Date();
      hoy.setHours(0, 0, 0, 0);
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

    setLoading(true);

    try {
      const fechaStr = format(form.fecha, 'yyyy-MM-dd HH:mm:ss');
      console.log("Enviando datos de cita:", {
        nombre: form.nombre,
        email: form.email,
        tel: form.tel || '',
        motivo: form.motivo,
        fecha: fechaStr,
      });
      console.log("Tipo de dato del teléfono:", typeof (form.tel || ''));
      console.log("Valor exacto del teléfono:", JSON.stringify(form.tel || ''));

      // Usar fetch con manejo adecuado de CORS
      const baseURL = process.env.REACT_APP_API_URL || 'http://localhost:8081';
      const url = `${baseURL}/reservar-cita`;

      console.log(`Enviando solicitud a ${url}`);
      const response = await fetch(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        credentials: 'include',
        body: JSON.stringify({
          nombre: form.nombre,
          email: form.email,
          tel: form.tel ? form.tel.trim() : '',
          motivo: form.motivo,
          fecha: fechaStr
        })
      });

      // Manejar respuesta no-JSON
      let data;
      const contentType = response.headers.get('content-type');
      if (contentType && contentType.includes('application/json')) {
        data = await response.json();
      } else {

        const text = await response.text();
        console.log("Respuesta en texto plano:", text);
        try {
          data = JSON.parse(text);
        } catch (e) {
          console.error("Error al parsear respuesta:", e);
          throw new Error(`El servidor respondió con un formato no esperado: ${text.substring(0, 100)}`);
        }
      }

      console.log("Respuesta del servidor:", data);

      if (!response.ok) {
        throw new Error(data.mensaje || `Error del servidor: ${response.status}`);
      }

      if (data.ok) {
        onSuccess('¡Reserva enviada! Te avisaremos cuando el equipo confirme tu cita');
      } else {
        throw new Error(data.mensaje || 'Error al reservar la cita');
      }
    } catch (err) {
      console.error("Error reservando cita:", err);
      const msg = err.message || 'Error al reservar la cita';
      onError(msg);
    } finally {
      setLoading(false);
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
              className={errs.tel ? 'invalid' : ''}
              type="tel"
              pattern="[0-9]{9}"
            />
            {errs.tel && <span className="field-error">{errs.tel}</span>}
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

          <button
            type="submit"
            className="btn-submit btn-full"
            disabled={loading}
          >
            {loading ? 'Procesando...' : 'Confirmar cita'}
          </button>
        </form>
      </div>
    </div>
  );
}
