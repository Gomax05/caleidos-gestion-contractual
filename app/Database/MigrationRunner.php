<?php
declare(strict_types=1);
namespace Caleidos\Database;
use Caleidos\Core\Database;
use Caleidos\Core\Repository;

final class MigrationRunner
{
    public function migrate(Database $database): void {
        $repo = new Repository($database->connection());
        $sqlsrv = $database->driver() === 'sqlsrv';
        $repo->execute($sqlsrv
            ? "IF OBJECT_ID('schema_migrations','U') IS NULL CREATE TABLE schema_migrations (version VARCHAR(80) PRIMARY KEY, applied_at VARCHAR(32) NOT NULL)"
            : 'CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(80) PRIMARY KEY, applied_at VARCHAR(32) NOT NULL)');
        if ($repo->one('SELECT version FROM schema_migrations WHERE version = ?', ['002_demo_workspaces'])) return;
        if (!$sqlsrv) {
            $file = $repo->one('PRAGMA database_list')['file'] ?? '';
            if ($file !== '' && (int) $repo->one('SELECT COUNT(*) AS total FROM contracts')['total'] > 0) {
                $directory = dirname($file) . '/backups';
                if (!is_dir($directory)) mkdir($directory, 0775, true);
                $repo->execute('VACUUM INTO ?', [$directory . '/pre_migration_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.sqlite']);
            }
        }
        $repo->transaction(function () use ($repo, $sqlsrv): void {
            foreach (['assignee_id' => 'VARCHAR(36) NULL REFERENCES users(id)', 'due_date' => 'VARCHAR(10) NULL', 'assigned_at' => 'VARCHAR(32) NULL', 'review_days' => 'INT NOT NULL DEFAULT 3'] as $name => $type) {
                $exists = $sqlsrv ? $repo->one('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ? AND COLUMN_NAME = ?', ['contracts', $name]) : array_filter($repo->all('PRAGMA table_info(contracts)'), static fn($column) => $column['name'] === $name);
                if (!$exists) $repo->execute('ALTER TABLE contracts ADD ' . $name . ' ' . $type);
            }
            $repo->execute('CREATE TABLE contract_drafts (id VARCHAR(36) PRIMARY KEY, owner_id VARCHAR(36) NOT NULL REFERENCES users(id), payload ' . ($sqlsrv ? 'NVARCHAR(MAX)' : 'TEXT') . ' NOT NULL, original_name ' . ($sqlsrv ? 'NVARCHAR(200)' : 'VARCHAR(200)') . ' NULL, storage_name VARCHAR(80) NULL, file_size INT NOT NULL DEFAULT 0, created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL)');
            $repo->execute('CREATE TABLE review_periods (id VARCHAR(36) PRIMARY KEY, contract_id VARCHAR(36) NOT NULL REFERENCES contracts(id), user_id VARCHAR(36) NOT NULL REFERENCES users(id), phase INT NOT NULL, task_type VARCHAR(20) NOT NULL, started_at VARCHAR(32) NOT NULL, completed_at VARCHAR(32) NULL, outcome VARCHAR(80) NULL)');
            $repo->execute('CREATE INDEX ix_contracts_assignee ON contracts(assignee_id, due_date)');
            $repo->execute('CREATE INDEX ix_drafts_owner ON contract_drafts(owner_id, updated_at)');
            $repo->execute('CREATE INDEX ix_periods_contract ON review_periods(contract_id, completed_at)');
            $repo->insert('schema_migrations', ['version' => '002_demo_workspaces', 'applied_at' => date(DATE_ATOM)]);
        });
    }
}
