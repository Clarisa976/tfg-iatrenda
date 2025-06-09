import React, { useState, useEffect } from 'react';
import axios from 'axios';
import { EllipsisVertical, CheckCircle, XCircle } from 'lucide-react';
import 'react-toastify/dist/ReactToastify.css';

import AddUserModal from '../../components/modals/AddUserModal';
import ConfirmacionEliminacionModal from '../../components/modals/ConfirmacionEliminacionModal';
import '../../styles.css';

export default function Usuarios() {

  const [usuarios, setUsuarios] = useState([]);
  const [busqueda, setBusqueda] = useState('');
  const [filtro, setFiltro] = useState('TODOS');

  const [openModal, setOpenModal] = useState(false);
  const [selectedUser, setSelectedUser] = useState(null);

  const [delOpen, setDelOpen] = useState(false);
  const [userToDel, setUserToDel] = useState(null);

  const [toast, setToast] = useState({ show: false, ok: true, titulo: '', mensaje: '' });


  const cargar = () => {
    const tk = localStorage.getItem('token');
    axios.get(`${process.env.REACT_APP_API_URL}/admin/usuarios`, {
      headers: { Authorization: `Bearer ${tk}` }
    })
      .then(r => setUsuarios(Array.isArray(r.data.usuarios) ? r.data.usuarios : []))
      .catch(() => setUsuarios([]));
  };
  useEffect(cargar, []);

  const mostrados = usuarios.filter(u => {
    const nombre = `${u.nombre} ${u.apellido1} ${u.apellido2 || ''}`.toLowerCase();
    const okTxt = nombre.includes(busqueda.toLowerCase());
    const okRol =
      filtro === 'TODOS' ||
      (filtro === 'PROFESIONALES' && u.rol === 'profesional') ||
      (filtro === 'PACIENTES' && u.rol === 'paciente');
    return okTxt && okRol;
  });

  const agregar = () => { setSelectedUser(null); setOpenModal(true); };
  const editar = u => { setSelectedUser(u); setOpenModal(true); };
  const borrar = u => { setUserToDel(u); setDelOpen(true); };

  const mostrarToast = (config) => {

    setToast({ ...config, show: true });
    setTimeout(() => {
      setToast(prev => ({ ...prev, show: false }));
    }, 5000);
  };

  const confirmarDelete = async () => {
    const tk = localStorage.getItem('token');
    try {
      const response = await axios.delete(
        `/admin/borrar-usuario/${userToDel.id}`,
        { headers: { 'Authorization': `Bearer ${tk}` } }
      );

      console.log('Respuesta al eliminar:', response.data);
      if (response.data && response.data.ok) {
        mostrarToast({
          ok: true,
          titulo: 'Usuario desactivado',
          mensaje: `${userToDel.nombre} ${userToDel.apellido1} desactivado correctamente.`
        });
        setDelOpen(false);
        setUserToDel(null);
        cargar();
      } else {
        mostrarToast({
          ok: false,
          titulo: 'Error',
          mensaje: response.data.mensaje || 'No se pudo desactivar el usuario.'
        });
      }
    } catch (err) {
      console.error('Error al desactivar:', err);

      // Si hay datos en la respuesta de error
      if (err.response && err.response.data) {
        console.log('Datos de error:', err.response.data);

        // Manejar dependencias si existen
        if (err.response.status === 409 && err.response.data.tipo === 'DEPENDENCIAS') {
          const d = err.response.data.deps;
          const detalle = Object.entries(d)
            .filter(([k, v]) => v > 0)
            .map(([k, v]) => `${v} ${k}`)
            .join(', ');
          mostrarToast({
            ok: false,
            titulo: 'No se puede borrar',
            mensaje: `El usuario tiene ${detalle}.`
          });
        } else {
          mostrarToast({
            ok: false,
            titulo: 'Error',
            mensaje: err.response.data.mensaje || 'No se pudo eliminar el usuario.'
          });
        }
      } else {
        mostrarToast({
          ok: false,
          titulo: 'Error',
          mensaje: 'Error de conexión. Inténtalo de nuevo.'
        });
      }
    }
  };


  const afterSave = (nombre, toastConfig) => {
    if (toastConfig) {
      mostrarToast(toastConfig);
    } else if (nombre) {
      mostrarToast({
        ok: true,
        titulo: selectedUser ? 'Usuario actualizado' : 'Usuario creado',
        mensaje: selectedUser
          ? `${nombre} ha sido actualizado correctamente.`
          : `${nombre} ha sido creado correctamente.`
      });
    }

    setOpenModal(false);
    setSelectedUser(null);
    cargar();
  };

  return (
    <div className="usuarios-container">
      {/* cabecera */}
      <div className="usuarios-header">
        <h2 className="usuarios-title">Usuarios</h2>
        <button className="btn-agregar" onClick={agregar}>Agregar usuario</button>
      </div>

      {/* buscador */}
      <div className="usuarios-buscar">
        <label>Buscar:</label>
        <div className="input-buscar">
          <input value={busqueda}
            onChange={e => setBusqueda(e.target.value)}
            placeholder="Nombre o apellido" />
          <button className="btn-buscar">Buscar</button>
        </div>
      </div>

      {/* filtros */}
      <div className="usuarios-filtros">
        {['TODOS', 'PROFESIONALES', 'PACIENTES'].map(f => (
          <button key={f}
            className={`filtro-btn${filtro === f ? ' active' : ''}`}
            onClick={() => setFiltro(f)}>
            {f === 'TODOS' ? 'Todos' : f === 'PROFESIONALES' ? 'Profesionales' : 'Pacientes'}
          </button>
        ))}
      </div>

      {/* tabla */}
      <table className="usuarios-table">
        <thead>
          <tr><th>Nombre completo</th><th>Rol</th><th>Acciones</th></tr>
        </thead>
        <tbody>
          {mostrados.length ? mostrados.map(u => (
            <tr key={u.id}>
              <td>{u.nombre} {u.apellido1}{u.apellido2 ? ` ${u.apellido2}` : ''}</td>
              <td className="mayusculas">{u.rol}</td>
              <td className="acciones-col">
                {/* desktop / tablet */}
                <div className="acciones-desktop">
                  <button className="btn-action" onClick={() => editar(u)}>Ver / Editar</button>
                  <button className="btn-action btn-remove" onClick={() => borrar(u)}>Eliminar</button>
                </div>
                {/* móvil */}
                <div className="acciones-mobile">
                  <EllipsisVertical size={20} />
                  <div className="acciones-dropdown">
                    <button className="dropdown-item" onClick={() => editar(u)}>Ver / Editar</button>
                    <button className="dropdown-item text-red" onClick={() => borrar(u)}>Eliminar</button>
                  </div>
                </div>
              </td>
            </tr>
          )) : (
            <tr><td colSpan="3" className="sin-resultados">No hay usuarios</td></tr>
          )}
        </tbody>
      </table>

      {/* modales */}
      <AddUserModal
        open={openModal}
        toggle={() => { setOpenModal(false); setSelectedUser(null); }}
        initialUser={selectedUser}
        onSuccess={afterSave}
      />      <ConfirmacionEliminacionModal
        open={delOpen}
        toggle={() => setDelOpen(o => !o)}
        onConfirm={confirmarDelete}
        message={`¿Desactivar a ${userToDel?.nombre} ${userToDel?.apellido1}?`}
      />

      {/* Toast global personalizado */}
      {toast.show && (
        <div className="toast-global centered-toast">
          <div className={`toast-card ${toast.ok ? 'success' : 'error'}`}>
            {toast.ok
              ? <CheckCircle size={48} className="toast-icon success" />
              : <XCircle size={48} className="toast-icon error" />
            }
            <h3 className="toast-title">{toast.titulo}</h3>
            <p className="toast-text">{toast.mensaje}</p>
          </div>
        </div>
      )}
    </div>
  );
}

