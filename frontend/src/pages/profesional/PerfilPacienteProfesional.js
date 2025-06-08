import React, { useState, useEffect, useCallback, memo } from 'react';
import axios from 'axios';
import { useParams } from 'react-router-dom';
import { CheckCircle, X, XCircle, EllipsisVertical, Paperclip } from 'lucide-react';
import DatePicker, { registerLocale } from 'react-datepicker';
import { setHours, setMinutes } from 'date-fns';
import es from 'date-fns/locale/es';

import '../../styles.css';

import ModalDocumento from '../../components/modals/ModalDocumento';
import SubirTratamiento from '../../components/modals/SubirTratamiento';
import SubirDocumento from '../../components/modals/SubirDocumento';
import ModalTratamiento from '../../components/modals/ModalTratamiento';


registerLocale('es', es);


const TabBtn = ({ label, sel, onClick }) => (
  <button className={`tab-btn ${sel ? 'tab-button-selected' : 'tab-button-unselected'}`}
    onClick={onClick}>{label}</button>
);

// Función para obtener la clase CSS del estado
const getEstadoClass = (estado) => {
  const clases = {
    'PENDIENTE_VALIDACION': 'estado-pendiente-validacion',
    'SOLICITADA': 'estado-solicitada',
    'CONFIRMADA': 'estado-confirmada',
    'ATENDIDA': 'estado-atendida',
    'CANCELADA': 'estado-cancelada',
    'NO_PRESENTADA': 'estado-no-presentada',
    'CAMBIAR': 'estado-cambiar',
    'CANCELAR': 'estado-cancelar',
    'NO_ATENDIDA': 'estado-no-atendida'
  };
  return clases[estado] || '';
};


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
      <InputField obj={pTut} onChange={hTut} fieldKey="telefono" label="Teléfono*" edit={edit} />      <div className="field full">
        <label>Método contacto*</label>
        <div className="datos-perfil-flex">          <label>
            <input
              type="checkbox"
              disabled={!edit}              checked={pTut.metodo_contacto_preferido?.includes('TEL') || false}
              onChange={e => {
                // FIXED: Get current methods as array regardless of source format
                // This fixes inconsistencies in how metodo_contacto_preferido was handled
                let metodosActuales = [];
                if (pTut.metodo_contacto_preferido) {
                  metodosActuales = typeof pTut.metodo_contacto_preferido === 'string' 
                    ? pTut.metodo_contacto_preferido.split(',') 
                    : [...pTut.metodo_contacto_preferido];
                }
                
                if (e.target.checked) {
                  const newMethods = [...metodosActuales, 'TEL'].filter(m => m);
                  hTut('metodo_contacto_preferido', newMethods);
                } else {
                  const newMethods = metodosActuales.filter(m => m !== 'TEL');
                  hTut('metodo_contacto_preferido', newMethods);
                }
              }}
            /> Teléfono
          </label>
          <label>
            <input
              type="checkbox"
              disabled={!edit}              checked={pTut.metodo_contacto_preferido?.includes('EMAIL') || false}              onChange={e => {
                // Get current methods as array
                let metodosActuales = [];
                if (pTut.metodo_contacto_preferido) {
                  metodosActuales = typeof pTut.metodo_contacto_preferido === 'string' 
                    ? pTut.metodo_contacto_preferido.split(',') 
                    : [...pTut.metodo_contacto_preferido];
                }
                
                if (e.target.checked) {
                  const newMethods = [...metodosActuales, 'EMAIL'].filter(m => m);
                  hTut('metodo_contacto_preferido', newMethods);
                } else {
                  const newMethods = metodosActuales.filter(m => m !== 'EMAIL');
                  hTut('metodo_contacto_preferido', newMethods);
                }
              }}
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


