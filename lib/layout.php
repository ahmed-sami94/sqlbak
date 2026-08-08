<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

function sqlbak_page_start(string $title, string $active = ''): void
{
    sqlbak_require_login();
    $flash = sqlbak_pull_flash();
    $user = $_SESSION['sqlbak_user'];
    $canManageBackups = sqlbak_can_manage_backups();
    $canManageSystem = sqlbak_can_manage_system();
    $items = [
        'dashboard' => ['index.php', 'Ø§Ù„Ø§ÙƒÙˆØ¨ Ø§Ù„ØªØ­ÙƒÙ…', 'fa-dashboard'],
    ];
    if ($canManageBackups) {
        $items += [
            'databases' => ['databases.php', 'ØŒÙ…ÙˆØ§Ø¹Ø© Ø§Ù„Ø¨ÙŠØ§Ù†Ø§Øª', 'fa-database'],
            'policies' => ['policies.php', 'Ø³ÙŠØ§Ø³Ø§Ù‚ Ø§Ù„Ù†Ø³Ø®', 'fa-clock-o'],
            'storage' => ['storage.php', 'ØˆØ¬Ù‡Ø§Øª Ø§Ù„ØªØ®Ø²ÙŠÙ†', 'fa-hdd-o'],
            'backups' => ['backups.php', 'Ø§Ù„Ù†Ø³Ø® ÙˆØ§Ù„Ø§Ø³ØªÙŠØ§Ø·Ø©', 'fa-history'],
            'reports' => ['reports.php', 'Ø¥Ù‚Ø¯ÙŠÙ… Ø§Ù„Ø¨Ø±ÙŠØ¯', 'fa-envelope'],
        ];
    }
    if ($canManageSystem) {
        $items += [
            'logs' => ['logs.php', 'Ø§Ø£Ø®Ø·Ø§ÙŒ ØˆÙŠØ¯ÙŠÙ…', 'fa-bug'],
            'settings' => ['settings.php', 'Ø§Ù„Ø¥Ø¹Ø¯Ø§Ø¯Ø§Ø¥', 'fa-cog'],
            'users' => ['users.php', 'Ù…Ø´Ø¯Ø¹Ø± Ø§Ù„Ù…Ø³ÙƒÙ‡', 'fa-users'],
        ];
    }
    ?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= sqlbak_h($title) ?> | SQLBak</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
    <link rel="stylesheet" href="styles/app.css?v=20260711">
    <link rel="stylesheet" href="styles/operations.css?v=2026071203">
    <?php if ($active === 'backups'): ?><link rel="stylesheet" href="styles/restore-sources.css?v=2026071201"><?php endif; ?>
    <?php if ($active === 'backups'): ?><link rel="stylesheet" href="styles/upload-restore.css?v=2026071201"><?php endif; ?>
    <?php if ($active === 'settings'): ?><link rel="stylesheet" href="styles/mail-settings.css?v=2026071201"><?php endif; ?>
    <?php if ($active === 'storage'): ?><link rel="stylesheet" href="styles/storage-metrics.css?v=2026071201"><?php endif; ?>
</head>
<body class="sqlbak-app" data-page="<?= sqlbak_h($active) ?>">
<aside class="app-sidebar" id="app-sidebar">
    <a class="brand" href="index.php"><span class="brand-mark"><i class="fa fa-shield"></i></span><span><strong>SQLBak</strong><small>Ø§Ù„Ø§ØµÙ…Ù†Ø¥Øª Ø§Ù„Ø«Ù„ÙŠØ±Ø´</small></span></a>
    <nav class="app-nav" aria-label="Ø§Ù„Ù‚ÙˆØ§Ø¦Ø§Ø± Ø§Ù„Ø¢Ø®Ù†ÙŠØ©">
        <?php foreach ($items as $key => [$url, $label, $icon]): ?>
            <a href="<?= $url ?>" class="<?= $active === $key ? 'is-active' : '' ?>"><i class="fa <?= $icon ?>"></i><span><?= $label ?></span></a>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-foot"><span class="user-avatar"><?= sqlbak_h(substr($user['username'], 0, 1)) ?></span><span><?= sqlbak_h($user['username']) ?></span><a href="logout.php" title="ØªØ¶Ø­Ù† Ù‡ØºØµ"><i class="fa fa-sign-out"></i></a></div>
</aside>
<main class="app-main">
    <header class="app-topbar"><button class="menu-toggle" type="button" aria-label="Ø¹Ø§Ø¦Ù„ Ø§Ù„Ù‚ÙˆØ§Ø¦Ø§Ø±" data-menu-toggle><i class="fa fa-bars"></i></button><div class="topbar-title"><p class="eyebrow">Ø¥Ø±Ø³Ø§Ù„ Ø§Ù„Ø®Ø§Ù¾Ø¯ Ø§Ù…Ø³Ø¯Ø§Ø¡ Ø§Ù„Ù…Ù…Ù†ÙŠÙ†</p><h1><?= sqlbak_h($title) ?></h1></div><div class="topbar-actions"><span class="last-refresh" data-last-refresh></span><button class="refresh-button" type="button" title="ØªØ­Ø°Ù" aria-label="ØªØ­Ø°Ù" data-refresh><i class="fa fa-refresh"></i></button><?php if ($canManageBackups): ?><a class="top-action" href="manual_backup.php"><i class="fa fa-play"></i> Ø¨Ø¯Ø­ Ø§Ù„Ù†Ø³Ø®Ø© Ø§Ù„Ø¬Ù‹Ø±</a><?php endif; ?></div></header>
    <section class="app-content">
        <?php if ($flash): ?><div class="notice notice-<?= sqlbak_h($flash['type']) ?>"><i class="fa fa-info-circle"></i><?= sqlbak_h($flash['message']) ?></div><?php endif; ?>
<?php
}

function sqlbak_page_end(): void
{
    ?>
    </section>
</main>
<script src="assets/js/app.js?v=2026071205"></script>
</body>
</html>
<?php
}
