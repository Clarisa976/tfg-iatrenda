import React from 'react';

const services = [
  {
    title: 'Atención Temprana (0 a 6 años):',
    desc: 'Niños con trastorno o riesgo a padecerlo.'
  },
  {
    title: 'Trastornos del lenguaje:',
    desc: 'Retraso del lenguaje, trastorno específico del lenguaje (TEL), afasia, anomia.'
  },
  {
    title: 'Trastornos de la lectura y la escritura:',
    desc: 'Dislexia, disgrafía, disortografía, discalculia, retraso lector.'
  },
  {
    title: 'Trastornos del habla:',
    desc: 'Trastorno de los sonidos del habla o dislalia, disfemia, disglosia.'
  },
  {
    title: 'Trastornos de la articulación:',
    desc: 'Disartria, anartria.'
  },
  {
    title: 'Alteraciones de la voz:',
    desc: 'Parálisis de las cuerdas vocales, disfonías, afonías, nódulos, pólipos, quistes, edema de Reinke, cáncer de laringe, etc.'
  },
  {
    title: 'Trastornos orofaciales:',
    desc: 'Disfagia, deglución atípica, respiración bucal, alteraciones craneofaciales, fisuras labiopalatinas, apraxia, dispraxia, parálisis facial.'
  },
  {
    title: 'Trastornos de la audición:',
    desc: 'Hipoacusia, sordera, implante coclear.'
  }
];

export default function Servicios({ onReservarCita, user }) {
  const userRole = user?.rol || user?.role || null;
  const isLoggedIn = !!userRole;

  return (
    <section id="servicios" className="services">
      <h2 className="services__header">Nuestras especialidades</h2>
      <div className="services__list">
        {services.map((svc, idx) => (
          <div key={idx} className="service-item">
            <span className="service-icon"></span>
            <div className="service-text">
              <h3 className="service-text__title">{svc.title}</h3>
              <p className="service-text__desc">{svc.desc}</p>
            </div>
          </div>
        ))}      </div>
      <div className="services__cta">
        {/* Solo mostrar si NO está logueado */}
        {!isLoggedIn && (
          <a href="#reserva" className="btn-reserva" onClick={e => { e.preventDefault(); onReservarCita(); }}>Reserve su cita</a>
        )}
      </div>
    </section>
  );
}
