<?php
declare(strict_types=1);
namespace Caleidos\Core;
use Caleidos\Database\DatabaseSchema;
use Caleidos\Database\DatabaseSeeder;
use Caleidos\Modules\Auth\{AuthService, AuthController};
use Caleidos\Modules\Contracts\{ContractRepository, ContractService, ContractsController, WorkflowService, AssignmentService, DraftService};
use Caleidos\Modules\Dashboard\DashboardController;
use Caleidos\Modules\Documents\{DocumentService, DocumentsController};
use Caleidos\Modules\Reviews\ReviewsController;
use Caleidos\Modules\Traceability\TraceabilityController;
use Caleidos\Modules\Users\{UserService, UsersController};
use Caleidos\Modules\Backups\{BackupService, BackupsController};
use DomainException;

/** Coordinación HTTP. Cada módulo resuelve sus consultas, acciones y vistas. */
final class ApplicationController
{
    public function run(): void {
        $root = dirname(__DIR__, 2); $config = require $root . '/config/app.php';
        $storage = $config['storage_path'];
        if (!is_dir($storage . '/sessions')) mkdir($storage . '/sessions', 0775, true);
        session_save_path($storage . '/sessions');
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
        session_start(); $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
        header('X-Content-Type-Options: nosniff'); header('X-Frame-Options: SAMEORIGIN'); header('Cache-Control: no-store');
        $database = new Database(require $root . '/config/database.php');
        (new DatabaseSchema())->initialize($database);
        $repository = new ContractRepository($database->connection());
        (new DatabaseSeeder())->seed($repository);
        $assignments = new AssignmentService($repository); $assignments->initializeMissing();
        $auth = new AuthService($repository); $authController = new AuthController($auth);
        $user = $auth->currentUser();
        $workflow = new WorkflowService($repository);
        $documents = new DocumentService($storage . '/documents');
        $contracts = new ContractService($repository, $workflow, $documents);
        $drafts = new DraftService($repository, $contracts, $documents);
        $contractsController = new ContractsController($repository, $contracts, $drafts, $assignments);
        $reviewsController = new ReviewsController($repository, $workflow);
        $documentsController = new DocumentsController($repository, $documents, $drafts);
        $traceabilityController = new TraceabilityController($repository);
        $backupsController = new BackupsController(new BackupService($repository, $storage));
        $titles = ['login' => 'Iniciar sesión', 'dashboard' => 'Panel de mi área', 'contracts' => 'Contratos', 'reviews' => 'Mis pendientes', 'drafts' => 'Borradores', 'new' => 'Preparar solicitud', 'detail' => 'Detalle del contrato', 'documents' => 'Documentos', 'observations' => 'Observaciones', 'timeline' => 'Trazabilidad', 'workflow' => 'Flujo de aprobación', 'users' => 'Usuarios y roles', 'backups' => 'Respaldos'];
        $page = (string) ($_GET['page'] ?? (empty($_GET) ? 'login' : 'dashboard'));
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                if (!hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) throw new DomainException('La sesión del formulario venció. Recarga la página.');
                $action = (string) ($_POST['action'] ?? '');
                if ($action !== 'login' && !$user) throw new DomainException('Inicia sesión para continuar.');
                if (in_array($action, ['login','logout','switch_user'], true)) $result = $authController->handle($action, $_POST);
                elseif (in_array($action, ['create_contract','save_draft','add_version','assign_task'], true)) $result = $contractsController->handle($action, $user, $_POST, $_FILES);
                elseif (in_array($action, ['approve','observe','send','sign'], true)) $result = $reviewsController->handle($action, $user, $_POST);
                elseif (in_array($action, ['create_backup','restore_backup'], true)) $result = $backupsController->handle($action, $user, $_POST, $_FILES);
                elseif (in_array($action, ['create_user','update_user','toggle_user'], true)) $result = (new UsersController($repository))->handle($action, $user, $_POST);
                else throw new DomainException('Esta función no está disponible en la demostración.');
                if (isset($result['message'])) $_SESSION['flash'] = ['type' => 'success', 'message' => $result['message']];
                redirectTo($result['page'], $result['query'] ?? []);
            } catch (DomainException $error) {
                $_SESSION['flash'] = ['type' => 'error', 'message' => $error->getMessage()];
                $_SESSION['old_input'] = array_diff_key($_POST, array_flip(['password','csrf_token','new_password']));
                $target = isset($titles[$page]) ? $page : 'dashboard'; $query = [];
                if ($target === 'login' && ($_POST['next'] ?? '') === 'users') $query['manage_users'] = 1;
                if ($target === 'detail') $query['id'] = (string) ($_POST['contract_id'] ?? $_GET['id'] ?? '');
                if ($target === 'new' && !empty($_POST['draft_id'])) $query['draft_id'] = (string) $_POST['draft_id'];
                redirectTo($target, $query);
            }
        }
        $flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
        $old = $_SESSION['old_input'] ?? []; unset($_SESSION['old_input']);
        $roles = UserService::ROLES; $phases = WorkflowService::PHASES; $statuses = WorkflowService::STATUSES; $services = ContractService::SERVICES;
        $activeUsers = $repository->all('SELECT id, name, email, role FROM users WHERE active = 1 ORDER BY name');
        if (!$user || $page === 'login') { require $root . '/app/Modules/Auth/views/login.php'; return; }
        try {
            if (isset($_GET['download']) || isset($_GET['draft_document'])) { $isDraft = isset($_GET['draft_document']); $documentsController->download((string) ($_GET[$isDraft ? 'draft_document' : 'download']), isset($_GET['inline']), $user, $isDraft); return; }
            if (isset($_GET['backup_download'])) { $backupsController->download($user, (string) $_GET['backup_download']); return; }
            if (isset($_GET['export'])) {
                if ($_GET['export'] === 'timeline') $traceabilityController->export($_GET); else $contractsController->export($_GET, $user);
                return;
            }
            if (!isset($titles[$page])) throw new DomainException('La página solicitada no existe.');
            $data = match ($page) {
                'dashboard' => (new DashboardController($repository))->show($user),
                'contracts','reviews','new','detail','drafts' => $contractsController->show($page, $_GET, $user),
                'observations','workflow' => $reviewsController->show($page, $_GET),
                'documents' => $documentsController->show($_GET),
                'timeline' => $traceabilityController->show($_GET),
                'users' => (new UsersController($repository))->show($user),
                'backups' => $backupsController->show($user),
            };
            $pendingCount = $repository->pendingCount($user);
            (new ViewRenderer())->render($data, compact('page','titles','user','roles','phases','statuses','services','activeUsers','flash','old','pendingCount'));
        } catch (DomainException $error) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => $error->getMessage()];
            redirectTo($page === 'dashboard' ? 'contracts' : 'dashboard');
        }
    }
}
