<?php

namespace App\Services;

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

class S3Service
{
    private $s3Client;
    private $bucket;

    public function __construct()
    {
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

    public function uploadFile($fileContent, $fileName, $contentType, $metadata = [])
    {
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

    public function getPresignedUrl($key, $expiration = '+1 hour', $filename = null)
    {
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

    public function deleteFile($key)
    {
        try {
            // Log detallado para debug
            error_log("=== S3 DELETE START ===");
            error_log("Key a eliminar: " . $key);
            error_log("Bucket: " . $this->bucket);

            // Limpiar la key de posibles problemas de encoding
            $cleanKey = trim($key);
            $cleanKey = str_replace(['\\', '//'], ['/', '/'], $cleanKey);

            error_log("Key limpia: " . $cleanKey);

            // Verificar que el archivo existe antes de intentar eliminarlo
            try {
                $headResult = $this->s3Client->headObject([
                    'Bucket' => $this->bucket,
                    'Key' => $cleanKey
                ]);
                error_log("Archivo existe en S3, procediendo a eliminar...");
            } catch (\Exception $e) {
                error_log("Archivo NO existe en S3: " . $e->getMessage());
                // Devolver éxito porque ya no existe
                return [
                    'success' => true,
                    'message' => 'Archivo ya no existe en S3'
                ];
            }

            // Eliminar el archivo
            $result = $this->s3Client->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $cleanKey
            ]);

            error_log("Resultado eliminación S3: " . json_encode($result->toArray()));

            // AWS no devuelve error si el archivo no existe, así que verificamos que se eliminó
            try {
                $this->s3Client->headObject([
                    'Bucket' => $this->bucket,
                    'Key' => $cleanKey
                ]);
                // Si llegamos aquí, el archivo AÚN existe
                error_log("ERROR: Archivo todavía existe después de eliminar");
                return [
                    'success' => false,
                    'error' => 'El archivo no se pudo eliminar de S3'
                ];
            } catch (\Exception $e) {
                // Si da error al buscar, significa que se eliminó correctamente
                error_log("Confirmado: Archivo eliminado exitosamente de S3");
                return [
                    'success' => true,
                    'message' => 'Archivo eliminado correctamente de S3'
                ];
            }
        } catch (\Exception $e) {
            error_log("Error eliminando archivo de S3: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());

            return [
                'success' => false,
                'error' => 'Error eliminando de S3: ' . $e->getMessage()
            ];
        }
    }

    public function validateConfiguration()
    {
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
