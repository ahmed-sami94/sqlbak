<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/policy.php';
require_once __DIR__ . '/lib/backup_engine.php';

sqlbak_require_admin();
$pdo = sqlbak_db();

function sqlbak_policy_from_request(): array
{
    return [
        'name' => trim((string) ($_POST['name'] ?? '')),
        'database_id' => (int) ($_POST['database_id'] ?? 0),
        'schedule_type' => (string) ($_POST['schedule_type'] ?? 'interval'),
        'interval_minutes' => (int) ($_POST['interval_minutes'] ?? 60),
        'run_time' => (string) ($_POST['run_time'] ?? '08:00'),
        'weekday' => (int) ($_POST['weekday'] ?? 1),
        'day_of_month' => (int) ($_POST['day_of_month'] ?? 1),
        'retention_count' => (int) ($_POST['retention_count'] ?? 24),
    ];
}

function sqlbak_policy_by_id(PDO $pdo, int $policyId): array
{
    $statement = $pdo->prepare('SELECT * FROM backup_policy_rules WHERE id=?');
    $statement->execute([$policyId]);
    $policy = $statement->fetch();
    if (!$policy) {
        throw new RuntimeException('السياسة غير موجودة. حدّث الصفحة وحاول مرة أخرى.');
    }
    return $policy;
}

function sqlbak_save_policy(PDO $pdo, int $policyId, array $policy): int
{
    sqlbak_validate_policy($policy);
    if ($policy['name'] === '' || $policy['database_id'] < 1) {
        throw new InvalidArgumentException('اسم السياسة وقاعدة البيانات مطلوبان.');
    }
    $nextRun = sqlbak_policy_next_run($policy)->format('Y-m-d H:i:s');
    $values = [$policy['name'], $policy['database_id'], $policy['schedule_type'], $policy['interval_minutes'], $policy['run_time'], $policy['weekday'], $policy['day_of_month'], $policy['retention_count'], $nextRun];
    if ($policyId > 0) {
        $pdo->prepare('UPDATE backup_policy_rules SET name=?,database_id=?,schedule_type=?,interval_minutes=?,run_time=?,weekday=?,day_of_month=?,retention_count=?,next_run_at=?,updated_at=NOW() WHERE id=?')->execute([...$values, $policyId]);
        sqlbak_policy_by_id($pdo, $policyId);
        return $policyId;
    }
    $pdo->prepare('INSERT INTO backup_policy_rules (name,database_id,enabled,schedule_type,interval_minutes,run_time,weekday,day_of_month,retention_count,next_run_at) VALUES (?,?,1,?,?,?,?,?,?,?)')->execute($values);
    return (int) $pdo->lastInsertId();
}

