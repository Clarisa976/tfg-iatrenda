import React from 'react';
import Header from './components/Header';
import Hero from './components/Hero';
import QuienesSomos from './components/QuienesSomos';
import Servicios from './components/Servicios';
import './styles.css';

function App() {
  return (
    <>
      <Header />
      <Hero />
      <QuienesSomos />
      <Servicios />
    </>
  );
}

export default App;
