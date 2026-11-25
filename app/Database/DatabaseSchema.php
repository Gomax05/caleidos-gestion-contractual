<?php
declare(strict_types=1);
namespace Caleidos\Database;
use Caleidos\Core\Database;
final class DatabaseSchema
{
    public function initialize(Database $database): void {
        if ($database->driver() === 'sqlite') {
            $database->connection()->exec(file_get_contents(dirname(__DIR__, 2) . '/database/schema.sqlite.sql'));
        }
        (new MigrationRunner())->migrate($database);
    }
}
