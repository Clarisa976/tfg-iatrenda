import React, { useState, useEffect } from 'react';
import axios       from 'axios';
import { EllipsisVertical } from 'lucide-react';
import { useNavigate }      from 'react-router-dom';
import '../../styles.css';

export default function PacientesProfesional () {
  /* ------------- estado interno ---------------------------------------- */
  const [pacientes,setPacientes]=useState([]);
  const [busqueda ,setBusqueda ]=useState('');
  const navigate=useNavigate();

  /* ------------- carga inicial ----------------------------------------- */
  const cargar = ()=>{
    const tk=localStorage.getItem('token');
    axios.get('/prof/pacientes',{headers:{Authorization:`Bearer ${tk}`}})
         .then(r=>{
            const arr = Array.isArray(r.data.pacientes)?r.data.pacientes:[];
            setPacientes(arr);
            if(r.data.token) localStorage.setItem('token',r.data.token);
         })
         .catch(()=>setPacientes([]));
  };
  useEffect(cargar,[]);

  /* ------------- filtrado por texto ------------------------------------ */
  const filtrados = pacientes.filter(p=>{
    const nom=`${p.nombre} ${p.apellido1} ${p.apellido2||''}`.toLowerCase();
    return nom.includes(busqueda.toLowerCase());
  });

  /* ------------- helpers UI ------------------------------------------- */
  const verPerfil = p => navigate(`/profesional/paciente/${p.id}`);

  /* ------------- render ------------------------------------------------ */
  return(
    <div className="usuarios-container">
      {/* encabezado */}
      <div className="usuarios-header">
        <h2 className="usuarios-title">Mis Pacientes</h2>
      </div>

      {/* buscador */}
      <div className="usuarios-buscar">
        <label>Buscar:</label>
        <div className="input-buscar">
          <input
            value={busqueda}
            onChange={e=>setBusqueda(e.target.value)}
            placeholder="Nombre o apellido"
          />
          <button className="btn-buscar">Buscar</button>
        </div>
      </div>

      {/* tabla pacientes */}
      <table className="usuarios-table">
        <thead>
          <tr>
            <th>Nombre completo</th>
            <th>Próxima cita</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
          {filtrados.length ? (
            filtrados.map(p=>(
              <tr key={p.id}>
                <td>{p.nombre} {p.apellido1}{p.apellido2?` ${p.apellido2}`:''}</td>
                <td>
                  {p.proxima_cita
                    ? new Date(p.proxima_cita).toLocaleDateString('es-ES',{
                        day:'2-digit',month:'2-digit',year:'numeric',
                        hour:'2-digit',minute:'2-digit'})
                    : 'Sin citas programadas'}
                </td>
                <td className="acciones-col">
                  {/* escritorio / tablet */}
                  <div className="acciones-desktop">
                    <button className="btn-action" onClick={()=>verPerfil(p)}>
                      Ver perfil
                    </button>
                  </div>
                  {/* móvil */}
                  <div className="acciones-mobile">
                    <EllipsisVertical size={20}/>
                    <div className="acciones-dropdown">
                      <button className="dropdown-item" onClick={()=>verPerfil(p)}>
                        Ver perfil
                      </button>
                    </div>
                  </div>
                </td>
              </tr>
            ))
          ) : (
            <tr>
              <td colSpan="3" className="sin-resultados">
                No tienes pacientes asignados
              </td>
            </tr>
          )}
        </tbody>
      </table>
    </div>
  );
}
