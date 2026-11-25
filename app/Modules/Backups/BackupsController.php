<?php
declare(strict_types=1);
namespace Caleidos\Modules\Backups;
use DomainException;
final class BackupsController
{
    public function __construct(private BackupService $service) {}
    public function show(array $actor): array { return ['view' => 'Backups/views/list', 'backups' => $this->service->listing($actor)]; }
    public function download(array $actor, string $name): void { $this->service->download($actor, $name); }
    public function handle(string $action, array $actor, array $data, array $files): array {
        if ($action === 'create_backup') {
            $this->service->create($actor);
            return ['page' => 'backups', 'message' => 'Respaldo creado. Incluye los contratos, borradores y PDF.'];
        }
        if ($action !== 'restore_backup') throw new DomainException('Acción de respaldo no disponible.');
        $file = $files['backup'] ?? [];
        if (($data['confirm_restore'] ?? '') !== 'RESTAURAR') throw new DomainException('Escribe RESTAURAR para confirmar la recuperación.');
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']) || filesize($file['tmp_name']) > 64 * 1024 * 1024) throw new DomainException('Selecciona un respaldo ZIP de hasta 64 MB.');
        $safety = $this->service->restore($actor, $file['tmp_name']);
        return ['page' => 'backups', 'message' => 'Datos recuperados. El estado anterior se conservó en ' . $safety . '.'];
    }
}
