<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
use Caleidos\Core\Database;
use Caleidos\Database\DatabaseSchema;
use Caleidos\Database\DatabaseSeeder;
use Caleidos\Modules\Contracts\AssignmentService;
use Caleidos\Modules\Contracts\ContractRepository;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$database = new Database(require dirname(__DIR__) . '/config/database.php');
(new DatabaseSchema())->initialize($database);
$repository = new ContractRepository($database->connection());
(new DatabaseSeeder())->seed($repository);
(new AssignmentService($repository))->initializeMissing();
echo 'Motor: ' . $database->driver() . PHP_EOL;
foreach (['users','contracts','contract_versions','observations','contract_drafts','review_periods'] as $table) echo $table . ': ' . $repository->one('SELECT COUNT(*) AS total FROM ' . $table)['total'] . PHP_EOL;
echo 'Migraciones: ' . implode(', ', array_column($repository->all('SELECT version FROM schema_migrations ORDER BY version'), 'version')) . PHP_EOL;
