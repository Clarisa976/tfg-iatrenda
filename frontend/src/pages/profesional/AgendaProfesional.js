import React, { useEffect, useState } from 'react';
import axios from 'axios';
import { Calendar, momentLocalizer } from 'react-big-calendar';
import moment from 'moment';
import EventoModal from '../../components/modals/EventoModal';
import EventoDetalleModal from '../../components/modals/EventoDetalleModal';
import { toast } from 'react-toastify';
import 'react-big-calendar/lib/css/react-big-calendar.css';
import 'moment/locale/es';
import '../../styles.css';

axios.defaults.baseURL = process.env.REACT_APP_API_URL;
moment.locale('es');
const localizer = momentLocalizer(moment);

export default function AgendaProfesional() {
  const [eventos, setEventos] = useState([]);
  const [perfilProf, setPerfilProf] = useState(null);
  const [openModal, setOpenModal] = useState(false);
  const [detalle, setDetalle] = useState(null);
  const [detalleOpen, setDetalleOpen] = useState(false);  
  useEffect(() => { 
    cargar(); 
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Función para cargar eventos de un mes
  const cargarEventosMes = async (year, month, idProf) => {
    try {
      console.log(`Cargando eventos para ${year}-${month} (Profesional ID: ${idProf})`);
      const token = localStorage.getItem('token');
      if (token) axios.defaults.headers.common['Authorization'] = `Bearer ${token}`;
      
      const eRes = await axios.get(`/agenda/global?profId=${idProf}&year=${year}&month=${month}`);
      
      if (eRes.data.ok) {
        console.log('Eventos recibidos del servidor:', eRes.data.data);
        const eventosFormateados = map(eRes.data.data || []);
        console.log('Eventos formateados para el calendario:', eventosFormateados);
        return eventosFormateados;
      } else {
        console.error('Error en respuesta de eventos:', eRes.data);
        return [];
      }
    } catch (e) {
      console.error(`Error al cargar eventos para ${year}-${month}:`, e);
      return [];
    }
  };

  const cargar = async () => {
    try {
      const token = localStorage.getItem('token');
      if (token) axios.defaults.headers.common['Authorization'] = `Bearer ${token}`;

      // perfil del profesional logueado
      const perfilRes = await axios.get('/prof/perfil');
      
      if (perfilRes.data.ok) {
        const perfil = perfilRes.data.data;
        console.log('Perfil del profesional cargado:', perfil);
        setPerfilProf(perfil);
        
        // eventos solo de este profesional
        const idProf = perfil.persona.id_persona;
        console.log(`Obteniendo eventos para el profesional ID: ${idProf}`);
        
        // mes actual
        const today = new Date();
        const currentYear = today.getFullYear();
        const currentMonth = today.getMonth() + 1; // JavaScript months are 0-indexed
        
        const eventosActuales = await cargarEventosMes(currentYear, currentMonth, idProf);
        setEventos(eventosActuales);
      }
    } catch (e) { 
      console.error('Error al cargar la agenda:', e);
      toast.error('Error al cargar la agenda');
    }
  };
  const map = arr => arr.map(x => {
    return {
      id: x.id,
      tipo: x.tipo,
      nota: x.titulo,
      start: new Date(x.inicio),
      end: new Date(x.fin),
      profId: x.recurso,
      profNombre: x.nombre_profesional || 'Yo',
      creadorNombre: x.creador || 'Sin especificar',
      title: `${x.tipo} – ${x.titulo || ''}`
    };
  });
  /* guardar */
  const guardarEvento = async datos => {
    try {
      // Establecer el token para la solicitud
      const token = localStorage.getItem('token');
      if (token) axios.defaults.headers.common['Authorization'] = `Bearer ${token}`;
      
      // Asegurar que el evento se asigna al profesional logueado
      if (perfilProf) {
        datos.profId = perfilProf.persona.id_persona;
      }
      
      // Verificar que tenemos los datos mínimos necesarios
      if (!datos.tipo || !datos.inicio || !datos.fin) {
        toast.error('Faltan datos obligatorios para el evento');
        return;
      }
      
      console.log('Enviando datos de evento:', datos);
      
      const response = await axios.post('/agenda/global', datos);
      console.log('Respuesta del servidor:', response.data);
      
      if (response.data.ok) {
        toast.success('Evento guardado correctamente');
        
        // Obtener el mes y año del evento creado para cargar los eventos correctos
        const fechaEvento = new Date(datos.inicio);
        const yearEvento = fechaEvento.getFullYear();
        const monthEvento = fechaEvento.getMonth() + 1; 
        
        // Cargar eventos para el mes del evento creado
        if (perfilProf) {
          const idProf = perfilProf.persona.id_persona;
          const nuevosEventos = await cargarEventosMes(yearEvento, monthEvento, idProf);
          setEventos(nuevosEventos);
        }
        
        setOpenModal(false);
      } else {
        throw new Error(response.data.mensaje || 'Error desconocido');
      }
    } catch (e) {
      console.error('Error al guardar evento:', e);
      toast.error(e.response?.data?.mensaje || 'Error al guardar el evento');
    }
  };

  /* eliminar */
  const eliminarEvento = async id => {
    try {
      const token = localStorage.getItem('token');
      if (token) axios.defaults.headers.common['Authorization'] = `Bearer ${token}`;
      
      await axios.delete(`/agenda/global/${id}`);
      await cargar();
      setDetalleOpen(false);
      toast.success('Evento eliminado correctamente');
    } catch (e) {
      console.error('Error al eliminar evento:', e);
      toast.error('No se pudo eliminar el evento');
    }
  };

  /* colores para tipos de eventos */
  const eventStyleGetter = event => {
    const c = { 
      VACACIONES: '#FEC400', 
      AUSENCIA: '#FF6464', 
      BAJA: '#A259FF',
      EVENTO: '#56CCF2', 
      cita: '#2F80ED' 
    };
    return { style: { backgroundColor: c[event.tipo] || '#2F80ED' } };
  };

  return (
    <div className="agenda-wrapper">
      <div className="agenda-header">
        <h2 className="section-title">Mi Agenda</h2>
      </div>

      <div className="agenda-actions">
        <a href="#nuevo" className="btn-reserva"
           onClick={e => { e.preventDefault(); setOpenModal(true); }}>
          Añadir evento
        </a>
      </div>      
      <div className="cal-wrapper">
        <Calendar
          localizer={localizer}
          events={eventos}
          startAccessor="start"
          endAccessor="end"
          tooltipAccessor="nota"
          eventPropGetter={eventStyleGetter}
          className="calendario-agenda"
          onSelectEvent={e => { setDetalle(e); setDetalleOpen(true); }}
          onNavigate={(date) => {
            // Cuando el usuario navega a un mes diferente
            const year = date.getFullYear();
            const month = date.getMonth() + 1; 
            
            if (perfilProf) {
              // Cargar eventos para el nuevo mes
              const idProf = perfilProf.persona.id_persona;
              cargarEventosMes(year, month, idProf).then(nuevosEventos => {
                setEventos(nuevosEventos);
              });
            }
          }}
          messages={{
            next: "Siguiente",
            previous: "Anterior",
            today: "Hoy",
            month: "Mes",
            week: "Semana",
            day: "Día",
            agenda: "Agenda",
            date: "Fecha",
            time: "Hora",
            event: "Evento",
            noEventsInRange: "No hay eventos en este periodo"
          }}
        />
      </div>

      {/* Modales */}
      <EventoModal
        open={openModal}
        toggle={() => setOpenModal(!openModal)}
        profesionales={perfilProf ? [{
          id: perfilProf.persona.id_persona,
          nombre: `${perfilProf.persona.nombre} ${perfilProf.persona.apellido1}`
        }] : []}
        profSeleccionado={perfilProf?.persona.id_persona}
        soloLecturaProfesional={false} 
        esProfesionalPropio={true}
        onSave={guardarEvento}
      />

      <EventoDetalleModal
        open={detalleOpen}
        toggle={() => setDetalleOpen(!detalleOpen)}
        event={detalle}
        onDelete={eliminarEvento}
      />
    </div>
  );
}