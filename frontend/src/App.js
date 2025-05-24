import React, { useState, useEffect } from 'react';
import { CheckCircle, XCircle } from 'lucide-react';
import Header from './components/Header';
import Hero from './components/Hero';
import QuienesSomos from './components/QuienesSomos';
import Servicios from './components/Servicios';
import Resenas from './components/Resenas';
import ScrollArriba from './components/ScrollArriba';
import Footer from './components/Footer';
import LoginModal from './components/LoginModal';
import ReservarCitaModal from './components/ReservarCitaModal';

import './styles.css';

export default function App() {
  const [user, setUser] = useState(null);
  const [loginOpen, setLoginOpen] = useState(false);
  const [reservarCita, setReservarCita] = useState(false);

  // Estado global de toast
  const [toast, setToast] = useState({
    show: false,
    ok: true,
    msg: ''
  });

  // Oculta el toast tras 3s
  useEffect(() => {
    if (toast.show) {
      const id = setTimeout(() => {
        setToast(t => ({ ...t, show: false }));
      }, 8000);
      return () => clearTimeout(id);
    }
  }, [toast.show]);

  const handleLoginSuccess = userData => {
    setUser(userData);
    setLoginOpen(false);
  };

  const abrirCita = () => setReservarCita(true);

  // Callbacks para la modal de cita
  const onCitaSuccess = msg => {
    setReservarCita(false);
    setToast({ show: true, ok: true, msg });
  };
  const onCitaError = msg => {
    setReservarCita(false);
    setToast({ show: true, ok: false, msg });
  };

  return (
    <>
      <Header
        user={user}
        onAccessClick={() => setLoginOpen(true)}
        onReservarCita={abrirCita}
      />

      <Hero onReservarCita={abrirCita} />
      <QuienesSomos />
      <Servicios onReservarCita={abrirCita} />
      <Resenas />
      <ScrollArriba />
      <Footer />

      {/* Login Modal */}
      {loginOpen && (
        <LoginModal
          onClose={() => setLoginOpen(false)}
          onLoginSuccess={handleLoginSuccess}
        />
      )}

      {/* Reservar Cita Modal */}
      {reservarCita && (
        <ReservarCitaModal
          onClose={() => setReservarCita(false)}
          onSuccess={onCitaSuccess}
          onError={onCitaError}
        />
      )}

      {/* Toast Global */}
 {toast.show && (
       <div className="toast-global centered-toast">
         <div className={`toast-card ${toast.ok ? 'success' : 'error'}`}>
           {toast.ok
             ? <CheckCircle size={48} className="toast-icon success" />
             : <XCircle      size={48} className="toast-icon error"   />
           }
           <h3 className="toast-title">
             {toast.ok ? '¡Reserva enviada!' : '¡Lo sentimos!'}
           </h3>
           <p className="toast-text">
             {toast.ok
               ? 'Te avisaremos cuando el equipo confirme tu cita.'
               : 'El día o la hora que has seleccionado no están disponibles en estos momentos. Elige otra fecha.'
             }
           </p>
         </div>
       </div>
     )}
    </>
  );
}
