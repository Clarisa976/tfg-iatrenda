<?php
namespace App\Services;

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

class S3Service {
    private $s3Client;
    private $bucket;

    public function __construct() {
        // Obtener configuración desde variables de entorno
        $this->bucket = getenv('AWS_BUCKET') ?: $_ENV['AWS_BUCKET'] ?? 'iatrenda-documents-prod';
        
        $awsKey = getenv('AWS_ACCESS_KEY_ID') ?: $_ENV['AWS_ACCESS_KEY_ID'] ?? '';
        $awsSecret = getenv('AWS_SECRET_ACCESS_KEY') ?: $_ENV['AWS_SECRET_ACCESS_KEY'] ?? '';
        $awsRegion = getenv('AWS_REGION') ?: $_ENV['AWS_REGION'] ?? 'eu-west-1';
        
        if (empty($awsKey) || empty($awsSecret)) {
            throw new \Exception('AWS credentials not configured');
        }
        
        $this->s3Client = new S3Client([
            'version' => 'latest',
            'region' => $awsRegion,
            'credentials' => [
                'key' => $awsKey,
                'secret' => $awsSecret,
            ]
        ]);
    }

    /**
     * Subir archivo a S3
     */
    public function uploadFile($fileContent, $fileName, $contentType = 'application/octet-stream', $metadata = []) {
        try {
            // Generar key única con estructura de carpetas
            $year = date('Y');
            $month = date('m');
            $uniqueId = uniqid();
            $extension = pathinfo($fileName, PATHINFO_EXTENSION);
            
            // Limpiar nombre de archivo
            $cleanFileName = $this->sanitizeFileName($fileName);
            $s3Key = "documents/{$year}/{$month}/{$uniqueId}_{$cleanFileName}";

            $result = $this->s3Client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $s3Key,
                'Body' => $fileContent,
                'ContentType' => $contentType,
                'Metadata' => array_merge([
                    'original-name' => $fileName,
                    'upload-time' => date('c'),
                    'uploaded-by' => 'iatrenda-app',
                    'file-size' => strlen($fileContent)
                ], $metadata),
                'ServerSideEncryption' => 'AES256',
                'StorageClass' => 'STANDARD'
            ]);
            
            error_log("File uploaded to S3: {$s3Key}");
            
