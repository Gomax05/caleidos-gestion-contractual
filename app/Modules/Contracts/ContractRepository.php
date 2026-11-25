<?php
declare(strict_types=1);
namespace Caleidos\Modules\Contracts;
use Caleidos\Core\Repository;
use DomainException;
final class ContractRepository extends Repository
{
    public function listContracts(): array {
        return $this->all('SELECT c.*, u.name AS owner_name FROM contracts c JOIN users u ON u.id = c.owner_id ORDER BY c.created_at DESC, c.code DESC');
    }
    public function find(string $id): array {
        $contract = $this->one('SELECT c.*, u.name AS owner_name, a.name AS assignee_name FROM contracts c JOIN users u ON u.id = c.owner_id LEFT JOIN users a ON a.id = c.assignee_id WHERE c.id = ?', [$id]);
        if (!$contract) throw new DomainException('Contrato no encontrado.');
        return $contract;
    }
    public function details(string $id): array {
        $c = $this->find($id);
        $c['versions'] = $this->all('SELECT v.*, u.name AS author_name FROM contract_versions v JOIN users u ON u.id = v.author_id WHERE v.contract_id = ? ORDER BY v.version_number', [$id]);
        $c['approvals'] = $this->all('SELECT a.*, u.name AS user_name FROM approvals a JOIN users u ON u.id = a.user_id WHERE a.contract_id = ? ORDER BY a.phase', [$id]);
        $c['observations'] = $this->all('SELECT o.*, u.name AS user_name FROM observations o JOIN users u ON u.id = o.user_id WHERE o.contract_id = ? ORDER BY o.created_at DESC', [$id]);
        $c['events'] = $this->events($id);
        $c['periods'] = $this->all('SELECT p.*, u.name AS user_name FROM review_periods p JOIN users u ON u.id = p.user_id WHERE p.contract_id = ? ORDER BY p.started_at, p.id', [$id]);
        return $c;
    }
    public function events(?string $id = null): array {
        return $this->all('SELECT e.*, u.name AS user_name, u.role, c.code, c.client FROM contract_events e JOIN users u ON u.id = e.user_id JOIN contracts c ON c.id = e.contract_id' . ($id ? ' WHERE e.contract_id = ?' : '') . ' ORDER BY e.created_at DESC', $id ? [$id] : []);
    }
    public function event(string $contractId, array $actor, string $action, string $detail): void {
        $at = date(DATE_ATOM);
        $this->insert('contract_events', ['id' => self::id(), 'contract_id' => $contractId, 'user_id' => $actor['id'], 'action' => $action, 'detail' => $detail, 'created_at' => $at]);
        $this->execute('UPDATE contracts SET updated_at = ? WHERE id = ?', [$at, $contractId]);
    }
    public function search(array $filters, array $actor, bool $mine = false, bool $export = false): array {
        $clauses = ['1 = 1']; $parameters = [];
        if ($mine) {
            $clauses[] = "c.status <> 'signed'";
            if ($actor['role'] !== 'admin') { $clauses[] = 'c.assignee_id = ?'; $parameters[] = $actor['id']; }
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            // Comodines tratados como texto, no como filtros implícitos del usuario.
            $like = '%' . str_replace(['!', '%', '_', '['], ['!!', '!%', '!_', '!['], $q) . '%';
            $clauses[] = "(LOWER(c.client) LIKE LOWER(?) ESCAPE '!' OR LOWER(c.code) LIKE LOWER(?) ESCAPE '!' OR LOWER(c.title) LIKE LOWER(?) ESCAPE '!' OR LOWER(c.service) LIKE LOWER(?) ESCAPE '!')";
            array_push($parameters, $like, $like, $like, $like);
        }
        foreach (['status', 'service'] as $field) if (($filters[$field] ?? '') !== '') { $clauses[] = "c.$field = ?"; $parameters[] = (string) $filters[$field]; }
        if (($filters['phase'] ?? '') !== '') { $clauses[] = "c.status = 'review' AND c.stage = ?"; $parameters[] = (int) $filters['phase']; }
        $urgency = $filters['urgency'] ?? '';
        if ($urgency === 'overdue') { $clauses[] = "c.status <> 'signed' AND c.due_date < ?"; $parameters[] = date('Y-m-d'); }
        if ($urgency === 'today') { $clauses[] = "c.status <> 'signed' AND c.due_date = ?"; $parameters[] = date('Y-m-d'); }
        if ($urgency === 'expiring') { $clauses[] = "c.status IN ('approved','sent','signed') AND c.end_date BETWEEN ? AND ?"; array_push($parameters, date('Y-m-d'), date('Y-m-d', strtotime('+30 days'))); }
        $from = ' FROM contracts c JOIN users u ON u.id = c.owner_id LEFT JOIN users a ON a.id = c.assignee_id WHERE ' . implode(' AND ', $clauses);
        $total = (int) $this->one('SELECT COUNT(*) AS total' . $from, $parameters)['total'];
        $pages = max(1, (int) ceil($total / 8)); $page = max(1, min($pages, (int) ($filters['p'] ?? 1)));
        $sql = 'SELECT c.*, u.name AS owner_name, a.name AS assignee_name' . $from . ($mine ? ' ORDER BY c.due_date ASC, c.created_at DESC, c.id DESC' : ' ORDER BY c.created_at DESC, c.id DESC');
        return ['items' => $this->all($export ? $sql : $this->window($sql, 8, ($page - 1) * 8), $parameters), 'total' => $total, 'pages' => $pages, 'currentPage' => $page];
    }
    public function pendingCount(array $actor): int {
        return (int) $this->one("SELECT COUNT(*) AS total FROM contracts WHERE status <> 'signed'" . ($actor['role'] === 'admin' ? '' : ' AND assignee_id = ?'), $actor['role'] === 'admin' ? [] : [$actor['id']])['total'];
    }
    public function overview(array $actor): array {
        $counts = ['total' => 0, 'review' => 0, 'observed' => 0, 'approved' => 0, 'sent' => 0, 'signed' => 0];
        foreach ($this->all('SELECT status, COUNT(*) AS total FROM contracts GROUP BY status') as $row) { $counts[$row['status']] = (int) $row['total']; $counts['total'] += (int) $row['total']; }
        $counts['pending'] = $this->pendingCount($actor);
        $counts['overdue'] = $this->search(['urgency' => 'overdue'], $actor, true)['total'];
        $counts['expiring'] = $this->search(['urgency' => 'expiring'], $actor)['total'];
        $stages = array_fill(0, 4, 0);
        foreach ($this->all("SELECT stage, COUNT(*) AS total FROM contracts WHERE status = 'review' GROUP BY stage") as $row) $stages[(int) $row['stage']] = (int) $row['total'];
        return ['counts' => $counts, 'stageCounts' => $stages];
    }
    public function recentEvents(int $limit = 4): array {
        return $this->all($this->window('SELECT e.*, u.name AS user_name, c.client FROM contract_events e JOIN users u ON u.id = e.user_id JOIN contracts c ON c.id = e.contract_id ORDER BY e.created_at DESC, e.id DESC', $limit));
    }
    public function timeByPhase(): array {
        $rows = $this->all("SELECT phase, started_at, completed_at FROM review_periods WHERE completed_at IS NOT NULL AND task_type = 'review'");
        $totals = [];
        foreach ($rows as $row) { $phase = (int) $row['phase']; $totals[$phase]['seconds'] = ($totals[$phase]['seconds'] ?? 0) + max(0, strtotime($row['completed_at']) - strtotime($row['started_at'])); $totals[$phase]['count'] = ($totals[$phase]['count'] ?? 0) + 1; }
        return $totals;
    }
}
