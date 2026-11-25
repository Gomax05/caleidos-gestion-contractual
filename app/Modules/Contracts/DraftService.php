<?php
declare(strict_types=1);
namespace Caleidos\Modules\Contracts;
use Caleidos\Core\Repository;
use Caleidos\Modules\Documents\DocumentService;
use DomainException;
final class DraftService
{
    public function __construct(private ContractRepository $repository, private ContractService $contracts, private DocumentService $documents) {}
    public function find(string $id, array $actor): array {
        $draft = $this->repository->one('SELECT * FROM contract_drafts WHERE id = ?', [$id]);
        if (!$draft || ($actor['role'] !== 'admin' && $draft['owner_id'] !== $actor['id'])) throw new DomainException('No tienes acceso a este borrador.');
        $draft['data'] = json_decode($draft['payload'], true, 512, JSON_THROW_ON_ERROR);
        return $draft;
    }
    public function save(array $actor, array $data, array $file): string {
        if (!in_array($actor['role'], ['commercial', 'admin'], true)) throw new DomainException('Solo Comercial o Administración puede guardar borradores.');
        $id = (string) ($data['draft_id'] ?? ''); $existing = $id !== '' ? $this->find($id, $actor) : null;
        $payload = [];
        foreach (['title' => 160, 'client' => 100, 'email' => 150, 'service' => 100, 'amount' => 30, 'currency' => 3, 'start_date' => 10, 'end_date' => 10, 'description' => 10000, 'clause_text' => 20000, 'review_days' => 2] as $name => $length) {
            $value = trim((string) ($data[$name] ?? ''));
            if (mb_strlen($value) > $length) throw new DomainException('El campo ' . $name . ' supera la longitud permitida.');
            $payload[$name] = $value;
        }
        $stored = ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE ? null : $this->documents->upload($file);
        $id = $existing['id'] ?? Repository::id(); $at = date(DATE_ATOM);
        try {
            $this->repository->transaction(function () use ($id, $actor, $payload, $existing, $stored, $at): void {
                $file = $stored ?? ['original_name' => $existing['original_name'] ?? null, 'storage_name' => $existing['storage_name'] ?? null, 'file_size' => $existing['file_size'] ?? 0];
                if ($existing) $this->repository->execute('UPDATE contract_drafts SET payload = ?, original_name = ?, storage_name = ?, file_size = ?, updated_at = ? WHERE id = ?', [json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $file['original_name'], $file['storage_name'], $file['file_size'], $at, $id]);
                else $this->repository->insert('contract_drafts', ['id' => $id, 'owner_id' => $actor['id'], 'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_at' => $at, 'updated_at' => $at] + $file);
            });
        } catch (\Throwable $error) { if ($stored) $this->documents->remove($stored['storage_name']); throw $error; }
        if ($stored && $existing) $this->documents->remove($existing['storage_name']);
        return $id;
    }
    public function submit(string $id, array $actor, array $data, array $file): string {
        $draft = $this->find($id, $actor);
        $saved = $draft['storage_name'] ? ['original_name' => $draft['original_name'], 'storage_name' => $draft['storage_name'], 'file_size' => $draft['file_size']] : null;
        $contractId = $this->contracts->create($actor, $data, $file, $saved, $id);
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE && $saved) $this->documents->remove($saved['storage_name']);
        return $contractId;
    }
    public function page(array $actor, int $page): array {
        $where = $actor['role'] === 'admin' ? '' : ' WHERE d.owner_id = ?';
        $params = $actor['role'] === 'admin' ? [] : [$actor['id']];
        $total = (int) $this->repository->one('SELECT COUNT(*) AS total FROM contract_drafts d' . $where, $params)['total'];
        $pages = max(1, (int) ceil($total / 8)); $page = max(1, min($pages, $page));
        $items = $this->repository->all($this->repository->window('SELECT d.*, u.name AS owner_name FROM contract_drafts d JOIN users u ON u.id = d.owner_id' . $where . ' ORDER BY d.updated_at DESC, d.id DESC', 8, ($page - 1) * 8), $params);
        foreach ($items as &$item) $item['data'] = json_decode($item['payload'], true, 512, JSON_THROW_ON_ERROR);
        return ['items' => $items, 'total' => $total, 'pages' => $pages, 'currentPage' => $page];
    }
}
