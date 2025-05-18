import React from 'react';
import '../styles.css';

const Hero = () => (
  <section className="hero">
    <img
      src="https://iatrenda-petaka.s3.eu-west-3.amazonaws.com/images/psicologa-ayudando-una-nina-en-la-terapia-del-habla.webp"
      alt="Psicóloga con niña en terapia"
      className="hero__img"
    />
    <div className="hero__overlay" />
    <div className="hero__content">
      <h1 className="hero__title hero__title--green">Petaka</h1>
      <h2 className="hero__title hero__title--blue">Clínica logopédica</h2>
      <p className="hero__subtitle">Mejoramos tu comunicación</p>
      <a href="/reserva" className="btn-reserva">Reserve su cita</a>
    </div>
  </section>
);

export default Hero;
