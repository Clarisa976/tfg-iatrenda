<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../funciones_CTES_servicios.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

class BackupService
{
    private $s3Client;
    private $bucketName;
    private $backupFolder = 'database-backups';
    private $tempDir = '/tmp';

    public function __construct()
    {
        $this->bucketName = $_ENV['AWS_S3_BUCKET_NAME'];

        $this->s3Client = new S3Client([
            'version' => 'latest',
            'region' => $_ENV['AWS_REGION'],
            'credentials' => [
                'key' => $_ENV['AWS_ACCESS_KEY_ID'],
                'secret' => $_ENV['AWS_SECRET_ACCESS_KEY'],
            ]
        ]);
    }

    private function generateFileName(): string
    {
        $timestamp = date('Y-m-d-H-i-s');
        return "iatrenda_backup_{$timestamp}.sql";
    }

    private function buildDatabaseUrl(): string
    {
        // Si existe DATABASE_URL, usarla directamente
        if (!empty($_ENV['DATABASE_URL'])) {
            return $_ENV['DATABASE_URL'];
        }
        
        // Si no, construir desde variables separadas
        $host = $_ENV['DB_HOST'];
        $user = $_ENV['DB_USER'];
        $pass = $_ENV['DB_PASS'];
        $name = $_ENV['DB_NAME'];
        $port = $_ENV['DB_PORT'] ?? 5432;
        
        return "postgresql://{$user}:{$pass}@{$host}:{$port}/{$name}";
    }

    public function createDatabaseDump(): array
    {
        $fileName = $this->generateFileName();
        $filePath = $this->tempDir . '/' . $fileName;

        error_log('Creando dump de la base de datos...');

        $databaseUrl = $this->buildDatabaseUrl();
        
        // Agregar parámetros SSL si no están
        if (strpos($databaseUrl, 'sslmode=') === false) {
            $separator = strpos($databaseUrl, '?') !== false ? '&' : '?';
            $databaseUrl .= $separator . 'sslmode=require';
        }

        // Comando mejorado para Supabase
        $command = sprintf(
            'pg_dump "%s" --no-owner --no-privileges --verbose --format=plain > "%s" 2>&1',
            $databaseUrl,
            $filePath
        );

        error_log("Ejecutando pg_dump...");

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            error_log("pg_dump falló. Output: " . implode("\n", $output));
            throw new Exception('Error ejecutando pg_dump: ' . implode("\n", $output));
        }

        if (!file_exists($filePath) || filesize($filePath) === 0) {
            throw new Exception('El archivo de backup está vacío o no se creó');
        }

        $size = filesize($filePath);
        error_log("Dump creado exitosamente: {$fileName} ({$size} bytes)");

