<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
use Caleidos\Core\Database;
use Caleidos\Database\DatabaseSchema;
use Caleidos\Database\DatabaseSeeder;
use Caleidos\Modules\Contracts\AssignmentService;
use Caleidos\Modules\Contracts\ContractRepository;
use Caleidos\Modules\Backups\BackupService;

function expect(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function invalidBackup(callable $operation): void { try { $operation(); } catch (DomainException $error) { return; } throw new RuntimeException('Debió rechazarse el respaldo.'); }
$directory = sys_get_temp_dir() . '/caleidos-backup-' . bin2hex(random_bytes(5));
mkdir($directory); mkdir($directory . '/documents');
try {
    $database = new Database(['driver' => 'sqlite', 'sqlite_path' => ':memory:']);
    (new DatabaseSchema())->initialize($database);
    $repo = new ContractRepository($database->connection()); (new DatabaseSeeder())->seed($repo); (new AssignmentService($repo))->initializeMissing();
    $actor = $repo->one('SELECT * FROM users WHERE id = ?', ['admin']);
    $service = new BackupService($repo, $directory);
    $file = str_repeat('a', 32) . '.pdf'; $content = "%PDF-1.4\nPDF de prueba\n%%EOF";
    file_put_contents($directory . '/documents/' . $file, $content);
    $repo->execute('UPDATE contract_versions SET storage_name = ?, file_size = ? WHERE id = ?', [$file, strlen($content), 'seed-0-1']);
    $original = $repo->find('CL-2026-0012')['title'];
    $backup = $service->create($actor);
    (new \Caleidos\Modules\Users\UserService($repo))->save($actor, ['name' => 'Perfil posterior', 'email' => 'posterior@example.com', 'role' => 'legal', 'new_password' => 'posterior123']);
    $repo->execute('UPDATE contracts SET title = ? WHERE id = ?', ['Cambio posterior', 'CL-2026-0012']);
    unlink($directory . '/documents/' . $file);
    $safety = $service->restore($actor, $backup);
    expect(is_file($directory . '/backups/' . $safety), 'Debe guardar el estado previo.');
    expect($repo->find('CL-2026-0012')['title'] === $original, 'Debe recuperar los registros.');
    expect(file_get_contents($directory . '/documents/' . $file) === $content, 'Debe recuperar bytes originales del PDF.');
    $zip = new ZipArchive(); $zip->open($backup); $data = json_decode($zip->getFromName('data.json'), true); $zip->close();
    $malicious = $directory . '/malicious.zip';
    $zip->open($malicious, ZipArchive::CREATE); $zip->addFromString('data.json', json_encode($data)); $zip->addFromString('documents/' . $file, $content); $zip->addFromString('../escape.pdf', $content); $zip->close();
    invalidBackup(fn() => $service->restore($actor, $malicious));
    expect(!is_file(dirname($directory) . '/escape.pdf'), 'No debe extraer rutas externas.');
    $corrupt = $directory . '/corrupt.zip';
    $zip->open($corrupt, ZipArchive::CREATE); $zip->addFromString('data.json', json_encode($data)); $zip->addFromString('documents/' . $file, $content . 'alterado'); $zip->close();
    invalidBackup(fn() => $service->restore($actor, $corrupt));
    $newFile = str_repeat('b', 32) . '.pdf'; $data['files'][$newFile] = $data['files'][$file];
    $data['tables']['contract_versions'][0]['storage_name'] = $newFile;
    $data['tables']['contracts'][0]['owner_id'] = 'perfil-inexistente';
    $broken = $directory . '/broken.zip';
    $zip->open($broken, ZipArchive::CREATE); $zip->addFromString('data.json', json_encode($data)); $zip->addFromString('documents/' . $file, $content); $zip->addFromString('documents/' . $newFile, $content); $zip->close();
    invalidBackup(fn() => $service->restore($actor, $broken));
    expect(!is_file($directory . '/documents/' . $newFile), 'Revertir archivos nuevos tras fallo de base.');
    expect($repo->find('CL-2026-0012')['title'] === $original, 'Revertir registros tras fallo de base.');
    expect((int) $repo->one('SELECT COUNT(*) AS total FROM users')['total'] === 6, 'Mantener los perfiles creados después del respaldo.');
    expect(password_verify('posterior123', $repo->one('SELECT password_hash FROM users WHERE email = ?', ['posterior@example.com'])['password_hash']), 'Conservar credenciales actuales al recuperar contratos.');
    invalidBackup(fn() => $service->create($repo->one('SELECT * FROM users WHERE id = ?', ['legal'])));
    echo "OK: respaldo íntegro, recuperación de PDF, rutas rechazadas, integridad, permisos y rollback de base/archivos.\n";
} finally {
    unset($service, $repo, $database, $zip);
    $base = realpath(sys_get_temp_dir()); $resolved = realpath($directory);
    if ($resolved && dirname($resolved) === $base && str_starts_with(basename($resolved), 'caleidos-backup-')) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $entry) { if ($entry->isDir()) rmdir($entry->getPathname()); else unlink($entry->getPathname()); }
        rmdir($resolved);
    }
}
