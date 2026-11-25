<?php
declare(strict_types=1);
namespace Caleidos\Modules\Auth;
use DomainException;
final class AuthController
{
    public function __construct(private AuthService $auth) {}
    public function handle(string $action, array $data): array {
        if ($action === 'login') $this->auth->login((string) ($data['email'] ?? ''), (string) ($data['password'] ?? ''));
        elseif ($action === 'logout') $this->auth->logout();
        elseif ($action === 'switch_user') $this->auth->switchDemoUser((string) ($data['user_id'] ?? ''));
        else throw new DomainException('Acción de acceso no disponible.');
        if ($action === 'logout') return ['page' => 'login'];
        return ['page' => $action === 'login' && ($data['next'] ?? '') === 'users' && $this->auth->currentUser()['role'] === 'admin' ? 'users' : 'dashboard'];
    }
}
