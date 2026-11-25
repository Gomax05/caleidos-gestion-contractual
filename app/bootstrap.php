<?php
declare(strict_types=1);
date_default_timezone_set('America/Lima');
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Caleidos\\')) {
        $path = __DIR__ . '/' . str_replace('\\', '/', substr($class, 9)) . '.php';
        if (is_file($path)) require $path;
    }
});
function e($value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function csrfField(): string { return '<input type="hidden" name="csrf_token" value="' . e($_SESSION['csrf_token']) . '">'; }
function redirectTo(string $page = 'dashboard', array $query = []): void {
    header('Location: index.php?' . http_build_query(['page' => $page] + $query), true, 303);
    exit;
}
function taskLabel(array $contract): string {
    return match ($contract['status']) {
        'review' => 'Revisar y validar', 'observed' => 'Atender observaciones', 'approved' => 'Preparar envío al cliente', 'sent' => 'Dar seguimiento a la firma', 'signed' => 'Acuerdo completado', default => 'Preparar solicitud',
    };
}
function dueLabel(?string $date): string {
    if (!$date) return 'Sin tarea pendiente';
    $days = (int) (new DateTimeImmutable('today'))->diff(new DateTimeImmutable($date))->format('%r%a');
    return $days < 0 ? 'Vencido hace ' . abs($days) . ' d' : ($days === 0 ? 'Vence hoy' : 'Vence en ' . $days . ' d');
}
function durationLabel(int $seconds): string {
    if ($seconds < 60) return 'Menos de 1 min';
    if ($seconds < 3600) return (int) floor($seconds / 60) . ' min';
    if ($seconds < 86400) return round($seconds / 3600, 1) . ' h';
    return round($seconds / 86400, 1) . ' días';
}
