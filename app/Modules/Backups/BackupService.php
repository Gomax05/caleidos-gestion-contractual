<?php
declare(strict_types=1);
namespace Caleidos\Modules\Backups;
use Caleidos\Core\Repository;
use DomainException;
use ZipArchive;
final class BackupService
{
    private const TABLES = ['users','contracts','contract_drafts','contract_versions','approvals','observations','contract_events','review_periods'];
    public function __construct(private Repository $repository, private string $storage) {
        if (!is_dir($storage . '/backups')) mkdir($storage . '/backups', 0775, true);
    }
    private function authorize(array $actor): void {
        if ($actor['role'] !== 'admin') throw new DomainException('Solo Administración puede gestionar respaldos.');
        if (!class_exists(ZipArchive::class)) throw new DomainException('Activa la extensión ZIP de PHP para respaldar los documentos.');
    }
    public function create(array $actor): string {
        $this->authorize($actor);
        $path = $this->storage . '/backups/caleidos_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) throw new DomainException('No se pudo crear el respaldo.');
        try {
            $snapshot = $this->repository->transaction(function (): array {
                $tables = [];
                foreach (self::TABLES as $table) $tables[$table] = $this->repository->all('SELECT * FROM ' . $table);
                return $tables;
            });
            $files = [];
            foreach (array_merge($snapshot['contract_versions'], $snapshot['contract_drafts']) as $version) {
                $name = $version['storage_name'];
                if (!$name || isset($files[$name])) continue;
                if (!preg_match('/^[a-f0-9]{32}\.pdf$/', $name) || !is_file($this->storage . '/documents/' . $name)) throw new DomainException('Falta un PDF referenciado. Revisa los documentos antes de crear el respaldo.');
                $file = $this->storage . '/documents/' . $name;
                $files[$name] = ['sha256' => hash_file('sha256', $file), 'size' => filesize($file)];
                $zip->addFile($file, 'documents/' . $name);
            }
            $zip->addFromString('data.json', json_encode(['format' => 'caleidos-demo', 'schema_version' => 2, 'created_at' => date(DATE_ATOM), 'tables' => $snapshot, 'files' => $files], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            if (!$zip->close()) throw new DomainException('No se pudo finalizar el respaldo.');
            return $path;
        } catch (\Throwable $error) { if ($zip->status === ZipArchive::ER_OK) @$zip->close(); if (is_file($path)) unlink($path); throw $error; }
    }
    public function restore(array $actor, string $path): string {
        $this->authorize($actor);
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new DomainException('El archivo no es un respaldo ZIP válido.');
        $createdFiles = [];
        try {
            $stat = $zip->statName('data.json');
            if (!$stat || $stat['size'] > 25 * 1024 * 1024 || $zip->numFiles > 1001) throw new DomainException('El respaldo no tiene un manifiesto válido o excede el límite.');
            $data = json_decode($zip->getFromName('data.json'), true, 512, JSON_THROW_ON_ERROR);
            if (($data['format'] ?? '') !== 'caleidos-demo' || ($data['schema_version'] ?? 0) !== 2 || !isset($data['tables'], $data['files']) || !is_array($data['files'])) throw new DomainException('Formato o versión del respaldo no compatible.');
            if (array_diff(self::TABLES, array_keys($data['tables'])) || array_diff(array_keys($data['tables']), self::TABLES)) throw new DomainException('Las tablas del respaldo no coinciden.');
            // Recuperar contratos no revierte contraseñas ni elimina usuarios creados después.
            $currentRoles = array_column($this->repository->all('SELECT id, role FROM users'), 'role', 'id');
            foreach ($data['tables']['users'] as $savedUser) {
                if (!isset($currentRoles[$savedUser['id']]) || $currentRoles[$savedUser['id']] !== $savedUser['role']) throw new DomainException('El respaldo requiere perfiles o áreas que no están disponibles en esta instalación.');
            }
            $allowed = ['data.json']; $totalBytes = 0;
            foreach ($data['files'] as $name => $meta) {
                if (!preg_match('/^[a-f0-9]{32}\.pdf$/', $name)) throw new DomainException('Nombre de documento no válido en el respaldo.');
                $entry = 'documents/' . $name; $allowed[] = $entry; $entryStat = $zip->statName($entry);
                if (!$entryStat || $entryStat['size'] <= 0 || $entryStat['size'] > 10 * 1024 * 1024 || $entryStat['size'] !== $meta['size']) throw new DomainException('Tamaño de documento no válido.');
                $totalBytes += $entryStat['size'];
                if ($totalBytes > 200 * 1024 * 1024) throw new DomainException('El respaldo supera 200 MB descomprimidos.');
                $content = $zip->getFromName($entry);
                if (!hash_equals((string) $meta['sha256'], hash('sha256', $content)) || !str_contains(substr($content, 0, 1024), '%PDF-')) throw new DomainException('Un documento está incompleto o alterado.');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) if (!in_array($zip->getNameIndex($i), $allowed, true)) throw new DomainException('El ZIP incluye archivos ajenos al respaldo.');
            foreach (array_merge($data['tables']['contract_versions'], $data['tables']['contract_drafts']) as $version) if ($version['storage_name'] && !isset($data['files'][$version['storage_name']])) throw new DomainException('El respaldo no incluye todos los PDF referenciados.');
            foreach ($data['tables'] as $table => $rows) {
                if (!is_array($rows) || count($rows) > 100000) throw new DomainException('Contenido de tabla no válido.');
                foreach ($rows as $row) if (!is_array($row) || !$row || array_filter(array_keys($row), static fn($column) => !preg_match('/^[a-z_]+$/', $column))) throw new DomainException('Columnas de tabla no válidas.');
            }
            // Prepara solo archivos ausentes; permite recuperar un PDF perdido
            // antes de respaldar los registros actuales. No extrae rutas del ZIP.
            foreach ($data['files'] as $name => $meta) {
                $target = $this->storage . '/documents/' . $name;
                if (is_file($target)) {
                    if (!hash_equals($meta['sha256'], hash_file('sha256', $target))) throw new DomainException('Un archivo existente tiene contenido diferente. No se sobrescribió.');
                } else {
                    if (file_put_contents($target, $zip->getFromName('documents/' . $name), LOCK_EX) === false) throw new DomainException('No se pudo recuperar un documento.');
                    $createdFiles[] = $target;
                }
            }
            $safety = $this->create($actor);
            $this->repository->transaction(function () use ($data): void {
                foreach (array_reverse(array_slice(self::TABLES, 1)) as $table) $this->repository->execute('DELETE FROM ' . $table);
                foreach (array_slice(self::TABLES, 1) as $table) foreach ($data['tables'][$table] as $row) $this->repository->insert($table, $row);
            });
            return basename($safety);
        } catch (\Throwable $error) {
            foreach ($createdFiles as $file) if (is_file($file)) unlink($file);
            if ($error instanceof DomainException) throw $error;
            throw new DomainException('No se pudo restaurar el respaldo. Los registros anteriores se conservaron.', 0, $error);
        } finally { $zip->close(); }
    }
    public function listing(array $actor): array {
        $this->authorize($actor);
        $files = glob($this->storage . '/backups/caleidos_*.zip') ?: []; rsort($files);
        return array_map(static fn($file) => ['name' => basename($file), 'size' => filesize($file), 'created_at' => date('d/m/Y H:i', filemtime($file))], $files);
    }
    public function download(array $actor, string $name): void {
        $this->authorize($actor);
        if (!preg_match('/^caleidos_\d{8}_\d{6}_[a-f0-9]{8}\.zip$/', $name) || !is_file($this->storage . '/backups/' . $name)) throw new DomainException('Respaldo no encontrado.');
        $file = $this->storage . '/backups/' . $name;
        header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="' . $name . '"'); header('Content-Length: ' . filesize($file)); readfile($file);
    }
}
