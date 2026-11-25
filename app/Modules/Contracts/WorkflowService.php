<?php
declare(strict_types=1);
namespace Caleidos\Modules\Contracts;
use Caleidos\Core\Repository;
use DomainException;
final class WorkflowService
{
    public const PHASES = ['Legal', 'Comercial', 'Administración y Finanzas', 'Gerencia'];
    public const PHASE_ROLES = ['legal', 'commercial', 'finance', 'management'];
    public const STATUSES = ['review' => 'En revisión', 'observed' => 'Con observaciones', 'approved' => 'Aprobado', 'sent' => 'Pendiente de firma', 'signed' => 'Firmado'];
    private AssignmentService $assignments;
    public function __construct(private ContractRepository $repository) { $this->assignments = new AssignmentService($repository); }
    public static function canReview(array $contract, array $actor): bool {
        return $contract['status'] === 'review' && (self::PHASE_ROLES[(int) $contract['stage']] ?? '') === $actor['role'] && (empty($contract['assignee_id']) || $contract['assignee_id'] === $actor['id']);
    }
    public static function canVersion(array $contract, array $actor): bool {
        return $actor['role'] === 'commercial' && in_array($contract['status'], ['review', 'observed', 'approved', 'sent'], true);
    }
    public function approve(string $id, array $actor): void {
        $this->repository->transaction(function () use ($id, $actor): void {
            $c = $this->repository->details($id);
            if (!self::canReview($c, $actor)) throw new DomainException('Solo el responsable de la fase activa puede aprobar.');
            $stage = (int) $c['stage'];
            $phases = array_map('intval', array_column($c['approvals'], 'phase'));
            for ($i = 0; $i < $stage; $i++) if (!in_array($i, $phases, true)) throw new DomainException('Faltan aprobaciones anteriores.');
            $this->repository->insert('approvals', ['id' => Repository::id(), 'contract_id' => $id, 'phase' => $stage, 'version_number' => count($c['versions']), 'user_id' => $actor['id'], 'created_at' => date(DATE_ATOM)]);
            $this->repository->execute('UPDATE contracts SET stage = ?, status = ? WHERE id = ?', [$stage + 1, $stage === 3 ? 'approved' : 'review', $id]);
            $this->assignments->transition($id, $stage === 3 ? 'approved' : 'review', $stage + 1);
            $this->repository->event($id, $actor, 'Aprobación de ' . self::PHASES[$stage], 'Versión ' . count($c['versions']) . ' validada.');
        });
    }
    public function observe(string $id, array $actor, array $data): void {
        $this->repository->transaction(function () use ($id, $actor, $data): void {
            $c = $this->repository->details($id);
            $fromClient = ($data['from_client'] ?? '') === '1';
            if ($fromClient ? !($actor['role'] === 'commercial' && in_array($c['status'], ['approved','sent'], true)) : !self::canReview($c, $actor)) throw new DomainException('Tu perfil no puede observar esta fase.');
            $text = trim((string) ($data['comment'] ?? ''));
            $category = trim((string) ($data['category'] ?? 'General'));
            if ($text === '' || mb_strlen($text) > 5000 || $category === '' || mb_strlen($category) > 100) throw new DomainException('Describe la observación (máximo 5000 caracteres) y su categoría.');
            $stage = (int) $c['stage'];
            if ($fromClient) {
                $stage = filter_var($data['affected_phase'] ?? null, FILTER_VALIDATE_INT);
                if ($stage === false || $stage < 0 || $stage > 3) throw new DomainException('Selecciona un área válida.');
                $this->repository->execute('DELETE FROM approvals WHERE contract_id = ? AND phase >= ?', [$id, $stage]);
            }
            $this->repository->insert('observations', ['id' => Repository::id(), 'contract_id' => $id, 'user_id' => $actor['id'], 'category' => $category, 'comment' => $text, 'version_number' => count($c['versions']), 'from_client' => (int) $fromClient, 'resolved' => 0, 'created_at' => date(DATE_ATOM)]);
            $this->repository->execute('UPDATE contracts SET status = ?, stage = ? WHERE id = ?', ['observed', $stage, $id]);
            $this->assignments->transition($id, 'observed', $stage);
            $this->repository->event($id, $actor, $fromClient ? 'Observación del cliente' : 'Correcciones solicitadas', $category . ': ' . $text);
        });
    }
    public function applyVersion(array $c, array $actor, array $version, array $areas): void {
        if (!self::canVersion($c, $actor)) throw new DomainException('Solo Comercial puede versionar un contrato abierto.');
        if (!$areas) throw new DomainException('Selecciona al menos un área afectada.');
        foreach ($areas as $area) if (filter_var($area, FILTER_VALIDATE_INT) === false || (int) $area < 0 || (int) $area > 3) throw new DomainException('Área afectada no válida.');
        $stage = min((int) $c['stage'], ...array_map('intval', $areas));
        $this->repository->insert('contract_versions', $version);
        $this->repository->execute('DELETE FROM approvals WHERE contract_id = ? AND phase >= ?', [$c['id'], $stage]);
        $this->repository->execute('UPDATE observations SET resolved = 1, resolved_version = ? WHERE contract_id = ? AND resolved = 0', [$version['version_number'], $c['id']]);
        $this->repository->execute('UPDATE contracts SET stage = ?, status = ? WHERE id = ?', [$stage, 'review', $c['id']]);
        $this->assignments->transition($c['id'], 'review', $stage);
        $this->repository->event($c['id'], $actor, 'Nueva versión ' . $version['version_number'], $version['summary'] . ' Revisión desde ' . self::PHASES[$stage] . '.');
    }
    public function send(string $id, array $actor, string $email): void {
        $this->repository->transaction(function () use ($id, $actor, $email): void {
            $c = $this->repository->details($id);
            if ($actor['role'] !== 'commercial' || $c['status'] !== 'approved' || count($c['approvals']) !== 4) throw new DomainException('El contrato necesita las cuatro aprobaciones para enviarse.');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) throw new DomainException('Correo del cliente no válido.');
            $this->repository->execute('UPDATE contracts SET status = ?, email = ? WHERE id = ?', ['sent', $email, $id]);
            $this->assignments->transition($id, 'sent', 4);
            $this->repository->event($id, $actor, 'Envío al cliente simulado', 'Destinatario: ' . $email . '. No se envió un correo real.');
        });
    }
    public function sign(string $id, array $actor, string $method): void {
        $this->repository->transaction(function () use ($id, $actor, $method): void {
            $c = $this->repository->find($id);
            if ($actor['role'] !== 'commercial' || $c['status'] !== 'sent') throw new DomainException('Solo Comercial puede registrar la firma de un contrato enviado.');
            if (!in_array($method, ['Firma manual', 'Firma digital del cliente', 'Firma de Gerencia'], true)) throw new DomainException('Modalidad de firma no válida.');
            $this->repository->execute('UPDATE contracts SET status = ? WHERE id = ?', ['signed', $id]);
            $this->assignments->transition($id, 'signed', 4);
            $this->repository->event($id, $actor, 'Firma registrada (demo)', $method . '. No se aplica una firma electrónica al PDF.');
        });
    }
}
