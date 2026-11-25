<?php
declare(strict_types=1);
namespace Caleidos\Modules\Reviews;
use Caleidos\Modules\Contracts\ContractRepository;
use Caleidos\Modules\Contracts\WorkflowService;
use DomainException;
final class ReviewsController
{
    public function __construct(private ContractRepository $repository, private WorkflowService $workflow) {}
    public function handle(string $action, array $actor, array $data): array {
        $id = (string) ($data['contract_id'] ?? '');
        switch ($action) {
            case 'approve': $this->workflow->approve($id, $actor); break;
            case 'observe': $this->workflow->observe($id, $actor, $data); break;
            case 'send': $this->workflow->send($id, $actor, (string) ($data['email'] ?? '')); break;
            case 'sign': $this->workflow->sign($id, $actor, (string) ($data['method'] ?? '')); break;
            default: throw new DomainException('Acción de revisión no disponible.');
        }
        return ['page' => 'detail', 'query' => ['id' => $id], 'message' => 'Paso registrado. El seguimiento del contrato está actualizado.'];
    }
    public function show(string $page, array $query): array {
        if ($page === 'workflow') return ['view' => 'Reviews/views/workflow'];
        $q = trim((string) ($query['q'] ?? '')); $status = (string) ($query['status'] ?? '');
        $where = ' WHERE 1 = 1'; $params = [];
        if ($q !== '') { $where .= ' AND (LOWER(c.client) LIKE LOWER(?) OR LOWER(o.comment) LIKE LOWER(?))'; array_push($params, '%' . $q . '%', '%' . $q . '%'); }
        if (in_array($status, ['pending','resolved'], true)) { $where .= ' AND o.resolved = ?'; $params[] = $status === 'resolved' ? 1 : 0; }
        $from = ' FROM observations o JOIN contracts c ON c.id = o.contract_id JOIN users u ON u.id = o.user_id' . $where;
        $total = (int) $this->repository->one('SELECT COUNT(*) AS total' . $from, $params)['total'];
        $pages = max(1, (int) ceil($total / 12)); $currentPage = max(1, min($pages, (int) ($query['p'] ?? 1)));
        return ['view' => 'Reviews/views/observations', 'q' => $q, 'status' => $status, 'allObservations' => $this->repository->all($this->repository->window('SELECT o.*, c.code, c.client, u.name AS user_name' . $from . ' ORDER BY o.resolved, o.created_at DESC, o.id', 12, ($currentPage - 1) * 12), $params), 'totalPages' => $pages, 'currentPage' => $currentPage, 'paginationQuery' => ['page' => $page, 'q' => $q, 'status' => $status]];
    }
}
