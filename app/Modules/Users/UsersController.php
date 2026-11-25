<?php
declare(strict_types=1);
namespace Caleidos\Modules\Users;
use Caleidos\Core\Repository;
use DomainException;
final class UsersController
{
    public function __construct(private Repository $repository) {}
    public function show(array $actor): array {
        if ($actor['role'] !== 'admin') throw new DomainException('Este directorio corresponde a Administración.');
        return ['view' => 'Users/views/list', 'users' => $this->repository->all('SELECT id, name, email, role, active FROM users ORDER BY name')];
    }
    public function handle(string $action, array $actor, array $data): array {
        $service = new UserService($this->repository);
        if ($action === 'create_user') $service->save($actor, $data);
        elseif ($action === 'update_user') $service->save($actor, $data, (string) ($data['user_id'] ?? ''));
        elseif ($action === 'toggle_user') $service->toggle($actor, (string) ($data['user_id'] ?? ''));
        else throw new DomainException('Acción de usuarios no disponible.');
        return ['page' => 'users', 'message' => $action === 'create_user' ? 'Usuario creado correctamente.' : 'Usuario actualizado correctamente.'];
    }
}
