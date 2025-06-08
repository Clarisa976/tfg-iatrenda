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
     * Subir documento clínico a S3
     * POST /api/s3/upload
     */
    public function uploadDocument($request, $response) {
        try {
            $uploadedFiles = $request->getUploadedFiles();
            $data = $request->getParsedBody();

            // Validar que se subió un archivo
            if (!isset($uploadedFiles['documento'])) {
                return $this->jsonResponse($response, [
                    'ok' => false,
                    'mensaje' => 'No se ha subido ningún archivo'
                ], 400);
            }

            $uploadedFile = $uploadedFiles['documento'];
            
            // Validar errores de upload
            if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
                return $this->jsonResponse($response, [
                    'ok' => false,
                    'mensaje' => 'Error en la subida del archivo'
                ], 400);
            }

            // Validar datos requeridos
            if (!isset($data['id_profesional'])) {
                return $this->jsonResponse($response, [
                    'ok' => false,
                    'mensaje' => 'ID del profesional es requerido'
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
                    'profesional-id' => $data['id_profesional'],
                    'historial-id' => $data['id_historial'] ?? '',
                    'tratamiento-id' => $data['id_tratamiento'] ?? '',
                    'tipo-documento' => $data['tipo_documento'] ?? 'general'
                ]
            );

            if (!$uploadResult['success']) {
                return $this->jsonResponse($response, [
                    'ok' => false,
                    'mensaje' => $uploadResult['error']
                ], 500);
            }

            // Guardar en base de datos
            $documentData = [
                'id_historial' => $data['id_historial'] ?? null,
                'id_tratamiento' => $data['id_tratamiento'] ?? null,
                'id_profesional' => $data['id_profesional'],
                'ruta' => $uploadResult['key'], // Guardar la S3 key
                'tipo' => $fileType,
                'nombre_original' => $fileName,
                'tamano' => $uploadedFile->getSize()
            ];

            $documentId = $this->saveDocumentToDatabase($documentData);

            // Registrar actividad usando la función existente
            if (function_exists('registrarActividad')) {
                $val = verificarTokenUsuario();
                if ($val !== false) {
                    registrarActividad(
                        $val['usuario']['id_persona'], 
                        $data['id_profesional'],
                        'documento_clinico',
                        'ruta',
                        null,
                        $uploadResult['key'],
                        'INSERT'
                    );
                }
            }

            return $this->jsonResponse($response, [
                'ok' => true,
                'mensaje' => 'Documento subido correctamente',
                'data' => [
                    'id' => $documentId,
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

            // Verificar si es el profesional que subió el documento o es admin
            $userRole = strtolower($val['usuario']['rol']);
            $userId = $val['usuario']['id_persona'];
            
            if ($userRole !== 'admin' && $document['id_profesional'] != $userId) {
                // Si es paciente, verificar que el documento le pertenece
                if ($userRole === 'paciente') {
                    if (!$this->documentBelongsToPatient($document, $userId)) {
                        return $this->jsonResponse($response, [
                            'ok' => false,
                            'mensaje' => 'Acceso denegado'
                        ], 403);
                    }
                } else {
                    return $this->jsonResponse($response, [
                        'ok' => false,
                        'mensaje' => 'Acceso denegado'
                    ], 403);
                }
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

            return $this->jsonResponse($response, [
                'ok' => true,
                'download_url' => $urlResult['url'],
                'expires_in' => '30 minutes',
                'documento' => [
                    'id' => $document['id_documento'],
                    'nombre' => $document['nombre_original'] ?? 'documento',
                    'tipo' => $document['tipo'],
                    'fecha_subida' => $document['fecha_subida']
                ]
            ]);

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
     * GET /api/s3/documentos?historial_id=X&tratamiento_id=Y
     */
    public function listDocuments($request, $response) {
        try {
            $params = $request->getQueryParams();
            $historialId = $params['historial_id'] ?? null;
            $tratamientoId = $params['tratamiento_id'] ?? null;
            $pacienteId = $params['paciente_id'] ?? null;

            // Verificar autorización
            $val = verificarTokenUsuario();
            if ($val === false) {
                return $this->jsonResponse($response, [
                    'ok' => false,
                    'mensaje' => 'No autorizado'
                ], 401);
            }

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
            
            // Verificar autorización
            $val = verificarTokenUsuario();
            if ($val === false) {
                return $this->jsonResponse($response, [
                    'ok' => false,
                    'mensaje' => 'No autorizado'
                ], 401);
            }

            // Solo profesionales y admins pueden eliminar documentos
            $userRole = strtolower($val['usuario']['rol']);
            if (!in_array($userRole, ['profesional', 'admin'])) {
                return $this->jsonResponse($response, [
                    'ok' => false,
                    'mensaje' => 'Acceso denegado'
                ], 403);
            }
            
            // Obtener documento de la base de datos
            $document = $this->getDocumentFromDatabase($documentId);
            
            if (!$document) {
                return $this->jsonResponse($response, [
                    'ok' => false,
                    'mensaje' => 'Documento no encontrado'
                ], 404);
            }

            // Verificar que el profesional puede eliminar este documento
            $userId = $val['usuario']['id_persona'];
            if ($userRole !== 'admin' && $document['id_profesional'] != $userId) {
                return $this->jsonResponse($response, [
                    'ok' => false,
                    'mensaje' => 'Solo puedes eliminar documentos que has subido'
                ], 403);
            }

            // Eliminar de S3
            $deleteResult = $this->s3Service->deleteFile($document['ruta']);
            
            if (!$deleteResult['success']) {
                error_log('S3 delete error for document ' . $documentId . ': ' . $deleteResult['error']);
                // Continuar con la eliminación de BD aunque S3 falle
            }

            // Eliminar de base de datos
            $deleted = $this->deleteDocumentFromDatabase($documentId);
            
            if (!$deleted) {
                return $this->jsonResponse($response, [
                    'ok' => false,
                    'mensaje' => 'Error al eliminar el documento de la base de datos'
                ], 500);
            }

            // Registrar actividad
            if (function_exists('registrarActividad')) {
                registrarActividad(
                    $userId,
                    $document['id_profesional'],
                    'documento_clinico',
                    'ruta',
                    $document['ruta'],
                    null,
                    'DELETE'
                );
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
     * Métodos privados para base de datos - Compatibles con PostgreSQL
     */
    private function saveDocumentToDatabase($data) {
        try {
            $baseDatos = conectar();
            
            // Determinar si es documento de historial o tratamiento
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

    private function documentBelongsToPatient($document, $patientId) {
        try {
            $baseDatos = conectar();
            
            // Verificar si el documento pertenece al historial del paciente
            if ($document['id_historial']) {
                $sql = "SELECT 1 FROM historial_clinico WHERE id_historial = ? AND id_paciente = ?";
                $stmt = $baseDatos->prepare($sql);
                $stmt->execute([$document['id_historial'], $patientId]);
                return $stmt->fetch() !== false;
            }
            
            // Verificar si el documento pertenece a un tratamiento del paciente
            if ($document['id_tratamiento']) {
                $sql = "SELECT 1 FROM tratamiento t 
                        JOIN historial_clinico h ON t.id_historial = h.id_historial 
                        WHERE t.id_tratamiento = ? AND h.id_paciente = ?";
                $stmt = $baseDatos->prepare($sql);
                $stmt->execute([$document['id_tratamiento'], $patientId]);
                return $stmt->fetch() !== false;
            }
            
            return false;
        } catch (\Exception $e) {
            error_log('Error checking document ownership: ' . $e->getMessage());
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