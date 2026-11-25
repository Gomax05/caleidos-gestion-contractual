<?php
declare(strict_types=1);
namespace Caleidos\Core;
final class ViewRenderer
{
    public function render(array $data, array $shared): void {
        $view = $data['view']; unset($data['view']);
        $root = dirname(__DIR__, 2);
        extract($shared, EXTR_SKIP); extract($data, EXTR_SKIP);
        $template = $root . '/app/Modules/' . $view . '.php';
        require $root . '/app/Views/layout.php';
    }
}
