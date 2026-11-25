<?php
declare(strict_types=1);
namespace Caleidos\Modules\Dashboard;
use Caleidos\Modules\Contracts\ContractRepository;
final class DashboardController
{
    public function __construct(private ContractRepository $repository) {}
    public function show(array $actor): array {
        $overview = $this->repository->overview($actor); $pending = $this->repository->search([], $actor, true);
        return ['view' => 'Dashboard/views/dashboard', 'counts' => $overview['counts'], 'stageCounts' => $overview['stageCounts'], 'events' => $this->repository->recentEvents(), 'recentContracts' => array_slice($this->repository->search([], $actor)['items'], 0, 5), 'myTasks' => $pending['items'], 'timeByPhase' => $this->repository->timeByPhase(), 'roleIntro' => ['commercial' => 'Prepara solicitudes, atiende correcciones y acompaña el envío y la firma.', 'legal' => 'Revisa cláusulas y responsabilidades de los contratos asignados.', 'finance' => 'Valida montos, pagos y condiciones financieras de tus revisiones.', 'management' => 'Evalúa las validaciones previas y da la aprobación final.', 'admin' => 'Supervisa responsables, plazos, borradores y respaldos del prototipo.'][$actor['role']]];
    }
}
