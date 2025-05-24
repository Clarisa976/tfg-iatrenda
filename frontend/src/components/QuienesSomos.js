import React from 'react';
import { Heart, CheckCircle, ChartBar } from 'lucide-react';
    const quienesUrl = process.env.REACT_APP_QUIENES_IMG;
export default function QuienesSomos() {

  return (
    <section id="quienes-somos" className="about">
      <h2 className="about__title">Sobre nosotros</h2>
      <p className="about__text">
        En Clínica Petaka ayudamos a niños y adultos a mejorar su voz y lenguaje con terapias personalizadas y cercanas.
      </p>
  <div
    className="about__image-wrapper"
    alt="Letas del abecedario"
    style={{ backgroundImage: `url(${quienesUrl})` }}
  ></div>

      <ul className="about__features">
        <li>
          <Heart className="about__icon" color="#27AE60" />
          <div>
            <h3 className="about__feature-title">Empatía</h3>
            <p className="about__feature-sub">Nos ponemos en tu lugar</p>
          </div>
        </li>
        <li>
          <CheckCircle className="about__icon" color="#27AE60" />
          <div>
            <h3 className="about__feature-title">Profesionalidad</h3>
            <p className="about__feature-sub">Formación continua</p>
          </div>
        </li>
        <li>
          <ChartBar className="about__icon" color="#27AE60" />
          <div>
            <h3 className="about__feature-title">Resultados</h3>
            <p className="about__feature-sub">Seguimiento medible</p>
          </div>
        </li>
      </ul>
    </section>
  );
}
