import React, { useState } from 'react';
import Header from './components/Header';
import Hero from './components/Hero';
import QuienesSomos from './components/QuienesSomos';
import Servicios from './components/Servicios';
import Resenas from './components/Resenas';
import Footer from './components/Footer';
import ScrollArriba from './components/ScrollArriba';
import LoginModal from './components/LoginModal';

import './styles.css';
import 'react-datepicker/dist/react-datepicker.css';


export default function App() {
  const [user, setUser] = useState(null);           // null = no hay sesión iniciada
  const [loginOpen, setLoginOpen] = useState(false);

  // Se ejecuta tras login exitoso
  const handleLoginSuccess = (userData) => {
    setUser(userData);
    setLoginOpen(false);
  };

  return (
    <>
      <Header
        user={user}
        onAccessClick={() => setLoginOpen(true)} />

      <Hero />
      <QuienesSomos />
      <Servicios />
      <Resenas />
      <ScrollArriba />
      <Footer />

      {/* Modal de login */}
      {loginOpen && (
        <LoginModal
          onClose={() => setLoginOpen(false)}
          onLoginSuccess={handleLoginSuccess}
        />
      )}
    </>
  );
}


