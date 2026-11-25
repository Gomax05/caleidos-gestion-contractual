<?php
declare(strict_types=1);
namespace Caleidos\Database;
use Caleidos\Core\Repository;
use Caleidos\Modules\Contracts\WorkflowService;
final class DatabaseSeeder
{
    public function seed(Repository $repository): void {
        if ((int) $repository->one('SELECT COUNT(*) AS total FROM users')['total'] > 0) return;
        $data = json_decode(file_get_contents(dirname(__DIR__, 2) . '/database/demo_data.json'), true, 512, JSON_THROW_ON_ERROR);
        $repository->transaction(function () use ($repository, $data): void {
            foreach ($data['users'] as $user) $repository->insert('users', ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email'], 'role' => $user['role'], 'password_hash' => password_hash('demo1234', PASSWORD_DEFAULT), 'active' => 1]);
            foreach ($data['contracts'] as $c) {
                $repository->insert('contracts', ['id' => $c['id'], 'code' => $c['id'], 'title' => $c['title'], 'client' => $c['client'], 'email' => $c['email'], 'service' => $c['service'], 'amount' => $c['amount'], 'currency' => $c['currency'], 'start_date' => $c['start'], 'end_date' => $c['end'], 'description' => $c['description'], 'status' => $c['status'], 'stage' => $c['stage'], 'owner_id' => 'commercial', 'created_at' => $c['created'], 'updated_at' => $c['updated']]);
                foreach ($c['versions'] as $v) $repository->insert('contract_versions', ['id' => $v['id'], 'contract_id' => $c['id'], 'version_number' => $v['number'], 'original_name' => $v['name'], 'storage_name' => null, 'file_size' => 0, 'clause_text' => $v['text'], 'summary' => $v['summary'], 'author_id' => 'commercial', 'created_at' => $v['at']]);
                foreach ($c['approvals'] as $a) $repository->insert('approvals', ['id' => Repository::id(), 'contract_id' => $c['id'], 'phase' => $a['phase'], 'version_number' => $a['version'], 'user_id' => WorkflowService::PHASE_ROLES[$a['phase']], 'created_at' => $a['at']]);
                foreach ($c['observations'] as $o) $repository->insert('observations', ['id' => $o['id'], 'contract_id' => $c['id'], 'user_id' => $o['role'], 'category' => $o['category'], 'comment' => $o['text'], 'version_number' => $o['version'], 'from_client' => 0, 'resolved' => (int) $o['resolved'], 'created_at' => $o['at']]);
                foreach ($c['events'] as $event) $repository->insert('contract_events', ['id' => $event['id'], 'contract_id' => $c['id'], 'user_id' => $event['role'], 'action' => $event['action'], 'detail' => $event['detail'], 'created_at' => $event['at']]);
            }
        });
    }
}