        return [
            'fileName' => $fileName,
            'filePath' => $filePath,
            'size' => $size
        ];
    }

    public function uploadToS3(string $fileName, string $filePath): array
    {
        error_log('Subiendo backup a AWS S3...');

        $fileContent = file_get_contents($filePath);
        $checksum = hash('sha256', $fileContent);
        $s3Key = $this->backupFolder . '/' . $fileName;

        try {
            $result = $this->s3Client->putObject([
                'Bucket' => $this->bucketName,
                'Key' => $s3Key,
                'Body' => $fileContent,
                'ContentType' => 'application/sql',
                'ServerSideEncryption' => 'AES256', // Encriptar en S3
                'Metadata' => [
                    'backup-date' => date('c'),
                    'database' => $_ENV['DB_NAME'],
                    'type' => 'full-backup',
                    'checksum' => $checksum
                ]
            ]);

            $location = $result['ObjectURL'] ?? "s3://{$this->bucketName}/{$s3Key}";
            error_log("Backup subido exitosamente a S3: {$s3Key}");

            return [
                'location' => $location,
                'checksum' => $checksum,
                'size' => strlen($fileContent),
                's3Key' => $s3Key
            ];
        } catch (AwsException $e) {
            error_log('Error subiendo a S3: ' . $e->getMessage());
            throw $e;
        }
    }

    public function logBackupToDatabase(string $fileName, string $s3Location, string $checksum, int $size): void
    {
        try {
            $baseDatos = conectar();

            $sql = "INSERT INTO backup (path_al_fichero, checksum_sha256, tamano_bytes, encriptado) 
                    VALUES (?, ?, ?, ?)";

            $stmt = $baseDatos->prepare($sql);
            $stmt->execute([
                $s3Location,
                $checksum,
                $size,
                true  // Encriptado en S3
            ]);

            error_log('Backup registrado en BD exitosamente');
        } catch (Exception $e) {
            error_log('Error registrando backup en BD: ' . $e->getMessage());
            // No lanzar excepción aquí para no fallar todo el backup
        }
    }

    private function cleanupLocalFile(string $filePath): void
    {
        try {
            if (file_exists($filePath)) {
                unlink($filePath);
                error_log('Archivo temporal eliminado');
            }
        } catch (Exception $e) {
            error_log('No se pudo eliminar archivo temporal: ' . $e->getMessage());
        }
    }

    public function createFullBackup(): array
    {
        error_log('=== INICIANDO BACKUP COMPLETO ===');

        try {
            // 1. Crear dump
            $dumpResult = $this->createDatabaseDump();
            
            // 2. Subir a S3
            $uploadResult = $this->uploadToS3($dumpResult['fileName'], $dumpResult['filePath']);

            // 3. Registrar en BD
            $this->logBackupToDatabase(
                $dumpResult['fileName'],
                $uploadResult['location'],
                $uploadResult['checksum'],
                $uploadResult['size']
            );

            // 4. Limpiar archivo temporal
            $this->cleanupLocalFile($dumpResult['filePath']);

            error_log('=== BACKUP COMPLETADO EXITOSAMENTE ===');

            return [
                'success' => true,
                'fileName' => $dumpResult['fileName'],
                's3Location' => $uploadResult['location'],
                'size' => $uploadResult['size'],
                'checksum' => $uploadResult['checksum'],
                'timestamp' => date('c')
            ];
        } catch (Exception $e) {
            error_log('=== ERROR EN BACKUP: ' . $e->getMessage() . ' ===');
            throw $e;
        }
    }

    public function listBackups(): array
    {
        try {
            $result = $this->s3Client->listObjectsV2([
                'Bucket' => $this->bucketName,
                'Prefix' => $this->backupFolder . '/'
            ]);

            $backups = [];
            if (isset($result['Contents'])) {
                foreach ($result['Contents'] as $object) {
                    $backups[] = [
                        'fileName' => str_replace($this->backupFolder . '/', '', $object['Key']),
                        'size' => $object['Size'],
                        'lastModified' => $object['LastModified']->format('c'),
                        's3Key' => $object['Key']
                    ];
                }
            }

            // Ordenar por fecha, más recientes primero
            usort($backups, function ($a, $b) {
                return strtotime($b['lastModified']) - strtotime($a['lastModified']);
            });

            return $backups;
        } catch (AwsException $e) {
            error_log('Error listando backups: ' . $e->getMessage());
            throw $e;
        }
    }

    public function deleteOldBackups(int $keepLastN = 10): array
    {
        $backups = $this->listBackups();

        if (count($backups) <= $keepLastN) {
            error_log('No hay backups antiguos para eliminar');
            return ['deleted' => 0, 'message' => 'No hay backups para eliminar'];
        }

        $backupsToDelete = array_slice($backups, $keepLastN);
        $deletedCount = 0;

        foreach ($backupsToDelete as $backup) {
            try {
                $this->s3Client->deleteObject([
                    'Bucket' => $this->bucketName,
                    'Key' => $backup['s3Key']
                ]);

                error_log("Backup eliminado: {$backup['fileName']}");
                $deletedCount++;
            } catch (AwsException $e) {
                error_log("Error eliminando {$backup['fileName']}: " . $e->getMessage());
            }
        }

        error_log("Eliminados {$deletedCount} backups antiguos");
        return ['deleted' => $deletedCount, 'message' => "Eliminados {$deletedCount} backups antiguos"];
    }
}