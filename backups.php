<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/layout.php';
sqlbak_require_operator();

function sqlbak_active_destinations_by_database(PDO $pdo, array $databaseIds): array
{
    if ($databaseIds === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($databaseIds), '?'));
    $statement = $pdo->prepare("SELECT link.database_id,d.id AS destination_id,d.name AS destination_name,d.type AS destination_type,d.display_order FROM database_storage_destinations link JOIN storage_destinations d ON d.id=link.destination_id WHERE link.database_id IN ({$placeholders}) AND d.enabled=1 ORDER BY d.display_order,d.id");
    $statement->execute($databaseIds);
    $destinations = [];
    foreach ($statement->fetchAll() as $destination) {
        $destinations[$destination['database_id']][] = $destination;
    }
    return $destinations;
}

function sqlbak_backup_source_cards(array $activeDestinations, array $backupCopies): array
{
    $copiesByDestination = [];
    foreach ($backupCopies as $copy) {
        $copiesByDestination[$copy['destination_id']] = $copy;
    }
    $sourceCards = [];
    foreach ($activeDestinations as $destination) {
        $sourceCards[] = [...$destination, 'copy' => $copiesByDestination[$destination['destination_id']] ?? null];
        unset($copiesByDestination[$destination['destination_id']]);
    }
    foreach ($copiesByDestination as $copy) {
        $sourceCards[] = ['destination_id' => $copy['destination_id'], 'destination_name' => $copy['destination_name'], 'destination_type' => $copy['destination_type'], 'display_order' => $copy['display_order'], 'copy' => $copy];
    }
    usort($sourceCards, static fn (array $left, array $right): int => $left['display_order'] <=> $right['display_order']);
    return $sourceCards;
}

function sqlbak_source_state(?array $copy): array
{
    if ($copy === null) {
        return ['class' => 'unavailable', 'label' => 'غير متاحة', 'restorable' => false];
    }
    return ['class' => $copy['status'], 'label' => $copy['status'], 'restorable' => $copy['status'] === 'success'];
}

