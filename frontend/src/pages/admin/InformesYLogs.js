import React, { useEffect, useState } from 'react';
import axios from 'axios';
import { BarChart2, FileDown, Calendar } from 'lucide-react';
import { toast } from 'react-toastify';
import 'react-toastify/dist/ReactToastify.css';
import '../../styles.css';


const now = new Date();
const year = now.getFullYear();
const month = now.getMonth() + 1; 


const meses = [
  'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 
  'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'
];

// Años desde 2023 hasta el año actual
const años = Array.from({ length: year - 2022 }, (_, i) => 2023 + i);

export default function InformesYLogs() {
  const [stats, setStats] = useState(null);
  const [loading, setLoading] = useState(false);
  const [loadingDownload, setLoadingDownload] = useState(false);
  const [selectedMonth, setSelectedMonth] = useState(month);
  const [selectedYear, setSelectedYear] = useState(year);
  const token = localStorage.getItem('token');

  /* carga estadísticas */
  useEffect(() => {
    async function fetchStats() {
      setLoading(true);
      try {
        const url = `${process.env.REACT_APP_API_URL}/admin/informes?year=${selectedYear}&month=${selectedMonth}`;
        const { data } = await axios.get(url, {
          headers: { Authorization: `Bearer ${token}` }
        });
        if (data.ok) {
          setStats(data.data);
        } else {
          toast.error('Error al obtener estadísticas: ' + (data.mensaje || 'Error desconocido'));
        }
      } catch (error) {
        console.error('Error al cargar estadísticas:', error);
        toast.error('No se pudieron obtener las estadísticas: ' + (error.response?.data?.mensaje || error.message));
      } finally {
        setLoading(false);
      }
    }
    fetchStats();
  }, [token, selectedMonth, selectedYear]);

  /* descarga CSV de logs */
  const descargarLogs = async () => {
    setLoadingDownload(true);
    try {
      const url = `${process.env.REACT_APP_API_URL}/admin/logs?year=${selectedYear}&month=${selectedMonth}`;
      
      const res = await axios.get(url, {
        headers: { Authorization: `Bearer ${token}` },
        responseType: 'blob'
      });

      // Verificar si la respuesta es realmente un CSV
      if (res.headers['content-type']?.includes('text/csv')) {
        const blob = new Blob([res.data], { type: 'text/csv' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = `logs_${selectedYear}_${String(selectedMonth).padStart(2, '0')}.csv`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(link.href);
        
        toast.success('Logs descargados correctamente');
      } else {
        // Si no es un CSV, probablemente es un mensaje de error
        const reader = new FileReader();
        reader.onload = function() {
          try {
            const errorData = JSON.parse(this.result);
            toast.error(errorData.mensaje || 'Error al descargar los logs');
          } catch {
            toast.error('Error al procesar la respuesta del servidor');
          }
        };
        reader.readAsText(res.data);
      }
    } catch (error) {
      console.error('Error al descargar logs:', error);
      toast.error('No se pudieron descargar los logs: ' + (error.response?.data?.mensaje || error.message));
    } finally {
      setLoadingDownload(false);
    }
  };

  // Formatea la fecha seleccionada para mostrar
  const fechaSeleccionada = new Date(selectedYear, selectedMonth - 1).toLocaleDateString('es-ES', 
    { month: 'long', year: 'numeric' }).replace(/^./, m => m.toUpperCase());
    
  return (
    <div className="usuarios-container informes-container-wide">
      <h2 className="usuarios-title informes-title-margin">
        Informes y Logs
      </h2>

      {/* Selector de fecha */}
      <div className="informes-fecha-selector">
        <Calendar size={18} />
        <div className="informes-fecha-selects">
          <select 
            value={selectedMonth} 
            onChange={(e) => setSelectedMonth(parseInt(e.target.value))}
            className="select-field"
          >
            {meses.map((mes, index) => (
              <option key={index + 1} value={index + 1}>{mes}</option>
            ))}
          </select>
          
          <select 
            value={selectedYear} 
            onChange={(e) => setSelectedYear(parseInt(e.target.value))}
            className="select-field"
          >
            {años.map(año => (
              <option key={año} value={año}>{año}</option>
            ))}
          </select>
        </div>
      </div>

      {/* estadísticas */}
      <h3>Estadísticas - {fechaSeleccionada}</h3>
      {loading ? (
        <p>Cargando estadísticas...</p>
      ) : stats ? (
        <div className="stats-grid">
          <div className="stat-card">
            <BarChart2 size={28} />
            <div>
              <p className="stat-number">{stats.total_citas}</p>
              <p className="stat-label">Citas totales</p>
            </div>
          </div>
          <div className="stat-card">
            <BarChart2 size={28} />
            <div>
              <p className="stat-number">{stats.citas_confirmadas}</p>
              <p className="stat-label">Confirmadas</p>
            </div>
          </div>
          <div className="stat-card">
            <BarChart2 size={28} />
            <div>
              <p className="stat-number red">{stats.citas_canceladas}</p>
              <p className="stat-label">Canceladas</p>
            </div>
          </div>
          <div className="stat-card">
            <BarChart2 size={28} />
            <div>
              <p className="stat-number">{stats.usuarios_activos}</p>
              <p className="stat-label">Usuarios activos</p>
            </div>
          </div>
        </div>
      ) : (
        <p>No hay datos disponibles para el período seleccionado</p>
      )}      

      {/* logs */}
      <h3 className="informes-logs-title">Logs</h3>
      <p>Descarga el histórico de eventos registrados para {fechaSeleccionada}:</p>
      <button 
        className="btn-reserva blue" 
        onClick={descargarLogs}
        disabled={loadingDownload}
      >
        <FileDown size={18} className="informes-icono-margin" />
        {loadingDownload ? 'Descargando...' : 'Descargar CSV'}
      </button>
    </div>
  );
}
