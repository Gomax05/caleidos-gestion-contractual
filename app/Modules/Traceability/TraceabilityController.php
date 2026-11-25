<?php
declare(strict_types=1);
namespace Caleidos\Modules\Traceability;
use Caleidos\Modules\Contracts\ContractRepository;
use Caleidos\Core\CsvResponse;
final class TraceabilityController
{
    public function __construct(private ContractRepository $repository) {}
    public function show(array $query, bool $export = false): array {
        $q = trim((string) ($query['q'] ?? '')); $role = (string) ($query['role'] ?? ''); $params = [];
        $from = ' FROM contract_events e JOIN contracts c ON c.id = e.contract_id JOIN users u ON u.id = e.user_id WHERE 1 = 1';
        if ($q !== '') { $from .= ' AND (LOWER(c.client) LIKE LOWER(?) OR LOWER(u.name) LIKE LOWER(?) OR LOWER(e.action) LIKE LOWER(?) OR LOWER(e.detail) LIKE LOWER(?))'; array_push($params, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%', '%' . $q . '%'); }
        if ($role !== '') { $from .= ' AND u.role = ?'; $params[] = $role; }
        $total = (int) $this->repository->one('SELECT COUNT(*) AS total' . $from, $params)['total'];
        $pages = max(1, (int) ceil($total / 15)); $currentPage = max(1, min($pages, (int) ($query['p'] ?? 1)));
        $sql = 'SELECT e.*, c.code, c.client, u.name AS user_name, u.role' . $from . ' ORDER BY e.created_at DESC, e.id DESC';
        $events = $this->repository->all($export ? $sql : $this->repository->window($sql, 15, ($currentPage - 1) * 15), $params);
        return ['view' => 'Traceability/views/timeline', 'q' => $q, 'events' => $events, 'totalPages' => $pages, 'currentPage' => $currentPage, 'paginationQuery' => ['page' => 'timeline', 'q' => $q, 'role' => $role]];
    }
    public function export(array $query): void { CsvResponse::send($this->show($query, true)['events'], ['created_at','code','client','user_name','action','detail'], 'historial'); }
}
