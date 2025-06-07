import React, { useState, useEffect, useCallback, memo } from 'react';
import axios from 'axios';
import { useParams } from 'react-router-dom';
import { CheckCircle, X, XCircle, EllipsisVertical } from 'lucide-react';
import DatePicker, { registerLocale } from 'react-datepicker';
import { setHours, setMinutes, format } from 'date-fns';
import es from 'date-fns/locale/es';

import '../../styles.css';

// Importar componentes modales separados
import ModalDocumento from '../../components/modals/ModalDocumento';
import SubirTratamiento from '../../components/modals/SubirTratamiento';
import SubirDocumento from '../../components/modals/SubirDocumento';
import ModalTratamiento from '../../components/modals/ModalTratamiento';


registerLocale('es', es);


/* ----------  helpers ---------- */
const TabBtn = ({ label, sel, onClick }) => (
  <button className="tab-btn"
    style={{ background: sel ? 'var(--blue)' : '#d9d9d9', color: sel ? '#fff' : '#000' }}
    onClick={onClick}>{label}</button>
);

const estadoColor = {
  CONFIRMADA: '#6fcf97', ATENDIDA: '#6fcf97',
  PENDIENTE_VALIDACION: '#f2c94c',
  NO_PRESENTADA: '#9b51e0',
  CANCELADA: '#f04b4b',
  CAMBIO_SOLICITADO: 'var(--blue)',
  CANCELACION_SOLICITADA: '#eb5757'
};

/* ================= COMPONENTES SEPARADOS ================= */

// Componente Input memoizado
const InputField = memo(({ obj, onChange, fieldKey, label, type = 'text', full = false, edit }) => {
  return (
    <div className={`field${full ? ' full' : ''}`}>
      <label>{label}</label>
      <input
        type={type}
        readOnly={!edit}
        className={!edit ? 'readonly-input' : ''}
        value={obj[fieldKey] || ''}
        onChange={e => onChange(fieldKey, e.target.value)}
      />
    </div>
  );
});

// Componente BloquePaciente memoizado
const BloquePaciente = memo(({ pPac, hPac, edit }) => (
  <>
    <h4>Datos de paciente</h4>
    <div className="form-grid">
      <div className="field">
        <label>Tipo paciente*</label>
        <select
          disabled={!edit}
          className={!edit ? 'readonly-input' : ''}
          value={pPac.tipo_paciente || 'ADULTO'}
          onChange={e => hPac('tipo_paciente', e.target.value)}
        >
          <option value="ADULTO">Adulto</option>
          <option value="ADOLESCENTE">Adolescente</option>
          <option value="NIÑO">Niño</option>
          <option value="INFANTE">Infante</option>
        </select>
      </div>
      <InputField
        obj={pPac}
        onChange={hPac}
        fieldKey="observaciones_generales"
        label="Observaciones"
        type="text"
        full={true}
        edit={edit}
      />
    </div>
  </>
));

// Componente BloqueTutor memoizado
const BloqueTutor = memo(({ pTut, hTut, edit }) => (
  <>
    <h4>Datos del tutor</h4>
    <div className="form-grid">
      <InputField obj={pTut} onChange={hTut} fieldKey="nombre" label="Nombre*" edit={edit} />
      <InputField obj={pTut} onChange={hTut} fieldKey="apellido1" label="Primer apellido*" edit={edit} />
      <InputField obj={pTut} onChange={hTut} fieldKey="apellido2" label="Segundo apellido" edit={edit} />
      <InputField obj={pTut} onChange={hTut} fieldKey="fecha_nacimiento" label="F. nacimiento*" type="date" edit={edit} />
      <InputField obj={pTut} onChange={hTut} fieldKey="nif" label="DNI" edit={edit} />
      <InputField obj={pTut} onChange={hTut} fieldKey="email" label="Email*" type="email" edit={edit} />
      <InputField obj={pTut} onChange={hTut} fieldKey="telefono" label="Teléfono*" edit={edit} />
      <div className="field full">
        <label>Método contacto*</label>
        <div style={{ display: 'flex', gap: '1rem' }}>
          <label>
            <input
              type="checkbox"
              disabled={!edit}
              checked={pTut.metodo_contacto_preferido === 'TEL'}
              onChange={e => hTut('metodo_contacto_preferido', e.target.checked ? 'TEL' : '')}
            /> Teléfono
          </label>
          <label>
            <input
              type="checkbox"
              disabled={!edit}
              checked={pTut.metodo_contacto_preferido === 'EMAIL'}
              onChange={e => hTut('metodo_contacto_preferido', e.target.checked ? 'EMAIL' : '')}
            /> Email
          </label>
        </div>
      </div>
    </div>
  </>
));

