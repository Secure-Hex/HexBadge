<?php
/**
 * Sidebar del panel admin.
 *
 * @var array<string,mixed> $currentUser
 * @var string              $appName
 */
$role = (string) ($currentUser['role'] ?? 'issuer');
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

$active = static function (string $href) use ($path): string {
    if ($href === '/admin') {
        return $path === '/admin' ? ' active' : '';
    }
    return ($path === $href || str_starts_with($path, $href . '/')) ? ' active' : '';
};

/** Íconos (stroke, 18px). */
$icons = [
    'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/>',
    'template'  => '<path d="M12 2 21 7v10l-9 5-9-5V7z"/><path d="m7.5 9.5 4.5 2.5 4.5-2.5M12 12v6.5"/>',
    'diploma'   => '<rect x="3" y="4" width="18" height="13" rx="1.5"/><circle cx="12" cy="10" r="2.5"/><path d="m9.5 13-1 6 3.5-2 3.5 2-1-6"/>',
    'issue'     => '<path d="M22 2 11 13M22 2l-7 20-4-9-9-4 20-7z"/>',
    'bulk'      => '<path d="M12 2 2 7l10 5 10-5-10-5z"/><path d="m2 12 10 5 10-5M2 17l10 5 10-5"/>',
    'badge'     => '<circle cx="12" cy="9" r="6"/><path d="M8.5 14 7 22l5-3 5 3-1.5-8"/>',
    'earners'   => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13A4 4 0 0 1 16 11"/>',
    'analytics' => '<path d="M3 3v18h18"/><rect x="7" y="11" width="3" height="6" rx="1"/><rect x="12" y="7" width="3" height="10" rx="1"/><rect x="17" y="13" width="3" height="4" rx="1"/>',
    'users'     => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/>',
    'apikeys'   => '<circle cx="7.5" cy="15.5" r="4.5"/><path d="m10.5 12.5 8-8M16 5l3 3M14 7l3 3"/>',
    'audit'     => '<path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="m9 14 2 2 4-4"/>',
    'smtp'      => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 7 10 6 10-6"/>',
    'companies' => '<path d="M3 21h18"/><path d="M9 21V4a1 1 0 0 0-1-1H4a1 1 0 0 0-1 1v17"/><path d="M21 21V8a1 1 0 0 0-1-1h-8"/><path d="M6 7h0M6 11h0M6 15h0M15 11h0M15 15h0"/>',
    'fonts'     => '<path d="M4 7V5h16v2"/><path d="M9 19h6"/><path d="M12 5v14"/>',
];
$ico = static fn (string $k): string => '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">' . ($icons[$k] ?? '') . '</svg>';

$item = static function (string $href, string $label, string $key) use ($active, $ico): string {
    return '<a class="' . trim('nav-link' . $active($href)) . '" href="' . e($href) . '">' . $ico($key) . '<span>' . e($label) . '</span></a>';
};
?>
<aside class="sidebar">
    <a class="sidebar-brand" href="/admin">
        <span class="brand-mark"><?= \HexBadge\Core\View::renderPartial('layout/securelogo') ?></span>
        <span><?= e($appName) ?><small>by SecureHex</small></span>
    </a>

    <nav class="sidebar-nav">
        <div class="nav-section">Operación</div>
        <?= $item('/admin', 'Dashboard', 'dashboard') ?>
        <?= $item('/admin/templates', 'Templates', 'template') ?>
        <?= $item('/admin/diploma-templates', 'Plantillas de diplomas', 'diploma') ?>
        <?= $item('/admin/issue', 'Emitir badge', 'issue') ?>
        <?= $item('/admin/bulk-issue', 'Emisión masiva', 'bulk') ?>

        <div class="nav-section">Gestión</div>
        <?= $item('/admin/badges', 'Badges emitidos', 'badge') ?>
        <?= $item('/admin/earners', 'Receptores', 'earners') ?>
        <?= $item('/admin/analytics', 'Analytics', 'analytics') ?>

        <?php if ($role === 'admin' || $role === 'superadmin'): ?>
            <div class="nav-section">Administración</div>
            <?php if ($role === 'superadmin'): ?>
                <?= $item('/admin/companies', 'Empresas', 'companies') ?>
            <?php else: ?>
                <?= $item('/admin/company', 'Mi empresa', 'companies') ?>
            <?php endif; ?>
            <?= $item('/admin/users', 'Usuarios', 'users') ?>
            <?= $item('/admin/api-keys', 'API Keys', 'apikeys') ?>
            <?= $item('/admin/fonts', 'Tipografías', 'fonts') ?>
            <?= $item('/admin/audit', 'Auditoría', 'audit') ?>
            <?php if ($role === 'superadmin'): ?>
                <?= $item('/admin/settings', 'Configuración', 'smtp') ?>
            <?php endif; ?>
        <?php endif; ?>
    </nav>

    <div class="sidebar-foot">
        Una herramienta de <a href="https://securehex.cl" target="_blank" rel="noopener">SecureHex</a>
        <div style="opacity:.6;margin-top:.2rem">HexBadge · v1.0</div>
    </div>
</aside>
