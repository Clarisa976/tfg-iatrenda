import React from 'react';
import '../../styles.css';

export default function PoliticaPrivacidad() {
  return (
    <div className="legal-page-container">
      <div className="legal-header">
        <h1>Política de Privacidad</h1>
      </div>

      <div className="legal-content">
        <p>
          <strong>Responsable:</strong> Iatrenda S.L. - privacidad@iatrenda.com
        </p>
        
        <p>
          <strong>Datos que recogemos:</strong> Nombre, email, teléfono, dirección y datos clínicos.
        </p>
        
        <p>
          <strong>Para qué:</strong> Gestionar citas, historiales clínicos y cumplir la ley.
        </p>
        
        <p>
          <strong>Cuánto tiempo:</strong> Historiales clínicos mínimo 5 años, resto según la ley.
        </p>
        
        <p>
          <strong>Tus derechos:</strong> Puedes acceder, corregir o eliminar tus datos.
        </p>
        
        <p>
          <strong>Seguridad:</strong> Ciframos todo y hacemos copias de seguridad.
        </p>
        
        <p>
          Ejercer derechos: <strong>derechos@iatrenda.com</strong>
        </p>
        
        <p>
          Reclamaciones: <strong>www.aepd.es</strong>
        </p>
      </div>
    </div>
  );
}