$pdo = sqlbak_db();
$databaseId = (int) ($_GET['database_id'] ?? 0);
$dateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['date_from'] ?? '')) ? (string) $_GET['date_from'] : '';
$dateTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['date_to'] ?? '')) ? (string) $_GET['date_to'] : '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$filters = ['(?=0 OR b.database_id=?)'];
$parameters = [$databaseId, $databaseId];
if ($dateFrom !== '') { $filters[] = 'b.created_at>=?'; $parameters[] = $dateFrom . ' 00:00:00'; }
if ($dateTo !== '') { $filters[] = 'b.created_at<?'; $parameters[] = date('Y-m-d 00:00:00', strtotime($dateTo . ' +1 day')); }
$where = implode(' AND ', $filters);
$count = $pdo->prepare("SELECT COUNT(*) FROM backups b WHERE {$where}");
$count->execute($parameters);
$totalBackups = (int) $count->fetchColumn();
$pageCount = max(1, (int) ceil($totalBackups / 12));
$page = min($page, $pageCount);
$statement = $pdo->prepare("SELECT b.*,d.name AS database_name,p.name AS policy_name FROM backups b JOIN `databases` d ON d.id=b.database_id LEFT JOIN backup_policy_rules p ON p.id=b.policy_rule_id WHERE {$where} ORDER BY b.created_at DESC,b.id DESC LIMIT 12 OFFSET " . (($page - 1) * 12));
$statement->execute($parameters);
$backups = $statement->fetchAll();
$databaseIds = array_values(array_unique(array_map('intval', array_column($backups, 'database_id'))));
$activeDestinations = sqlbak_active_destinations_by_database($pdo, $databaseIds);
$copiesByBackup = [];
if ($backups !== []) {
    $ids = array_column($backups, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $copies = $pdo->prepare("SELECT c.*,d.name AS destination_name,d.type AS destination_type,d.display_order FROM backup_copies c JOIN storage_destinations d ON d.id=c.destination_id WHERE c.backup_id IN ({$placeholders}) ORDER BY d.display_order,d.id");
    $copies->execute($ids);
    foreach ($copies->fetchAll() as $copy) {
        $copiesByBackup[$copy['backup_id']][] = $copy;
    }
}
$databases = $pdo->query('SELECT id,name,host FROM `databases` ORDER BY name')->fetchAll();
sqlbak_page_start('النسخ والاستعادة', 'backups');
?>
<details class="panel upload-restore-panel">
    <summary><span><i class="fa fa-upload"></i> رفع واستعادة ملف SQL</span><small>مغلق افتراضياً</small></summary>
    <div class="upload-restore-body">
    <div class="panel-head"><div><h2>رفع واستعادة ملف SQL</h2><p>يدعم .sql و .sql.gz حتى 500 MB. سيحذف الملف بعد اكتمال العملية.</p></div></div>
    <form method="post" action="restore_upload.php" enctype="multipart/form-data" data-upload-restore-form>
        <input type="hidden" name="csrf" value="<?= sqlbak_h(sqlbak_csrf_token()) ?>">
        <div class="form-grid"><div class="field full"><label>ملف قاعدة البيانات</label><input name="sql_file" type="file" accept=".sql,.sql.gz,application/sql,application/gzip" required data-upload-restore-file><small class="upload-file-name">لم يتم اختيار ملف.</small></div><div class="field"><label>طريقة الاستعادة</label><select name="restore_mode" data-upload-restore-mode><option value="existing">قاعدة مسجلة</option><option value="new">إنشاء قاعدة جديدة</option></select></div><div class="field" data-upload-existing><label>قاعدة الهدف</label><select name="database_id"><?php foreach ($databases as $database): ?><option value="<?= (int) $database['id'] ?>"><?= sqlbak_h($database['name']) ?></option><?php endforeach; ?></select></div><div class="field" data-upload-new hidden><label>قالب اتصال MySQL</label><select name="template_database_id"><?php foreach ($databases as $database): ?><option value="<?= (int) $database['id'] ?>"><?= sqlbak_h($database['name'] . ' · ' . $database['host']) ?></option><?php endforeach; ?></select></div><div class="field" data-upload-new hidden><label>اسم قاعدة البيانات الجديدة</label><input name="new_database_name" pattern="[A-Za-z0-9_$]{1,50}" placeholder="new_database"></div></div>
        <div class="form-actions"><button class="button danger" type="submit"><i class="fa fa-upload"></i> رفع واستعادة</button><small class="muted">سيتم إنشاء نسخة أمان قبل الكتابة فوق قاعدة مسجلة.</small></div>
    </form>
</div></details>
<section class="panel">
    <div class="panel-head"><div><h2>إصدارات النسخ</h2><p>اختر النسخة والخادم المصدر للاستعادة أو التنزيل.</p></div><a class="button gold" href="manual_backup.php"><i class="fa fa-play"></i> نسخة الآن</a></div>
    <form method="get" class="form-actions filter-bar"><select name="database_id"><option value="0">كل قواعد البيانات</option><?php foreach ($databases as $database): ?><option value="<?= (int) $database['id'] ?>" <?= $databaseId === (int) $database['id'] ? 'selected' : '' ?>><?= sqlbak_h($database['name']) ?></option><?php endforeach; ?></select><input name="date_from" type="date" value="<?= sqlbak_h($dateFrom) ?>" title="من تاريخ"><input name="date_to" type="date" value="<?= sqlbak_h($dateTo) ?>" title="إلى تاريخ"><button class="button secondary"><i class="fa fa-filter"></i> تصفية</button></form>
    <div class="versions-list">
        <?php foreach ($backups as $backup): $backupCopies = $copiesByBackup[$backup['id']] ?? []; $sourceCards = sqlbak_backup_source_cards($activeDestinations[$backup['database_id']] ?? [], $backupCopies); ?>
            <article class="version-row">
                <div class="version-main"><span class="version-icon"><i class="fa fa-database"></i></span><div><strong><?= sqlbak_h($backup['database_name']) ?></strong><b dir="ltr"><?= sqlbak_h($backup['filename'] ?: 'قيد الإنشاء') ?></b><small><?= sqlbak_h($backup['policy_name'] ?: ($backup['type'] === 'manual' ? 'نسخة يدوية' : $backup['type'])) ?> · <span dir="ltr"><?= sqlbak_h($backup['created_at']) ?></span></small></div><span class="status status-<?= sqlbak_h($backup['status']) ?>"><?= sqlbak_h($backup['status']) ?></span></div>
                <div class="restore-source-grid">
                    <?php foreach ($sourceCards as $sourceCard): $copy = $sourceCard['copy']; $sourceState = sqlbak_source_state($copy); ?>
                        <article class="restore-source-card is-<?= sqlbak_h($sourceState['class']) ?>">
                            <div class="restore-source-head"><span class="status status-<?= sqlbak_h($sourceState['class']) ?>"><?= sqlbak_h($sourceState['label']) ?></span><span class="server-number">Server <?= (int) $sourceCard['display_order'] ?></span></div>
                            <strong><?= sqlbak_h($sourceCard['destination_name']) ?></strong>
                            <small><?= strtoupper(sqlbak_h($sourceCard['destination_type'])) ?> · <?= $copy && $copy['size_bytes'] ? number_format((int) $copy['size_bytes'] / 1048576, 1) . ' MB' : 'لا توجد نسخة لهذا الإصدار' ?> · <?= $copy && $copy['duration_ms'] ? (int) $copy['duration_ms'] . ' ms' : '-' ?></small>
                            <?php if ($copy && $copy['error_message']): ?><p class="inline-error"><i class="fa fa-exclamation-circle"></i> <?= sqlbak_h(($copy['error_code'] ? $copy['error_code'] . ' · ' : '') . $copy['error_message']) ?></p><?php endif; ?>
                            <div class="restore-source-actions">
                                <?php if ($sourceState['restorable']): ?>
                                    <form method="post" action="restore_backup.php" onsubmit="return confirm('استعادة هذه النسخة من Server <?= (int) $sourceCard['display_order'] ?>؟ سيتم إنشاء نسخة أمان أولاً.')"><input type="hidden" name="csrf" value="<?= sqlbak_h(sqlbak_csrf_token()) ?>"><input type="hidden" name="copy_id" value="<?= (int) $copy['id'] ?>"><button class="icon-button" type="submit" title="استعادة من هذا الخادم"><i class="fa fa-history"></i></button></form>
                                    <a class="icon-button" href="download.php?copy_id=<?= (int) $copy['id'] ?>" title="تنزيل من هذا الخادم"><i class="fa fa-download"></i></a>
                                <?php else: ?>
                                    <button class="icon-button" type="button" disabled title="لا يمكن الاستعادة من خادم لا يملك هذه النسخة"><i class="fa fa-history"></i></button>
                                    <button class="icon-button" type="button" disabled title="لا يمكن تنزيل نسخة غير متاحة"><i class="fa fa-download"></i></button>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                    <?php if ($sourceCards === []): ?><div class="empty">لا توجد وجهات مرتبطة بهذا الإصدار.</div><?php endif; ?>
                </div>
                <?php if ($backup['error_message']): ?><div class="job-error"><i class="fa fa-warning"></i><span><?= sqlbak_h($backup['error_message']) ?></span><?php if ($backup['trace_id']): ?><code><?= sqlbak_h($backup['trace_id']) ?></code><?php endif; ?></div><?php endif; ?>
            </article>
        <?php endforeach; ?>
        <?php if ($backups === []): ?><div class="empty">لا توجد نسخ مطابقة.</div><?php endif; ?>
    </div>
    <?php if ($pageCount > 1): $firstPage = max(1, min($page - 2, $pageCount - 4)); $lastPage = min($pageCount, $firstPage + 4); ?><nav class="backup-pagination" aria-label="صفحات النسخ"><?php if ($page > 1): ?><a href="backups.php?<?= sqlbak_h(http_build_query(['database_id' => $databaseId, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'page' => $page - 1])) ?>"><i class="fa fa-chevron-right"></i></a><?php endif; ?><?php for ($number = $firstPage; $number <= $lastPage; $number++): $query = http_build_query(['database_id' => $databaseId, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'page' => $number]); ?><a class="<?= $number === $page ? 'is-current' : '' ?>" href="backups.php?<?= sqlbak_h($query) ?>"><?= $number ?></a><?php endfor; ?><?php if ($page < $pageCount): ?><a href="backups.php?<?= sqlbak_h(http_build_query(['database_id' => $databaseId, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'page' => $page + 1])) ?>"><i class="fa fa-chevron-left"></i></a><?php endif; ?></nav><?php endif; ?>
</section>
<script src="assets/js/upload-restore.js?v=2026071201"></script>
<?php sqlbak_page_end();
