<?php
namespace App\Services;

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

class S3Service {
    private $s3Client;
    private $bucket;

    public function __construct() {
        $this->bucket = $_ENV['AWS_BUCKET'] ?? '';
        
        $this->s3Client = new S3Client([
            'version' => 'latest',
            'region' => $_ENV['AWS_REGION'] ?? 'us-east-1',
            'credentials' => [
                'key' => $_ENV['AWS_ACCESS_KEY_ID'] ?? '',
                'secret' => $_ENV['AWS_SECRET_ACCESS_KEY'] ?? '',
            ],
        ]);
    }

    public function uploadFile($fileContent, $fileName, $contentType, $metadata = []) {
        try {
            // Generar nombre único para el archivo
            $key = 'documents/' . date('Y/m/d/') . uniqid() . '_' . $fileName;
            
            $result = $this->s3Client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'Body' => $fileContent,
                'ContentType' => $contentType,
                'Metadata' => $metadata,
                'ServerSideEncryption' => 'AES256',
            ]);

            return [
                'success' => true,
                'key' => $key,
                'url' => $result['ObjectURL'] ?? null,
                'etag' => $result['ETag'] ?? null
            ];
            
        } catch (AwsException $e) {
            error_log('S3 Upload Error: ' . $e->getAwsErrorMessage());
            return [
                'success' => false,
                'error' => 'Error al subir archivo: ' . $e->getAwsErrorMessage()
            ];
        } catch (\Exception $e) {
            error_log('S3 Upload Exception: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Error inesperado al subir archivo'
            ];
        }
    }

    public function getPresignedUrl($key, $expiration = '+1 hour', $filename = null) {
        try {
            $cmd = $this->s3Client->getCommand('GetObject', [
                'Bucket' => $this->bucket,
                'Key' => $key,
                'ResponseContentDisposition' => $filename ? "attachment; filename=\"$filename\"" : null
            ]);

            $request = $this->s3Client->createPresignedRequest($cmd, $expiration);
            
            return [
                'success' => true,
                'url' => (string) $request->getUri()
            ];
            
        } catch (AwsException $e) {
            error_log('S3 Presigned URL Error: ' . $e->getAwsErrorMessage());
            return [
                'success' => false,
                'error' => 'Error al generar URL de descarga'
            ];
        }
    }

    public function deleteFile($key) {
        try {
            $this->s3Client->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);

            return ['success' => true];
            
        } catch (AwsException $e) {
            error_log('S3 Delete Error: ' . $e->getAwsErrorMessage());
            return [
                'success' => false,
                'error' => 'Error al eliminar archivo'
            ];
        }
    }

    public function validateConfiguration() {
        try {
            // Intentar listar objetos para verificar configuración
            $this->s3Client->headBucket(['Bucket' => $this->bucket]);
            
            return [
                'success' => true,
                'bucket' => $this->bucket
            ];
            
        } catch (AwsException $e) {
            return [
                'success' => false,
                'error' => 'Error de configuración S3: ' . $e->getAwsErrorMessage()
            ];
        }
    }
}
?>