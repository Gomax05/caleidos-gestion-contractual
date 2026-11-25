<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
use Caleidos\Core\Database;
use Caleidos\Core\Repository;
use Caleidos\Database\DatabaseSchema;
use Caleidos\Database\DatabaseSeeder;
use Caleidos\Modules\Contracts\ContractRepository;
use Caleidos\Modules\Contracts\WorkflowService;
use Caleidos\Modules\Documents\ComparisonService;
use Caleidos\Modules\Users\UserService;
use Caleidos\Modules\Contracts\AssignmentService;

function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function rejects(callable $callback, string $message): void {
    try { $callback(); } catch (DomainException $error) { return; }
    throw new RuntimeException($message);
}
$db = new Database(['driver' => 'sqlite', 'sqlite_path' => ':memory:']);
(new DatabaseSchema())->initialize($db);
$repo = new ContractRepository($db->connection());
(new DatabaseSeeder())->seed($repo);
(new AssignmentService($repo))->initializeMissing();
(new DatabaseSchema())->initialize($db);
$workflow = new WorkflowService($repo);
$actors = array_column($repo->all('SELECT * FROM users'), null, 'role');
check(count($repo->listContracts()) === 12, 'Deben existir 12 contratos demo.');
check(password_verify('demo1234', $actors['commercial']['password_hash']), 'Contraseñas con hash.');
$id = 'CL-2026-0012';
foreach (['commercial','finance','management','admin'] as $role) rejects(fn() => $workflow->approve($id, $actors[$role]), 'No se debe aprobar fuera de fase.');
$workflow->observe($id, $actors['legal'], ['comment' => 'Ajustar confidencialidad.', 'category' => 'Legal']);
check($repo->find($id)['status'] === 'observed', 'Debe bloquearse el flujo observado.');
rejects(fn() => $workflow->approve($id, $actors['legal']), 'No aprobar con observaciones.');
$version = ['id' => Repository::id(), 'contract_id' => $id, 'version_number' => 4, 'original_name' => 'corregido.pdf', 'storage_name' => null, 'file_size' => 0, 'clause_text' => 'Texto nuevo', 'summary' => 'Corrección', 'author_id' => 'commercial', 'created_at' => date(DATE_ATOM)];
$repo->transaction(fn() => $workflow->applyVersion($repo->details($id), $actors['commercial'], $version, [2]));
check((int) $repo->find($id)['stage'] === 0, 'No debe saltar Legal aunque se seleccione Finanzas.');
check((int) $repo->details($id)['observations'][0]['resolved'] === 1, 'Debe atenderse la observación.');
foreach (WorkflowService::PHASE_ROLES as $i => $role) {
    $workflow->approve($id, $actors[$role]);
    check((int) $repo->find($id)['stage'] === $i + 1, 'Secuencia incorrecta.');
}
check($repo->find($id)['status'] === 'approved', 'Deben completarse cuatro aprobaciones.');
$workflow->send($id, $actors['commercial'], 'cliente@example.com');
$workflow->observe($id, $actors['commercial'], ['comment' => 'Ajustar monto', 'category' => 'Finanzas', 'from_client' => '1', 'affected_phase' => '2']);
check(count($repo->details($id)['approvals']) === 2, 'Conservar las aprobaciones de Legal y Comercial.');
$version['id'] = Repository::id(); $version['version_number'] = 5;
$repo->transaction(fn() => $workflow->applyVersion($repo->details($id), $actors['commercial'], $version, [2]));
$workflow->approve($id, $actors['finance']); $workflow->approve($id, $actors['management']);
$workflow->send($id, $actors['commercial'], 'cliente@example.com');
$workflow->sign($id, $actors['commercial'], 'Firma manual');
check($repo->find($id)['status'] === 'signed', 'Debe quedar firmado.');
rejects(fn() => $workflow->sign($id, $actors['commercial'], 'Firma manual'), 'No firmar dos veces.');
rejects(fn() => $workflow->applyVersion($repo->details($id), $actors['commercial'], $version, [0]), 'No versionar firmados.');
check((int) $repo->one('SELECT COUNT(*) AS total FROM users')['total'] === 5, 'Conservar los cinco perfiles.');
$userService = new UserService($repo);
rejects(fn() => $userService->save($actors['legal'], ['name' => 'Intruso']), 'Solo Administración gestiona usuarios.');
rejects(fn() => $userService->toggle($actors['admin'], 'legal'), 'Mantener último Legal activo.');
$userService->save($actors['admin'], ['name' => 'Legal adicional', 'email' => 'otro@example.com', 'role' => 'legal']);
$additional = $repo->one('SELECT * FROM users WHERE email = ?', ['otro@example.com']);
check(password_verify('demo1234', $additional['password_hash']), 'Contraseña demo inicial.');
$userService->save($actors['admin'], ['name' => 'Legal editado', 'email' => 'otro@example.com', 'role' => 'legal', 'new_password' => 'nueva1234'], $additional['id']);
check(password_verify('nueva1234', $repo->one('SELECT password_hash FROM users WHERE id = ?', [$additional['id']])['password_hash']), 'Actualizar contraseña con hash.');
$userService->toggle($actors['admin'], $additional['id']);
check((int) $repo->one('SELECT active FROM users WHERE id = ?', [$additional['id']])['active'] === 0, 'Desactivar perfil sin tareas.');
rejects(fn() => $userService->save($actors['admin'], ['name' => 'Duplicado', 'email' => 'COMERCIAL@CALEIDOS.PE', 'role' => 'legal']), 'Evitar correos duplicados.');
check($repo->find($id)['assignee_id'] === null && $repo->find($id)['due_date'] === null, 'Un firmado no debe tener tarea pendiente.');
check(count($repo->details($id)['periods']) >= 9, 'Registrar tiempos de las tareas recorridas.');
$assignments = new AssignmentService($repo);
rejects(fn() => $assignments->update('CL-2026-0010', $actors['legal'], ['assignee_id' => 'commercial', 'due_date' => date('Y-m-d')]), 'Legal no debe gestionar asignaciones.');
rejects(fn() => $assignments->update('CL-2026-0010', $actors['admin'], ['assignee_id' => 'legal', 'due_date' => date('Y-m-d')]), 'No asignar otro rol.');
$assignments->update('CL-2026-0010', $actors['admin'], ['assignee_id' => 'commercial', 'due_date' => date('Y-m-d', strtotime('-1 day'))]);
check($repo->search(['urgency' => 'overdue'], $actors['commercial'], true)['total'] >= 1, 'Detectar vencidos propios.');
check(count($repo->search([], $actors['admin'])['items']) === 8 && $repo->search([], $actors['admin'])['total'] === 12, 'Paginar en SQL.');
check(count($repo->search(['p' => 2], $actors['admin'])['items']) === 4, 'Segunda página SQL.');
check($repo->search(['q' => '%'], $actors['admin'])['total'] === 0, 'El porcentaje debe buscarse como texto.');
$comparison = (new ComparisonService())->compare("uno\nrepetido\nrepetido\nviejo", "uno\nrepetido\nnuevo\n<script>");
check(count(array_filter($comparison['before'], fn($line) => $line['type'] === 'removed')) === 2, 'Comparación maneja líneas repetidas.');
$reordered = (new ComparisonService())->compare("a\nb\nc", "b\na\nc");
check($reordered['added'] === 1 && $reordered['removed'] === 1, 'Detectar reordenamientos.');
$large = (new ComparisonService())->compare(str_repeat("a\n", 1500), str_repeat("b\n", 1500));
check($large['simplified'] && count($large['before']) === count($large['after']), 'Limitar recursos en textos grandes.');
check(e('<script>') === '&lt;script&gt;', 'Escapar HTML.');
$count = count($repo->events());
try { $repo->transaction(function () use ($repo, $id, $actors): void { $repo->event($id, $actors['commercial'], 'Temporal', 'No persistir'); throw new DomainException('Rollback'); }); } catch (DomainException $error) {}
check(count($repo->events()) === $count, 'La transacción debe revertirse.');
echo "OK: usuarios, permisos, versiones, cuatro fases, responsables, plazos, paginación SQL, comparación y rollback.\n";