// Componente BloquePersona memoizado
const BloquePersona = memo(({ pPer, hPer, edit }) => (
  <>
    <h4>Datos personales</h4>
    <div className="form-grid">
      <InputField obj={pPer} onChange={hPer} fieldKey="nombre" label="Nombre*" edit={edit} />
      <InputField obj={pPer} onChange={hPer} fieldKey="apellido1" label="Primer apellido*" edit={edit} />
      <InputField obj={pPer} onChange={hPer} fieldKey="apellido2" label="Segundo apellido" edit={edit} />
      <InputField obj={pPer} onChange={hPer} fieldKey="fecha_nacimiento" label="F. nacimiento*" type="date" edit={edit} />
      <InputField obj={pPer} onChange={hPer} fieldKey="nif" label="DNI*" edit={edit} />
    </div>
  </>
));

// Componente BloqueContacto memoizado
const BloqueContacto = memo(({ pPer, hPer, edit }) => (
  <>
    <h4>Datos de contacto</h4>
    <div className="form-grid">
      <InputField obj={pPer} onChange={hPer} fieldKey="email" label="Email*" type="email" edit={edit} />
      <InputField obj={pPer} onChange={hPer} fieldKey="telefono" label="Teléfono*" edit={edit} />
      <InputField obj={pPer} onChange={hPer} fieldKey="tipo_via" label="Tipo vía" edit={edit} />
      <InputField obj={pPer} onChange={hPer} fieldKey="nombre_calle" label="Nombre calle" full={true} edit={edit} />
      <InputField obj={pPer} onChange={hPer} fieldKey="numero" label="Número" edit={edit} />
      <InputField obj={pPer} onChange={hPer} fieldKey="escalera" label="Escalera" edit={edit} />
      <InputField obj={pPer} onChange={hPer} fieldKey="piso" label="Piso" edit={edit} />
      <InputField obj={pPer} onChange={hPer} fieldKey="puerta" label="Puerta" edit={edit} />
      <InputField obj={pPer} onChange={hPer} fieldKey="codigo_postal" label="CP" edit={edit} />
      <InputField obj={pPer} onChange={hPer} fieldKey="ciudad" label="Ciudad" edit={edit} />
      <InputField obj={pPer} onChange={hPer} fieldKey="provincia" label="Provincia" edit={edit} />
      <InputField obj={pPer} onChange={hPer} fieldKey="pais" label="País" edit={edit} />
    </div>
  </>
));