function sqlbak_test_policy(PDO $pdo, int $policyId): array
{
    $policy = sqlbak_policy_by_id($pdo, $policyId);
    $traceId = sqlbak_trace_id();
    $pdo->prepare("INSERT INTO backups (database_id,policy_rule_id,trace_id,filename,note,type,status) VALUES (?,?,?,'',?,'policy_test','queued')")
        ->execute([$policy['database_id'], $policyId, $traceId, 'اختبار السياسة: ' . $policy['name']]);
    $backupId = (int) $pdo->lastInsertId();
    sqlbak_run_backup($backupId);
    $status = $pdo->prepare('SELECT status,error_message FROM backups WHERE id=?');
    $status->execute([$backupId]);
    return ['backup_id' => $backupId, 'trace_id' => $traceId, ...$status->fetch()];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    sqlbak_verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $policyId = (int) ($_POST['id'] ?? 0);
    $redirectUrl = 'policies.php';
    try {
        if ($action === 'save') {
            $policyId = sqlbak_save_policy($pdo, $policyId, sqlbak_policy_from_request());
            sqlbak_flash('success', 'تم حفظ سياسة النسخ.');
            $redirectUrl = 'policies.php?edit=' . $policyId . '#policy-form';
        } elseif ($action === 'toggle') {
            sqlbak_policy_by_id($pdo, $policyId);
            $pdo->prepare('UPDATE backup_policy_rules SET enabled=1-enabled WHERE id=?')->execute([$policyId]);
            sqlbak_flash('success', 'تم تحديث حالة السياسة.');
        } elseif ($action === 'delete') {
            $pdo->prepare('DELETE FROM backup_policy_rules WHERE id=?')->execute([$policyId]);
            sqlbak_flash('success', 'تم حذف السياسة مع الاحتفاظ بسجل النسخ.');
        } elseif ($action === 'test') {
            $test = sqlbak_test_policy($pdo, $policyId);
            $message = $test['status'] === 'success' ? 'نجح اختبار السياسة.' : 'انتهى الاختبار بالحالة ' . $test['status'] . ': ' . ($test['error_message'] ?: 'راجع تفاصيل الوجهات.');
            sqlbak_flash($test['status'] === 'success' ? 'success' : 'error', $message . ' Trace: ' . $test['trace_id']);
        }
    } catch (Throwable $error) {
        sqlbak_flash('error', $error->getMessage());
        if ($action === 'save' && $policyId > 0) {
            $redirectUrl = 'policies.php?edit=' . $policyId . '#policy-form';
        }
    }
    header('Location: ' . $redirectUrl);
    exit;
}

