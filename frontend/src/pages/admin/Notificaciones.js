import React, { useEffect, useState } from 'react';
import axios from 'axios';
import { toast } from 'react-toastify';
import '../../styles.css';

export default function Notificaciones() {
  const [items,   setItems] = useState([]);
  const [loading, setLoad]  = useState(true);

  /* ─────────────── carga inicial ─────────────── */
  useEffect(()=>{ cargar(); },[]);

  const cargar = async () => {
    try{
      setLoad(true);
      const token = localStorage.getItem('token');
      if (token) axios.defaults.headers.common['Authorization']=`Bearer ${token}`;
      const { data } = await axios.get('/notificaciones');
      setItems(data.data || []);
      /* avisa al Header del nuevo total */
      window.dispatchEvent(new CustomEvent('noti-count',{detail:(data.data||[]).length}));
    }catch(e){ console.error(e); }
    setLoad(false);
  };

  /* ─────────────── confirmar / rechazar ─────────────── */
  const accion = async (id, tipoAcc) => {
    try{
      await axios.post(`/notificaciones/${id}`, { accion:tipoAcc });
      toast.success(`Cita ${tipoAcc==='CONFIRMAR'?'confirmada':'cancelada'}`);
      setItems(lst=>{
        const nuevo = lst.filter(x=>x.id!==id);
        /* actualiza Header inmediatamente */
        window.dispatchEvent(new CustomEvent('noti-count',{detail:nuevo.length}));
        return nuevo;
      });
    }catch{
      toast.error('No se pudo procesar');
    }
  };

  /* ───────────────  UI  ─────────────── */
  return (
    <div className="usuarios-container">
      <div className="usuarios-header">
        <h2 className="usuarios-title">Notificaciones pendientes</h2>
        <a className="btn-agregar" onClick={cargar}>Actualizar</a>
      </div>

      {loading? (
        <p>Cargando…</p>
      ) : items.length===0 ? (
        <p>No hay notificaciones.</p>
      ) : (
        <table className="usuarios-table">
          <thead>
            <tr>
              <th>Fecha y hora</th>
              <th>Paciente</th>
              <th>Profesional</th>
              <th>Tipo</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {items.map(n=>(
              <tr key={n.id}>
                <td>{n.fecha}</td>
                <td className="mayusculas">{n.paciente}</td>
                <td className="mayusculas">{n.profesional}</td>
                <td>{n.tipo.replace(/_/g,' ')}</td>
                <td>
                  <button className="btn-primary"
                          onClick={()=>accion(n.id,'CONFIRMAR')}>Confirmar</button>{' '}
                  <button className="btn-secondary"
                          onClick={()=>accion(n.id,'RECHAZAR')}>Rechazar</button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  );
}
