<?php
declare(strict_types=1);
namespace Caleidos\Modules\Documents;
use Caleidos\Modules\Contracts\ContractRepository;
use Caleidos\Modules\Contracts\DraftService;
use DomainException;
final class DocumentsController
{
    public function __construct(private ContractRepository $repository, private DocumentService $documents, private DraftService $drafts) {}
    public function download(string $id, bool $inline, array $actor, bool $draft = false): void {
        if ($draft) {
            $version = $this->drafts->find($id, $actor);
            if (!$version['storage_name']) throw new DomainException('El borrador aún no tiene PDF.');
            $version['version_number'] = 0; $contract = [];
        } else {
            $version = $this->repository->one('SELECT * FROM contract_versions WHERE id = ?', [$id]);
            if (!$version) throw new DomainException('Documento no encontrado.');
            $contract = $this->repository->find($version['contract_id']);
        }
        $content = $this->documents->contents($version, $contract);
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="contrato-v' . (int) $version['version_number'] . '.pdf"');
        header('Content-Length: ' . strlen($content)); echo $content;
    }
    public function show(array $query): array {
        $q = trim((string) ($query['q'] ?? '')); $params = [];
        $from = ' FROM contract_versions v JOIN contracts c ON c.id = v.contract_id JOIN users u ON u.id = v.author_id';
        if ($q !== '') { $from .= ' WHERE LOWER(c.client) LIKE LOWER(?) OR LOWER(c.code) LIKE LOWER(?) OR LOWER(v.original_name) LIKE LOWER(?)'; array_push($params, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%'); }
        $total = (int) $this->repository->one('SELECT COUNT(*) AS total' . $from, $params)['total'];
        $pages = max(1, (int) ceil($total / 12)); $currentPage = max(1, min($pages, (int) ($query['p'] ?? 1)));
        return ['view' => 'Documents/views/list', 'q' => $q, 'allVersions' => $this->repository->all($this->repository->window('SELECT v.*, c.code, c.client, u.name AS author_name' . $from . ' ORDER BY v.created_at DESC, v.id DESC', 12, ($currentPage - 1) * 12), $params), 'totalPages' => $pages, 'currentPage' => $currentPage, 'paginationQuery' => ['page' => 'documents', 'q' => $q]];
    }
}
