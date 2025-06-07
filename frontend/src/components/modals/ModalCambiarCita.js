import React, { useState, useEffect, useCallback } from 'react';
import axios from 'axios';
import { X } from 'lucide-react';
import DatePicker, { registerLocale } from 'react-datepicker';
import { setHours, setMinutes, format } from 'date-fns';
import es from 'date-fns/locale/es';

import 'react-datepicker/dist/react-datepicker.css';

registerLocale('es', es);

export default function ModalCitaUniversal({ 
  modo = 'cambiar', // 'cambiar' | 'nueva'
  cita, 
  onClose, 
  onSuccess, 
  onError 
}) {
  const [form, setForm] = useState({
    profesional_id: '',
    motivo: '',
    fecha: null
  });
  const [errs, setErrs] = useState({});
  const [enviando, setEnviando] = useState(false);
  const [profesionales, setProfesionales] = useState([]);
  const [loadingProfesionales, setLoadingProfesionales] = useState(false);
  const [horasOcupadas, setHorasOcupadas] = useState([]);
  const [cargandoHoras, setCargandoHoras] = useState(false);
  const [error, setError] = useState('');
  const [profesionalId, setProfesionalId] = useState(null);
  const [diasBloqueados, setDiasBloqueados] = useState([]);
  
  const HORA_INICIO = 10;
  const HORA_FIN = 17;  const esModoCambiar = modo === 'cambiar';
  const esModoNueva = modo === 'nueva';;

  // Función para cargar profesionales
  const cargarProfesionales = useCallback(async () => {
    try {
      setLoadingProfesionales(true);
      const token = localStorage.getItem('token');
      const response = await axios.get('/profesionales', {
        headers: { 'Authorization': `Bearer ${token}` }
      });

      if (response.data.ok) {
        setProfesionales(response.data.data || []);
      }
    } catch (error) {
      console.error('Error cargando profesionales:', error);
      onError('Error al cargar la lista de profesionales');
    } finally {
      setLoadingProfesionales(false);
    }
  }, [onError]);

  // Configurar form inicial según el modo
  useEffect(() => {
    if (esModoCambiar && cita) {
      setForm(prev => ({
        ...prev,
        motivo: cita.motivo || ''
      }));
    } else if (esModoNueva) {
      setForm(prev => ({
        ...prev,
        profesional_id: '',
        motivo: '',
        fecha: null
      }));
    }
  }, [modo, cita, esModoCambiar, esModoNueva]);
  // Cargar profesionales para modo nueva
  useEffect(() => {
    if (esModoNueva) {
      cargarProfesionales();
    }
  }, [esModoNueva, cargarProfesionales]);
  // Obtener profesional para modo cambiar
  const obtenerProfesionalId = useCallback(async () => {
    if (!esModoCambiar || !cita) return;

    try {
      const idProf = cita?.id_profesional || 
                     cita?.profesional_id || 
                     cita?.id_prof || 
                     cita?.profesional;
      
      if (idProf) {
        setProfesionalId(idProf);
        cargarDiasBloqueados(idProf);
        return;
      }

      // Obtener desde API de citas
      if (cita?.id_cita) {
        const token = localStorage.getItem('token');
        
        try {
          const response = await axios.get(`/pac/citas`, {
            headers: { 'Authorization': `Bearer ${token}` }
          });
          
          if (response.data.ok) {
            const citaCompleta = response.data.citas.find(c => c.id_cita === cita.id_cita);
            if (citaCompleta?.id_profesional) {
              setProfesionalId(citaCompleta.id_profesional);
              cargarDiasBloqueados(citaCompleta.id_profesional);
              return;
            }
          }
        } catch (e) {
          console.error('Error obteniendo cita completa:', e);
        }
      }

      // Fallback si no se encuentra el ID del profesional
      const idFallback = 1;
      setProfesionalId(idFallback);
      cargarDiasBloqueados(idFallback);
      
    } catch (e) {
      console.error('Error obteniendo profesional:', e);
      setError('Error al obtener información del profesional');
    }
  }, [cita, esModoCambiar]);

  // Ejecutar obtención de profesional para modo cambiar
  useEffect(() => {
    if (esModoCambiar) {
      obtenerProfesionalId();
    }
  }, [obtenerProfesionalId, esModoCambiar]);

  // Configurar profesional ID cuando se selecciona en modo nueva
  useEffect(() => {
    if (esModoNueva && form.profesional_id) {
      setProfesionalId(parseInt(form.profesional_id));
      cargarDiasBloqueados(parseInt(form.profesional_id));
    }
  }, [form.profesional_id, esModoNueva]);  const cargarDiasBloqueados = async (idProf) => {
    if (!idProf) return;

    try {
      const hoy = new Date().toISOString().split('T')[0];
      const tresMeses = new Date(Date.now() + 90 * 24 * 60 * 60 * 1000).toISOString().split('T')[0];

      const token = localStorage.getItem('token');
      const response = await axios.get(`/pac/profesional/${idProf}/dias-bloqueados`, {
        params: {
          fecha_inicio: hoy,
          fecha_fin: tresMeses
        },
        headers: { 'Authorization': `Bearer ${token}` }
      });      if (response.data.ok) {
        const diasBloqueados = response.data.dias_bloqueados || [];
        setDiasBloqueados(diasBloqueados);
      }
    } catch (e) {
      console.error('Error al cargar días bloqueados:', e);
    }
  };

  const cargarHorasOcupadas = async (fecha) => {
    if (!fecha || !profesionalId) return;

    setCargandoHoras(true);
    setError('');    try {
      const fechaStr = fecha.toISOString().split('T')[0];
      
      const token = localStorage.getItem('token');
      const response = await axios.get(`/pac/profesional/${profesionalId}/horas-disponibles`, {
        params: { fecha: fechaStr },
        headers: { 'Authorization': `Bearer ${token}` }
      });

      if (response.data.ok) {
        const horasDisponibles = response.data.horas || [];

        if (horasDisponibles.length === 0) {
          setError('El profesional no está disponible este día (ausencia/vacaciones). Seleccione otra fecha.');
          setHorasOcupadas(['10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00']);
          return;
        }

        const todasLasHoras = [];
        for (let h = HORA_INICIO; h <= HORA_FIN; h++) {
          todasLasHoras.push(`${h.toString().padStart(2, '0')}:00`);
        }

        const ocupadas = todasLasHoras.filter(hora => !horasDisponibles.includes(hora));
        
        // En modo cambiar, no bloquear la hora actual de la cita
        if (esModoCambiar && cita?.fecha_hora) {
          const fechaCitaActual = new Date(cita.fecha_hora);
          const fechaCitaActualStr = fechaCitaActual.toISOString().split('T')[0];
          
          if (fechaStr === fechaCitaActualStr) {
            const horaCitaActual = fechaCitaActual.getHours().toString().padStart(2, '0') + ':00';
            const ocupadasSinLaActual = ocupadas.filter(hora => hora !== horaCitaActual);
            setHorasOcupadas(ocupadasSinLaActual);
          } else {
            setHorasOcupadas(ocupadas);
          }
        } else {
          setHorasOcupadas(ocupadas);
        }

        if (ocupadas.length === todasLasHoras.length) {
          setError('Todas las horas están ocupadas este día. Seleccione otra fecha.');
        }
      } else {
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
  };

  const filterTime = (time) => {
    const hour = time.getHours();
    const timeStr = `${hour.toString().padStart(2, '0')}:00`;
    const dentroDelRango = hour >= HORA_INICIO && hour <= HORA_FIN;
    const noOcupada = !horasOcupadas.includes(timeStr);
    return dentroDelRango && noOcupada;
  };

  const filterDate = (date) => {
    const hoy = new Date();
    hoy.setHours(0, 0, 0, 0);
    const diaSemana = date.getDay();
    const fechaStr = date.toISOString().split('T')[0];

    const esLaborable = diaSemana >= 1 && diaSemana <= 5;
    const esFutura = date >= hoy;
    const noEstaBloqueado = !diasBloqueados.includes(fechaStr);

    return esLaborable && esFutura && noEstaBloqueado;
  };
  const handleDateChange = (date) => {
    setForm(prev => ({ ...prev, fecha: date }));
    setError('');

    if (date && profesionalId) {
      cargarHorasOcupadas(date);
    }
  };

  const validarFormulario = () => {
    const errores = {};
    
    if (esModoNueva && !form.profesional_id) {
      errores.profesional_id = 'Seleccione un profesional';
    }
    
    if (esModoNueva && !form.motivo.trim()) {
      errores.motivo = 'El motivo es obligatorio';
    }
    
    if (!form.fecha) {
      errores.fecha = 'Seleccione una fecha y hora';
    }
    
    setErrs(errores);
    return Object.keys(errores).length === 0;
  };  const enviarSolicitud = async () => {
    if (!validarFormulario()) {
      if (esModoNueva) {
        onError('Por favor complete todos los campos requeridos');
      } else {
        onError('Por favor selecciona una fecha y hora');
      }
      return;
    }

    setEnviando(true);
    setError('');

    try {
      const fechaHoraCompleta = format(form.fecha, 'yyyy-MM-dd HH:mm:ss');
      const token = localStorage.getItem('token');

      if (esModoCambiar) {
        // Cambiar cita existente
        const { data } = await axios.post(`/pac/citas/${cita.id_cita}/solicitud`, {
          accion: 'CAMBIAR',
          nueva_fecha: fechaHoraCompleta
        }, {
          headers: { 'Authorization': `Bearer ${token}` }
        });

        if (data.ok) {
          onSuccess('Tu solicitud de cambio ha sido enviada correctamente');
        } else {
          onError(data.mensaje || 'Error al enviar la solicitud');
        }
      } else {
        // Nueva cita
        const requestData = {
          profesional_id: parseInt(form.profesional_id),
          motivo: form.motivo,
          fecha: fechaHoraCompleta
        };

        const response = await axios.post('/pac/solicitar-cita', requestData, {
          headers: { 'Authorization': `Bearer ${token}` }
        });

        if (response.data.ok) {
          onSuccess('¡Solicitud de cita enviada! Te avisaremos cuando el profesional la confirme');
        } else {
          onError(response.data.mensaje || 'Error al solicitar la cita');
        }
      }
    } catch (error) {
      console.error('Error completo:', error);
      const mensaje = error.response?.data?.mensaje || error.message || 'Error al procesar la solicitud';
      onError(mensaje);
    } finally {
      setEnviando(false);
    }
  };
  const getTitulo = () => {
    return esModoCambiar ? 'Cambiar cita' : 'Solicitar nueva cita';
  };
  const getBotonTexto = () => {
    if (enviando) return 'Enviando...';
    return esModoCambiar ? 'Solicitar cambio' : 'Solicitar cita';
  };

  // Determinar si mostrar DatePicker
  const mostrarDatePicker = esModoCambiar ? 
    Boolean(profesionalId) : 
    Boolean(form.profesional_id);

  return (
    <div className="modal-backdrop" onClick={onClose}>
      <div className="modal" onClick={e => e.stopPropagation()} style={{ maxWidth: '600px' }}>        <div className="modal-header">
          <h5>{getTitulo()}</h5>
          <button className="modal-close" onClick={onClose}><X /></button>
        </div><div className="modal-body">
          {/* Selección de profesional (solo en modo nueva) */}
          {esModoNueva && (
            <div className="field" style={{ marginBottom: '1.5rem' }}>
              <label style={{ color: 'var(--black)', fontWeight: '500' }}>Profesional*</label>
              <select
                value={form.profesional_id}
                onChange={e => setForm(prev => ({ ...prev, profesional_id: e.target.value }))}
                className={errs.profesional_id ? 'invalid' : ''}
                disabled={loadingProfesionales}
                style={{
                  width: '100%',
                  padding: '0.75rem',
                  border: '1px solid var(--gray)',
                  borderRadius: '4px',
                  color: 'var(--black)'
                }}
              >
                <option value="">
                  {loadingProfesionales ? 'Cargando profesionales...' : 'Seleccione un profesional'}
                </option>
                {profesionales.map(prof => (
                  <option key={prof.id} value={prof.id}>
                    {prof.nombre}
                  </option>
                ))}
              </select>
              {errs.profesional_id && <span className="field-error">{errs.profesional_id}</span>}
            </div>
          )}

          {/* Motivo (solo en modo nueva) */}
          {esModoNueva && (
            <div className="field" style={{ marginBottom: '1.5rem' }}>
              <label style={{ color: 'var(--black)', fontWeight: '500' }}>Motivo de la consulta*</label>
              <textarea
                value={form.motivo}
                onChange={e => setForm(prev => ({ ...prev, motivo: e.target.value }))}
                className={errs.motivo ? 'invalid' : ''}
                placeholder="Describe brevemente el motivo de tu consulta..."
                rows={3}
                style={{
                  width: '100%',
                  padding: '0.75rem',
                  border: '1px solid var(--gray)',
                  borderRadius: '4px',
                  resize: 'vertical',
                  color: 'var(--black)'
                }}
              />
              {errs.motivo && <span className="field-error">{errs.motivo}</span>}
            </div>
          )}          {/* Selección de fecha y hora */}
          {mostrarDatePicker && (
            <div style={{ marginBottom: '1.5rem', display: 'flex', flexDirection: 'column', alignItems: 'center' }}>
              <label style={{ color: 'var(--black)', fontWeight: '500', marginBottom: '1rem' }}>
                {esModoCambiar ? 'Nueva fecha y hora*' : 'Seleccione fecha y hora*'}
              </label>
              
              {cargandoHoras && (
                <div style={{ margin: '0.5rem 0', color: 'var(--black)' }}>
                  Cargando disponibilidad...
                </div>
              )}

              <DatePicker
                selected={form.fecha}
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
                disabled={cargandoHoras}
              />
            </div>
          )}

          {/* Mensaje para seleccionar profesional */}
          {esModoNueva && !form.profesional_id && (
            <div style={{ 
              textAlign: 'center', 
              color: 'var(--gray)', 
              fontStyle: 'italic',
              padding: '2rem'
            }}>
              Seleccione un profesional para ver las fechas disponibles
            </div>
          )}

          {/* Mensaje de error */}
          {error && (
            <div style={{ 
              color: 'var(--red)',
              padding: '0.75rem',
              textAlign: 'center',
              marginBottom: '1rem'
            }}>
              {error}
            </div>
          )}
        </div>        <div className="modal-footer" style={{ justifyContent: 'space-between' }}>
          <button 
            className="btn-cancel"
            onClick={onClose}
            disabled={enviando}
            style={{ color: 'var(--black)' }}
          >
            Cancelar
          </button>
          
          <button 
            className="btn-reserva"
            onClick={enviarSolicitud}
            disabled={
              enviando || 
              !form.fecha || 
              cargandoHoras ||
              (esModoNueva && (!form.profesional_id || !form.motivo.trim()))
            }
          >
            {getBotonTexto()}
          </button>
        </div>
      </div>
    </div>
  );
}
