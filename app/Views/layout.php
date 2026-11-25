<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#0bdb90">
    <title><?= e($titles[$page]) ?> · Caleidos Gestión Contractual</title>
    <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/styles.css">
    <script type="module" src="assets/js/app.js"></script>
</head>
<body>
<a class="skip-link" href="#main">Saltar al contenido</a>
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <a href="index.php?page=dashboard" class="brand"><img src="assets/caleidos-logo.svg" alt="Caleidos" width="190" height="56"><span>GESTIÓN CONTRACTUAL</span></a>
        <div class="workspace-label"><span>C</span> Espacio de trabajo <small>PROTOTIPO</small></div>
        <p class="nav-label">TU ESPACIO</p>
        <nav aria-label="Navegación principal">
            <?php foreach (['dashboard' => 'Panel de mi área', 'contracts' => 'Contratos', 'reviews' => 'Mis pendientes', 'observations' => 'Observaciones', 'documents' => 'Documentos', 'timeline' => 'Trazabilidad', 'workflow' => 'Flujo de aprobación'] as $key => $label): ?>
            <a class="nav-item <?= $page === $key ? 'active' : '' ?>" href="index.php?page=<?= e($key) ?>" <?= $page === $key ? 'aria-current="page"' : '' ?>><?= e($label) ?><?php if ($key === 'reviews'): ?><span class="nav-count"><?= $pendingCount ?></span><?php endif ?></a>
            <?php endforeach ?>
            <?php if (in_array($user['role'], ['commercial','admin'], true)): ?><a class="nav-item <?= $page === 'drafts' ? 'active' : '' ?>" href="index.php?page=drafts">Borradores</a><?php endif ?>
            <?php if ($user['role'] === 'admin'): ?><a class="nav-item <?= $page === 'users' ? 'active' : '' ?>" href="index.php?page=users">Usuarios y roles</a><a class="nav-item <?= $page === 'backups' ? 'active' : '' ?>" href="index.php?page=backups">Respaldos</a><?php endif ?>
        </nav>
        <div class="sidebar-bottom">
            <div class="sidebar-note"><strong>Un acuerdo, muchas manos.</strong><p>Cada revisión nos acerca a un mejor resultado.</p><a href="index.php?page=workflow">Conoce el flujo →</a></div>
            <div class="user-panel"><span class="avatar"><?= e(mb_substr($user['name'], 0, 1)) ?></span><div><strong><?= e($user['name']) ?></strong><small><?= e($roles[$user['role']]) ?></small></div></div>
            <form method="post" class="profile-switch"><?= csrfField() ?><input type="hidden" name="action" value="switch_user"><label for="demo-user">Vista demo</label><select id="demo-user" name="user_id"><?php foreach ($activeUsers as $profile): ?><option value="<?= e($profile['id']) ?>" <?= $user['id'] === $profile['id'] ? 'selected' : '' ?>><?= e($profile['name']) ?></option><?php endforeach ?></select><button class="btn btn-small" type="submit">Cambiar</button></form>
            <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="logout"><button class="text-link" type="submit">Cerrar sesión</button></form>
        </div>
    </aside>
    <div class="mobile-overlay" data-close-menu></div>
    <header class="topbar"><button class="icon-btn mobile-menu" type="button" data-menu aria-controls="sidebar" aria-expanded="false" aria-label="Abrir menú">☰</button><div class="breadcrumb">Mi espacio <span>/</span> <strong><?= e($titles[$page]) ?></strong></div><form class="top-search" method="get"><input type="hidden" name="page" value="contracts"><label class="sr-only" for="global-search">Buscar contratos</label><input id="global-search" name="q" placeholder="Buscar un contrato…"><button type="submit" aria-label="Buscar">⌕</button></form><span class="demo-tag">PROTOTIPO</span></header>
    <main id="main" class="main">
        <?php if ($flash): ?><div class="notice <?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div><?php endif ?>
        <div class="page-heading"><div><p class="eyebrow">CADA ACUERDO CUENTA</p><h1><?= e($titles[$page]) ?></h1><p>Un espacio para revisar, aprobar y dar seguimiento a cada acuerdo.</p></div><?php if (in_array($user['role'], ['commercial','admin'], true) && $page !== 'new'): ?><a class="btn btn-primary" href="index.php?page=new">＋ Nueva solicitud</a><?php endif ?></div>
        <?php require $template ?>
        <footer class="page-footer"><span>Caleidos · Gestión contractual / Hecho para avanzar juntos.</span><span class="status-dot">Prototipo funcional</span></footer>
    </main>
</div>
</body>
</html>
