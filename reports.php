<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/reports.php';

sqlbak_require_operator();
$pdo = sqlbak_db();

function sqlbak_report_schedule_request(): array
{
    $frequency = (string) ($_POST['frequency'] ?? 'daily');
    if (!in_array($frequency, ['daily', 'weekly', 'monthly'], true)) {
        throw new InvalidArgumentException('تكرار التقرير غير صالح.');
    }
    $emails = preg_split('/[\s,;]+/', trim((string) ($_POST['custom_recipients'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    foreach ($emails as $email) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('بريد مستلم غير صالح: ' . $email);
        }
    }
    return [
        'name' => trim((string) ($_POST['name'] ?? '')),
        'frequency' => $frequency,
        'run_time' => (string) ($_POST['run_time'] ?? '08:00'),
        'weekday' => min(7, max(1, (int) ($_POST['weekday'] ?? 1))),
        'day_of_month' => min(28, max(1, (int) ($_POST['day_of_month'] ?? 1))),
        'custom_recipients_json' => json_encode(array_values(array_unique($emails)), JSON_THROW_ON_ERROR),
        'user_ids_json' => json_encode(array_values(array_unique(array_map('intval', $_POST['user_ids'] ?? []))), JSON_THROW_ON_ERROR),
    ];
}

function sqlbak_save_report_schedule(PDO $pdo, int $scheduleId, array $schedule): int
{
    if ($schedule['name'] === '') {
        throw new InvalidArgumentException('اسم التقرير مطلوب.');
    }
    $nextRun = sqlbak_report_next_run($schedule)->format('Y-m-d H:i:s');
    $values = [...array_values($schedule), $nextRun];
    if ($scheduleId > 0) {
        $pdo->prepare('UPDATE report_schedules SET name=?,frequency=?,run_time=?,weekday=?,day_of_month=?,custom_recipients_json=?,user_ids_json=?,next_run_at=? WHERE id=?')->execute([...$values, $scheduleId]);
        sqlbak_report_schedule_by_id($pdo, $scheduleId);
        return $scheduleId;
    }
    $pdo->prepare('INSERT INTO report_schedules (name,enabled,frequency,run_time,weekday,day_of_month,custom_recipients_json,user_ids_json,next_run_at) VALUES (?,1,?,?,?,?,?,?,?)')->execute($values);
    return (int) $pdo->lastInsertId();
}

function sqlbak_report_schedule_by_id(PDO $pdo, int $scheduleId): array
{
    $statement = $pdo->prepare('SELECT * FROM report_schedules WHERE id=?');
    $statement->execute([$scheduleId]);
    $schedule = $statement->fetch();
    if (!$schedule) {
        throw new RuntimeException('جدول التقرير غير موجود. حدّث الصفحة وحاول مرة أخرى.');
    }
    return $schedule;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    sqlbak_verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $scheduleId = (int) ($_POST['id'] ?? 0);
    $redirectUrl = 'reports.php';
    try {
        if ($action === 'save') {
            $scheduleId = sqlbak_save_report_schedule($pdo, $scheduleId, sqlbak_report_schedule_request());
            sqlbak_flash('success', 'تم حفظ جدول التقرير.');
            $redirectUrl = 'reports.php?edit=' . $scheduleId . '#report-form';
        } elseif ($action === 'toggle') {
            sqlbak_report_schedule_by_id($pdo, $scheduleId);
            $pdo->prepare('UPDATE report_schedules SET enabled=1-enabled WHERE id=?')->execute([$scheduleId]);
            sqlbak_flash('success', 'تم تحديث حالة التقرير.');
        } elseif ($action === 'delete') {
            $pdo->prepare('DELETE FROM report_schedules WHERE id=?')->execute([$scheduleId]);
            sqlbak_flash('success', 'تم حذف جدول التقرير.');
        } elseif ($action === 'send_now') {
            $deliveryId = sqlbak_send_report(sqlbak_report_schedule_by_id($pdo, $scheduleId));
            sqlbak_flash('success', 'تم إرسال التقرير. Delivery #' . $deliveryId);
        } elseif ($action === 'send_sql_report') {
            $mail = sqlbak_mail_settings();
            $deliveryId = sqlbak_send_report(['name' => 'تقرير SQLBak يدوي', 'frequency' => 'daily', 'custom_recipients_json' => $mail['default_report_recipients_json'] ?? '[]', 'user_ids_json' => '[]']);
            sqlbak_flash('success', 'تم إرسال تقرير SQLBak. Delivery #' . $deliveryId);
        }
    } catch (Throwable $error) {
        sqlbak_flash('error', $error->getMessage());
        if ($action === 'save' && $scheduleId > 0) {
            $redirectUrl = 'reports.php?edit=' . $scheduleId . '#report-form';
        }
    }
    header('Location: ' . $redirectUrl);
    exit;
}

$edit = null;
if (isset($_GET['edit'])) {
    $statement = $pdo->prepare('SELECT * FROM report_schedules WHERE id=?');
    $statement->execute([(int) $_GET['edit']]);
    $edit = $statement->fetch() ?: null;
}
$users = $pdo->query("SELECT id,username,email FROM users WHERE email<>'' ORDER BY username")->fetchAll();
$selectedUsers = array_map('intval', json_decode($edit['user_ids_json'] ?? '[]', true) ?: []);
$customRecipients = implode(', ', json_decode($edit['custom_recipients_json'] ?? '[]', true) ?: []);
$schedules = $pdo->query('SELECT * FROM report_schedules ORDER BY enabled DESC,next_run_at,id')->fetchAll();
$deliveries = $pdo->query('SELECT d.*,s.name AS schedule_name FROM report_deliveries d LEFT JOIN report_schedules s ON s.id=d.schedule_id ORDER BY d.created_at DESC,d.id DESC LIMIT 30')->fetchAll();
$preview = null;
if (isset($_GET['preview'])) {
    $statement = $pdo->prepare('SELECT * FROM report_schedules WHERE id=?');
    $statement->execute([(int) $_GET['preview']]);
    $previewSchedule = $statement->fetch();
    if ($previewSchedule) {
        [$previewStart, $previewEnd] = sqlbak_report_period($previewSchedule['frequency']);
        $preview = ['schedule' => $previewSchedule, 'start' => $previewStart, 'end' => $previewEnd, 'report' => sqlbak_report_summary($previewStart, $previewEnd)];
    }
}
sqlbak_page_start('تقارير البريد', 'reports');
?>
<div class="form-actions"><a class="button" href="reports.php#report-form"><i class="fa fa-plus"></i> إضافة تقرير جديد</a><form method="post" class="report-send-now"><input type="hidden" name="csrf" value="<?= sqlbak_h(sqlbak_csrf_token()) ?>"><input type="hidden" name="action" value="send_sql_report"><button class="button gold" type="submit"><i class="fa fa-paper-plane"></i> إرسال تقرير SQL الآن</button></form></div>
<span id="report-form"></span>
<section class="grid-two report-layout">
    <article class="panel"><div class="panel-head"><div><h2><?= $edit ? 'تعديل التقرير' : 'إضافة تقرير مجدول' ?></h2><p>ملخص HTML في جدول مع تفاصيل الفشل والوجهات.</p></div></div><form method="post"><input type="hidden" name="csrf" value="<?= sqlbak_h(sqlbak_csrf_token()) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>"><div class="form-grid"><div class="field"><label>اسم التقرير</label><input name="name" required value="<?= sqlbak_h($edit['name'] ?? '') ?>" placeholder="الملخص اليومي"></div><div class="field"><label>التكرار</label><select name="frequency" data-report-frequency><option value="daily" <?= ($edit['frequency'] ?? '') === 'daily' ? 'selected' : '' ?>>يومي</option><option value="weekly" <?= ($edit['frequency'] ?? '') === 'weekly' ? 'selected' : '' ?>>أسبوعي</option><option value="monthly" <?= ($edit['frequency'] ?? '') === 'monthly' ? 'selected' : '' ?>>شهري</option></select></div><div class="field"><label>وقت الإرسال</label><input name="run_time" type="time" value="<?= sqlbak_h(substr((string) ($edit['run_time'] ?? '08:00'), 0, 5)) ?>"></div><div class="field" data-report-field="weekly"><label>يوم الأسبوع</label><select name="weekday"><?php foreach ([1=>'الاثنين',2=>'الثلاثاء',3=>'الأربعاء',4=>'الخميس',5=>'الجمعة',6=>'السبت',7=>'الأحد'] as $day=>$label): ?><option value="<?= $day ?>" <?= (int) ($edit['weekday'] ?? 1) === $day ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></div><div class="field" data-report-field="monthly"><label>يوم الشهر</label><input name="day_of_month" type="number" min="1" max="28" value="<?= (int) ($edit['day_of_month'] ?? 1) ?>"></div><div class="field full"><label>عناوين إضافية</label><textarea name="custom_recipients" placeholder="admin@example.com, backup@example.com"><?= sqlbak_h($customRecipients) ?></textarea></div><div class="field full"><label>مستخدمو SQLBak</label><select name="user_ids[]" multiple size="4"><?php foreach ($users as $user): ?><option value="<?= (int) $user['id'] ?>" <?= in_array((int) $user['id'], $selectedUsers, true) ? 'selected' : '' ?>><?= sqlbak_h($user['username'] . ' · ' . $user['email']) ?></option><?php endforeach; ?></select></div></div><div class="form-actions"><label class="switch"><input type="checkbox" name="enabled" <?= !$edit || $edit['enabled'] ? 'checked' : '' ?>><span></span> مفعّل</label><button class="button"><i class="fa fa-save"></i> حفظ</button><?php if ($edit): ?><a class="button secondary" href="reports.php">إلغاء</a><?php endif; ?></div></form></article>
    <article class="panel"><div class="panel-head"><div><h2>الجداول الحالية</h2><p><?= count($schedules) ?> تقرير.</p></div></div><div class="report-schedules"><?php foreach ($schedules as $schedule): ?><div class="report-card"><div><span class="health-dot is-<?= $schedule['enabled'] ? ($schedule['last_status'] === 'failed' ? 'failed' : 'success') : 'disabled' ?>"></span><strong><?= sqlbak_h($schedule['name']) ?></strong><small><?= sqlbak_h($schedule['frequency']) ?> · القادمة <span dir="ltr"><?= sqlbak_h($schedule['next_run_at'] ?? '-') ?></span></small></div><?php if ($schedule['last_error']): ?><p class="inline-error"><?= sqlbak_h($schedule['last_error']) ?></p><?php endif; ?><div class="table-actions"><a class="icon-button" href="?preview=<?= (int) $schedule['id'] ?>" title="معاينة"><i class="fa fa-eye"></i></a><a class="icon-button" href="?edit=<?= (int) $schedule['id'] ?>" title="تعديل"><i class="fa fa-pencil"></i></a><form method="post"><input type="hidden" name="csrf" value="<?= sqlbak_h(sqlbak_csrf_token()) ?>"><input type="hidden" name="action" value="send_now"><input type="hidden" name="id" value="<?= (int) $schedule['id'] ?>"><button class="icon-button" title="إرسال الآن"><i class="fa fa-paper-plane"></i></button></form><form method="post"><input type="hidden" name="csrf" value="<?= sqlbak_h(sqlbak_csrf_token()) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $schedule['id'] ?>"><button class="icon-button" title="تفعيل/تعطيل"><i class="fa fa-power-off"></i></button></form><form method="post" onsubmit="return confirm('حذف التقرير؟')"><input type="hidden" name="csrf" value="<?= sqlbak_h(sqlbak_csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $schedule['id'] ?>"><button class="icon-button danger-icon"><i class="fa fa-trash"></i></button></form></div></div><?php endforeach; ?><?php if ($schedules === []): ?><div class="empty">لا توجد تقارير مجدولة.</div><?php endif; ?></div></article>
</section>
<?php if ($preview): ?><section class="panel"><div class="panel-head"><div><h2>معاينة: <?= sqlbak_h($preview['schedule']['name']) ?></h2><p dir="ltr"><?= $preview['start']->format('Y-m-d H:i') ?> — <?= $preview['end']->format('Y-m-d H:i') ?></p></div><a class="button secondary" href="reports.php">إغلاق</a></div><div class="report-preview"><?= sqlbak_report_html($preview['report'], $preview['start'], $preview['end']) ?></div></section><?php endif; ?>
<section class="panel"><div class="panel-head"><div><h2>سجل الإرسال</h2><p>آخر 30 محاولة.</p></div></div><div class="table-wrap"><table><thead><tr><th>التقرير</th><th>الفترة</th><th>الحالة</th><th>السبب</th><th>Trace</th><th>التاريخ</th></tr></thead><tbody><?php foreach ($deliveries as $delivery): ?><tr><td><?= sqlbak_h($delivery['schedule_name'] ?: 'محذوف') ?></td><td dir="ltr"><?= sqlbak_h($delivery['period_start']) ?> — <?= sqlbak_h($delivery['period_end']) ?></td><td><span class="status status-<?= $delivery['status'] === 'success' ? 'success' : ($delivery['status'] === 'failed' ? 'failed' : 'queued') ?>"><?= sqlbak_h($delivery['status']) ?></span></td><td><?= sqlbak_h(($delivery['error_code'] ? $delivery['error_code'] . ' · ' : '') . ($delivery['error_message'] ?: '-')) ?></td><td><code><?= sqlbak_h($delivery['trace_id']) ?></code></td><td dir="ltr"><?= sqlbak_h($delivery['created_at']) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<script src="assets/js/reports.js?v=20260711"></script>
<?php sqlbak_page_end();
