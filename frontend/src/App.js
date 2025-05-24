import React, { useState } from 'react';
import Header       from './components/Header';
import Hero         from './components/Hero';
import QuienesSomos from './components/QuienesSomos';
import Servicios    from './components/Servicios';
import Resenas      from './components/Resenas';
import Footer       from './components/Footer';
import ScrollArriba from './components/ScrollArriba';
import LoginModal   from './components/LoginModal';
import ReservarCitaModal from './components/ReservarCitaModal';

import './styles.css';

function App() {
  const [user, setUser]           = useState(null);
  const [loginOpen,   setLoginOpen]   = useState(false);
  const [reservarCita, setReservarCita] = useState(false);

  // Login exitoso
  const handleLoginSuccess = (userData) => {
    setUser(userData);
    setLoginOpen(false);
  };

  // Abre modal de reserva
  const handleReservarOpen = () => {
    console.log('📅 abrir ReservarCitaModal'); // para debug
    setReservarCita(true);
  };

  return (
    <>
      <Header 
        user={user} 
        onAccessClick={() => setLoginOpen(true)}
        onReservarCita={handleReservarOpen}  // nuevo prop
      />

      <Hero />
      <QuienesSomos />
      <Servicios />
      <Resenas />
      <ScrollArriba />
      <Footer />

      {/* LoginModal */}
      {loginOpen && (
        <LoginModal
          onClose={() => setLoginOpen(false)}
          onLoginSuccess={handleLoginSuccess}
        />
      )}

      {/* ReservarCitaModal */}
      {reservarCita && (
        <ReservarCitaModal
          onClose={() => setReservarCita(false)}
        />
      )}
    </>
  );
}

export default App;
