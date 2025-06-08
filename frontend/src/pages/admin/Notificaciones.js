import React, { useEffect, useState } from 'react';
import axios from 'axios';
import { toast } from 'react-toastify';
import '../../styles.css';

export default function Notificaciones() {
  const [items, setItems] = useState([]);
  const [loading, setLoad] = useState(true);

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
  };

  const accion = async (id, tipoAcc) => {
    try {
      const token = localStorage.getItem('token');
      const baseURL = process.env.REACT_APP_API_URL || 'http://localhost:8081';
      
      console.log(`Enviando solicitud a ${baseURL}/notificaciones/${id} con acción ${tipoAcc}`);

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
      
      console.log('Status de respuesta:', response.status);
      console.log('Headers de respuesta:', Object.fromEntries(response.headers.entries()));
      

      const responseText = await response.text();
      console.log('Respuesta del servidor (texto):', responseText);
      
      if (!response.ok) {

        throw new Error(`Error HTTP ${response.status}: ${responseText.substring(0, 200)}...`);
      }

      let data;
      try {
        data = JSON.parse(responseText);
        console.log('Respuesta del servidor (JSON):', data);
      } catch (parseError) {
        console.error('Error parseando JSON:', parseError);
        throw new Error(`El servidor devolvió una respuesta no válida: ${responseText.substring(0, 200)}...`);
      }
      
      if (data.ok || data.success || data.status === 'success') {

        let mensaje = '';
        if (tipoAcc === 'CONFIRMAR') {
          mensaje = data.mensaje || data.message || 'Cita confirmada exitosamente. Se ha enviado una notificación al paciente.';
        } else if (tipoAcc === 'CANCELAR') {
          mensaje = data.mensaje || data.message || 'Cita rechazada exitosamente. Se ha enviado una notificación al paciente.';
        } else {
          mensaje = data.mensaje || data.message || `Acción ${tipoAcc} procesada correctamente`;
        }
        
        toast.success(mensaje, {
          position: "top-right",
          autoClose: 5000,
          hideProgressBar: false,
          closeOnClick: true,
          pauseOnHover: true,
          draggable: true,
        });
        
        // Actualizar la lista de notificaciones
        setItems(lst => {
          const nuevo = lst.filter(x => x.id !== id);
          window.dispatchEvent(new CustomEvent('noti-count', {detail: nuevo.length}));
          return nuevo;
        });

        setTimeout(() => {
          cargar();
        }, 1000);
        
      } else {
        throw new Error(data.mensaje || data.message || data.error || 'Error desconocido del servidor');
      }
    } catch (error) {
      console.error('Error procesando acción:', error);
      toast.error(`Error al procesar la acción: ${error.message}`, {
        position: "top-right",
        autoClose: 5000,
      });
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
        <p>No hay notificaciones pendientes.</p>
      ) : (
        <table className="usuarios-table">
          <thead>
            <tr>
              <th>Fecha y hora</th>
              <th>Paciente</th>
              <th>Profesional</th>
              <th>Tipo</th>
              <th>Acciones</th>
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
                    style={{ marginRight: '8px' }}
                  >Confirmar
                  </button>
                  <button 
                    className="btn-secondary"
                    onClick={() => accion(n.id, 'CANCELAR')}
                  >Rechazar
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