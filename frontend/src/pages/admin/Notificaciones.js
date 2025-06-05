import React, { useEffect, useState } from 'react';
import axios from 'axios';
import { toast } from 'react-toastify';
import '../../styles.css';

export default function Notificaciones() {
  const [items, setItems] = useState([]);
  const [loading, setLoad] = useState(true);

  // Configurar axios una sola vez al montar el componente
  useEffect(() => {
    const baseURL = process.env.REACT_APP_API_URL || 'http://localhost:8081';
    axios.defaults.baseURL = baseURL;
    axios.defaults.withCredentials = true;
    
    cargar();
  }, []);

  const cargar = async () => {
    try {
      setLoad(true);
      const token = localStorage.getItem('token');
      const baseURL = process.env.REACT_APP_API_URL || 'http://localhost:8081';
      
      const response = await fetch(`${baseURL}/notificaciones`, {
        method: 'GET',
        headers: {
          'Authorization': token ? `Bearer ${token}` : '',
          'Accept': 'application/json'
        },
        credentials: 'include'
      });
      
      if (!response.ok) {
        throw new Error(`Error HTTP: ${response.status}`);
      }
      
      const data = await response.json();
      
      setItems(data.data || []);
      window.dispatchEvent(new CustomEvent('noti-count', {detail: (data.data || []).length}));
    } catch (error) {
      console.error('Error cargando notificaciones:', error);
      toast.error('Error al cargar las notificaciones: ' + (error.message || 'Error desconocido'));
    } finally {
      setLoad(false);
    }
  };  const accion = async (id, tipoAcc) => {
    try {
      const token = localStorage.getItem('token');
      const baseURL = process.env.REACT_APP_API_URL || 'http://localhost:8081';
      
      console.log(`Enviando solicitud a ${baseURL}/notificaciones/${id} con acción ${tipoAcc}`);
      
      // Usar fetch en lugar de axios para seguir el mismo patrón que cargar()
      const response = await fetch(`${baseURL}/notificaciones/${id}`, {
        method: 'POST',
        headers: {
          'Authorization': token ? `Bearer ${token}` : '',
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        body: JSON.stringify({ accion: tipoAcc }),
        credentials: 'include'
      });
      
      if (!response.ok) {
        throw new Error(`Error HTTP: ${response.status}`);
      }
      
      const data = await response.json();
      console.log('Respuesta del servidor:', data);
      
      if (data.ok) {
        toast.success(`Cita ${tipoAcc === 'CONFIRMAR' ? 'confirmada' : 'cancelada'}`);
        setItems(lst => {
          const nuevo = lst.filter(x => x.id !== id);
          window.dispatchEvent(new CustomEvent('noti-count', {detail: nuevo.length}));
          return nuevo;
        });
      } else {
        throw new Error(data.mensaje || 'Error desconocido');
      }
    } catch (error) {
      console.error('Error procesando acción:', error);
      toast.error(error.message || 'No se pudo procesar la acción');
    }
  };

  return (
    <div className="usuarios-container">
      <div className="usuarios-header">
        <h2 className="usuarios-title">Notificaciones pendientes</h2>
        <button className="btn-agregar" onClick={cargar}>Actualizar</button>
      </div>

      {loading ? (
        <p>Cargando…</p>
      ) : items.length === 0 ? (
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
            {items.map(n => (
              <tr key={n.id}>
                <td>{n.fecha}</td>
                <td className="mayusculas">{n.paciente}</td>
                <td className="mayusculas">{n.profesional}</td>
                <td>{n.tipo.replace(/_/g, ' ')}</td>
                <td>
                  <button 
                    className="btn-primary"
                    onClick={() => accion(n.id, 'CONFIRMAR')}
                  >
                    Confirmar
                  </button>{' '}
                  <button 
                    className="btn-secondary"
                    onClick={() => accion(n.id, 'RECHAZAR')}
                  >
                    Rechazar
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  );
}
