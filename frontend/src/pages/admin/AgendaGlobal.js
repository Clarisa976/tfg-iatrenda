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
moment.locale('es');
const localizer = momentLocalizer(moment);

export default function AgendaGlobal() {
  const [profesionales, setProfesionales] = useState([]);
  const [eventos, setEventos]             = useState([]);
  const [busqueda, setBusqueda]           = useState('');
  const [openModal, setOpenModal]         = useState(false);
  const [detalle, setDetalle]             = useState(null);
  const [detalleOpen, setDetalleOpen]     = useState(false);

  /* cargar datos */
  useEffect(() => { cargar(); }, []);
  const cargar = async () => {
    try {
      const token = localStorage.getItem('token');
      if (token) axios.defaults.headers.common['Authorization'] = `Bearer ${token}`;

      const [pRes, eRes] = await Promise.all([
        axios.get('/profesionales'),
        axios.get('/agenda/global')
      ]);

      if (pRes.data.ok) setProfesionales(pRes.data.data || []);
      if (eRes.data.ok) setEventos(map(eRes.data.data || []));
    } catch (e) { console.error(e); }
  };

  const map = arr => arr.map(x => {
    // Usar directamente el nombre del profesional que viene del backend
    let profNombre = x.nombre_profesional || '';
    
    // Si no hay nombre, mostrar el ID como fallback
    if (!profNombre && x.recurso) {
      profNombre = `ID: ${x.recurso}`;
    }
    
    return {
      id: x.id,
      tipo: x.tipo,
      nota: x.titulo,
      start: new Date(x.inicio),
      end: new Date(x.fin),
      profId: x.recurso,
      profNombre: profNombre,
      creadorNombre: x.creador || '—',
      title: `${x.tipo} – ${profNombre || 'Todos'}`
    };
  });

  /* filtrado */
  const eventosFiltrados = useMemo(() => {
    if (busqueda.trim() === '') return eventos;
    const txt = busqueda.toLowerCase();
    const ids = profesionales
      .filter(p => p.nombre.toLowerCase().includes(txt))
      .map(p => p.id);
    return eventos.filter(ev => ids.includes(ev.profId));
  }, [busqueda, eventos, profesionales]);

  /* guardar */
  const guardarEvento = async datos => {
    try {
      await axios.post('/agenda/global', datos);
      await cargar();
      setOpenModal(false);
      toast.success('Evento guardado');
    } catch (e) {
      toast.error(e.response?.data?.mensaje || 'Error');
    }
  };

  /* eliminar */
  const eliminarEvento = async id => {
    try {
      await axios.delete(`/agenda/global/${id}`);
      await cargar();
      setDetalleOpen(false);
      toast.success('Evento eliminado');
    } catch {
      toast.error('No se pudo eliminar');
    }
  };

  /* colores opcionales */
  const eventStyleGetter = event => {
    const c = { VACACIONES:'#FEC400', AUSENCIA:'#FF6464', BAJA:'#A259FF',
                EVENTO:'#56CCF2', OTROS:'#7AD6A0', cita:'#2F80ED' };
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
        <Calendar
          localizer={localizer}
          events={eventosFiltrados}
          startAccessor="start"
          endAccessor="end"
          tooltipAccessor="nota"
          eventPropGetter={eventStyleGetter}
          className="calendario-agenda"
          onSelectEvent={e => { setDetalle(e); setDetalleOpen(true); }}
        />
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
