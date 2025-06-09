import React, { useState, useEffect, useCallback } from 'react';
import { X } from 'lucide-react';
import axios from 'axios';
import '../../styles.css';

const isImage = (filePath) => {
  const imageExtensions = ['.jpg', '.jpeg', '.png', '.webp'];
  return imageExtensions.some(ext => filePath.toLowerCase().includes(ext));
};

export default function ModalDocumento({ doc, onClose, onChange }) {
  const API = process.env.REACT_APP_API_URL;
  const tk  = localStorage.getItem('token');

  const [showDeleteModal, setShowDeleteModal] = useState(false);
  const [isDeleting,       setIsDeleting]       = useState(false);
  const [error,            setError]            = useState('');
  const [signedUrl,        setSignedUrl]        = useState(null);
  const [urlError,         setUrlError]         = useState('');

  // Obtiene URL firmada para descarga/previsualización
  const fetchSignedUrl = useCallback(async () => {
    try {
      const res = await axios.get(
        `${API}/api/s3/documentos/${doc.id_documento}/url`,
        { headers: { Authorization: `Bearer ${tk}` } }
      );
      if (res.data.ok) {
        setSignedUrl(res.data.url);
        setUrlError('');
        return;
      }
      throw new Error(res.data.mensaje);
    } catch (e) {
      console.error('Error fetching signed URL:', e);
      setUrlError('No se pudo cargar el archivo');
    }
  }, [API, doc.id_documento, tk]);

  useEffect(() => {
    fetchSignedUrl();
  }, [fetchSignedUrl]);

  // Elimina el documento
  const deleteDocument = async () => {
    setIsDeleting(true);
    setError('');
    try {
      await axios.delete(
        `/api/s3/documentos/${doc.id_documento}`,
        { headers: { Authorization: `Bearer ${tk}` } }
      );
      onChange();
      onClose();
    } catch (e) {
      console.error('Error deleting document:', e);
      setError('No se pudo eliminar el documento.');
      setIsDeleting(false);
    }
  };

  const isDocImage = isImage(doc.ruta);

  return (
    <>
      <div className="modal-backdrop" onClick={onClose}>
        <div
          className="modal modal-documento-wide"
          onClick={e => e.stopPropagation()}
        >
          <div className="modal-header">
            <h3>{doc.nombre_archivo || 'Documento'}</h3>
            <button className="modal-close" onClick={onClose}><X /></button>
          </div>

          <div className="modal-body">
            <p>
              <strong>Subido:</strong>{' '}
              {new Date(doc.fecha_subida).toLocaleDateString()}
            </p>

            <div className="documento-preview">
              <h4>Archivo</h4>
              {signedUrl ? (
                isDocImage ? (
                  <img
                    src={signedUrl}
                    alt={doc.nombre_archivo}
                    className="documento-imagen"
                  />
                ) : (
                  <a
                    href={signedUrl}
                    download={doc.nombre_archivo || 'documento'}
                    className="documento-descarga-archivo"
                    target="_blank"
                    rel="noreferrer"
                  >
                    Descargar fichero
                  </a>
                )
              ) : (
                <div className="documento-denied">
                  {urlError || 'Cargando…'}
                </div>
              )}
            </div>

            {error && (
              <div className="error-global">
                {error}
              </div>
            )}
          </div>

          <div className="modal-footer">
            <button
              className="btn-delete"
              onClick={() => setShowDeleteModal(true)}
            >
              Eliminar
            </button>
            <button className="btn-cancel" onClick={onClose}>
              Cerrar
            </button>
          </div>
        </div>
      </div>

      {showDeleteModal && (
        <div
          className="modal-backdrop modal-backdrop-confirmacion"
          onClick={() => setShowDeleteModal(false)}
        >
          <div
            className="modal modal-confirmacion-eliminar"
            onClick={e => e.stopPropagation()}
          >
            <div className="modal-header">
              <h3>Confirmar eliminación</h3>
            </div>
            <div className="modal-body">
              <p>¿Seguro que quieres eliminar este documento?</p>
              <p><strong>{doc.nombre_archivo}</strong></p>
              {error && (
                <div className="error-eliminacion-container">
                  {error}
                </div>
              )}
            </div>
            <div className="modal-footer">
              <button
                className="btn-cancel"
                onClick={() => setShowDeleteModal(false)}
                disabled={isDeleting}
              >
                Cancelar
              </button>
              <button
                className={`btn-delete ${isDeleting ? 'loading' : ''}`}
                onClick={deleteDocument}
                disabled={isDeleting}
              >
                {isDeleting ? 'Eliminando...' : 'Eliminar'}
              </button>
            </div>
          </div>
        </div>
      )}
    </>
  );
}
