import React from 'react';
import '../../styles.css';
const heroImg = process.env.REACT_APP_HERO_IMG;
const Hero = ({ onReservarCita }) => (
  <section className="hero">
    <img
      src={heroImg}
      alt="Psicóloga con niña en terapia"
      className="hero__img"
    />
    <div className="hero__overlay" />
    <div className="hero__content">
      <h1 className="hero__title hero__title--green">Petaka</h1>
      <h2 className="hero__title hero__title--blue">Clínica logopédica</h2>
      <p className="hero__subtitle">Mejoramos tu comunicación</p>
      <a href="/reserva" className="btn-reserva"  onClick={e => { e.preventDefault(); onReservarCita(); }}>Reserve su cita</a>
    </div>
  </section>
);

export default Hero;
