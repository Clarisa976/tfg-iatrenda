import React from 'react';
import '../../styles.css';

export default function TerminosCondiciones() {
  return (
    <div className="legal-page-container">
      <div className="legal-header">
        <h1>Términos y Condiciones</h1>
      </div>

      <div className="legal-content">
        <p>
          Al usar esta plataforma, aceptas estos términos. 
          Es para gestión de citas y expedientes clínicos.
        </p>
        
        <p>
          Los profesionales sanitarios son responsables de sus decisiones médicas. 
          Nosotros solo damos la herramienta.
        </p>
        
        <p>
          Cumplimos con la ley de protección de datos.
        </p>
        
        <p>
          Podemos modificar estos términos avisando con tiempo.
        </p>
        
        <p>
          Dudas: <strong>legal@iatrenda.com</strong>
        </p>
      </div>
    </div>
  );
}