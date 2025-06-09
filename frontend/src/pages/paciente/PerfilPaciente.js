import React, { useState, useEffect } from 'react';
import axios from 'axios';
import { useSearchParams } from 'react-router-dom';
import { CheckCircle, XCircle, ChevronLeft, ChevronRight } from 'lucide-react';
import ModalVerTarea from '../../components/modals/ModalVerTarea';
import ModalVerHistorial from '../../components/modals/ModalVerHistorial';
import '../../styles.css';

export default function PerfilPaciente() {

    const [searchParams] = useSearchParams();
    const hoy = new Date().toISOString().split('T')[0];

    const [tareaSeleccionada, setTareaSeleccionada] = useState(null);
    const [mostrarModalTarea, setMostrarModalTarea] = useState(false);
    const [documentosHistorial, setDocumentosHistorial] = useState([]);
    const [mostrarModalHistorial, setMostrarModalHistorial] = useState(false);
    const [loadingHistorial, setLoadingHistorial] = useState(false);

    const [loadingTareas, setLoadingTareas] = useState(false);
    const [tareas, setTareas] = useState([]);
    const [currentSlide, setCurrentSlide] = useState(0);

    // Modo edición / solo lectura
    const [editMode, setEditMode] = useState(false);

    // Datos de persona editables
    const [form, setForm] = useState({
        nombre: '',
        apellido1: '',
        apellido2: '',
        email: '',
        telefono: '',
        fecha_nacimiento: '',
        tipo_via: '',
        nombre_calle: '',
        numero: '',
        escalera: '',
        piso: '',
        puerta: '',
        codigo_postal: '',
        ciudad: '',
        provincia: '',
        pais: 'España'
    });

    // Datos de paciente 
    const [pacData, setPacData] = useState({
        tipo_paciente: 'ADULTO',
        observaciones_generales: ''
    });

    // Datos de tutor
    const [tutorData, setTutorData] = useState({
        nombre: '',
        apellido1: '',
        apellido2: '',
        fecha_nacimiento: '',
        nif: '',
        email: '',
        telefono: '',
        metodo_contacto_preferido: []
    });


    const [consent, setConsent] = useState(false);

    const [toast, setToast] = useState({ show: false, ok: true, titulo: '', msg: '' });
    const [errors, setErrors] = useState({});


    useEffect(() => {
        axios.defaults.baseURL = process.env.REACT_APP_API_URL;
        const token = localStorage.getItem('token');
        if (token) axios.defaults.headers.common['Authorization'] = `Bearer ${token}`;
    }, []);

    // Carga inicial 
    useEffect(() => {
        async function cargar() {
            try {

                const { data } = await axios.get('/pac/perfil');
                if (!data.ok) throw new Error(data.mensaje || 'Error al cargar el perfil');

                if (data.data) {
                    const { persona = {}, paciente = {}, tutor = null } = data.data;

                    setForm({
                        nombre: persona.nombre || '',
                        apellido1: persona.apellido1 || '',
                        apellido2: persona.apellido2 || '',
                        email: persona.email || '',
                        telefono: persona.telefono || '',
                        fecha_nacimiento: persona.fecha_nacimiento || '',
                        nif: persona.nif || '',
                        tipo_via: persona.tipo_via || '',
                        nombre_calle: persona.nombre_calle || '',
                        numero: persona.numero || '',
                        escalera: persona.escalera || '',
                        piso: persona.piso || '',
                        puerta: persona.puerta || '',
                        codigo_postal: persona.codigo_postal || '',
                        ciudad: persona.ciudad || '',
                        provincia: persona.provincia || '',
                        pais: persona.pais || 'España'
                    });


                    setPacData({
                        tipo_paciente: paciente.tipo_paciente || 'ADULTO',
                        observaciones_generales: paciente.observaciones_generales || ''
                    });

                    if (tutor) {
                        setTutorData({
                            nombre: tutor.nombre || '',
                            apellido1: tutor.apellido1 || '',
                            apellido2: tutor.apellido2 || '',
                            fecha_nacimiento: tutor.fecha_nacimiento || '',
                            nif: tutor.nif || '',
                            email: tutor.email || '',
                            telefono: tutor.telefono || '',
                            metodo_contacto_preferido: Array.isArray(tutor.metodo_contacto_preferido)
                                ? tutor.metodo_contacto_preferido
                                : tutor.metodo_contacto_preferido ? [tutor.metodo_contacto_preferido] : []
                        });
                    }
                }

                if (data.token) localStorage.setItem('token', data.token);
            } catch (error) {
                console.error('Error al cargar perfil:', error.message);
                setToast({
                    show: true,
                    ok: false,
                    titulo: 'Error',
                    msg: 'No se pudo cargar el perfil: ' + (error.message || 'Error desconocido')
                });
            }

            try {

                const { data } = await axios.get('/consentimiento');
                if (data.ok) {
                    setConsent(data.consentimiento && !data.revocado);
                }
            } catch (error) {
                console.error('Error al cargar consentimiento:', error);
            }

            // Cargar tareas para casa
            try {
                setLoadingTareas(true);
                const { data } = await axios.get('/pac/tareas');
                if (data.ok && data.tareas) {
                    setTareas(data.tareas);
                }
            } catch (error) {
                console.error('Error al cargar tareas:', error);
            } finally {
                setLoadingTareas(false);
            }
        }
        cargar();
    }, [hoy]);


    useEffect(() => {
        const section = searchParams.get('section');
        if (section) {
            setTimeout(() => {
                const element = document.getElementById(`section-${section}`);
                if (element) {
                    element.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }, 300);
        }
    }, [searchParams]);

    // Ocultar toast
    useEffect(() => {
        if (!toast.show) return;
        const id = setTimeout(() => setToast(t => ({ ...t, show: false })), 5000);
        return () => clearTimeout(id);
    }, [toast.show]);

    // Validación antes de enviar
    const validar = () => {
        const errs = {};
        ['nombre', 'apellido1', 'email', 'fecha_nacimiento'].forEach(k => {
            if (!form[k]?.toString().trim()) errs[k] = true;
        });

        // Si tiene tutor validar campos del tutor
        if (pacData.tipo_paciente !== 'ADULTO') {
            ['nombre', 'apellido1', 'email', 'telefono'].forEach(k => {
                if (!tutorData[k]?.toString().trim()) errs[`tutor_${k}`] = true;
            });
        }

        setErrors(errs);
        return Object.keys(errs).length === 0;
    };

    const handleChange = e => {
        const { name, value } = e.target;
        setForm(f => ({ ...f, [name]: value }));
    };

    const handlePacienteChange = e => {
        const { name, value } = e.target;
        setPacData(p => ({ ...p, [name]: value }));
    };

    const handleTutorChange = e => {
        const { name, value } = e.target;
        setTutorData(t => ({ ...t, [name]: value }));
    };

    const handleTutorContactoChange = (metodo) => {
        setTutorData(t => {
            const metodosActuales = t.metodo_contacto_preferido || [];
            if (metodosActuales.includes(metodo)) {
                return { ...t, metodo_contacto_preferido: metodosActuales.filter(m => m !== metodo) };
            } else {
                return { ...t, metodo_contacto_preferido: [...metodosActuales, metodo] };
            }
        });
    };

    // Cancelar edición
    const handleCancel = () => {
        setEditMode(false);
        setErrors({});
        axios.get('/pac/perfil')
            .then(({ data }) => {
                if (data.ok && data.data) {
                    const { persona = {}, paciente = {}, tutor = null } = data.data;

                    setForm({
                        nombre: persona.nombre || '',
                        apellido1: persona.apellido1 || '',
                        apellido2: persona.apellido2 || '',
                        email: persona.email || '',
                        telefono: persona.telefono || '',
                        fecha_nacimiento: persona.fecha_nacimiento || '',
                        nif: persona.nif || '',
                        tipo_via: persona.tipo_via || '',
                        nombre_calle: persona.nombre_calle || '',
                        numero: persona.numero || '',
                        escalera: persona.escalera || '',
                        piso: persona.piso || '',
                        puerta: persona.puerta || '',
                        codigo_postal: persona.codigo_postal || '',
                        ciudad: persona.ciudad || '',
                        provincia: persona.provincia || '',
                        pais: persona.pais || 'España'
                    });

                    setPacData({
                        tipo_paciente: paciente.tipo_paciente || 'ADULTO',
                        observaciones_generales: paciente.observaciones_generales || ''
                    });

                    if (tutor) {
                        setTutorData({
                            nombre: tutor.nombre || '',
                            apellido1: tutor.apellido1 || '',
                            apellido2: tutor.apellido2 || '',
                            fecha_nacimiento: tutor.fecha_nacimiento || '',
                            nif: tutor.nif || '',
                            email: tutor.email || '',
                            telefono: tutor.telefono || '',
                            metodo_contacto_preferido: Array.isArray(tutor.metodo_contacto_preferido)
                                ? tutor.metodo_contacto_preferido
                                : tutor.metodo_contacto_preferido ? [tutor.metodo_contacto_preferido] : []
                        });
                    }
                }
            })
            .catch((error) => {
                console.error('Error al recargar datos:', error);
            });
    };

    // Enviar cambios y consentimientos
    const handleSubmit = async e => {
        e.preventDefault();
        if (!validar()) return;

        try {
            const payload = {
                persona: form,
                paciente: {
                    tipo_paciente: pacData.tipo_paciente,
                    observaciones_generales: pacData.observaciones_generales
                }
            };

            if (pacData.tipo_paciente !== 'ADULTO') {
                payload.tutor = tutorData;
            }

            const { data } = await axios.put('/pac/perfil', payload);
            if (!data.ok) throw new Error(data.mensaje || 'Error al actualizar');

            if (data.token) localStorage.setItem('token', data.token);

            if (consent) {
                await axios.post('/consentimiento', { canal: 'WEB' });
            } else {
                await axios.post('/consentimiento/revocar', {});
            }

            setToast({
                show: true,
                ok: true,
                titulo: 'Éxito',
                msg: 'Perfil y consentimiento actualizados'
            });
            setEditMode(false);
        } catch (error) {
            console.error('Error al guardar:', error);
            setToast({
                show: true,
                ok: false,
                titulo: 'Error',
                msg: 'No se pudo guardar los cambios: ' + (error.message || 'Error desconocido')
            });
        }
    };

    // Funciones para el slider de tareas
    const nextSlide = () => {
        const maxSlides = Math.max(0, tareas.length - 3);
        setCurrentSlide((prev) => (prev + 1) % (maxSlides + 1));
    };

    const prevSlide = () => {
        const maxSlides = Math.max(0, tareas.length - 3);
        setCurrentSlide((prev) => (prev - 1 + (maxSlides + 1)) % (maxSlides + 1));
    };

    const verTarea = (tarea) => {
        console.log('Datos de la tarea:', tarea);
        setTareaSeleccionada(tarea);
        setMostrarModalTarea(true);
    };

    const cargarHistorial = async () => {
        setLoadingHistorial(true);
        try {
            const { data } = await axios.get('/pac/historial');
            if (data.ok) {
                setDocumentosHistorial(data.documentos);
                setMostrarModalHistorial(true);
            } else {
                setToast({
                    show: true,
                    ok: false,
                    titulo: 'Error',
                    msg: 'No se pudo cargar el historial'
                });
            }
        } catch (error) {
            console.error('Error al cargar historial:', error);
            setToast({
                show: true,
                ok: false,
                titulo: 'Error',
                msg: 'Error al cargar el historial: ' + (error.message || 'Error desconocido')
            });
        } finally {
            setLoadingHistorial(false);
        }
    };

    // Formatear fechas
    const formatDate = (dateString) => {
        if (!dateString) return 'Sin fecha';
        const date = new Date(dateString);
        return date.toLocaleDateString('es-ES', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric'
        });
    };


    const input = (key, label, type = 'text', full = false, disabled = false) => (
        <div className={`field${full ? ' full' : ''}`} key={key}>
            <label>{label}</label>
            <input
                type={type}
                name={key}
                value={form[key] ?? ''}
                onChange={editMode && !disabled ? handleChange : undefined}
                readOnly={!editMode || disabled}
                className={editMode && !disabled ? (errors[key] ? 'invalid' : '') : 'readonly-input'}
            />
            {editMode && errors[key] && <span className="error-msg">Obligatorio</span>}
        </div>
    );


    const pacienteInput = (key, label, type = 'text', full = false) => (
        <div className={`field${full ? ' full' : ''}`} key={key}>
            <label>{label}</label>
            <input
                type={type}
                name={key}
                value={pacData[key] ?? ''}
                onChange={editMode ? handlePacienteChange : undefined}
                readOnly={!editMode}
                className={editMode ? (errors[key] ? 'invalid' : '') : 'readonly-input'}
            />
            {editMode && errors[key] && <span className="error-msg">Obligatorio</span>}
        </div>
    );

    const tutorInput = (key, label, type = 'text', full = false) => (
        <div className={`field${full ? ' full' : ''}`} key={key}>
            <label>{label}</label>
            <input
                type={type}
                name={key}
                value={tutorData[key] ?? ''}
                onChange={editMode ? handleTutorChange : undefined}
                readOnly={!editMode}
                className={editMode ? (errors[`tutor_${key}`] ? 'invalid' : '') : 'readonly-input'}
            />
            {editMode && errors[`tutor_${key}`] && <span className="error-msg">Obligatorio</span>}
        </div>
    );

    return (
        <div className="usuarios-container perfil-paciente-container">
            <h1 className="usuarios-title">Mi Perfil</h1>

            {/* SECCIÓN MI PERFIL */}
            <div id="section-perfil" className="modal-body">
                <form onSubmit={handleSubmit}>
                    {/* Sección paciente */}
                    <h4>Datos de paciente</h4>
                    <div className="form-grid">
                        <div className="field">
                            <label>Tipo paciente</label>
                            <input
                                type="text"
                                value={pacData.tipo_paciente === 'ADULTO' ? 'Adulto' :
                                    pacData.tipo_paciente === 'ADOLESCENTE' ? 'Adolescente' :
                                        pacData.tipo_paciente === 'NIÑO' ? 'Niño' : 'Infante'}
                                readOnly
                                className="readonly-input"
                            />
                        </div>
                        {pacienteInput('observaciones_generales', 'Observaciones', 'text', true)}
                    </div>

                    {/* Datos del tutor*/}
                    {pacData.tipo_paciente !== 'ADULTO' && (
                        <>
                            <h4>Datos del tutor</h4>
                            <div className="form-grid">
                                {tutorInput('nombre', 'Nombre*')}
                                {tutorInput('apellido1', 'Primer apellido*')}
                                {tutorInput('apellido2', 'Segundo apellido')}
                                {tutorInput('fecha_nacimiento', 'Fecha nacimiento', 'date')}
                                {tutorInput('nif', 'DNI')}
                                {tutorInput('email', 'Email*', 'email')}
                                {tutorInput('telefono', 'Teléfono*')}

                                <div className="field full">
                                    <label>Métodos de contacto preferidos</label>
                                    <div className="perfil-actions-flex">
                                        <label>
                                            <input
                                                type="checkbox"
                                                name="tutor_metodo_contacto_tel"
                                                disabled={!editMode}
                                                checked={tutorData.metodo_contacto_preferido?.includes('TEL') || false}
                                                onChange={() => handleTutorContactoChange('TEL')}
                                            /> Teléfono
                                        </label>
                                        <label>
                                            <input
                                                type="checkbox"
                                                name="tutor_metodo_contacto_email"
                                                disabled={!editMode}
                                                checked={tutorData.metodo_contacto_preferido?.includes('EMAIL') || false}
                                                onChange={() => handleTutorContactoChange('EMAIL')}
                                            /> Email
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </>
                    )}

                    {/* Datos personales */}
                    <h4>Datos personales</h4>
                    <div className="form-grid">
                        {input('nombre', 'Nombre*')}
                        {input('apellido1', 'Primer apellido*')}
                        {input('apellido2', 'Segundo apellido')}
                        {input('fecha_nacimiento', 'Fecha nacimiento*', 'date')}
                        {input('nif', 'DNI', 'text', false, true)}
                    </div>

                    {/* Datos de contacto */}
                    <h4>Datos de contacto</h4>
                    <div className="form-grid">
                        {input('email', 'Email*', 'email')}
                        {input('telefono', 'Teléfono')}
                        {input('tipo_via', 'Tipo vía')}
                        {input('nombre_calle', 'Nombre calle', 'text', true)}
                        {input('numero', 'Número')}
                        {input('escalera', 'Escalera')}
                        {input('piso', 'Piso')}
                        {input('puerta', 'Puerta')}
                        {input('codigo_postal', 'Código postal')}
                        {input('ciudad', 'Ciudad')}
                        {input('provincia', 'Provincia')}
                        {input('pais', 'País')}
                    </div>
                    {/* Consentimiento */}
                    <h4>Consentimiento de datos</h4>
                    <div className="field checkbox-field">
                        <label>
                            <input
                                type="checkbox"
                                checked={consent}
                                onChange={e => setConsent(e.target.checked)}
                                disabled={!editMode}
                            />{' '}Acepto el uso y tratamiento de mis datos personales según la{' '}
                            <a
                                href="/politica-privacidad"
                                target="_blank"
                                rel="noopener noreferrer"
                                style={{ color: 'var(--blue)', textDecoration: 'underline' }}
                            > Política de Privacidad
                            </a>
                        </label>
                    </div>

                    {/* Botones */}
                    <div className="modal-footer">
                        {editMode ? (
                            <>
                                <button type="button" className="btn-cancel" onClick={handleCancel}>
                                    Cancelar
                                </button>
                                <button type="submit" className="btn-save">Guardar</button>
                            </>
                        ) : (
                            <button type="button" className="btn-save" onClick={() => setEditMode(true)}>
                                Editar
                            </button>
                        )}
                    </div>
                </form>
            </div>

            {/* SECCIÓN TAREAS PARA CASA */}
            <div id="section-tareas" className="modal-body modal-tareas-body">
                <h2 className="modal-tareas-titulo">
                    Tareas para Casa
                </h2>

                {loadingTareas ? (
                    <div className="tareas-sin-asignar">
                        <p>Cargando tareas...</p>
                    </div>
                ) : tareas.length === 0 ? (
                    <div className="tareas-sin-asignar-fondo">
                        <p className="tareas-sin-asignar-texto">No tienes tareas para casa asignadas en este momento</p>
                    </div>) : (
                    <div className="tareas-container">
                        {/* Slider Container */}
                        <div className="tareas-slider-container">                            <div
                                className="tareas-slider"
                                style={{ transform: `translateX(-${currentSlide * 33.333}%)` }}
                            >                                {tareas.map((tarea, index) => (
                                    <div key={index} className="tarea-slide-item">
                                        <h5 className="tarea-slide-titulo">
                                            {tarea.titulo || 'Tarea sin título'}
                                        </h5>
                                        <p className="tarea-slide-descripcion">
                                            {tarea.descripcion && tarea.descripcion.length > 100
                                                ? `${tarea.descripcion.substring(0, 100)}...`
                                                : tarea.descripcion || 'Sin descripción disponible'
                                            }
                                        </p>
                                        <button 
                                            className="btn-ver-tarea"
                                            onClick={() => verTarea(tarea)}
                                        >
                                            Ver tarea
                                        </button>
                                    </div>
                                ))}
                            </div>
                        </div>                        {/* Controles del Slider */}
                        {tareas.length > 3 && (
                            <div className="tareas-slider-controls">
                                <button
                                    onClick={prevSlide}
                                    className="slider-btn"
                                    disabled={currentSlide === 0}
                                    aria-label="Tareas anteriores"
                                >
                                    <ChevronLeft size={20} />
                                </button>
                                
                                <div className="slider-dots">
                                    {Array.from({ length: Math.ceil(tareas.length / 3) }).map((_, index) => (
                                        <div
                                            key={index}
                                            className={`slider-dot ${currentSlide === index ? 'active' : ''}`}
                                            onClick={() => setCurrentSlide(index)}
                                        />
                                    ))}
                                </div>
                                
                                <button
                                    onClick={nextSlide}
                                    className="slider-btn"
                                    disabled={currentSlide >= Math.ceil(tareas.length / 3) - 1}
                                    aria-label="Siguientes tareas"
                                >
                                    <ChevronRight size={20} />
                                </button>
                            </div>
                        )}
                    </div>
                )}

                {/* Modal para ver tarea */}
                {mostrarModalTarea && tareaSeleccionada && (
                    <ModalVerTarea
                        tarea={tareaSeleccionada}
                        onClose={() => {
                            setMostrarModalTarea(false);
                            setTareaSeleccionada(null);
                        }}
                    />
                )}
            </div>

            {/* SECCIÓN HISTORIAL CLÍNICO */}
            <div id="section-historial" className='modal-body modal-historial-body'>
                <h2 className="modal-historial-titulo">Historial clínico</h2>
                <button
                    type="button"
                    className="btn-save btn-margin-right"
                    onClick={cargarHistorial}
                    disabled={loadingHistorial}
                >
                    {loadingHistorial ? 'Cargando...' : 'Descargar historial'}
                </button>

                {/* Modal para ver historial */}
                {mostrarModalHistorial && (
                    <ModalVerHistorial
                        documentos={documentosHistorial}
                        onClose={() => {
                            setMostrarModalHistorial(false);
                            setDocumentosHistorial([]);
                        }}
                    />
                )}
            </div>

            {/* Toast */}
            {toast.show && (
                <div className="toast-global centered-toast">
                    <div className={`toast-card ${toast.ok ? 'success' : 'error'}`}>
                        {toast.ok
                            ? <CheckCircle size={48} className="toast-icon success" />
                            : <XCircle size={48} className="toast-icon error" />
                        }
                        <h3 className="toast-title">{toast.titulo}</h3>
                        <p className="toast-text">{toast.msg}</p>
                    </div>
                </div>
            )}
        </div>
    );
}