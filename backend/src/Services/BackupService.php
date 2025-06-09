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

    public function createDatabaseDump(): array
    {
        $fileName = $this->generateFileName();
        $filePath = $this->tempDir . '/' . $fileName;

        error_log('=== INICIANDO BACKUP CON PDO ===');
        error_log('Reason: pg_dump version mismatch (server 17.4 vs pg_dump 15.13)');

        try {
            $pdo = conectarPostgreSQL();
            error_log('Conexión PDO establecida correctamente');
            
            $sql = $this->generateCompleteBackupSQL($pdo);
            
            if (file_put_contents($filePath, $sql) === false) {
                throw new Exception('No se pudo escribir el archivo de backup');
            }

            $size = filesize($filePath);
            error_log("Backup PDO creado exitosamente: {$fileName} ({$size} bytes)");

            return [
                'fileName' => $fileName,
                'filePath' => $filePath,
                'size' => $size,
                'method' => 'pdo_native'
            ];
        } catch (Exception $e) {
            error_log('Error en backup PDO: ' . $e->getMessage());
            throw $e;
        }
    }

    private function generateCompleteBackupSQL(PDO $pdo): string
    {
        $startTime = microtime(true);
        
        $sql = "-- =============================================\n";
        $sql .= "-- IATRENDA DATABASE BACKUP\n";
        $sql .= "-- =============================================\n";
        $sql .= "-- Generado: " . date('Y-m-d H:i:s T') . "\n";
        $sql .= "-- Base de datos: " . $_ENV['DB_NAME'] . "\n";
        $sql .= "-- Host: " . $_ENV['DB_HOST'] . "\n";
        $sql .= "-- Método: PDO (PostgreSQL 17.4)\n";
        $sql .= "-- =============================================\n\n";
        
        $sql .= "-- Configuración inicial\n";
        $sql .= "SET client_encoding = 'UTF8';\n";
        $sql .= "SET timezone = 'Europe/Madrid';\n";
        $sql .= "SET datestyle = 'ISO, MDY';\n";
        $sql .= "SET default_transaction_isolation = 'read committed';\n";
        $sql .= "SET client_min_messages = warning;\n\n";
        
        // Obtener información del servidor
        $version = $pdo->query("SELECT version()")->fetchColumn();
        $sql .= "-- Versión del servidor: " . substr($version, 0, 100) . "\n\n";
        
        // Obtener lista de tablas ordenada
        $tables = $this->getTablesInDependencyOrder($pdo);
        
        $totalTables = count($tables);
        $totalRecords = 0;
        
        error_log("Procesando {$totalTables} tablas...");

        // Deshabilitar foreign key checks temporalmente
        $sql .= "-- Deshabilitando foreign key checks\n";
        $sql .= "SET session_replication_role = replica;\n\n";

        foreach ($tables as $index => $table) {
            error_log("Procesando tabla " . ($index + 1) . "/{$totalTables}: {$table}");
            $tableBackup = $this->generateTableBackup($pdo, $table);
            $sql .= $tableBackup;
            
            // Contar registros para estadísticas
            try {
                $count = $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
                $totalRecords += $count;
            } catch (Exception $e) {
                error_log("Error contando registros en {$table}: " . $e->getMessage());
            }
        }

        // Rehabilitar foreign key checks
        $sql .= "-- Rehabilitando foreign key checks\n";
        $sql .= "SET session_replication_role = DEFAULT;\n\n";

        $endTime = microtime(true);
        $duration = round($endTime - $startTime, 2);
        
        $sql .= "-- =============================================\n";
        $sql .= "-- RESUMEN DEL BACKUP\n";
        $sql .= "-- =============================================\n";
        $sql .= "-- Tablas procesadas: {$totalTables}\n";
        $sql .= "-- Total de registros: {$totalRecords}\n";
        $sql .= "-- Tiempo de generación: {$duration} segundos\n";
        $sql .= "-- Finalizado: " . date('Y-m-d H:i:s T') . "\n";
        $sql .= "-- =============================================\n";

        error_log("Backup SQL generado: {$totalTables} tablas, {$totalRecords} registros, {$duration}s");
        
        return $sql;
    }

    private function getTablesInDependencyOrder(PDO $pdo): array
    {
        // Orden específico respetando foreign keys
        $dependencyOrder = [
            'persona',           // Sin dependencias
            'tutor',            // persona
            'profesional',      // persona  
            'paciente',         // persona, tutor
            'bloque_agenda',    // profesional
            'cita',             // paciente, profesional, bloque_agenda
            'historial_clinico', // paciente
            'tratamiento',      // historial_clinico, profesional
            'documento_clinico', // historial_clinico, profesional, tratamiento
            'notificacion',     // persona, cita
            'consentimiento',   // persona
            'log_evento_dato',  // persona
            'backup'            // Sin dependencias
        ];
        
        // Verificar que todas las tablas existen
        $existingTables = $pdo->query("
            SELECT tablename 
            FROM pg_tables 
            WHERE schemaname = 'public' 
            ORDER BY tablename
        ")->fetchAll(PDO::FETCH_COLUMN);

        $orderedTables = [];
        
        // Primero las tablas en orden de dependencias
        foreach ($dependencyOrder as $table) {
            if (in_array($table, $existingTables)) {
                $orderedTables[] = $table;
            }
        }
        
        // Agregar cualquier tabla nueva que no esté en el orden
        foreach ($existingTables as $table) {
            if (!in_array($table, $orderedTables)) {
                $orderedTables[] = $table;
                error_log("Tabla nueva encontrada: {$table}");
            }
        }
        
        return $orderedTables;
    }

    private function generateTableBackup(PDO $pdo, string $table): string
    {
        $sql = "\n-- ============================================\n";
        $sql .= "-- TABLA: {$table}\n";
        $sql .= "-- ============================================\n";

        try {
            // Obtener información de la tabla
            $count = $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
            $sql .= "-- Registros: {$count}\n";
            
            if ($count > 0) {
                $sql .= "-- Timestamp: " . date('H:i:s') . "\n\n";
                
                // Limpiar tabla (TRUNCATE es más rápido que DELETE)
                $sql .= "TRUNCATE TABLE {$table} RESTART IDENTITY CASCADE;\n\n";
                
                $sql .= $this->getTableData($pdo, $table, $count);
            } else {
                $sql .= "-- Tabla vacía\n\n";
            }
            
        } catch (Exception $e) {
            $sql .= "-- ERROR procesando tabla {$table}: " . $e->getMessage() . "\n\n";
            error_log("Error procesando tabla {$table}: " . $e->getMessage());
        }

        return $sql;
    }

    private function getTableData(PDO $pdo, string $table, int $count): string
    {
        $sql = "";
        
        try {
            // Para tablas grandes, procesar en lotes
            $batchSize = 1000;
            $batches = ceil($count / $batchSize);
            
            if ($batches > 1) {
                $sql .= "-- Procesando en {$batches} lotes de {$batchSize} registros\n";
            }
            
            for ($batch = 0; $batch < $batches; $batch++) {
                $offset = $batch * $batchSize;
                
                $stmt = $pdo->query("SELECT * FROM {$table} LIMIT {$batchSize} OFFSET {$offset}");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (empty($rows)) {
                    continue;
                }
                
                if ($batch === 0) {
                    // Primera vez, obtener columnas
                    $columns = array_keys($rows[0]);
                    $columnList = implode(', ', array_map(function($col) {
                        return '"' . $col . '"';
                    }, $columns));
                }
                
                $sql .= "INSERT INTO {$table} ({$columnList}) VALUES\n";
                
                $values = [];
                foreach ($rows as $row) {
                    $rowValues = [];
                    foreach ($row as $value) {
                        $rowValues[] = $this->formatValue($value);
                    }
                    $values[] = '(' . implode(', ', $rowValues) . ')';
                }
                
                $sql .= implode(",\n", $values) . ";\n\n";
            }
            
        } catch (Exception $e) {
            $sql .= "-- Error obteniendo datos de {$table}: " . $e->getMessage() . "\n\n";
            error_log("Error obteniendo datos de {$table}: " . $e->getMessage());
        }
        
        return $sql;
    }

    private function formatValue($value): string
    {
        if ($value === null) {
            return 'NULL';
        } elseif (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        } elseif (is_int($value) || is_float($value)) {
            return strval($value);
        } elseif (is_string($value)) {
            // Escapar comillas simples y caracteres especiales
            $escaped = str_replace("'", "''", $value);
            $escaped = str_replace("\\", "\\\\", $escaped);
            return "'" . $escaped . "'";
        } else {
            // Para tipos complejos, convertir a string
            return "'" . str_replace("'", "''", strval($value)) . "'";
        }
    }

    public function uploadToS3(string $fileName, string $filePath): array
    {
        error_log('Subiendo backup a AWS S3...');

        $fileContent = file_get_contents($filePath);
        if ($fileContent === false) {
            throw new Exception('No se pudo leer el archivo de backup');
        }

        $checksum = hash('sha256', $fileContent);
        $s3Key = $this->backupFolder . '/' . $fileName;

        try {
            $result = $this->s3Client->putObject([
                'Bucket' => $this->bucketName,
                'Key' => $s3Key,
                'Body' => $fileContent,
                'ContentType' => 'application/sql',
                'ServerSideEncryption' => 'AES256',
                'Metadata' => [
                    'backup-date' => date('c'),
                    'database' => $_ENV['DB_NAME'],
                    'type' => 'full-backup-pdo',
                    'checksum' => $checksum,
                    'method' => 'pdo-native'
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
            $baseDatos = conectarPostgreSQL();

            $sql = "INSERT INTO backup (path_al_fichero, checksum_sha256, tamano_bytes, encriptado) 
                    VALUES (?, ?, ?, ?)";

            $stmt = $baseDatos->prepare($sql);
            $stmt->execute([
                $s3Location,
                $checksum,
                $size,
                true
            ]);

            error_log('Backup registrado en BD exitosamente');
        } catch (Exception $e) {
            error_log('Error registrando backup en BD: ' . $e->getMessage());
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
            $dumpResult = $this->createDatabaseDump();
            $uploadResult = $this->uploadToS3($dumpResult['fileName'], $dumpResult['filePath']);
            
            $this->logBackupToDatabase(
                $dumpResult['fileName'],
                $uploadResult['location'],
                $uploadResult['checksum'],
                $uploadResult['size']
            );

            $this->cleanupLocalFile($dumpResult['filePath']);

            error_log('=== BACKUP COMPLETADO EXITOSAMENTE ===');

            return [
                'success' => true,
                'fileName' => $dumpResult['fileName'],
                's3Location' => $uploadResult['location'],
                'size' => $uploadResult['size'],
                'checksum' => $uploadResult['checksum'],
                'method' => $dumpResult['method'],
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
                $deletedCount++;
            } catch (AwsException $e) {
                error_log("Error eliminando {$backup['fileName']}: " . $e->getMessage());
            }
        }

        return ['deleted' => $deletedCount, 'message' => "Eliminados {$deletedCount} backups antiguos"];
    }
}

