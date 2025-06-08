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
            error_log('=== UPLOAD DOCUMENT START ===');
            error_log('POST data: ' . json_encode($request->getParsedBody()));
            error_log('Files: ' . json_encode(array_keys($request->getUploadedFiles())));

            $uploadedFiles = $request->getUploadedFiles();
            $data = $request->getParsedBody();

            // Debug de los datos recibidos
            error_log('Datos recibidos: ' . print_r($data, true));
            error_log('Archivos recibidos: ' . print_r(array_keys($uploadedFiles), true));

            // Determinar si es tratamiento o documento de historial
            $tipo = $data['tipo'] ?? 'historial';

            if ($tipo === 'tratamiento') {
                return $this->handleTreatmentUpload($data, $uploadedFiles, $response);
            } else {
                return $this->handleDocumentUpload($data, $uploadedFiles, $response);
            }

        } catch (\Exception $e) {
            error_log('=== ERROR EN UPLOAD ===');
            error_log('Error message: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            error_log('=== END ERROR ===');
            
            return $this->jsonResponse($response, [
                'ok' => false,
                'mensaje' => 'Error interno del servidor: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Manejar subida de tratamiento (con o sin archivo)
     */
    private function handleTreatmentUpload($data, $uploadedFiles, $response) {
        $baseDatos = null;
        $uploadedToS3 = false;
        $s3Key = null;
        
        try {
            error_log('=== TREATMENT UPLOAD START ===');
            
            // Validar datos requeridos para tratamiento
            if (!isset($data['id_paciente']) || !isset($data['titulo']) || !isset($data['notas'])) {
                error_log('Datos incompletos. Recibido: ' . print_r($data, true));
                return $this->jsonResponse($response, [
                    'ok' => false,
                    'mensaje' => 'Datos del tratamiento incompletos (id_paciente, titulo, notas son requeridos)'
                ], 400);
            }

            // Obtener ID del profesional desde el token (ya validado en la ruta)
            $val = verificarTokenUsuario();
            $profesionalId = $val['usuario']['id_persona'];
            error_log('Profesional ID: ' . $profesionalId);

            // Iniciar transacción
            $baseDatos = conectar();
            $baseDatos->beginTransaction();
            error_log('Transacción iniciada');

            // 1. Crear historial clínico si no existe
            $historialId = $this->getOrCreateHistorial($data['id_paciente'], $baseDatos);
            if (!$historialId) {
                $baseDatos->rollBack();
                return $this->jsonResponse($response, [
                    'ok' => false,
                    'mensaje' => 'Error al crear/obtener historial clínico'
                ], 500);
            }
            error_log('Historial ID: ' . $historialId);

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

            $tratamientoId = $this->saveTreatmentToDatabase($tratamientoData, $baseDatos);
            if (!$tratamientoId) {
                $baseDatos->rollBack();
                return $this->jsonResponse($response, [
                    'ok' => false,
                    'mensaje' => 'Error al crear tratamiento en base de datos'
                ], 500);
            }
            error_log('Tratamiento ID creado: ' . $tratamientoId);

            // 3. Si hay archivo, procesarlo
            $documentData = null;
            $documentId = null;
            
            if (isset($uploadedFiles['file'])) {
                error_log('Procesando archivo...');
                $uploadedFile = $uploadedFiles['file'];
                
                // Validar archivo
                if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
                    $baseDatos->rollBack();
                    error_log('Error en archivo: ' . $uploadedFile->getError());
                    return $this->jsonResponse($response, [
                        'ok' => false,
                        'mensaje' => 'Error en la subida del archivo: ' . $uploadedFile->getError()
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
                error_log('Tipo de archivo: ' . $fileType);
                
                if (!in_array($fileType, $allowedTypes)) {
                    $baseDatos->rollBack();
                    return $this->jsonResponse($response, [
                        'ok' => false,
                        'mensaje' => 'Tipo de archivo no permitido. Permitidos: PDF, JPG, PNG, GIF, DOC, DOCX, TXT'
                    ], 400);
                }

                // Validar tamaño (max 10MB)
                $maxSize = 10 * 1024 * 1024; // 10MB
                if ($uploadedFile->getSize() > $maxSize) {
                    $baseDatos->rollBack();
                    return $this->jsonResponse($response, [
                        'ok' => false,
                        'mensaje' => 'El archivo es demasiado grande. Máximo 10MB'
                    ], 400);
                }

                // Subir a S3
                $fileContent = $uploadedFile->getStream()->getContents();
                $fileName = $uploadedFile->getClientFilename();
                
                error_log('Subiendo archivo a S3: ' . $fileName);
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
                    $baseDatos->rollBack();
                    error_log('Error subida S3: ' . $uploadResult['error']);
                    return $this->jsonResponse($response, [
                        'ok' => false,
                        'mensaje' => 'Error al subir archivo: ' . $uploadResult['error']
                    ], 500);
                }

                $uploadedToS3 = true;
                $s3Key = $uploadResult['key'];
                error_log('Archivo subido a S3 con clave: ' . $s3Key);

                // Guardar documento en base de datos
                $documentData = [
                    'id_historial' => $historialId,
                    'id_tratamiento' => $tratamientoId,
                    'id_profesional' => $profesionalId,
                    'ruta' => $uploadResult['key'],
                    'tipo' => $fileType,
                    'nombre_archivo' => $fileName
                ];

                $documentId = $this->saveDocumentToDatabase($documentData, $baseDatos);
                if (!$documentId) {
                    // Si falla guardar en BD, eliminar de S3
                    error_log('Error al guardar documento en BD, eliminando de S3...');
                    $this->s3Service->deleteFile($s3Key);
                    $baseDatos->rollBack();
                    return $this->jsonResponse($response, [
                        'ok' => false,
                        'mensaje' => 'Error al guardar documento en base de datos'
                    ], 500);
                }
                error_log('Documento guardado con ID: ' . $documentId);
            }

            // Todo OK, confirmar transacción
            $baseDatos->commit();
            error_log('Transacción confirmada');

            return $this->jsonResponse($response, [
                'ok' => true,
                'mensaje' => 'Tratamiento creado correctamente',
                'data' => [
                    'id_tratamiento' => $tratamientoId,
                    'id_historial' => $historialId,
                    'documento' => $documentData ? [
                        'id' => $documentId,
                        's3_key' => $documentData['ruta'],
                        'nombre' => $documentData['nombre_archivo']
                    ] : null
                ]
            ], 201);

        } catch (\Exception $e) {
            error_log('Error creating treatment: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            
            // Rollback de la base de datos
            if ($baseDatos && $baseDatos->inTransaction()) {
                $baseDatos->rollBack();
                error_log('Rollback realizado');
            }
            
            // Si se subió algo a S3, eliminarlo
            if ($uploadedToS3 && $s3Key) {
                error_log('Eliminando archivo de S3: ' . $s3Key);
                $this->s3Service->deleteFile($s3Key);
            }
            
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
        $baseDatos = null;
        $uploadedToS3 = false;
        $s3Key = null;
        
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

            // Obtener ID del profesional desde el token (ya validado en la ruta)
            $val = verificarTokenUsuario();
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

            // Iniciar transacción
            $baseDatos = conectar();
            $baseDatos->beginTransaction();

            // 1. Crear historial clínico si no existe
            $historialId = $this->getOrCreateHistorial($data['id_paciente'], $baseDatos);
            if (!$historialId) {
                $baseDatos->rollBack();
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
                $baseDatos->rollBack();
                return $this->jsonResponse($response, [
                    'ok' => false,
                    'mensaje' => $uploadResult['error']
                ], 500);
            }

            $uploadedToS3 = true;
            $s3Key = $uploadResult['key'];

            // 3. Actualizar historial con diagnósticos si se proporcionaron
            if (!empty($data['diagnostico_preliminar']) || !empty($data['diagnostico_final'])) {
                $this->updateHistorialDiagnosticos($historialId, $data, $baseDatos);
            }

            // 4. Guardar documento en base de datos
            $documentData = [
                'id_historial' => $historialId,
                'id_tratamiento' => null,
                'id_profesional' => $profesionalId,
                'ruta' => $uploadResult['key'],
                'tipo' => $fileType,
                'nombre_archivo' => $fileName
            ];

            $documentId = $this->saveDocumentToDatabase($documentData, $baseDatos);
            
            if (!$documentId) {
                // Si falla guardar en BD, eliminar de S3
                $this->s3Service->deleteFile($s3Key);
                $baseDatos->rollBack();
                return $this->jsonResponse($response, [
                    'ok' => false,
                    'mensaje' => 'Error al guardar documento en base de datos'
                ], 500);
            }

            // Todo OK, confirmar transacción
            $baseDatos->commit();

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
            
            // Rollback de la base de datos
            if ($baseDatos && $baseDatos->inTransaction()) {
                $baseDatos->rollBack();
            }
            
            // Si se subió algo a S3, eliminarlo
            if ($uploadedToS3 && $s3Key) {
                $this->s3Service->deleteFile($s3Key);
            }
            
            return $this->jsonResponse($response, [
                'ok' => false,
                'mensaje' => 'Error interno del servidor'
            ], 500);
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
     * Obtener o crear historial clínico para un paciente
     */
    private function getOrCreateHistorial($pacienteId, $baseDatos = null) {
        try {
            if (!$baseDatos) {
                $baseDatos = conectar();
            }
            
            // Buscar historial existente
            $sql = "SELECT id_historial FROM historial_clinico WHERE id_paciente = ? ORDER BY fecha_inicio DESC LIMIT 1";
            $stmt = $baseDatos->prepare($sql);
            $stmt->execute([$pacienteId]);
            $historial = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($historial) {
                error_log('Historial existente encontrado: ' . $historial['id_historial']);
                return $historial['id_historial'];
            }
            
            // Crear nuevo historial (PostgreSQL)
            $sql = "INSERT INTO historial_clinico (id_paciente, fecha_inicio) VALUES (?, CURRENT_DATE) RETURNING id_historial";
            $stmt = $baseDatos->prepare($sql);
            $stmt->execute([$pacienteId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                $newId = $result['id_historial'];
                error_log('Nuevo historial creado: ' . $newId);
                return $newId;
            }
            
            return null;
            
        } catch (\Exception $e) {
            error_log('Error getting/creating historial: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Guardar tratamiento en base de datos
     */
    private function saveTreatmentToDatabase($data, $baseDatos = null) {
        try {
            if (!$baseDatos) {
                $baseDatos = conectar();
            }
            
            // PostgreSQL - usar RETURNING
            $sql = "INSERT INTO tratamiento 
                    (id_historial, id_profesional, fecha_inicio, fecha_fin, frecuencia_sesiones, titulo, notas) 
                    VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id_tratamiento";
            
            $stmt = $baseDatos->prepare($sql);
            $stmt->execute([
                $data['id_historial'],
                $data['id_profesional'],
                $data['fecha_inicio'],
                $data['fecha_fin'],
                $data['frecuencia_sesiones'],
                $data['titulo'],
                $data['notas']
            ]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result) {
                return $result['id_tratamiento'];
            }
            
            return null;
            
        } catch (\Exception $e) {
            error_log('Error saving treatment to DB: ' . $e->getMessage());
            error_log('SQL Data: ' . print_r($data, true));
            return null;
        }
    }

    /**
     * Actualizar diagnósticos en historial
     */
    private function updateHistorialDiagnosticos($historialId, $data, $baseDatos = null) {
        try {
            if (!$baseDatos) {
                $baseDatos = conectar();
            }
            
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
     * Guardar documento en base de datos
     */
    private function saveDocumentToDatabase($data, $baseDatos = null) {
        try {
            if (!$baseDatos) {
                $baseDatos = conectar();
            }
            
            // PostgreSQL - usar RETURNING
            $sql = "INSERT INTO documento_clinico 
                    (id_historial, id_tratamiento, id_profesional, ruta, tipo, nombre_archivo) 
                    VALUES (?, ?, ?, ?, ?, ?) RETURNING id_documento";
            $params = [
                $data['id_historial'],
                $data['id_tratamiento'],
                $data['id_profesional'],
                $data['ruta'],
                $data['tipo'],
                $data['nombre_archivo']
            ];
            
            $stmt = $baseDatos->prepare($sql);
            $stmt->execute($params);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result) {
                return $result['id_documento'];
            }
            
            return null;
            
        } catch (\Exception $e) {
            error_log('Error saving S3 document to DB: ' . $e->getMessage());
            error_log('SQL params: ' . print_r($params, true));
            throw $e;
        }
    }

    /**
     * Obtener documento de la base de datos
     */
    private function getDocumentFromDatabase($documentId) {
        try {
            $baseDatos = conectar();
            $sql = "SELECT dc.*, 
                           COALESCE(dc.nombre_archivo, 'documento') as nombre_original
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

    /**
     * Obtener documentos de la base de datos
     */
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
                           COALESCE(dc.nombre_archivo, 'documento') as nombre_original,
                           dc.tipo, 
                           dc.fecha_subida,
                           dc.ruta,
                           dc.id_tratamiento,
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

    /**
     * Eliminar documento de la base de datos
     */
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

    /**
     * Crear respuesta JSON
     */
    private function jsonResponse($response, $data, $status = 200) {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
?>