export default function PerfilPacienteProfesional() {
  const { id } = useParams();
  const [data, setData] = useState(null);
  const [tab, setTab] = useState('perfil');
  const [edit, setEdit] = useState(false);
  const [drop, setDrop] = useState(null);
  const [repro, setRepro] = useState({ show: false, citaId: null, citaActual: null });
  const [selDoc, setSelDoc] = useState(null);
  const [selT, setSelT] = useState(null);
  const [toast, setToast] = useState({ show: false, ok: true, t: '', m: '' });

  const [pPer, setPPer] = useState({});
  const [pPac, setPPac] = useState({});
  const [pTut, setPTut] = useState({});
  const [rgpd, setRgpd] = useState(false);

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


  useEffect(() => {
    fetchData();
  }, [fetchData]);

  useEffect(() => {
    if (toast.show) {
      const t = setTimeout(() => setToast(s => ({ ...s, show: false })), 2500);
      return () => clearTimeout(t);
    }
  }, [toast.show]);


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


  const hPer = useCallback((k, v) => setPPer(s => ({ ...s, [k]: v })), []);
  const hPac = useCallback((k, v) => setPPac(s => ({ ...s, [k]: v })), []);
  const hTut = useCallback((k, v) => setPTut(s => ({ ...s, [k]: v })), []);

 
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

const doAccion = async (idCita, accion, fecha = null) => {
    try {
      const response = await axios.post(`/prof/citas/${idCita}/accion`, { accion, ...(fecha ? { fecha } : {}) });
      if (response.data?.token) {
        localStorage.setItem('token', response.data.token);
      }
      await fetchData();
      msg(true, 'Éxito', 'La cita se ha actualizado correctamente');
    } catch (e) {
      console.error('Error al realizar acción en cita:', e);
      const errorMsg = e.response?.data?.mensaje || 'No se pudo cambiar la cita. Por favor, inténtalo de nuevo.';
      msg(false, 'Error', errorMsg);
    }
  };

  if (data === null) return <div className="loading-estado">Cargando…</div>;


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
                  <Paperclip size={14} style={{ marginRight: '4px' }} /> {t.documentos.length} archivo{t.documentos.length > 1 ? 's' : ''}
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
    useEffect(() => {
      if (repro.show) {
        setFechaNueva(null);
        setHorasOcupadas([]);
        setError('');
        obtenerProfesionalId();
      }
      // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [repro.show]);

    // Obtener ID del profesional desde el token
    const obtenerProfesionalId = useCallback(() => {
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
    }, []);
    const [diasBloqueados, setDiasBloqueados] = useState([]);
    //  Cargar días bloqueados al montar el componente
    const cargarDiasBloqueados = useCallback(async () => {
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
        });        if (response.data.ok) {
          const diasBloqueados = response.data.dias_bloqueados || [];
          console.log('Días bloqueados recibidos:', diasBloqueados);
          setDiasBloqueados(diasBloqueados);
        }
      } catch (e) {
        console.error('Error al cargar días bloqueados:', e);
      }
    }, [profesionalId]);

    useEffect(() => {
      if (profesionalId) {
        cargarDiasBloqueados();
      }
    }, [profesionalId, cargarDiasBloqueados]);    // Cargar horas ocupadas cuando se selecciona una fecha
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
          
          // Si la fecha seleccionada es la misma que la cita actual, no bloquear la hora de la cita actual ya que esa misma hora debe estar disponible para reprogramación
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

      // A diferencia de la versión anterior, NO excluimos la fecha de la cita actual  porque sí queremos permitir reprogramar para el mismo día, quizás a otra hora

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
        <div className="modal modal-tratamiento-container" onClick={e => e.stopPropagation()}>
          <div className="modal-header">
            <h3>Reprogramar Cita</h3>
            <button className="modal-close" onClick={() => setRepro({ show: false, citaId: null, citaActual: null })}> <X />
            </button>
          </div>

          <div className="modal-body">
            {/* Información de la cita actual */}            
            {repro.citaActual && (
              <div className="cita-actual-container">
                <h4 className="cita-actual-titulo">
                  Cita actual
                </h4>                <div className="cita-actual-fecha-container">
                  <span>🕒</span>
                  <span className="cita-actual-fecha-texto">
                    {fechaActualCita}
                  </span>
                </div>
                {repro.citaActual.motivo && (
                  <p className="cita-actual-motivo">
                    <strong>Motivo:</strong> {repro.citaActual.motivo}
                  </p>
                )}
              </div>
            )}            
            {/* Información importante */}
            <div className="horario-atencion-info">
              <strong>Horario de atención:</strong>
              <ul className="horario-atencion-lista">
                <li>Lunes a Viernes: 10:00 - 17:00</li>
                <li>Solo se muestran horas realmente disponibles</li>
                <li>Las horas ocupadas aparecen deshabilitadas</li>
              </ul>
            </div>            
            {/* Selección de nueva fecha y hora con DatePicker */}
            <div className="field fecha-seleccion-field">
              <label className="fecha-seleccion-label">
                Nueva fecha y hora *
              </label>
              {cargandoHoras && (
                <div className="cargando-horas-container">
                  Cargando disponibilidad...
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
                disabled={cargandoHoras || !profesionalId}              />
              <small className="fecha-ayuda-texto">
                Solo días laborables (lunes a viernes) y horas disponibles
              </small>
            </div>            {/* Estado de carga */}
            {cargandoHoras && (
              <div className="estado-carga-container">
                Verificando disponibilidad horaria...
              </div>
            )}

            {/* Mensaje de error */}
            {error && (
              <div className="mensaje-error-container">
                {error}
              </div>
            )}            

          </div>

          <div className="modal-footer">
            <button
              className="btn-cancel"
              onClick={() => setRepro({ show: false, citaId: null, citaActual: null })}
              disabled={isLoading}
            >Cancelar
            </button>            
            <button
              className={`btn-save ${(isLoading || !fechaNueva || cargandoHoras) ? 'btn-reprogramar-disabled' : ''}`}
              onClick={reprogramar}
              disabled={isLoading || !fechaNueva || cargandoHoras}
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
          {(data.citas || []).map(c => (<tr key={c.id_cita}>
              <td>{new Date(c.fecha_hora).toLocaleString('es-ES')}</td>
              <td className={`cita-estado-celda ${getEstadoClass(c.estado)}`}>{c.estado}</td>
              <td className="acciones-col">
                <EllipsisVertical size={20} className="dropdown-toggle dropdown-icon-cita"
                  onClick={() => setDrop(drop === c.id_cita ? null : c.id_cita)} />
                <div className={`acciones-dropdown ${drop === c.id_cita ? 'show' : ''}`}>
                  {c.estado === 'CONFIRMADA' && <>
                    <a href="#!" onClick={e => { e.preventDefault(); doAccion(c.id_cita, 'MARCAR_ATENDIDA'); }}>Marcar como atendida</a>
                    <a href="#!" onClick={e => { e.preventDefault(); doAccion(c.id_cita, 'MARCAR_NO_ATENDIDA'); }}>Marcar como no atendida</a>
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
                    <a href="#!" onClick={e => { e.preventDefault(); doAccion(c.id_cita, 'CONFIRMAR_CITA'); }}>Confirmar cita</a>
                    <a href="#!" onClick={e => { e.preventDefault(); doAccion(c.id_cita, 'RECHAZAR_CITA'); }}>Rechazar cita</a>
                  </>}
                  {c.estado === 'CAMBIAR' && <>
                    <a href="#!" onClick={e => { e.preventDefault(); doAccion(c.id_cita, 'ACEPTAR_CAMBIO'); }}>Aceptar cambio</a>
                    <a href="#!" onClick={e => { e.preventDefault(); doAccion(c.id_cita, 'MANTENER_ESTADO_PREVIO'); }}>Volver al estado previo</a>
                  </>}
                  {c.estado === 'CANCELAR' && <>
                    <a href="#!" onClick={e => { e.preventDefault(); doAccion(c.id_cita, 'ACEPTAR_CANCELACION'); }}>Aceptar cancelación</a>
                    <a href="#!" onClick={e => { e.preventDefault(); doAccion(c.id_cita, 'MANTENER_CITA'); }}>Mantener cita</a>
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
  
  return (
    <div className="usuarios-container perfil-paciente-profesional-container">
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