/* ================= COMPONENTE PRINCIPAL ================= */
export default function PerfilPacienteProfesional() {
  const { id } = useParams();

  /* ---------------- estado ---------------- */
  const [data, setData] = useState(null);
  const [tab, setTab] = useState('perfil');
  const [edit, setEdit] = useState(false);
  const [drop, setDrop] = useState(null);
  const [repro, setRepro] = useState({ show: false, citaId: null, citaActual: null });
  const [selDoc, setSelDoc] = useState(null);
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
  }, []);

  const fetchData = useCallback(async () => {
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

  // Handlers estables con useCallback
  const hPer = useCallback((k, v) => setPPer(s => ({ ...s, [k]: v })), []);
  const hPac = useCallback((k, v) => setPPac(s => ({ ...s, [k]: v })), []);
  const hTut = useCallback((k, v) => setPTut(s => ({ ...s, [k]: v })), []);

  /* ---------- guardar perfil ---------- */
  const savePerfil = async () => {
    try {
      await axios.put(`/prof/pacientes/${id}`, {
        persona: pPer,
        paciente: { ...pPac, tutor: pPac.tipo_paciente !== 'ADULTO' ? pTut : null },
        rgpd
      });
      msg(true, '¡Éxito!', 'Paciente actualizado correctamente');
      setEdit(false);
    } catch (e) { msg(false, 'Error', e.response?.data?.mensaje || 'El paciente no se ha podido actualizar'); }
  };

  /* ---------- acciones citas ---------- */
  const doAccion = async (idCita, accion, fecha = null) => {
    try {
      await axios.post(`/prof/citas/${idCita}/accion`, { accion, ...(fecha ? { fecha } : {}) });
      await fetchData();
    } catch (e) { msg(false, 'Error', 'No se pudo cambiar la cita'); }
  };

  if (data === null) return <div style={{ padding: '2rem', textAlign: 'center' }}>Cargando…</div>;

  /* ------------------- TABS ------------------- */
  const Perfil = (
    <>
      <BloquePaciente pPac={pPac} hPac={hPac} edit={edit} />
      {pPac.tipo_paciente !== 'ADULTO' && <BloqueTutor pTut={pTut} hTut={hTut} edit={edit} />}
      <BloquePersona pPer={pPer} hPer={hPer} edit={edit} />
      <BloqueContacto pPer={pPer} hPer={hPer} edit={edit} />
      <div className="field full">
        <label>
          <input
            type="checkbox"
            disabled={!edit}
            checked={rgpd}
            onChange={e => setRgpd(e.target.checked)}
          /> Acepto la política de privacidad
        </label>
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
        </ul>}
      <SubirTratamiento onDone={fetchData} />
      {selT && (
        <ModalTratamiento idPac={id} treat={selT}
          onClose={() => setSelT(null)}
          onChange={fetchData} />
      )}
    </>
  );

  const Docs = (
    <>
      <h4>Documentos en historial</h4>
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


  const ReprogramarCitaModal = () => {
    const [fechaNueva, setFechaNueva] = useState(null);
    const [horasOcupadas, setHorasOcupadas] = useState([]);
    const [cargandoHoras, setCargandoHoras] = useState(false);
    const [error, setError] = useState('');
    const [isLoading, setIsLoading] = useState(false);
    const [profesionalId, setProfesionalId] = useState(null);

    const HORA_INICIO = 10;
    const HORA_FIN = 17;

    // Reset del modal cuando se abre
    useEffect(() => {
      if (repro.show) {
        setFechaNueva(null);
        setHorasOcupadas([]);
        setError('');
        obtenerProfesionalId();
      }
    }, [repro.show]);

    // Obtener ID del profesional desde el token
    const obtenerProfesionalId = () => {
      try {
        const token = localStorage.getItem('token');
        if (!token) {
          setError('No hay token de autenticación');
          return;
        }

        const payload = JSON.parse(atob(token.split('.')[1]));
        const profId = payload.sub;

        console.log('Profesional ID obtenido del token:', profId);
        setProfesionalId(profId);

      } catch (e) {
        console.error('Error obteniendo profesional del token:', e);
        setError('Error al obtener información del profesional');
      }
    };
    const [diasBloqueados, setDiasBloqueados] = useState([]);
    //  Cargar días bloqueados al montar el componente
    const cargarDiasBloqueados = async () => {
      if (!profesionalId) return;

      try {
        const hoy = new Date().toISOString().split('T')[0];
        const tresMeses = new Date(Date.now() + 90 * 24 * 60 * 60 * 1000).toISOString().split('T')[0];

        console.log('Cargando días bloqueados del profesional', profesionalId);

        const response = await axios.get('/prof/dias-bloqueados', {
          params: {
            profesional_id: profesionalId,
            fecha_inicio: hoy,
            fecha_fin: tresMeses
          }
        });

        if (response.data.ok) {
          const diasBloqueados = response.data.dias_bloqueados || [];
          console.log('Días bloqueados recibidos:', diasBloqueados);
          setDiasBloqueados(diasBloqueados);
        }
      } catch (e) {
        console.error('Error al cargar días bloqueados:', e);
      }
    };

    useEffect(() => {
      if (profesionalId) {
        cargarDiasBloqueados();
      }
    }, [profesionalId]);    // Cargar horas ocupadas cuando se selecciona una fecha
    const cargarHorasOcupadas = async (fecha) => {
      if (!fecha || !profesionalId) return;

      setCargandoHoras(true);
      setError('');

      try {
        const fechaStr = fecha.toISOString().split('T')[0]; // format to YYYY-MM-DD
        console.log('Cargando horas ocupadas para:', fechaStr);

        const response = await axios.get('/prof/horas-disponibles', {
          params: {
            profesional_id: profesionalId,
            fecha: fechaStr
          }
        });

        console.log('Respuesta horas disponibles:', response.data);

        if (response.data.ok) {
          const horasDisponibles = response.data.horas || [];

          // Si no hay horas disponibles, el profesional está bloqueado
          if (horasDisponibles.length === 0) {
            console.log('PROFESIONAL BLOQUEADO - No hay horas disponibles');
            setError('El profesional no está disponible este día (ausencia/vacaciones). Seleccione otra fecha.');
            // Bloquear todas las horas
            setHorasOcupadas(['10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00']);
            return;
          }

          // Generar todas las horas del día (10:00 a 17:00)
          const todasLasHoras = [];
          for (let h = HORA_INICIO; h <= HORA_FIN; h++) {
            todasLasHoras.push(`${h.toString().padStart(2, '0')}:00`);
          }

          // Las horas ocupadas son las que NO están disponibles
          const ocupadas = todasLasHoras.filter(hora => !horasDisponibles.includes(hora));
          
          // Si la fecha seleccionada es la misma que la cita actual, no bloquear la hora de la cita actual
          // ya que esa misma hora debe estar disponible para reprogramación
          if (repro.citaActual?.fecha_hora) {
            const fechaCitaActual = new Date(repro.citaActual.fecha_hora);
            const fechaCitaActualStr = fechaCitaActual.toISOString().split('T')[0];
            
            if (fechaStr === fechaCitaActualStr) {
              // Obtener la hora de la cita actual
              const horaCitaActual = fechaCitaActual.getHours().toString().padStart(2, '0') + ':00';
              console.log('Hora de cita actual que no se bloqueará:', horaCitaActual);
              
              // Filtrar la hora de la cita actual de las horas ocupadas
              const ocupadasSinLaActual = ocupadas.filter(hora => hora !== horaCitaActual);
              setHorasOcupadas(ocupadasSinLaActual);
            } else {
              setHorasOcupadas(ocupadas);
            }
          } else {
            setHorasOcupadas(ocupadas);
          }

          console.log('Horas ocupadas por citas:', ocupadas);
          console.log('Horas libres:', horasDisponibles);

          if (ocupadas.length === todasLasHoras.length) {
            setError('Todas las horas están ocupadas este día. Seleccione otra fecha.');
          }
        } else {
          console.log('Error en respuesta:', response.data.mensaje);
          setError(response.data.mensaje || 'Error al verificar disponibilidad');
          setHorasOcupadas(['10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00']);
        }
      } catch (e) {
        console.error('ERROR en petición:', e);
        const errorMsg = e.response?.data?.mensaje || 'Error al verificar disponibilidad';
        setError(errorMsg);
        setHorasOcupadas(['10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00']);
      } finally {
        setCargandoHoras(false);
      }
    };    // Función para filtrar horas disponibles en el DatePicker
    const filterTime = (time) => {
      const hour = time.getHours();
      const timeStr = `${hour.toString().padStart(2, '0')}:00`;

      // Solo permitir horas dentro del rango y que no estén ocupadas
      const dentroDelRango = hour >= HORA_INICIO && hour <= HORA_FIN;
      const noOcupada = !horasOcupadas.includes(timeStr);

      // Para depuración
      if (dentroDelRango && !noOcupada) {
        console.log(`Hora ${timeStr} bloqueada porque está ocupada`);
      }

      return dentroDelRango && noOcupada;
    };// Función para filtrar fechas válidas
    const filterDate = (date) => {
      const hoy = new Date();
      hoy.setHours(0, 0, 0, 0);
      const diaSemana = date.getDay();
      const fechaStr = date.toISOString().split('T')[0];

      // Solo lunes a viernes y fechas futuras
      const esLaborable = diaSemana >= 1 && diaSemana <= 5;
      const esFutura = date >= hoy;

      // No está en días bloqueados
      const noEstaBloqueado = !diasBloqueados.includes(fechaStr);

      // A diferencia de la versión anterior, NO excluimos la fecha de la cita actual
      // porque sí queremos permitir reprogramar para el mismo día, quizás a otra hora

      console.log('Validando fecha:', fechaStr, {
        esLaborable,
        esFutura,
        noEstaBloqueado,
        diasBloqueados
      });

      return esLaborable && esFutura && noEstaBloqueado;
    };

    // Manejar cambio de fecha
    const handleDateChange = (date) => {
      console.log('Fecha seleccionada:', date);
      setFechaNueva(date);
      setError('');

      if (date && profesionalId) {
        // Cargar horas ocupadas para esta fecha
        cargarHorasOcupadas(date);
      }
    };

    // Ejecutar reprogramación
    const reprogramar = async () => {
      if (!fechaNueva) {
        setError('Debe seleccionar una fecha y hora');
        return;
      }

      setIsLoading(true);
      setError('');

      try {
        const fechaHoraCompleta = fechaNueva.toISOString().slice(0, 19).replace('T', ' '); // format to YYYY-MM-DD HH:mm:ss

        console.log('Reprogramando cita:', {
          citaId: repro.citaId,
          fechaHora: fechaHoraCompleta
        });

        const response = await axios.post(
          `/prof/citas/${repro.citaId}/accion`,
          {
            accion: 'REPROGRAMAR',
            fecha: fechaHoraCompleta
          }
        );

        console.log('Respuesta reprogramación:', response.data);

        if (response.data.ok) {
          if (response.data.token) {
            localStorage.setItem('token', response.data.token);
          }

          msg(true, 'Éxito', 'Cita reprogramada exitosamente');
          setRepro({ show: false, citaId: null, citaActual: null });
          fetchData();
        } else {
          const errorMsg = response.data.mensaje || 'Error al reprogramar la cita';
          setError(errorMsg);
        }
      } catch (e) {
        console.error('Error reprogramando:', e);
        const errorMsg = e.response?.data?.mensaje || 'Error al reprogramar la cita. Inténtalo de nuevo.';
        setError(errorMsg);
      } finally {
        setIsLoading(false);
      }
    };

    if (!repro.show) return null;

    const fechaActualCita = repro.citaActual?.fecha_hora ?
      new Date(repro.citaActual.fecha_hora).toLocaleString('es-ES', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        weekday: 'long'
      }) : '';

    return (
      <div className="modal-backdrop" onClick={() => setRepro({ show: false, citaId: null, citaActual: null })}>
        <div className="modal" onClick={e => e.stopPropagation()} style={{ maxWidth: '650px' }}>
          <div className="modal-header">
            <h3>Reprogramar Cita</h3>
            <button className="modal-close" onClick={() => setRepro({ show: false, citaId: null, citaActual: null })}> <X />
            </button>
          </div>

          <div className="modal-body">
            {/* Información de la cita actual */}
            {repro.citaActual && (
              <div style={{
                background: '#f8f9fa',
                border: '1px solid #e9ecef',
                borderRadius: '6px',
                padding: '15px',
                marginBottom: '20px'
              }}>
                <h4 style={{ margin: '0 0 10px 0', color: '#495057', fontSize: '0.9em' }}>
                  Cita actual
                </h4>
                <div style={{ display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '8px' }}>
                  <span>🕒</span>
                  <span style={{ color: '#6c757d', fontSize: '0.9em' }}>
                    {fechaActualCita}
                  </span>
                </div>
                {repro.citaActual.motivo && (
                  <p style={{ margin: '0', fontSize: '0.85em', color: '#6c757d' }}>
                    <strong>Motivo:</strong> {repro.citaActual.motivo}
                  </p>
                )}
              </div>
            )}

            {/* Información importante */}
            <div style={{
              background: '#d1ecf1',
              border: '1px solid #bee5eb',
              borderRadius: '6px',
              padding: '12px',
              marginBottom: '20px',
              fontSize: '0.85em',
              color: '#0c5460'
            }}>
              <strong>Horario de atención:</strong>
              <ul style={{ margin: '5px 0 0 0', paddingLeft: '15px' }}>
                <li>Lunes a Viernes: 10:00 - 17:00</li>
                <li>Solo se muestran horas realmente disponibles</li>
                <li>Las horas ocupadas aparecen deshabilitadas</li>
              </ul>
            </div>

            {/* Selección de nueva fecha y hora con DatePicker */}
            <div className="field" style={{ marginBottom: '20px' }}>
              <label style={{ fontWeight: 'bold', marginBottom: '8px', display: 'block' }}>
                Nueva fecha y hora *
              </label>
              {cargandoHoras && (
                <div style={{
                  padding: '10px',
                  textAlign: 'center',
                  color: '#666',
                  fontSize: '0.9em'
                }}>
                  🔄 Cargando disponibilidad...
                </div>
              )}
              <DatePicker
                selected={fechaNueva}
                onChange={handleDateChange}
                inline
                locale="es"
                showTimeSelect
                timeIntervals={60}
                dateFormat="dd/MM/yyyy HH:mm"
                minTime={setMinutes(setHours(new Date(), HORA_INICIO), 0)}
                maxTime={setMinutes(setHours(new Date(), HORA_FIN), 0)}
                filterDate={filterDate}
                filterTime={filterTime}
                placeholderText="Seleccione fecha y hora"
                disabled={cargandoHoras || !profesionalId}
              />
              <small style={{ color: '#666', fontSize: '0.8em', marginTop: '5px', display: 'block' }}>
                Solo días laborables (lunes a viernes) y horas disponibles
              </small>
            </div>

            {/* Estado de carga */}
            {cargandoHoras && (
              <div style={{
                padding: '15px',
                background: '#e3f2fd',
                border: '1px solid #90caf9',
                borderRadius: '4px',
                color: '#1565c0',
                marginBottom: '15px'
              }}>
                🕒 Verificando disponibilidad horaria...
              </div>
            )}

            {/* Mensaje de error */}
            {error && (
              <div style={{
                background: '#fee',
                border: '1px solid #fcc',
                padding: '12px',
                borderRadius: '6px',
                marginBottom: '15px',
                color: '#c33'
              }}>
                ⚠️ {error}
              </div>
            )}            {/* Debug info */}
            <details style={{ marginBottom: '15px', fontSize: '0.8em' }}>
              <summary style={{ cursor: 'pointer', color: '#666' }}>
                🔧 Debug Info
              </summary>
              <div style={{ background: '#f0f0f0', padding: '10px', borderRadius: '4px', marginTop: '5px' }}>
                <div><strong>Cita ID:</strong> {repro.citaId || 'N/A'}</div>
                <div><strong>Profesional ID:</strong> {profesionalId || 'NO DETECTADO'}</div>
                <div><strong>Fecha seleccionada:</strong> {fechaNueva ? fechaNueva.toLocaleString('es-ES') : 'Ninguna'}</div>
                <div><strong>Horas ocupadas:</strong> [{horasOcupadas.join(', ') || 'Ninguna'}]</div>
                <div><strong>Cargando:</strong> {cargandoHoras ? 'Sí' : 'No'}</div>
                <div><strong>Fecha cita actual:</strong> {repro.citaActual?.fecha_hora ? new Date(repro.citaActual.fecha_hora).toISOString().split('T')[0] : 'N/A'}</div>
                <div><strong>Hora cita actual:</strong> {repro.citaActual?.fecha_hora ? new Date(repro.citaActual.fecha_hora).getHours().toString().padStart(2, '0') + ':00' : 'N/A'}</div>
              </div>
            </details>
          </div>

          <div className="modal-footer">
            <button
              className="btn-cancel"
              onClick={() => setRepro({ show: false, citaId: null, citaActual: null })}
              disabled={isLoading}
            >
              Cancelar
            </button>
            <button
              className="btn-save"
              onClick={reprogramar}
              disabled={isLoading || !fechaNueva || cargandoHoras}
              style={{
                opacity: (isLoading || !fechaNueva || cargandoHoras) ? 0.6 : 1
              }}
            >{isLoading ? 'Reprogramando...' : 'Reprogramar Cita'}
            </button>
          </div>
        </div>
      </div>
    );
  };


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
                    <a href="#!" onClick={e => {
                      e.preventDefault();
                      setRepro({
                        show: true,
                        citaId: c.id_cita,
                        citaActual: c
                      });
                      setDrop(null);
                    }}>Reprogramar</a>
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
      <ReprogramarCitaModal />
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
    </div>);
}






