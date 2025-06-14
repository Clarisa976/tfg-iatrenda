import React from 'react';
import CookieBanner from '../components/layout/CookieBanner';
import Hero from '../components/secciones/Hero';
import QuienesSomos from '../components/secciones/QuienesSomos';
import Resenas from '../components/secciones/Resenas';
import Servicios from '../components/secciones/Servicios';

export default function Inicio({ onReservarCita, user }) {
  return (
    <>
      <CookieBanner />
      <Hero onReservarCita={onReservarCita} user={user} />
      <QuienesSomos />
      <Resenas />
      <Servicios onReservarCita={onReservarCita} user={user} />
    </>
  );
}
