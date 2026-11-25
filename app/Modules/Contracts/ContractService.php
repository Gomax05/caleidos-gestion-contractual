<?php
declare(strict_types=1);
namespace Caleidos\Modules\Contracts;
use Caleidos\Core\Repository;
use Caleidos\Modules\Documents\DocumentService;
use DomainException;
final class ContractService
{
    public const SERVICES = ['Migración Cloud', 'Servicios administrados', 'Consultoría tecnológica', 'Desarrollo de software', 'Soporte y mantenimiento'];
    public function __construct(private ContractRepository $repository, private WorkflowService $workflow, private DocumentService $documents) {}
    public function create(array $actor, array $data, array $file, ?array $savedDocument = null, ?string $draftId = null): string {
        if (!in_array($actor['role'], ['commercial', 'admin'], true)) throw new DomainException('Solo Comercial o Administración puede registrar solicitudes.');
        $fields = [];
        foreach (['title' => 160, 'client' => 100, 'email' => 150, 'service' => 100, 'description' => 10000] as $key => $limit) {
            $fields[$key] = trim((string) ($data[$key] ?? ''));
            if (($key !== 'description' && $fields[$key] === '') || mb_strlen($fields[$key]) > $limit) throw new DomainException('Revisa los datos obligatorios y la longitud de los campos.');
        }
        if (!filter_var($fields['email'], FILTER_VALIDATE_EMAIL) || !in_array($fields['service'], self::SERVICES, true)) throw new DomainException('Correo o servicio no válido.');
        $amount = filter_var($data['amount'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($amount === false || !is_finite($amount) || $amount <= 0 || $amount > 9999999999999.99 || !in_array($data['currency'] ?? '', ['USD', 'PEN'], true)) throw new DomainException('Revisa el monto y la moneda.');
        foreach (['start_date', 'end_date'] as $key) {
            $fields[$key] = (string) ($data[$key] ?? '');
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $fields[$key]);
            if (!$date || $date->format('Y-m-d') !== $fields[$key]) throw new DomainException('Fecha de vigencia no válida.');
        }
        if ($fields['end_date'] < $fields['start_date']) throw new DomainException('El fin de vigencia no puede ser anterior al inicio.');
        $text = (string) ($data['clause_text'] ?? '');
        if (mb_strlen($text) > 20000) throw new DomainException('Las cláusulas admiten hasta 20000 caracteres.');
        $days = filter_var($data['review_days'] ?? 3, FILTER_VALIDATE_INT);
        if ($days === false || $days < 1 || $days > 30) throw new DomainException('El plazo por tarea debe estar entre 1 y 30 días calendario.');
        $stored = ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE && $savedDocument ? $savedDocument : $this->documents->upload($file);
        try {
            return $this->repository->transaction(function () use ($actor, $data, $fields, $amount, $text, $stored, $days, $draftId): string {
                if ($draftId !== null) {
                    $draft = $this->repository->one('SELECT owner_id FROM contract_drafts WHERE id = ?', [$draftId]);
                    if (!$draft || ($actor['role'] !== 'admin' && $draft['owner_id'] !== $actor['id'])) throw new DomainException('El borrador ya fue enviado o no está disponible.');
                }
                $id = Repository::id(); $at = date(DATE_ATOM);
                $code = 'CL-' . date('Y') . '-' . strtoupper(substr($id, 0, 8));
                $this->repository->insert('contracts', ['id' => $id, 'code' => $code] + $fields + ['amount' => round($amount, 2), 'currency' => $data['currency'], 'status' => 'review', 'stage' => 0, 'owner_id' => $actor['id'], 'review_days' => $days, 'created_at' => $at, 'updated_at' => $at]);
                $this->repository->insert('contract_versions', ['id' => Repository::id(), 'contract_id' => $id, 'version_number' => 1] + $stored + ['clause_text' => $text, 'summary' => 'Documento inicial de la solicitud.', 'author_id' => $actor['id'], 'created_at' => $at]);
                $this->repository->event($id, $actor, 'Solicitud registrada', 'Versión 1 adjunta. Revisión asignada a Legal.');
                (new AssignmentService($this->repository))->transition($id, 'review', 0);
                if ($draftId !== null) $this->repository->execute('DELETE FROM contract_drafts WHERE id = ?', [$draftId]);
                return $id;
            });
        } catch (\Throwable $error) { if (!$savedDocument || $stored['storage_name'] !== $savedDocument['storage_name']) $this->documents->remove($stored['storage_name']); throw $error; }
    }
    public function addVersion(string $id, array $actor, array $data, array $file): void {
        $c = $this->repository->find($id);
        if (!WorkflowService::canVersion($c, $actor)) throw new DomainException('Solo Comercial puede versionar un contrato abierto.');
        $summary = trim((string) ($data['summary'] ?? ''));
        $text = (string) ($data['clause_text'] ?? '');
        if ($summary === '' || mb_strlen($summary) > 300 || mb_strlen($text) > 20000) throw new DomainException('Revisa el resumen de cambios y el texto de cláusulas.');
        $areas = $data['areas'] ?? [];
        if (!is_array($areas) || !$areas) throw new DomainException('Selecciona las áreas afectadas.');
        $stored = $this->documents->upload($file);
        try {
            $this->repository->transaction(function () use ($id, $actor, $summary, $text, $areas, $stored): void {
                $c = $this->repository->details($id);
                $version = ['id' => Repository::id(), 'contract_id' => $id, 'version_number' => count($c['versions']) + 1] + $stored + ['clause_text' => $text, 'summary' => $summary, 'author_id' => $actor['id'], 'created_at' => date(DATE_ATOM)];
                $this->workflow->applyVersion($c, $actor, $version, $areas);
            });
        } catch (\Throwable $error) { $this->documents->remove($stored['storage_name']); throw $error; }
    }
}