            return [
                'success' => true,
                'key' => $s3Key,
                'url' => $result['ObjectURL'] ?? '',
                'etag' => $result['ETag'] ?? '',
                'size' => strlen($fileContent)
            ];

        } catch (AwsException $e) {
            error_log('S3 Upload Error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Error uploading file: ' . $e->getMessage()
            ];
        } catch (\Exception $e) {
            error_log('General Upload Error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Error uploading file: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Generar URL firmada para descarga
     */
    public function getPresignedUrl($s3Key, $expiration = '+20 minutes', $fileName = null) {
        try {
            $params = [
                'Bucket' => $this->bucket,
                'Key' => $s3Key
            ];

            // Si se proporciona fileName, forzar descarga con ese nombre
            if ($fileName) {
                $params['ResponseContentDisposition'] = 'attachment; filename="' . $fileName . '"';
            }

            $cmd = $this->s3Client->getCommand('GetObject', $params);
            $request = $this->s3Client->createPresignedRequest($cmd, $expiration);
            
            error_log("Generated presigned URL for: {$s3Key}");
            
            return [
                'success' => true,
                'url' => (string) $request->getUri(),
                'expires' => $expiration
            ];

        } catch (AwsException $e) {
            error_log('S3 Presigned URL Error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Error generating download URL: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Eliminar archivo de S3
     */
    public function deleteFile($s3Key) {
        try {
            $this->s3Client->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $s3Key
            ]);
            
            error_log("File deleted from S3: {$s3Key}");
            
            return [
                'success' => true,
                'message' => 'File deleted successfully'
            ];

        } catch (AwsException $e) {
            error_log('S3 Delete Error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Error deleting file: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Verificar si un archivo existe
     */
    public function fileExists($s3Key) {
        try {
            $this->s3Client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $s3Key
            ]);
            return true;
        } catch (AwsException $e) {
            return false;
        }
    }

    /**
     * Obtener información del archivo
     */
    public function getFileInfo($s3Key) {
        try {
            $result = $this->s3Client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $s3Key
            ]);

            return [
                'success' => true,
                'size' => $result['ContentLength'],
                'type' => $result['ContentType'],
                'lastModified' => $result['LastModified'],
                'metadata' => $result['Metadata'] ?? []
            ];

        } catch (AwsException $e) {
            return [
                'success' => false,
                'error' => 'File not found or error accessing file info'
            ];
        }
    }

    /**
     * Listar archivos (para admin/debug)
     */
    public function listFiles($prefix = 'documents/', $maxKeys = 50) {
        try {
            $result = $this->s3Client->listObjectsV2([
                'Bucket' => $this->bucket,
                'Prefix' => $prefix,
                'MaxKeys' => $maxKeys
            ]);

            $files = [];
            if (isset($result['Contents'])) {
                foreach ($result['Contents'] as $object) {
                    $files[] = [
                        'key' => $object['Key'],
                        'size' => $object['Size'],
                        'lastModified' => $object['LastModified']->format('Y-m-d H:i:s'),
                        'storageClass' => $object['StorageClass'] ?? 'STANDARD'
                    ];
                }
            }

            return [
                'success' => true,
                'files' => $files,
                'count' => count($files)
            ];

        } catch (AwsException $e) {
            error_log('S3 List Error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Error listing files: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Crear backup de documentos
     */
    public function createBackup($backupKey = null) {
        try {
            if (!$backupKey) {
                $backupKey = 'backups/documents_' . date('Y-m-d_H-i-s') . '/';
            }

            // Listar todos los documentos
            $allFiles = $this->listFiles('documents/');
            
            if (!$allFiles['success']) {
                return $allFiles;
            }

            $copiedFiles = 0;
            foreach ($allFiles['files'] as $file) {
                $sourceKey = $file['key'];
                $backupFileKey = $backupKey . basename($sourceKey);
                
                // Copiar archivo al directorio de backup
                $this->s3Client->copyObject([
                    'Bucket' => $this->bucket,
                    'CopySource' => $this->bucket . '/' . $sourceKey,
                    'Key' => $backupFileKey,
                    'MetadataDirective' => 'COPY'
                ]);
                
                $copiedFiles++;
            }

            error_log("Backup created: {$backupKey}, Files: {$copiedFiles}");

            return [
                'success' => true,
                'backup_key' => $backupKey,
                'files_backed_up' => $copiedFiles,
                'message' => "Backup created successfully with {$copiedFiles} files"
            ];

        } catch (AwsException $e) {
            error_log('S3 Backup Error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Error creating backup: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Obtener estadísticas del bucket
     */
    public function getBucketStats() {
        try {
            $documents = $this->listFiles('documents/');
            $backups = $this->listFiles('backups/');
            
            $totalSize = 0;
            $fileCount = 0;
            
            if ($documents['success']) {
                foreach ($documents['files'] as $file) {
                    $totalSize += $file['size'];
                    $fileCount++;
                }
            }

            return [
                'success' => true,
                'stats' => [
                    'total_files' => $fileCount,
                    'total_size_bytes' => $totalSize,
                    'total_size_mb' => round($totalSize / 1024 / 1024, 2),
                    'backup_files' => $backups['success'] ? $backups['count'] : 0,
                    'bucket' => $this->bucket,
                    'last_updated' => date('c')
                ]
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Error getting bucket stats: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Limpiar nombre de archivo para S3
     */
    private function sanitizeFileName($fileName) {
        // Reemplazar caracteres problemáticos
        $clean = preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);
        
        // Limitar longitud
        if (strlen($clean) > 100) {
            $extension = pathinfo($clean, PATHINFO_EXTENSION);
            $name = pathinfo($clean, PATHINFO_FILENAME);
            $clean = substr($name, 0, 95) . '.' . $extension;
        }
        
        return $clean;
    }

    /**
     * Validar configuración S3
     */
    public function validateConfiguration() {
        try {
            // Test simple: intentar listar objetos
            $result = $this->s3Client->listObjectsV2([
                'Bucket' => $this->bucket,
                'MaxKeys' => 1
            ]);

            return [
                'success' => true,
                'message' => 'S3 configuration is valid',
                'bucket' => $this->bucket,
                'region' => $this->s3Client->getRegion()
            ];

        } catch (AwsException $e) {
            error_log('S3 Configuration Error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => 'S3 configuration invalid: ' . $e->getMessage(),
                'bucket' => $this->bucket
            ];
        }
    }
}