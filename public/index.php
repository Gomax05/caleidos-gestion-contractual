<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
if (isset($_GET['health'])) {
    header('Content-Type: application/json');
    echo json_encode(['project' => 'caleidos-gestion-contractual', 'instance_id' => getenv('CALEIDOS_INSTANCE_TOKEN') ?: 'manual']);
    exit;
}
try {
    (new Caleidos\Core\ApplicationController())->run();
} catch (Throwable $error) {
    error_log((string) $error);
    http_response_code(500);
    echo '<!doctype html><html lang="es"><meta charset="utf-8"><title>Caleidos</title><h1>No se pudo completar la solicitud</h1><p>Consulta storage/server-error.log o el registro de PHP para revisar la configuración y vuelve a intentarlo.</p></html>';
}
