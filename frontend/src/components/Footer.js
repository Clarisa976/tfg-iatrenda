import React from 'react';
import { MapPin, Phone, Mail, Instagram, Facebook, Linkedin } from 'lucide-react';

export default function Footer() {
  const logo2 = process.env.REACT_APP_LOGO_2_IMG;

  return (
    <footer className="footer">
      <div className="footer__logo">
         <a href="/">
        <img src={logo2} alt="Clínica Petaka" />
        </a>
      </div>
      <div className="footer__grid">
        {/* CONTÁCTANOS */}
        <div className="footer__section">
          <h3>CONTÁCTANOS</h3>
          <div className="footer__item">
            <MapPin className="footer__icon" />
            <p>Av. Ejemplo 123, Estepona 29680</p>
          </div>
          <div className="footer__item">
            <Phone className="footer__icon" />
            <p>+34 123 456 789</p>
          </div>
          <div className="footer__item">
            <Mail className="footer__icon" />
            <p>info@clinicapetaka.es</p>
          </div>
        </div>

        {/* SÍGUENOS EN */}
        <div className="footer__section">
          <h3>SÍGUENOS EN</h3>
          <div className="footer__socials">
            <a href="#" aria-label="Instagram"><Instagram /></a>
            <a href="#" aria-label="Facebook"><Facebook /></a>
            <a href="#" aria-label="LinkedIn"><Linkedin /></a>
          </div>
        </div>

        {/* ENLACES GENERALES */}
        <div className="footer__section">
          <h3>ENLACES GENERALES</h3>
          <ul className="footer__links">
            <li><a href="/terminos">Términos y condiciones de uso</a></li>
            <li><a href="/privacidad">Política de privacidad</a></li>
            <li><a href="/cookies">Cookies</a></li>
          </ul>
        </div>
      </div>

      <div className="footer__bottom">
        <span>© Clínica Petaka</span>
      </div>
    </footer>
);
}
