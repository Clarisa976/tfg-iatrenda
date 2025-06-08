import React, { useState } from 'react';
import '../../styles.css';

export default function EventoModal({ 
  open, 
  toggle, 
  profesionales, 
  profSeleccionado = null, 
  soloLecturaProfesional = false, 
  onSave 
}) {
  const [form, setForm] = useState({
    tipo: 'EVENTO',
    profId: profSeleccionado || '', 
    inicio: '',
    fin: '',
    nota: ''
  });
  
  const set = (k, v) => setForm(o => ({...o, [k]: v}));
  const guardar = () => onSave({...form});
  
  if (!open) return null;
  
  return (
    <div className="modal-backdrop" onClick={toggle}>
      <div className="modal" onClick={e => e.stopPropagation()}>
        <div className="modal-header">
          <h5>Nuevo evento</h5>
          <button type="button" className="modal-close" onClick={toggle}>×</button>
        </div>
        
        <div className="modal-body">
          <form>
            <div className="field">
              <label>Profesional</label>
              {soloLecturaProfesional ? (
                <input 
                  type="text" 
                  value="Mi agenda personal" 
                  disabled
                />
              ) : (
                <select 
                  value={form.profId}
                  onChange={e => set('profId', e.target.value)}
                >
                  <option value="">Seleccionar profesional...</option>
                  <option value="0">Todos los profesionales</option>
                  {profesionales.map(p => (
                    <option key={p.id} value={p.id}>
                      {p.nombre} {p.apellido1} {p.apellido2 || ''}
                    </option>
                  ))}
                </select>
              )}
            </div>
            
            <div className="field">
              <label>Tipo de evento</label>
              <select 
                value={form.tipo}
                onChange={e => set('tipo', e.target.value)}
              >
                <option value="VACACIONES">Vacaciones</option>
                <option value="AUSENCIA">Ausencia</option>
                <option value="BAJA">Baja</option>
                <option value="EVENTO">Evento</option>
                <option value="OTROS">Otros</option>
              </select>
            </div>
            
            <div className="field">
              <label>Inicio</label>
              <input
                type="datetime-local"
                value={form.inicio}
                onChange={e => set('inicio', e.target.value)}
              />
            </div>
            
            <div className="field">
              <label>Fin</label>
              <input
                type="datetime-local"
                value={form.fin}
                onChange={e => set('fin', e.target.value)}
              />
            </div>
            
            <div className="field">
              <label>Comentario</label>
              <textarea
                value={form.nota}
                onChange={e => set('nota', e.target.value)}
              />
            </div>
          </form>
        </div>
        
        <div className="modal-footer">
          <button className="btn-secondary" onClick={toggle}>Cancelar</button>
          <button className="btn-primary" onClick={guardar}>Guardar</button>
        </div>
      </div>
    </div>
  );
}
