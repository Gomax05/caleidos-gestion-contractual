<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
use Caleidos\Core\Database;
use Caleidos\Core\Repository;
use Caleidos\Database\DatabaseSchema;
use Caleidos\Database\DatabaseSeeder;
use Caleidos\Modules\Contracts\AssignmentService;
use Caleidos\Modules\Contracts\ContractRepository;

$directory = sys_get_temp_dir() . '/caleidos-migration-' . bin2hex(random_bytes(5));
mkdir($directory);
try {
    $database = new Database(['driver' => 'sqlite', 'sqlite_path' => $directory . '/old.sqlite']);
    $database->connection()->exec(file_get_contents(dirname(__DIR__) . '/database/schema.sqlite.sql'));
    $repository = new Repository($database->connection());
    (new DatabaseSeeder())->seed($repository);
    $before = [];
    foreach (['users','contracts','contract_versions','approvals','observations','contract_events'] as $table) $before[$table] = $repository->all('SELECT * FROM ' . $table . ' ORDER BY id');
    (new DatabaseSchema())->initialize($database);
    (new DatabaseSchema())->initialize($database);
    $contractRepository = new ContractRepository($database->connection());
    (new AssignmentService($contractRepository))->initializeMissing();
    (new AssignmentService($contractRepository))->initializeMissing();
    foreach ($before as $table => $rows) {
        $after = $repository->all('SELECT * FROM ' . $table . ' ORDER BY id');
        if (count($after) !== count($rows)) throw new RuntimeException('Cambió el conteo de ' . $table);
        foreach ($rows as $i => $row) if (array_intersect_key($after[$i], $row) != $row) throw new RuntimeException('Se alteró un registro de ' . $table);
    }
    $copies = glob($directory . '/backups/pre_migration_*.sqlite');
    if (count($copies) !== 1) throw new RuntimeException('Debe guardarse un único respaldo anterior a la migración.');
    $copy = new PDO('sqlite:' . $copies[0]);
    if ((int) $copy->query('SELECT COUNT(*) FROM contracts')->fetchColumn() !== 12) throw new RuntimeException('El respaldo de migración no conserva los contratos.');
    $periods = $repository->one('SELECT COUNT(*) AS total FROM review_periods')['total'];
    if ((int) $periods !== 10) throw new RuntimeException('Debe asignarse una tarea por contrato no firmado, sin duplicados.');
    echo "OK: migración de base anterior, respaldo automático, conservación de registros e idempotencia.\n";
} finally {
    unset($copy, $contractRepository, $repository, $database);
    foreach (glob($directory . '/backups/*') ?: [] as $file) unlink($file);
    if (is_dir($directory . '/backups')) rmdir($directory . '/backups');
    if (is_file($directory . '/old.sqlite')) unlink($directory . '/old.sqlite');
    rmdir($directory);
}