$edit = null;
if (isset($_GET['edit'])) {
    $statement = $pdo->prepare('SELECT * FROM backup_policy_rules WHERE id=?');
    $statement->execute([(int) $_GET['edit']]);
    $edit = $statement->fetch() ?: null;
}
$databases = $pdo->query('SELECT id,name FROM `databases` WHERE enabled=1 ORDER BY name')->fetchAll();
$policies = $pdo->query('SELECT p.*,d.name AS database_name FROM backup_policy_rules p JOIN `databases` d ON d.id=p.database_id ORDER BY p.enabled DESC,p.next_run_at,p.id')->fetchAll();
sqlbak_page_start($edit ? 'تعديل سياسة النسخ' : 'سياسات النسخ', 'policies');
?>
<span id="policy-form"></span>
<section class="grid-two policy-layout">
    <article class="panel">
        <div class="panel-head"><div><h2><?= $edit ? 'تعديل السياسة' : 'إضافة سياسة' ?></h2><p>يمكن إنشاء أكثر من سياسة لنفس قاعدة البيانات.</p></div></div>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= sqlbak_h(sqlbak_csrf_token()) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
            <div class="form-grid">
                <div class="field"><label>اسم السياسة</label><input name="name" required value="<?= sqlbak_h($edit['name'] ?? '') ?>" placeholder="نسخة يومية رئيسية"></div>
                <div class="field"><label>قاعدة البيانات</label><select name="database_id" required><?php foreach ($databases as $database): ?><option value="<?= (int) $database['id'] ?>" <?= (int) ($edit['database_id'] ?? 0) === (int) $database['id'] ? 'selected' : '' ?>><?= sqlbak_h($database['name']) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>نوع الجدولة</label><select name="schedule_type" data-schedule-type><option value="interval" <?= ($edit['schedule_type'] ?? '') === 'interval' ? 'selected' : '' ?>>فاصل زمني</option><option value="daily" <?= ($edit['schedule_type'] ?? '') === 'daily' ? 'selected' : '' ?>>يومية</option><option value="weekly" <?= ($edit['schedule_type'] ?? '') === 'weekly' ? 'selected' : '' ?>>أسبوعية</option><option value="monthly" <?= ($edit['schedule_type'] ?? '') === 'monthly' ? 'selected' : '' ?>>شهرية</option></select></div>
                <div class="field" data-schedule-field="interval"><label>الفاصل بالدقائق</label><input name="interval_minutes" type="number" min="15" max="10080" value="<?= (int) ($edit['interval_minutes'] ?? 60) ?>"></div>
                <div class="field" data-schedule-field="time"><label>وقت التشغيل</label><input name="run_time" type="time" value="<?= sqlbak_h(substr((string) ($edit['run_time'] ?? '08:00'), 0, 5)) ?>"></div>
                <div class="field" data-schedule-field="weekly"><label>يوم الأسبوع</label><select name="weekday"><?php foreach ([1=>'الاثنين',2=>'الثلاثاء',3=>'الأربعاء',4=>'الخميس',5=>'الجمعة',6=>'السبت',7=>'الأحد'] as $day=>$label): ?><option value="<?= $day ?>" <?= (int) ($edit['weekday'] ?? 1) === $day ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></div>
                <div class="field" data-schedule-field="monthly"><label>يوم الشهر</label><input name="day_of_month" type="number" min="1" max="28" value="<?= (int) ($edit['day_of_month'] ?? 1) ?>"></div>
                <div class="field"><label>عدد النسخ المحتفظ بها</label><input name="retention_count" type="number" min="1" max="1000" value="<?= (int) ($edit['retention_count'] ?? 24) ?>"></div>
            </div>
            <div class="form-actions"><button class="button" type="submit"><i class="fa fa-save"></i> <?= $edit ? 'حفظ التحديثات' : 'حفظ السياسة الجديدة' ?></button><?php if ($edit): ?><a class="button secondary" href="policies.php">إلغاء</a><?php endif; ?></div>
        </form>
    </article>
    <article class="panel policy-list-panel">
        <div class="panel-head"><div><h2>السياسات الحالية</h2><p><?= count($policies) ?> سياسة قابلة للإدارة والاختبار.</p></div></div>
        <div class="policy-cards">
            <?php foreach ($policies as $policy): ?>
                <div class="policy-card <?= $policy['enabled'] ? '' : 'is-disabled' ?>">
                    <div><span class="health-dot <?= $policy['enabled'] ? 'is-success' : 'is-disabled' ?>"></span><strong><?= sqlbak_h($policy['name']) ?></strong><small><?= sqlbak_h($policy['database_name']) ?> · <?= sqlbak_h(sqlbak_policy_schedule_label($policy)) ?></small></div>
                    <div class="policy-meta"><span>القادمة <b dir="ltr"><?= sqlbak_h($policy['next_run_at'] ?? '-') ?></b></span><span class="status status-<?= sqlbak_h($policy['last_status'] === 'never' ? 'queued' : $policy['last_status']) ?>"><?= sqlbak_h($policy['last_status']) ?></span></div>
                    <?php if ($policy['last_error']): ?><p class="inline-error"><i class="fa fa-exclamation-circle"></i> <?= sqlbak_h($policy['last_error']) ?></p><?php endif; ?>
                    <div class="table-actions"><a class="icon-button" href="?edit=<?= (int) $policy['id'] ?>" title="تعديل"><i class="fa fa-pencil"></i></a><form method="post"><input type="hidden" name="csrf" value="<?= sqlbak_h(sqlbak_csrf_token()) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $policy['id'] ?>"><button class="icon-button" title="تفعيل/تعطيل"><i class="fa fa-power-off"></i></button></form><form method="post"><input type="hidden" name="csrf" value="<?= sqlbak_h(sqlbak_csrf_token()) ?>"><input type="hidden" name="action" value="test"><input type="hidden" name="id" value="<?= (int) $policy['id'] ?>"><button class="icon-button" title="اختبار السياسة"><i class="fa fa-flask"></i></button></form><form method="post" onsubmit="return confirm('حذف السياسة مع الاحتفاظ بسجل النسخ؟')"><input type="hidden" name="csrf" value="<?= sqlbak_h(sqlbak_csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $policy['id'] ?>"><button class="icon-button danger-icon" title="حذف"><i class="fa fa-trash"></i></button></form></div>
                </div>
            <?php endforeach; ?>
        </div>
    </article>
</section>
<script src="assets/js/policies.js?v=20260711"></script>
<?php sqlbak_page_end();
