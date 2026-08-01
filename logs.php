<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/layout.php';
sqlbak_require_admin();
$pdo = sqlbak_db();
$level = (string) ($_GET['level'] ?? '');
$destinationId = (int) ($_GET['destination_id'] ?? 0);
$trace = trim((string) ($_GET['trace_id'] ?? ''));
$conditions = ['1=1'];
$parameters = [];
if (in_array($level, ['info', 'warning', 'error'], true)) {
    $conditions[] = 'e.level=?';
    $parameters[] = $level;
}
if ($destinationId > 0) {
    $conditions[] = 'e.destination_id=?';
    $parameters[] = $destinationId;
}
if ($trace !== '') {
    $conditions[] = 'e.trace_id=?';
    $parameters[] = $trace;
}
$statement = $pdo->prepare('SELECT e.*,d.name AS destination_name,d.display_order FROM operation_events e LEFT JOIN storage_destinations d ON d.id=e.destination_id WHERE ' . implode(' AND ', $conditions) . ' ORDER BY e.created_at DESC,e.id DESC LIMIT 300');
$statement->execute($parameters);
$events = $statement->fetchAll();
$destinations = $pdo->query('SELECT id,name,display_order FROM storage_destinations ORDER BY display_order,id')->fetchAll();
sqlbak_page_start('الأخطاء والتتبع', 'logs');
?>
<section class="panel">
    <div class="panel-head"><div><h2>سجل العمليات</h2><p>تفاصيل آمنة دون كلمات مرور أو مفاتيح.</p></div><span class="status status-<?= $level === 'error' ? 'failed' : 'queued' ?>"><?= count($events) ?> حدث</span></div>
    <form method="get" class="form-actions filter-bar"><select name="level"><option value="">كل المستويات</option><option value="error" <?= $level === 'error' ? 'selected' : '' ?>>أخطاء</option><option value="warning" <?= $level === 'warning' ? 'selected' : '' ?>>تحذيرات</option><option value="info" <?= $level === 'info' ? 'selected' : '' ?>>معلومات</option></select><select name="destination_id"><option value="0">كل الوجهات</option><?php foreach ($destinations as $destination): ?><option value="<?= (int) $destination['id'] ?>" <?= $destinationId === (int) $destination['id'] ? 'selected' : '' ?>>Server <?= (int) $destination['display_order'] ?> · <?= sqlbak_h($destination['name']) ?></option><?php endforeach; ?></select><input name="trace_id" value="<?= sqlbak_h($trace) ?>" placeholder="Trace ID"><button class="button secondary"><i class="fa fa-filter"></i> تصفية</button></form>
    <div class="event-timeline">
        <?php foreach ($events as $event): ?>
            <article class="event-row level-<?= sqlbak_h($event['level']) ?>"><span class="event-marker"><i class="fa <?= $event['level'] === 'error' ? 'fa-times' : ($event['level'] === 'warning' ? 'fa-warning' : 'fa-check') ?>"></i></span><div><div class="event-title"><strong><?= sqlbak_h($event['error_code'] ?: $event['event_type']) ?></strong><span><?= sqlbak_h($event['phase']) ?></span><?php if ($event['destination_name']): ?><span>Server <?= (int) $event['display_order'] ?> · <?= sqlbak_h($event['destination_name']) ?></span><?php endif; ?></div><p><?= sqlbak_h($event['message']) ?></p><code><?= sqlbak_h($event['trace_id']) ?></code></div><time dir="ltr"><?= sqlbak_h($event['created_at']) ?></time></article>
        <?php endforeach; ?>
        <?php if ($events === []): ?><div class="empty">لا توجد أحداث مطابقة.</div><?php endif; ?>
    </div>
</section>
<?php sqlbak_page_end();
