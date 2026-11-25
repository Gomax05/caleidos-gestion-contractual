<?php
declare(strict_types=1);
namespace Caleidos\Modules\Auth;
use Caleidos\Core\Repository;
use DomainException;
final class AuthService
{
    public function __construct(private Repository $repository) {}
    public function currentUser(): ?array {
        return $this->repository->one('SELECT id, name, email, role, active FROM users WHERE id = ? AND active = 1', [$_SESSION['user_id'] ?? '']);
    }
    public function login(string $email, string $password): void {
        $user = $this->repository->one('SELECT * FROM users WHERE email = ? AND active = 1', [strtolower(trim($email))]);
        if (!$user || !password_verify($password, $user['password_hash'])) throw new DomainException('Correo o contraseña incorrectos.');
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    public function switchDemoUser(string $id): void {
        if (!$this->currentUser()) throw new DomainException('Inicia sesión para cambiar la vista demo.');
        if (!$this->repository->one('SELECT id FROM users WHERE id = ? AND active = 1', [$id])) throw new DomainException('Perfil no disponible.');
        session_regenerate_id(true);
        $_SESSION['user_id'] = $id;
    }
    public function logout(): void {
        unset($_SESSION['user_id']);
        session_regenerate_id(true);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}
