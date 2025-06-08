import React, { useEffect, useState, useMemo } from 'react';
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

// Configurar moment y localizer
moment.locale('es');
const localizer = momentLocalizer(moment);

// Verificar que el localizer se configuró correctamente
console.log('AgendaGlobal: Configuración de moment:', {
  locale: moment.locale(),
  localizerConfigured: !!localizer
});

export default function AgendaGlobal() {
  const [profesionales, setProfesionales] = useState([]);
  const [eventos, setEventos]             = useState([]);
  const [busqueda, setBusqueda]           = useState('');
  const [openModal, setOpenModal]         = useState(false);
  const [detalle, setDetalle]             = useState(null);
  const [detalleOpen, setDetalleOpen]     = useState(false);
  const [loading, setLoading]             = useState(true);
  /* cargar datos */
  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => { cargar(); }, []);  const cargar = async () => {
    try {
      setLoading(true);
      const token = localStorage.getItem('token');
      if (token) axios.defaults.headers.common['Authorization'] = `Bearer ${token}`;

      console.log('AgendaGlobal: Iniciando carga de datos...');
      
      const [pRes, eRes] = await Promise.all([
        axios.get('/profesionales'),
        axios.get('/agenda/global'),
      ]);

      console.log('AgendaGlobal: Respuesta profesionales:', pRes.data);
      console.log('AgendaGlobal: Respuesta eventos:', eRes.data);

      if (pRes.data.ok) {
        setProfesionales(pRes.data.data || []);
        console.log('AgendaGlobal: Profesionales cargados:', pRes.data.data?.length || 0);
      }
      
      if (eRes.data.ok) {
        const eventosFormateados = map(eRes.data.data || []);
        setEventos(eventosFormateados);
        console.log('AgendaGlobal: Eventos cargados y formateados:', eventosFormateados.length);
      }
    } catch (e) {
      console.error('AgendaGlobal: Error al cargar datos:', e);
      toast.error('Error al cargar los datos de la agenda: ' + (e.message || 'Error desconocido'));
    } finally {
      setLoading(false);
    }
  };
  const map = (arr) => {
    console.log('AgendaGlobal: Mapeando eventos recibidos:', arr);
    
    return arr.map((x) => {
      // Usar directamente el nombre del profesional 
      let profNombre = x.nombre_profesional || '';

      if (!profNombre && x.recurso) {
        profNombre = `ID: ${x.recurso}`;
      }
      
      const eventoFormateado = {
        id: x.id,
        tipo: x.tipo,
        nota: x.titulo,
        start: new Date(x.inicio),
        end: new Date(x.fin),
        profId: x.recurso,
        profNombre,
        creadorNombre: x.creador || '—',
        title: `${x.tipo} – ${profNombre || 'Todos'}`,
      };
      
      console.log('AgendaGlobal: Evento original:', x, 'Evento formateado:', eventoFormateado);
      return eventoFormateado;
    });
  };
  /* filtrado */
  const eventosFiltrados = useMemo(() => {
    console.log('AgendaGlobal: Filtrando eventos. Total:', eventos.length, 'Búsqueda:', busqueda);
    
    if (busqueda.trim() === '') return eventos;
    const txt = busqueda.toLowerCase();
    const ids = profesionales
      .filter(p => p.nombre.toLowerCase().includes(txt))
      .map(p => p.id);
    
    const filtrados = eventos.filter(ev => ids.includes(ev.profId));
    console.log('AgendaGlobal: Eventos filtrados:', filtrados.length);
    return filtrados;
  }, [busqueda, eventos, profesionales]);
  /* guardar */
  const guardarEvento = async datos => {
    try {
      console.log('AgendaGlobal: Guardando evento:', datos);
      await axios.post('/agenda/global', datos);
      await cargar();
      setOpenModal(false);
      toast.success('Evento guardado');
    } catch (e) {
      console.error('AgendaGlobal: Error al guardar evento:', e);
      toast.error(e.response?.data?.mensaje || 'Error al guardar el evento');
    }
  };
  /* eliminar */
  const eliminarEvento = async id => {
    try {
      console.log('AgendaGlobal: Eliminando evento ID:', id);
      await axios.delete(`/agenda/global/${id}`);
      await cargar();
      setDetalleOpen(false);
      toast.success('Evento eliminado');
    } catch (e) {
      console.error('AgendaGlobal: Error al eliminar evento:', e);
      toast.error('No se pudo eliminar el evento');
    }
  };

  /* colores opcionales */
  const eventStyleGetter = (event) => {
    const c = {
      VACACIONES: '#FEC400',
      AUSENCIA: '#FF6464',
      BAJA: '#A259FF',
      EVENTO: '#56CCF2',
      OTROS: '#7AD6A0',
      cita: '#2F80ED',
    };
    return { style: { backgroundColor: c[event.tipo] || '#2F80ED' } };
  };

  return (
    <div className="agenda-wrapper">
      <div className="agenda-header">
        <h2 className="section-title">Agenda global</h2>
      </div>

      <div className="agenda-actions">
        <a href="#nuevo" className="btn-reserva"
           onClick={e => { e.preventDefault(); setOpenModal(true); }}>
          Añadir evento
        </a>
      </div>

      <div className="agenda-buscar">
        <label htmlFor="buscarPro">Buscar profesional</label>
        <div className="agenda-buscar-input">
          <input id="buscarPro" type="search"
                 value={busqueda}
                 onChange={e=>setBusqueda(e.target.value)}
                 placeholder="Nombre o apellidos…" />
        </div>
      </div>      <div className="cal-wrapper">
        {loading ? (
          <div style={{ 
            textAlign: 'center', 
            padding: '3rem',
            backgroundColor: '#f8f9fa',
            borderRadius: '8px',
            margin: '2rem 0'
          }}>
            <p style={{ color: '#6c757d', margin: 0 }}>
              Cargando agenda global...
            </p>
          </div>
        ) : eventos.length === 0 ? (
          <div style={{ 
            textAlign: 'center', 
            padding: '3rem',
            backgroundColor: '#f8f9fa',
            borderRadius: '8px',
            margin: '2rem 0'
          }}>
            <p style={{ color: '#6c757d', margin: 0 }}>
              No hay eventos en la agenda para mostrar
            </p>
          </div>
        ) : (          <Calendar
            localizer={localizer}
            events={eventosFiltrados}
            startAccessor="start"
            endAccessor="end"
            tooltipAccessor="nota"
            eventPropGetter={eventStyleGetter}
            className="calendario-agenda"
            onSelectEvent={e => { setDetalle(e); setDetalleOpen(true); }}
            style={{
              height: '70vh',
              minHeight: '500px'
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
            onNavigate={(date) => {
              console.log('AgendaGlobal: Navegando a fecha:', date);
            }}
            onView={(view) => {
              console.log('AgendaGlobal: Cambiando vista a:', view);
            }}
          />
        )}
      </div>

      {/* Modales */}
      <EventoModal
        open={openModal}
        toggle={() => setOpenModal(!openModal)}
        profesionales={profesionales}
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
