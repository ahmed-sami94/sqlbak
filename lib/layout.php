<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

function sqlbak_page_start(string $title, string $active = ''): void
{
    sqlbak_require_login();
    $flash = sqlbak_pull_flash();
    $user = $_SESSION['sqlbak_user'];
    $items = [
        'dashboard' => ['index.php', 'لوحة التحكم', 'fa-dashboard'],
        'databases' => ['databases.php', 'قواعد البيانات', 'fa-database'],
        'policies' => ['policies.php', 'سياسات النسخ', 'fa-clock-o'],
        'storage' => ['storage.php', 'وجهات التخزين', 'fa-hdd-o'],
        'backups' => ['backups.php', 'النسخ والاستعادة', 'fa-history'],
        'reports' => ['reports.php', 'تقارير البريد', 'fa-envelope'],
        'logs' => ['logs.php', 'الأخطاء والتتبع', 'fa-bug'],
        'settings' => ['settings.php', 'الإعدادات', 'fa-cog'],
        'users' => ['users.php', 'المستخدمون', 'fa-users'],
    ];
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
    <a class="brand" href="index.php"><span class="brand-mark"><i class="fa fa-shield"></i></span><span><strong>SQLBak</strong><small>نسخ احتياطي آمن</small></span></a>
    <nav class="app-nav" aria-label="القائمة الرئيسية">
        <?php foreach ($items as $key => [$url, $label, $icon]): ?>
            <a href="<?= $url ?>" class="<?= $active === $key ? 'is-active' : '' ?>"><i class="fa <?= $icon ?>"></i><span><?= $label ?></span></a>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-foot"><span class="user-avatar"><?= sqlbak_h(substr($user['username'], 0, 1)) ?></span><span><?= sqlbak_h($user['username']) ?></span><a href="logout.php" title="تسجيل الخروج"><i class="fa fa-sign-out"></i></a></div>
</aside>
<main class="app-main">
    <header class="app-topbar"><button class="menu-toggle" type="button" aria-label="فتح القائمة" data-menu-toggle><i class="fa fa-bars"></i></button><div class="topbar-title"><p class="eyebrow">إدارة النسخ الاحتياطية</p><h1><?= sqlbak_h($title) ?></h1></div><div class="topbar-actions"><span class="last-refresh" data-last-refresh></span><button class="refresh-button" type="button" title="تحديث" aria-label="تحديث" data-refresh><i class="fa fa-refresh"></i></button><a class="top-action" href="manual_backup.php"><i class="fa fa-play"></i> نسخة احتياطية الآن</a></div></header>
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
