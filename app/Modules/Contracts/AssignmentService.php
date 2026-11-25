<?php
declare(strict_types=1);
namespace Caleidos\Modules\Contracts;
use Caleidos\Core\Repository;
use DomainException;
final class AssignmentService
{
    public function __construct(private ContractRepository $repository) {}
    public function initializeMissing(): void {
        $rows = $this->repository->all("SELECT * FROM contracts WHERE assigned_at IS NULL AND status <> 'signed'");
        if (!$rows) return;
        $this->repository->transaction(function () use ($rows): void {
            foreach ($rows as $c) {
                $this->transition($c['id'], $c['status'], (int) $c['stage']);
                // Conserva el plazo estimado desde el último movimiento; la medición
                // de atención comienza ahora para no inventar tiempos históricos.
                $due = (new \DateTimeImmutable($c['updated_at']))->modify('+' . (int) $c['review_days'] . ' days')->format('Y-m-d');
                $this->repository->execute('UPDATE contracts SET due_date = ? WHERE id = ?', [$due, $c['id']]);
            }
        });
    }
    public function transition(string $id, string $status, int $stage, ?string $at = null): void {
        $at ??= date(DATE_ATOM);
        $c = $this->repository->find($id);
        $this->repository->execute('UPDATE review_periods SET completed_at = ?, outcome = ? WHERE contract_id = ? AND completed_at IS NULL', [$at, $status, $id]);
        if ($status === 'signed') {
            $this->repository->execute('UPDATE contracts SET assignee_id = NULL, due_date = NULL, assigned_at = ? WHERE id = ?', [$at, $id]);
            return;
        }
        $role = $status === 'review' ? (WorkflowService::PHASE_ROLES[$stage] ?? '') : 'commercial';
        $owner = $this->repository->one('SELECT id FROM users WHERE id = ? AND role = ? AND active = 1', [$c['owner_id'], $role]);
        $assignees = $owner ? [$owner] : $this->repository->all('SELECT id FROM users WHERE role = ? AND active = 1 ORDER BY id', [$role]);
        if (!$assignees) throw new DomainException('No hay un perfil activo para atender la siguiente etapa.');
        $assignee = $assignees[0]['id'];
        $due = (new \DateTimeImmutable($at))->modify('+' . (int) $c['review_days'] . ' days')->format('Y-m-d');
        $this->repository->execute('UPDATE contracts SET assignee_id = ?, due_date = ?, assigned_at = ? WHERE id = ?', [$assignee, $due, $at, $id]);
        $this->repository->insert('review_periods', ['id' => Repository::id(), 'contract_id' => $id, 'user_id' => $assignee, 'phase' => min($stage, 3), 'task_type' => $status, 'started_at' => $at]);
    }
    public function update(string $id, array $actor, array $data): void {
        $this->repository->transaction(function () use ($id, $actor, $data): void {
            $c = $this->repository->find($id);
            if ($actor['role'] !== 'admin' && !($actor['role'] === 'commercial' && $c['owner_id'] === $actor['id'])) throw new DomainException('Solo el responsable comercial o Administración puede organizar el seguimiento.');
            if ($c['status'] === 'signed') throw new DomainException('El acuerdo firmado ya no tiene tareas pendientes.');
            $due = (string) ($data['due_date'] ?? '');
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $due);
            if (!$date || $date->format('Y-m-d') !== $due) throw new DomainException('Selecciona una fecha límite válida.');
            $role = $c['status'] === 'review' ? WorkflowService::PHASE_ROLES[(int) $c['stage']] : 'commercial';
            $assignee = $this->repository->one('SELECT id FROM users WHERE id = ? AND role = ? AND active = 1', [(string) ($data['assignee_id'] ?? ''), $role]);
            if (!$assignee) throw new DomainException('El responsable debe pertenecer al área de la tarea actual.');
            $this->repository->execute('UPDATE contracts SET assignee_id = ?, due_date = ? WHERE id = ?', [$assignee['id'], $due, $id]);
            $this->repository->execute('UPDATE review_periods SET user_id = ? WHERE contract_id = ? AND completed_at IS NULL', [$assignee['id'], $id]);
            $this->repository->event($id, $actor, 'Seguimiento actualizado', 'Responsable asignado y fecha límite: ' . $due . '.');
        });
    }
}
