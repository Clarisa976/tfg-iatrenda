import { useState, useEffect, useCallback } from 'react';

export const useS3Documents = (documentos) => {
  const [documentUrls, setDocumentUrls] = useState({});
  const [loadingUrls, setLoadingUrls] = useState({});
  const [urlErrors, setUrlErrors] = useState({});

  // Función para obtener URL firmada de S3
  const getS3DocumentUrl = useCallback(async (documentoId) => {
    try {
      const tk = localStorage.getItem('token');
      const response = await fetch(`${process.env.REACT_APP_API_URL}/api/s3/download/${documentoId}`, {
        method: 'GET',
        headers: {
          'Authorization': `Bearer ${tk}`
        },
        redirect: 'manual'
      });

      if (response.status === 302) {
        const location = response.headers.get('Location');
        return location;
      } else {
        throw new Error('No se pudo obtener la URL del documento');
      }
    } catch (error) {
      console.error('Error obteniendo URL de S3:', error);
      return null;
    }
  }, []);

  // Cargar URLs cuando cambian los documentos
  useEffect(() => {
    if (!documentos || documentos.length === 0) return;

    const loadUrls = async () => {
      for (const documento of documentos) {
        const docId = documento.id_documento;

        // Si ya tenemos la URL o está cargando, continuar
        if (documentUrls[docId] || loadingUrls[docId]) continue;

        setLoadingUrls(prev => ({ ...prev, [docId]: true }));

        try {
          let url = null;

          // Si ya tiene URL temporal de S3, usarla
          if (documento.url_descarga && documento.url_temporal) {
            url = documento.url_descarga;
          } else {
            // Obtener nueva URL firmada
            url = await getS3DocumentUrl(docId);
          }

          if (url) {
            setDocumentUrls(prev => ({ ...prev, [docId]: url }));
          } else {
            setUrlErrors(prev => ({ ...prev, [docId]: true }));
          }
        } catch (error) {
          console.error('Error loading document URL:', error);
          setUrlErrors(prev => ({ ...prev, [docId]: true }));
        } finally {
          setLoadingUrls(prev => ({ ...prev, [docId]: false }));
        }
      }
    };

    loadUrls();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [documentos]); // Solo documentos, ignoramos el resto

  const getDocumentUrl = useCallback((documentoId) => {
    return documentUrls[documentoId] || null;
  }, [documentUrls]);

  const isDocumentLoading = useCallback((documentoId) => {
    return loadingUrls[documentoId] || false;
  }, [loadingUrls]);

  const hasDocumentError = useCallback((documentoId) => {
    return urlErrors[documentoId] || false;
  }, [urlErrors]);

  const downloadDocument = useCallback(async (documento) => {
    try {
      const tk = localStorage.getItem('token');

      // Abrir directamente el endpoint de descarga
      const downloadUrl = `${process.env.REACT_APP_API_URL}/api/s3/download/${documento.id_documento}`;

      // Crear un enlace temporal para la descarga
      const link = document.createElement('a');
      link.href = downloadUrl;
      link.target = '_blank';

      // Añadir headers de autorización si es posible
      try {
        const response = await fetch(downloadUrl, {
          method: 'GET',
          headers: {
            'Authorization': `Bearer ${tk}`
          }
        });

        if (response.redirected) {
          window.open(response.url, '_blank');
        } else {
          link.click();
        }
      } catch {
        // Fallback: usar el enlace directo
        link.click();
      }

    } catch (error) {
      console.error('Error al descargar documento:', error);
      alert('Error al descargar el documento');
    }
  }, []);

  return {
    getDocumentUrl,
    isDocumentLoading,
    hasDocumentError,
    downloadDocument
  };
};