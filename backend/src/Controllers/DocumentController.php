<?php
namespace App\Controllers;

require_once __DIR__ . '/../Services/S3Service.php';
use App\Services\S3Service;
use PDO;

class DocumentController {
    private $s3Service;

    public function __construct() {
        $this->s3Service = new S3Service();
    }

    /**
     * Subir documento/tratamiento a S3
     * POST /api/s3/upload
     */
    public function uploadDocument($request, $response) {
        try {
            $uploadedFiles = $request->getUploadedFiles();
            $data = $request->getParsedBody();

            // Determinar si es tratamiento o documento de historial
            $tipo = $data['tipo'] ?? 'historial';

            if ($tipo === 'tratamiento') {
                return $this->handleTreatmentUpload($data, $uploadedFiles, $response);
            } else {
                return $this->handleDocumentUpload($data, $uploadedFiles, $response);
            }

        } catch (\Exception $e) {
            error_log('Error in uploadDocument: ' . $e->getMessage());
            return $this->jsonResponse($response, [
                'ok' => false,
                'mensaje' => 'Error interno del servidor'
            ], 500);
        }
    }

    /**
     * Manejar subida de tratamiento (con o sin archivo)
     */
    private function handleTreatmentUpload($data, $uploadedFiles, $response) {
        try {
            // Validar datos requeridos para tratamiento
            if (!isset($data['id_paciente']) || !isset($data['titulo']) || !isset($data['notas'])) {
                return $this->jsonResponse($response, [
                    'ok' => false,
                    'mensaje' => 'Datos del tratamiento incompletos (id_paciente, titulo, notas son requeridos)'
                ], 400);
            }

            // Obtener ID del profesional desde el token
            $val = verificarTokenUsuario();
            if ($val === false) {
                return $this->jsonResponse($response, [
                    'ok' => false,
                    'mensaje' => 'No autorizado'
                ], 401);
            }
            $profesionalId = $val['usuario']['id_persona'];

            // 1. Crear historial clínico si no existe
            $historialId = $this->getOrCreateHistorial($data['id_paciente']);
            if (!$historialId) {
                return $this->jsonResponse($response, [
                    'ok' => false,
                    'mensaje' => 'Error al crear/obtener historial clínico'
                ], 500);
            }

            // 2. Crear tratamiento en base de datos
            $tratamientoData = [
                'id_historial' => $historialId,
                'id_profesional' => $profesionalId,
                'fecha_inicio' => !empty($data['fecha_inicio']) ? $data['fecha_inicio'] : null,
                'fecha_fin' => !empty($data['fecha_fin']) ? $data['fecha_fin'] : null,
                'frecuencia_sesiones' => intval($data['frecuencia_sesiones'] ?? 1),
                'notas' => $data['notas'],
                'titulo' => $data['titulo']
            ];

            $tratamientoId = $this->saveTreatmentToDatabase($tratamientoData);
            if (!$tratamientoId) {
                return $this->jsonResponse($response, [
                    'ok' => false,
                    'mensaje' => 'Error al crear tratamiento'
                ], 500);
            }

            // 3. Si hay archivo, subirlo a S3
            $documentData = null;
            if (isset($uploadedFiles['file'])) {
                $uploadedFile = $uploadedFiles['file'];
                
                // Validar archivo
                if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
                    return $this->jsonResponse($response, [
                        'ok' => false,
                        'mensaje' => 'Error en la subida del archivo'
                    ], 400);
                }

                // Validar tipo de archivo
                $allowedTypes = [
                    'application/pdf',
                    'image/jpeg',
                    'image/jpg', 
                    'image/png',
                    'image/gif',
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'text/plain'
                ];
                
                $fileType = $uploadedFile->getClientMediaType();
                if (!in_array($fileType, $allowedTypes)) {
                    return $this->jsonResponse($response, [
                        'ok' => false,
                        'mensaje' => 'Tipo de archivo no permitido. Permitidos: PDF, JPG, PNG, GIF, DOC, DOCX, TXT'
                    ], 400);
                }

                // Validar tamaño (max 10MB)
                $maxSize = 10 * 1024 * 1024; // 10MB
                if ($uploadedFile->getSize() > $maxSize) {
                    return $this->jsonResponse($response, [
                        'ok' => false,
                        'mensaje' => 'El archivo es demasiado grande. Máximo 10MB'
                    ], 400);
                }

                // Subir a S3
                $fileContent = $uploadedFile->getStream()->getContents();
                $fileName = $uploadedFile->getClientFilename();
                
                $uploadResult = $this->s3Service->uploadFile(
                    $fileContent,
                    $fileName,
                    $fileType,
                    [
                        'profesional-id' => $profesionalId,
                        'tratamiento-id' => $tratamientoId,
                        'tipo-documento' => 'tratamiento'
                    ]
                );

                if (!$uploadResult['success']) {
                    return $this->jsonResponse($response, [
                        'ok' => false,
                        'mensaje' => $uploadResult['error']
                    ], 500);
                }

                // Guardar documento en base de datos
                $documentData = [
                    'id_tratamiento' => $tratamientoId,
                    'id_profesional' => $profesionalId,
                    'ruta' => $uploadResult['key'],
                    'tipo' => $fileType,
                    'nombre_original' => $fileName,
                    'tamano' => $uploadedFile->getSize()
                ];

                $documentId = $this->saveDocumentToDatabase($documentData);
            }

            return $this->jsonResponse($response, [
                'ok' => true,
                'mensaje' => 'Tratamiento creado correctamente',
                'data' => [
                    'id_tratamiento' => $tratamientoId,
                    'id_historial' => $historialId,
                    'documento' => $documentData ? [
                        'id' => $documentId ?? null,
                        's3_key' => $documentData['ruta'],
                        'nombre' => $documentData['nombre_original']
                    ] : null
                ]
            ], 201);

        } catch (\Exception $e) {
            error_log('Error creating treatment: ' . $e->getMessage());
            return $this->jsonResponse($response, [
                'ok' => false,
                'mensaje' => 'Error al crear tratamiento: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Manejar subida de documento al historial
     */
    private function handleDocumentUpload($data, $uploadedFiles, $response) {
        try {
            // Validar que se subió un archivo
            if (!isset($uploadedFiles['file'])) {
                return $this->jsonResponse($response, [
                    'ok' => false,
                    'mensaje' => 'No se ha subido ningún archivo'
                ], 400);
            }

            $uploadedFile = $uploadedFiles['file'];
            
            // Validar errores de upload
            if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
                return $this->jsonResponse($response, [
                    'ok' => false,
                    'mensaje' => 'Error en la subida del archivo'
                ], 400);
            }

            // Validar datos requeridos
            if (!isset($data['id_paciente'])) {
                return $this->jsonResponse($response, [
                    'ok' => false,
                    'mensaje' => 'ID del paciente es requerido'
                ], 400);
            }

            // Obtener ID del profesional desde el token
            $val = verificarTokenUsuario();
            if ($val === false) {
                return $this->jsonResponse($response, [
                    'ok' => false,
                    'mensaje' => 'No autorizado'
                ], 401);
            }
            $profesionalId = $val['usuario']['id_persona'];

            // Validar tipo de archivo
            $allowedTypes = [
                'application/pdf',
                'image/jpeg',
                'image/jpg', 
                'image/png',
                'image/gif',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'text/plain'
            ];
            
            $fileType = $uploadedFile->getClientMediaType();
            if (!in_array($fileType, $allowedTypes)) {
                return $this->jsonResponse($response, [
                    'ok' => false,
                    'mensaje' => 'Tipo de archivo no permitido. Permitidos: PDF, JPG, PNG, GIF, DOC, DOCX, TXT'
                ], 400);
            }

            // Validar tamaño (max 10MB)
            $maxSize = 10 * 1024 * 1024; // 10MB
            if ($uploadedFile->getSize() > $maxSize) {
                return $this->jsonResponse($response, [
                    'ok' => false,
                    'mensaje' => 'El archivo es demasiado grande. Máximo 10MB'
                ], 400);
            }

            // 1. Crear historial clínico si no existe
            $historialId = $this->getOrCreateHistorial($data['id_paciente']);
            if (!$historialId) {
                return $this->jsonResponse($response, [
                    'ok' => false,
                    'mensaje' => 'Error al crear/obtener historial clínico'
                ], 500);
            }

            // 2. Subir a S3
            $fileContent = $uploadedFile->getStream()->getContents();
            $fileName = $uploadedFile->getClientFilename();
            
            $uploadResult = $this->s3Service->uploadFile(
                $fileContent,
                $fileName,
                $fileType,
                [
                    'profesional-id' => $profesionalId,
                    'historial-id' => $historialId,
                    'tipo-documento' => 'historial'
                ]
            );

            if (!$uploadResult['success']) {
                return $this->jsonResponse($response, [
                    'ok' => false,
                    'mensaje' => $uploadResult['error']
                ], 500);
            }

            // 3. Actualizar historial con diagnósticos si se proporcionaron
            if (!empty($data['diagnostico_preliminar']) || !empty($data['diagnostico_final'])) {
                $this->updateHistorialDiagnosticos($historialId, $data);
            }

            // 4. Guardar documento en base de datos
            $documentData = [
                'id_historial' => $historialId,
                'id_profesional' => $profesionalId,
                'ruta' => $uploadResult['key'],
                'tipo' => $fileType,
                'nombre_original' => $fileName,
                'tamano' => $uploadedFile->getSize()
            ];

            $documentId = $this->saveDocumentToDatabase($documentData);

            return $this->jsonResponse($response, [
                'ok' => true,
                'mensaje' => 'Documento subido correctamente',
                'data' => [
                    'id' => $documentId,
                    'id_historial' => $historialId,
                    's3_key' => $uploadResult['key'],
                    'nombre' => $fileName,
                    'tipo' => $fileType,
                    'tamano' => $uploadedFile->getSize()
                ]
            ], 201);

        } catch (\Exception $e) {
            error_log('Error uploading document to S3: ' . $e->getMessage());
            return $this->jsonResponse($response, [
                'ok' => false,
                'mensaje' => 'Error interno del servidor'
            ], 500);
        }
    }

    /**
     * Obtener o crear historial clínico para un paciente
     */
    private function getOrCreateHistorial($pacienteId) {
        try {
            $baseDatos = conectar();
            
            // Buscar historial existente
            $sql = "SELECT id_historial FROM historial_clinico WHERE id_paciente = ? ORDER BY fecha_inicio DESC LIMIT 1";
            $stmt = $baseDatos->prepare($sql);
            $stmt->execute([$pacienteId]);
            $historial = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($historial) {
                return $historial['id_historial'];
            }
            
            // Crear nuevo historial
            $sql = "INSERT INTO historial_clinico (id_paciente, fecha_inicio) VALUES (?, CURRENT_DATE) RETURNING id_historial";
            $stmt = $baseDatos->prepare($sql);
            $stmt->execute([$pacienteId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result['id_historial'];
            
        } catch (\Exception $e) {
            error_log('Error getting/creating historial: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Guardar tratamiento en base de datos
     */
    private function saveTreatmentToDatabase($data) {
        try {
            $baseDatos = conectar();
            
            $sql = "INSERT INTO tratamiento 
                    (id_historial, id_profesional, fecha_inicio, fecha_fin, frecuencia_sesiones, notas) 
                    VALUES (?, ?, ?, ?, ?, ?) RETURNING id_tratamiento";
            
            $stmt = $baseDatos->prepare($sql);
            $stmt->execute([
                $data['id_historial'],
                $data['id_profesional'],
                $data['fecha_inicio'],
                $data['fecha_fin'],
                $data['frecuencia_sesiones'],
                $data['notas']
            ]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)$result['id_tratamiento'];
            
        } catch (\Exception $e) {
            error_log('Error saving treatment to DB: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Actualizar diagnósticos en historial
     */
    private function updateHistorialDiagnosticos($historialId, $data) {
        try {
            $baseDatos = conectar();
            
            $updates = [];
            $params = [];
            
            if (!empty($data['diagnostico_preliminar'])) {
                $updates[] = "diagnostico_preliminar = ?";
                $params[] = $data['diagnostico_preliminar'];
            }
            
            if (!empty($data['diagnostico_final'])) {
                $updates[] = "diagnostico_final = ?";
                $params[] = $data['diagnostico_final'];
            }
            
            if (!empty($updates)) {
                $params[] = $historialId;
                $sql = "UPDATE historial_clinico SET " . implode(', ', $updates) . " WHERE id_historial = ?";
                $stmt = $baseDatos->prepare($sql);
                $stmt->execute($params);
            }
            
        } catch (\Exception $e) {
            error_log('Error updating historial diagnosticos: ' . $e->getMessage());
        }
    }

    /**
     * Descargar documento desde S3
     * GET /api/s3/download/{id}
     */
    public function downloadDocument($request, $response, $args) {
        try {
            $documentId = $args['id'];
            
            // Obtener documento de la base de datos
            $document = $this->getDocumentFromDatabase($documentId);
            
            if (!$document) {
                return $this->jsonResponse($response, [
                    'ok' => false,
                    'mensaje' => 'Documento no encontrado'
                ], 404);
            }

            // Verificar permisos (básico)
            $val = verificarTokenUsuario();
            if ($val === false) {
                return $this->jsonResponse($response, [
                    'ok' => false,
                    'mensaje' => 'No autorizado'
                ], 401);
            }

            // Generar URL firmada para descarga
            $urlResult = $this->s3Service->getPresignedUrl(
                $document['ruta'],
                '+30 minutes',
                $document['nombre_original'] ?? 'documento'
            );

            if (!$urlResult['success']) {
                return $this->jsonResponse($response, [
                    'ok' => false,
                    'mensaje' => $urlResult['error']
                ], 500);
            }

            // Retornar redirección directa
            return $response->withHeader('Location', $urlResult['url'])->withStatus(302);

        } catch (\Exception $e) {
            error_log('Error downloading document from S3: ' . $e->getMessage());
            return $this->jsonResponse($response, [
                'ok' => false,
                'mensaje' => 'Error interno del servidor'
            ], 500);
        }
    }

    /**
     * Listar documentos
     * GET /api/s3/documentos
     */
    public function listDocuments($request, $response) {
        try {
            $params = $request->getQueryParams();
            $historialId = $params['historial_id'] ?? null;
            $tratamientoId = $params['tratamiento_id'] ?? null;
            $pacienteId = $params['paciente_id'] ?? null;

            if (!$historialId && !$tratamientoId && !$pacienteId) {
                return $this->jsonResponse($response, [
                    'ok' => false,
                    'mensaje' => 'Se requiere historial_id, tratamiento_id o paciente_id'
                ], 400);
            }

            $documents = $this->getDocumentsFromDatabase($historialId, $tratamientoId, $pacienteId);

            return $this->jsonResponse($response, [
                'ok' => true,
                'documentos' => $documents,
                'total' => count($documents)
            ]);

        } catch (\Exception $e) {
            error_log('Error listing S3 documents: ' . $e->getMessage());
            return $this->jsonResponse($response, [
                'ok' => false,
                'mensaje' => 'Error interno del servidor'
            ], 500);
        }
    }

    /**
     * Eliminar documento
     * DELETE /api/s3/documentos/{id}
     */
    public function deleteDocument($request, $response, $args) {
        try {
            $documentId = $args['id'];
            
            // Obtener documento de la base de datos
            $document = $this->getDocumentFromDatabase($documentId);
            
            if (!$document) {
                return $this->jsonResponse($response, [
                    'ok' => false,
                    'mensaje' => 'Documento no encontrado'
                ], 404);
            }

            // Eliminar de S3
            $deleteResult = $this->s3Service->deleteFile($document['ruta']);
            
            if (!$deleteResult['success']) {
                error_log('S3 delete error for document ' . $documentId . ': ' . $deleteResult['error']);
            }

            // Eliminar de base de datos
            $deleted = $this->deleteDocumentFromDatabase($documentId);
            
            if (!$deleted) {
                return $this->jsonResponse($response, [
                    'ok' => false,
                    'mensaje' => 'Error al eliminar el documento de la base de datos'
                ], 500);
            }

            return $this->jsonResponse($response, [
                'ok' => true,
                'mensaje' => 'Documento eliminado correctamente'
            ]);

        } catch (\Exception $e) {
            error_log('Error deleting S3 document: ' . $e->getMessage());
            return $this->jsonResponse($response, [
                'ok' => false,
                'mensaje' => 'Error interno del servidor'
            ], 500);
        }
    }

    /**
     * Health check para S3
     * GET /api/s3/health
     */
    public function healthCheck($request, $response) {
        try {
            $result = $this->s3Service->validateConfiguration();
            
            return $this->jsonResponse($response, [
                'ok' => $result['success'],
                'mensaje' => $result['success'] ? 'S3 funcionando correctamente' : $result['error'],
                's3_working' => $result['success'],
                'bucket' => $result['bucket'] ?? null
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse($response, [
                'ok' => false,
                'mensaje' => 'Error verificando S3: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Métodos privados para base de datos
     */
    private function saveDocumentToDatabase($data) {
        try {
            $baseDatos = conectar();
            
            if ($data['id_historial']) {
                $sql = "INSERT INTO documento_clinico 
                        (id_historial, id_profesional, ruta, tipo, nombre_original, fecha_subida) 
                        VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP) RETURNING id_documento";
                $params = [
                    $data['id_historial'],
                    $data['id_profesional'],
                    $data['ruta'],
                    $data['tipo'],
                    $data['nombre_original']
                ];
            } else {
                $sql = "INSERT INTO documento_clinico 
                        (id_tratamiento, id_profesional, ruta, tipo, nombre_original, fecha_subida) 
                        VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP) RETURNING id_documento";
                $params = [
                    $data['id_tratamiento'],
                    $data['id_profesional'],
                    $data['ruta'],
                    $data['tipo'],
                    $data['nombre_original']
                ];
            }
            
            $stmt = $baseDatos->prepare($sql);
            $stmt->execute($params);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)$result['id_documento'];
            
        } catch (\Exception $e) {
            error_log('Error saving S3 document to DB: ' . $e->getMessage());
            throw $e;
        }
    }

    private function getDocumentFromDatabase($documentId) {
        try {
            $baseDatos = conectar();
            $sql = "SELECT dc.*, 
                           COALESCE(dc.nombre_original, 'documento') as nombre_original
                    FROM documento_clinico dc 
                    WHERE dc.id_documento = ?";
            $stmt = $baseDatos->prepare($sql);
            $stmt->execute([$documentId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            error_log('Error getting S3 document from DB: ' . $e->getMessage());
            return null;
        }
    }

    private function getDocumentsFromDatabase($historialId, $tratamientoId, $pacienteId = null) {
        try {
            $baseDatos = conectar();
            
            $conditions = [];
            $params = [];
            
            if ($historialId) {
                $conditions[] = "dc.id_historial = ?";
                $params[] = $historialId;
            }
            
            if ($tratamientoId) {
                $conditions[] = "dc.id_tratamiento = ?";
                $params[] = $tratamientoId;
            }
            
            if ($pacienteId) {
                $conditions[] = "h.id_paciente = ?";
                $params[] = $pacienteId;
            }
            
            if (empty($conditions)) {
                return [];
            }
            
            $whereClause = implode(' OR ', $conditions);
            
            $sql = "SELECT dc.id_documento, 
                           COALESCE(dc.nombre_original, 'documento') as nombre_original,
                           dc.tipo, 
                           dc.fecha_subida,
                           dc.ruta,
                           (p.nombre || ' ' || p.apellido1) as profesional_nombre
                    FROM documento_clinico dc
                    LEFT JOIN historial_clinico h ON dc.id_historial = h.id_historial
                    LEFT JOIN persona p ON dc.id_profesional = p.id_persona
                    WHERE {$whereClause}
                    ORDER BY dc.fecha_subida DESC";
            
            $stmt = $baseDatos->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (\Exception $e) {
            error_log('Error getting S3 documents from DB: ' . $e->getMessage());
            return [];
        }
    }

    private function deleteDocumentFromDatabase($documentId) {
        try {
            $baseDatos = conectar();
            $sql = "DELETE FROM documento_clinico WHERE id_documento = ?";
            $stmt = $baseDatos->prepare($sql);
            $success = $stmt->execute([$documentId]);
            return $success && $stmt->rowCount() > 0;
        } catch (\Exception $e) {
            error_log('Error deleting S3 document from DB: ' . $e->getMessage());
            return false;
        }
    }

    private function jsonResponse($response, $data, $status = 200) {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}