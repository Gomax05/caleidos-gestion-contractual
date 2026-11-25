<?php
declare(strict_types=1);
namespace Caleidos\Modules\Contracts;
use Caleidos\Core\CsvResponse;
use Caleidos\Modules\Documents\ComparisonService;
use DomainException;
final class ContractsController
{
    public function __construct(private ContractRepository $repository, private ContractService $service, private DraftService $drafts, private AssignmentService $assignments) {}
    public function show(string $page, array $query, array $actor): array {
        if (in_array($page, ['new','drafts'], true) && !in_array($actor['role'], ['commercial','admin'], true)) throw new DomainException('Tu perfil no registra solicitudes.');
        if ($page === 'new') {
            $draft = !empty($query['draft_id']) ? $this->drafts->find((string) $query['draft_id'], $actor) : null;
            return ['view' => 'Contracts/views/create', 'draft' => $draft, 'formData' => $draft['data'] ?? []];
        }
        if ($page === 'drafts') return ['view' => 'Contracts/views/drafts', 'draftResult' => $this->drafts->page($actor, (int) ($query['p'] ?? 1))];
        if ($page === 'detail') {
            $contract = $this->repository->details((string) ($query['id'] ?? ''));
            $comparison = null; $before = null; $after = null;
            if (count($contract['versions']) > 1) {
                $byNumber = array_column($contract['versions'], null, 'version_number');
                $before = $byNumber[(int) ($query['before'] ?? count($byNumber) - 1)] ?? reset($byNumber);
                $after = $byNumber[(int) ($query['after'] ?? count($byNumber))] ?? end($byNumber);
                $comparison = (new ComparisonService())->compare($before['clause_text'], $after['clause_text']);
            }
            $taskRole = $contract['status'] === 'review' ? WorkflowService::PHASE_ROLES[(int) $contract['stage']] : 'commercial';
            return ['view' => 'Contracts/views/detail', 'contract' => $contract, 'comparison' => $comparison, 'before' => $before, 'after' => $after, 'eligibleAssignees' => $this->repository->all('SELECT id, name FROM users WHERE role = ? AND active = 1 ORDER BY name', [$taskRole])];
        }
        $result = $this->repository->search($query, $actor, $page === 'reviews');
        return ['view' => 'Contracts/views/list', 'visibleContracts' => $result['items'], 'totalContracts' => $result['total'], 'totalPages' => $result['pages'], 'currentPage' => $result['currentPage'], 'q' => (string) ($query['q'] ?? ''), 'status' => (string) ($query['status'] ?? ''), 'phase' => (string) ($query['phase'] ?? ''), 'service' => (string) ($query['service'] ?? ''), 'urgency' => (string) ($query['urgency'] ?? '')];
    }
    public function handle(string $action, array $actor, array $data, array $files): array {
        if ($action === 'save_draft') {
            $id = $this->drafts->save($actor, $data, $files['document'] ?? []);
            return ['page' => 'new', 'query' => ['draft_id' => $id], 'message' => 'Borrador guardado. Todavía no se ha enviado a Legal.'];
        }
        $id = (string) ($data['contract_id'] ?? '');
        if ($action === 'create_contract') $id = !empty($data['draft_id']) ? $this->drafts->submit((string) $data['draft_id'], $actor, $data, $files['document'] ?? []) : $this->service->create($actor, $data, $files['document'] ?? []);
        elseif ($action === 'add_version') $this->service->addVersion($id, $actor, $data, $files['document'] ?? []);
        elseif ($action === 'assign_task') $this->assignments->update($id, $actor, $data);
        else throw new DomainException('Acción de contratos no disponible.');
        return ['page' => 'detail', 'query' => ['id' => $id], 'message' => $action === 'create_contract' ? 'Solicitud enviada a Legal con responsable y fecha límite.' : 'Cambios guardados correctamente.'];
    }
    public function export(array $query, array $actor): void {
        CsvResponse::send($this->repository->search($query, $actor, ($query['scope'] ?? '') === 'mine', true)['items'], ['code','title','client','service','amount','currency','status','stage','assignee_name','due_date'], 'contratos');
    }
}
