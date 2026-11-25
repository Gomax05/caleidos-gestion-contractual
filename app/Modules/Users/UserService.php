<?php
declare(strict_types=1);
namespace Caleidos\Modules\Users;
use Caleidos\Core\Repository;
use DomainException;
final class UserService
{
    public const ROLES = ['commercial' => 'Comercial', 'legal' => 'Legal', 'finance' => 'Administración y Finanzas', 'management' => 'Gerencia', 'admin' => 'Administrador'];
    public const RESPONSIBILITIES = [
        'commercial' => 'Preparar borradores y solicitudes, atender observaciones, registrar versiones y simular envío y firma.',
        'legal' => 'Revisar las cláusulas y responsabilidades de la primera fase.',
        'finance' => 'Validar importes, pagos y condiciones financieras en la tercera fase.',
        'management' => 'Dar la aprobación final después de las tres validaciones anteriores.',
        'admin' => 'Supervisar el flujo, organizar responsables y fechas, revisar borradores y gestionar respaldos.',
    ];
    public function __construct(private Repository $repository) {}
    private function authorize(array $actor): void {
        if ($actor['role'] !== 'admin') throw new DomainException('Solo Administración puede gestionar usuarios.');
    }
    public function save(array $actor, array $data, ?string $id = null): void {
        $this->authorize($actor);
        $name = trim((string) ($data['name'] ?? ''));
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $role = (string) ($data['role'] ?? '');
        $password = (string) ($data['new_password'] ?? '');
        if ($name === '' || mb_strlen($name) > 100 || strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL) || !isset(self::ROLES[$role])) throw new DomainException('Revisa el nombre, correo y área del usuario.');
        if ($password !== '' && (strlen($password) < 8 || strlen($password) > 72)) throw new DomainException('La contraseña debe tener entre 8 y 72 caracteres.');
        $this->repository->transaction(function () use ($actor, $id, $name, $email, $role, $password): void {
            $duplicate = $this->repository->one('SELECT id FROM users WHERE LOWER(email) = LOWER(?)', [$email]);
            if ($duplicate && $duplicate['id'] !== $id) throw new DomainException('Ya existe un usuario con ese correo.');
            if ($id === null) {
                $this->repository->insert('users', ['id' => Repository::id(), 'name' => $name, 'email' => $email, 'role' => $role, 'password_hash' => password_hash($password !== '' ? $password : 'demo1234', PASSWORD_DEFAULT), 'active' => 1]);
                return;
            }
            $user = $this->repository->one('SELECT * FROM users WHERE id = ?', [$id]);
            if (!$user) throw new DomainException('Usuario no encontrado.');
            if ($role !== $user['role']) {
                if ($id === $actor['id']) throw new DomainException('No puedes cambiar tu propia área administrativa.');
                $this->ensureReplacement($user);
                foreach (['contract_events' => 'user_id', 'approvals' => 'user_id', 'review_periods' => 'user_id', 'contracts' => 'owner_id', 'contract_drafts' => 'owner_id'] as $table => $column) {
                    if ($this->repository->one("SELECT $column FROM $table WHERE $column = ?", [$id])) throw new DomainException('Este perfil ya tiene contratos o historial. Conserva su área y utiliza otro perfil para una función distinta.');
                }
            }
            $this->repository->execute('UPDATE users SET name = ?, email = ?, role = ? WHERE id = ?', [$name, $email, $role, $id]);
            if ($password !== '') $this->repository->execute('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
        });
    }
    private function ensureReplacement(array $user): void {
        $count = $this->repository->one('SELECT COUNT(*) AS total FROM users WHERE role = ? AND active = 1', [$user['role']]);
        if ($user['active'] && (int) $count['total'] <= 1) throw new DomainException('Debe permanecer al menos un perfil activo por área.');
    }
    public function toggle(array $actor, string $id): void {
        $this->authorize($actor);
        $this->repository->transaction(function () use ($actor, $id): void {
            $user = $this->repository->one('SELECT * FROM users WHERE id = ?', [$id]);
            if (!$user || $id === $actor['id']) throw new DomainException('No puedes desactivar tu propio perfil.');
            if ($user['active']) {
                $this->ensureReplacement($user);
                if ($this->repository->one("SELECT id FROM contracts WHERE assignee_id = ? AND status <> 'signed'", [$id])) throw new DomainException('Reasigna las tareas pendientes de esta persona antes de desactivarla.');
            }
            $this->repository->execute('UPDATE users SET active = ? WHERE id = ?', [$user['active'] ? 0 : 1, $id]);
        });
    }
}
