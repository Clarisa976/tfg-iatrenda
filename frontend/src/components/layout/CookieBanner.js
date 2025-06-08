import React, { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import '../../styles.css';

export default function CookieConsent() {
  const [visible, setVisible] = useState(false);

  useEffect(() => {
    if (!localStorage.getItem('cookie-accepted')) {
      setVisible(true);
    }
  }, []);

  const accept = () => {
    localStorage.setItem('cookie-accepted', 'true');
    setVisible(false);
  };
  const reject = () => setVisible(false);

  if (!visible) return null;

  return (
    <div className="cookie-banner">
      <div className="cookie-text">
        <p>Usamos cookies para mejorar tu experiencia.</p>
        <p className="cookie-leer">
          <Link to="/cookies">[Leer más]</Link>
        </p>
      </div>
      <div className="cookie-buttons">
        <button className="cookie-reject" onClick={reject}>
          Rechazar
        </button>
        <button className="cookie-accept" onClick={accept}>
          Aceptar
        </button>
      </div>
    </div>
  );
}
