import React from 'react';
import Header from './components/Header';
import Hero from './components/Hero';
import QuienesSomos from './components/QuienesSomos';
import Servicios from './components/Servicios';
import Resenas from './components/Resenas';
import Footer from './components/Footer';
import ScrollArriba from './components/ScrollArriba';
import CookieBanner from './components/CookieBanner.js';

import './styles.css';

function App() {
  return (
    <>
      <Header />
      <Hero />
      <QuienesSomos />
      <Servicios />
      <Resenas />

      <ScrollArriba />
      <Footer />
      <CookieBanner />
    </>
  );
}

export default App;
