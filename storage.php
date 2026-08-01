<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/destination_health.php';

sqlbak_require_admin();
$pdo = sqlbak_db();

function sqlbak_storage_request(): array
{
    $type = (string) ($_POST['type'] ?? 'local');
    $basePath = trim((string) ($_POST['base_path'] ?? ''));
    if (!in_array($type, ['local', 'ftp', 'sftp'], true) || trim((string) ($_POST['name'] ?? '')) === '' || $basePath === '') {
        throw new InvalidArgumentException('اسم الوجهة ونوعها ومسارها مطلوبة.');
    }
    if ($type === 'local') {
        sqlbak_validate_local_destination($basePath);
    } else {
        sqlbak_validate_remote_destination($type);
    }
    return [
        'name' => trim((string) $_POST['name']),
        'display_order' => max(1, (int) ($_POST['display_order'] ?? 1)),
        'type' => $type,
        'host' => $type === 'local' ? null : trim((string) ($_POST['host'] ?? '')),
        'port' => $type === 'local' ? null : (int) ($_POST['port'] ?? ($type === 'sftp' ? 22 : 21)),
        'username' => $type === 'local' ? null : trim((string) ($_POST['username'] ?? '')),
        'base_path' => $basePath,
        'options_json' => json_encode(['passive' => isset($_POST['passive']), 'tls' => isset($_POST['tls']), 'auth_method' => (string) ($_POST['auth_method'] ?? 'password'), 'host_fingerprint' => trim((string) ($_POST['host_fingerprint'] ?? ''))], JSON_THROW_ON_ERROR),
    ];
}

function sqlbak_validate_remote_destination(string $type): void
{
    $host = trim((string) ($_POST['host'] ?? ''));
    $username = trim((string) ($_POST['username'] ?? ''));
    $port = (int) ($_POST['port'] ?? 0);
    if ($host === '' || $username === '' || $port < 1 || $port > 65535) {
        throw new InvalidArgumentException('الخادم واسم المستخدم ومنفذ الاتصال الصحيح مطلوبة لوجهة ' . strtoupper($type) . '.');
    }
}

function sqlbak_validate_local_destination(string $path): void
{
    $root = rtrim(sqlbak_backup_root(), '/');
    $candidate = rtrim($path, '/');
    if ($candidate !== $root && !str_starts_with($candidate, $root . '/')) {
        throw new InvalidArgumentException('المسار المحلي يجب أن يكون داخل مجلد النسخ الاحتياطية.');
    }
}

function sqlbak_destination_secret(int $destinationId): ?string
{
    if ((string) ($_POST['password'] ?? '') !== '') {
        return sqlbak_encrypt(['password' => (string) $_POST['password']]);
    }
    if ((string) ($_POST['private_key'] ?? '') !== '') {
        return sqlbak_encrypt(['private_key' => (string) $_POST['private_key'], 'passphrase' => (string) ($_POST['passphrase'] ?? '')]);
    }
    if ($destinationId < 1) {
        return sqlbak_encrypt([]);
    }
    $statement = sqlbak_db()->prepare('SELECT secret_encrypted FROM storage_destinations WHERE id=?');
    $statement->execute([$destinationId]);
    return $statement->fetchColumn() ?: null;
}

function sqlbak_save_destination(PDO $pdo, int $destinationId): int
{
    $destination = sqlbak_storage_request();
    $secret = sqlbak_destination_secret($destinationId);
    $values = array_values($destination);
    if ($destinationId > 0) {
        $statement = $pdo->prepare('UPDATE storage_destinations SET name=?,display_order=?,type=?,host=?,port=?,username=?,base_path=?,options_json=?,secret_encrypted=? WHERE id=?');
        $statement->execute([...$values, $secret, $destinationId]);
        if (!sqlbak_destination_exists($pdo, $destinationId)) {
            throw new RuntimeException('وجهة التخزين المطلوب تحديثها غير موجودة. حدّث الصفحة وحاول مرة أخرى.');
        }
        return $destinationId;
    }

    $statement = $pdo->prepare("INSERT INTO storage_destinations (name,display_order,type,enabled,host,port,username,base_path,options_json,secret_encrypted,health_status) VALUES (?,?,?,1,?,?,?,?,?,?,'unknown')");
    $statement->execute([...$values, $secret]);
    return (int) $pdo->lastInsertId();
}

function sqlbak_destination_exists(PDO $pdo, int $destinationId): bool
{
    $statement = $pdo->prepare('SELECT 1 FROM storage_destinations WHERE id=?');
    $statement->execute([$destinationId]);
    return (bool) $statement->fetchColumn();
}

function sqlbak_destination_by_id(PDO $pdo, int $destinationId): array
{
    $statement = $pdo->prepare('SELECT * FROM storage_destinations WHERE id=?');
    $statement->execute([$destinationId]);
    $destination = $statement->fetch();
    if (!$destination) {
        throw new RuntimeException('وجهة التخزين غير موجودة. حدّث الصفحة وحاول مرة أخرى.');
    }
    return $destination;
}

function sqlbak_storage_size_label(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $size = (float) $bytes;
    $unit = 0;
    while ($size >= 1024 && $unit < count($units) - 1) {
        $size /= 1024;
        $unit++;
    }
    return number_format($size, $unit === 0 ? 0 : 1) . ' ' . $units[$unit];
}

function sqlbak_local_destination_space(array $destination): ?array
{
    if ($destination['type'] !== 'local' || !is_dir($destination['base_path'])) {
        return null;
    }
    $total = disk_total_space($destination['base_path']);
    $available = disk_free_space($destination['base_path']);
    if ($total === false || $available === false || $total < 1) {
        return null;
    }
    $used = $total - $available;
    return ['total' => (int) $total, 'available' => (int) $available, 'used' => (int) $used, 'used_percent' => (int) round(($used / $total) * 100)];
}

function sqlbak_toggle_destination(PDO $pdo, int $destinationId): bool
{
    $destination = sqlbak_destination_by_id($pdo, $destinationId);
    $enabled = !(bool) $destination['enabled'];
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE storage_destinations SET enabled=?,health_status=? WHERE id=?')
            ->execute([$enabled ? 1 : 0, $enabled ? 'unknown' : 'disabled', $destinationId]);
        sqlbak_audit($enabled ? 'storage_destination_resumed' : 'storage_destination_paused', 'storage_destination', (string) $destinationId);
        $pdo->commit();
        return $enabled;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function sqlbak_storage_error_message(Throwable $error): string
{
    if ($error instanceof PDOException && (string) $error->getCode() === '23000') {
        return 'اسم وجهة التخزين مستخدم بالفعل. اختر اسمًا مختلفًا.';
    }
    return $error->getMessage();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    sqlbak_verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $destinationId = (int) ($_POST['id'] ?? 0);
    $redirectUrl = 'storage.php';
    try {
        if ($action === 'save') {
            $destinationId = sqlbak_save_destination($pdo, $destinationId);
            sqlbak_flash('success', 'تم حفظ وجهة التخزين وتأكيد البيانات بنجاح.');
            $redirectUrl = 'storage.php?edit=' . $destinationId . '&saved=1#storage-form';
        } elseif ($action === 'toggle') {
            $enabled = sqlbak_toggle_destination($pdo, $destinationId);
            sqlbak_flash('success', $enabled ? 'تم استئناف وجهة التخزين.' : 'تم إيقاف وجهة التخزين مؤقتاً. لن تُرسل إليها نسخ جديدة.');
        } elseif ($action === 'delete') {
            $usage = $pdo->prepare('SELECT (SELECT COUNT(*) FROM database_storage_destinations WHERE destination_id=?) AS links,(SELECT COUNT(*) FROM backup_copies WHERE destination_id=?) AS copies');
            $usage->execute([$destinationId, $destinationId]);
            $counts = $usage->fetch();
            if ((int) $counts['links'] > 0 || (int) $counts['copies'] > 0) {
                throw new RuntimeException('لا يمكن حذف وجهة مرتبطة أو لها سجل نسخ. عطّلها بدلاً من الحذف.');
            }
            $pdo->prepare('DELETE FROM storage_destinations WHERE id=?')->execute([$destinationId]);
            sqlbak_flash('success', 'تم حذف الوجهة.');
        } elseif ($action === 'test') {
            $health = sqlbak_run_destination_health_check(sqlbak_destination_by_id($pdo, $destinationId));
            sqlbak_flash($health['status'] === 'success' ? 'success' : 'error', $health['message'] . ' Trace: ' . $health['trace_id']);
        } elseif ($action === 'test_all') {
            $failed = 0;
            foreach ($pdo->query('SELECT * FROM storage_destinations ORDER BY display_order,id')->fetchAll() as $destination) {
                $failed += sqlbak_run_destination_health_check($destination)['status'] === 'failed' ? 1 : 0;
            }
            sqlbak_flash($failed === 0 ? 'success' : 'error', $failed === 0 ? 'كل الوجهات المتاحة تعمل.' : 'فشل اختبار ' . $failed . ' وجهة.');
        }
    } catch (Throwable $error) {
        $message = $action === 'save' ? sqlbak_storage_error_message($error) : $error->getMessage();
        sqlbak_flash('error', $message);
        if ($action === 'save' && $destinationId > 0) {
            $redirectUrl = 'storage.php?edit=' . $destinationId . '#storage-form';
        }
    }
    header('Location: ' . $redirectUrl);
    exit;
}

$edit = null;
if (isset($_GET['edit'])) {
    $statement = $pdo->prepare('SELECT * FROM storage_destinations WHERE id=?');
    $statement->execute([(int) $_GET['edit']]);
    $edit = $statement->fetch() ?: null;
}
$options = $edit ? (json_decode($edit['options_json'] ?? '{}', true) ?: []) : [];
$destinations = $pdo->query("SELECT d.*,COUNT(DISTINCT link.database_id) AS database_count,COALESCE(copy_stats.backup_count,0) AS backup_count FROM storage_destinations d LEFT JOIN database_storage_destinations link ON link.destination_id=d.id LEFT JOIN (SELECT destination_id,COUNT(*) AS backup_count FROM backup_copies WHERE status='success' GROUP BY destination_id) copy_stats ON copy_stats.destination_id=d.id GROUP BY d.id ORDER BY d.display_order,d.id")->fetchAll();
foreach ($destinations as &$destination) {
    $destination['space'] = sqlbak_local_destination_space($destination);
}
unset($destination);
$nextOrder = $destinations === [] ? 1 : max(array_column($destinations, 'display_order')) + 1;
sqlbak_page_start($edit ? 'تعديل وجهة التخزين' : 'وجهات التخزين', 'storage');
?>
<section class="destination-overview">
    <?php foreach ($destinations as $destination): ?>
        <article class="destination-card">
            <div class="destination-title"><span class="server-number">Server <?= (int) $destination['display_order'] ?></span><span class="health-dot is-<?= sqlbak_h($destination['health_status']) ?>" title="<?= sqlbak_h($destination['last_test_message'] ?? 'لم تُختبر') ?>"></span><div><strong><?= sqlbak_h($destination['name']) ?></strong><small><?= strtoupper(sqlbak_h($destination['type'])) ?> · <?= (int) $destination['database_count'] ?> قاعدة</small></div></div>
            <div class="destination-health"><span class="status status-<?= $destination['enabled'] ? ($destination['health_status'] === 'success' ? 'success' : ($destination['health_status'] === 'failed' ? 'failed' : 'queued')) : 'disabled' ?>"><?= $destination['enabled'] ? sqlbak_h($destination['health_status']) : 'متوقفة مؤقتاً' ?></span><b><?= $destination['last_latency_ms'] !== null ? (int) $destination['last_latency_ms'] . ' ms' : '-' ?></b><small dir="ltr"><?= sqlbak_h($destination['last_tested_at'] ?? 'لم يختبر') ?></small></div>
            <div class="storage-metrics"><div><small>قواعد مرتبطة</small><strong><i class="fa fa-database"></i> <?= (int) $destination['database_count'] ?></strong></div><div><small>عدد النسخ الاحتياطية</small><strong><i class="fa fa-history"></i> <?= (int) $destination['backup_count'] ?></strong><span>نسخة ناجحة</span></div><?php if ($destination['space']): $space = $destination['space']; ?><div class="storage-space"><small>المستخدم <?= sqlbak_storage_size_label($space['used']) ?> · <?= $space['used_percent'] ?>%</small><progress value="<?= $space['used_percent'] ?>" max="100"></progress><strong dir="ltr">متاح <?= sqlbak_storage_size_label($space['available']) ?> / <?= sqlbak_storage_size_label($space['total']) ?></strong></div><?php else: ?><div class="storage-space is-remote"><small>المساحة المتاحة</small><strong><?= $destination['type'] === 'local' ? 'تعذر قراءة المسار المحلي' : 'غير متاحة عبر ' . strtoupper(sqlbak_h($destination['type'])) ?></strong><span>يعرض النظام عدد النسخ المسجلة فقط.</span></div><?php endif; ?></div>
            <?php if ($destination['last_test_message']): ?><p class="<?= $destination['health_status'] === 'failed' ? 'inline-error' : 'muted-line' ?>"><?= sqlbak_h($destination['last_error_code'] ? $destination['last_error_code'] . ' · ' . $destination['last_test_message'] : $destination['last_test_message']) ?></p><?php endif; ?>
            <div class="table-actions"><form method="post"><input type="hidden" name="csrf" value="<?= sqlbak_h(sqlbak_csrf_token()) ?>"><input type="hidden" name="action" value="test"><input type="hidden" name="id" value="<?= (int) $destination['id'] ?>"><button class="icon-button" type="submit" title="اختبار اتصال وكتابة"><i class="fa fa-refresh"></i></button></form><form method="post"><input type="hidden" name="csrf" value="<?= sqlbak_h(sqlbak_csrf_token()) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $destination['id'] ?>"><button class="icon-button" type="submit" title="<?= $destination['enabled'] ? 'إيقاف مؤقت' : 'استئناف' ?>"><i class="fa fa-<?= $destination['enabled'] ? 'pause' : 'play' ?>"></i></button></form><a class="icon-button" href="?edit=<?= (int) $destination['id'] ?>" title="تعديل"><i class="fa fa-pencil"></i></a><form method="post" onsubmit="return confirm('حذف الوجهة؟')"><input type="hidden" name="csrf" value="<?= sqlbak_h(sqlbak_csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $destination['id'] ?>"><button class="icon-button danger-icon" type="submit" title="حذف"><i class="fa fa-trash"></i></button></form></div>
        </article>
    <?php endforeach; ?>
</section>
<section class="grid-two">
    <article class="panel" id="storage-form">
        <div class="panel-head"><div><h2><?= $edit ? 'تعديل الوجهة' : 'إضافة وجهة' ?></h2><p>يتم اختبار الاتصال والكتابة والقراءة والحذف الفعلي.</p></div><form method="post"><input type="hidden" name="csrf" value="<?= sqlbak_h(sqlbak_csrf_token()) ?>"><input type="hidden" name="action" value="test_all"><button class="button secondary"><i class="fa fa-refresh"></i> تحديث الكل</button></form></div>
        <form method="post" data-storage-form>
            <input type="hidden" name="csrf" value="<?= sqlbak_h(sqlbak_csrf_token()) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
            <div class="form-grid">
                <div class="field"><label>الاسم</label><input name="name" required value="<?= sqlbak_h($edit['name'] ?? '') ?>"></div>
                <div class="field"><label>ترتيب الخادم</label><input name="display_order" type="number" min="1" value="<?= (int) ($edit['display_order'] ?? $nextOrder) ?>"></div>
                <div class="field"><label>النوع</label><select name="type" data-destination-type><option value="local" <?= ($edit['type'] ?? '') === 'local' ? 'selected' : '' ?>>Local</option><option value="ftp" <?= ($edit['type'] ?? '') === 'ftp' ? 'selected' : '' ?>>FTP / FTPS</option><option value="sftp" <?= ($edit['type'] ?? '') === 'sftp' ? 'selected' : '' ?>>SFTP</option></select></div>
                <div class="field remote-field"><label>الخادم</label><input name="host" value="<?= sqlbak_h($edit['host'] ?? '') ?>"></div>
                <div class="field remote-field"><label>المنفذ</label><input name="port" type="number" min="1" max="65535" value="<?= (int) ($edit['port'] ?? 22) ?>"></div>
                <div class="field remote-field"><label>اسم المستخدم</label><input name="username" value="<?= sqlbak_h($edit['username'] ?? '') ?>"></div>
                <div class="field remote-field"><label>كلمة المرور (فارغة للاحتفاظ)</label><input name="password" type="password"></div>
                <div class="field full"><label>المسار الأساسي</label><input name="base_path" required value="<?= sqlbak_h($edit['base_path'] ?? sqlbak_backup_root()) ?>"></div>
                <div class="field sftp-field"><label>مصادقة SFTP</label><select name="auth_method"><option value="password" <?= ($options['auth_method'] ?? '') === 'password' ? 'selected' : '' ?>>كلمة مرور</option><option value="key" <?= ($options['auth_method'] ?? '') === 'key' ? 'selected' : '' ?>>مفتاح خاص</option></select></div>
                <div class="field sftp-field"><label>بصمة SHA-256</label><input name="host_fingerprint" value="<?= sqlbak_h($options['host_fingerprint'] ?? '') ?>"></div>
                <div class="field full sftp-field"><label>المفتاح الخاص</label><textarea name="private_key" placeholder="فارغ للاحتفاظ بالمفتاح الحالي"></textarea><input name="passphrase" type="password" placeholder="عبارة مرور المفتاح"></div>
            </div>
            <div class="form-actions"><label class="ftp-field"><input type="checkbox" name="passive" <?= ($options['passive'] ?? true) ? 'checked' : '' ?>> Passive</label><label class="ftp-field"><input type="checkbox" name="tls" <?= ($options['tls'] ?? false) ? 'checked' : '' ?>> FTPS</label><button class="button" type="submit" data-storage-submit><i class="fa fa-save"></i> <?= $edit ? 'حفظ تحديثات الوجهة' : 'حفظ الوجهة الجديدة' ?></button><?php if ($edit): ?><a class="button secondary" href="storage.php">إلغاء</a><?php endif; ?></div>
        </form>
    </article>
    <article class="panel"><div class="panel-head"><div><h2>شرح الحالة</h2><p>حالة التشغيل منفصلة عن حالة الاتصال.</p></div></div><div class="legend-list"><span><i class="health-dot is-success"></i> اتصال وكتابة ناجحة</span><span><i class="health-dot is-failed"></i> فشل مع سبب ورمز تتبع</span><span><i class="health-dot is-unknown"></i> لم تختبر أو النتيجة قديمة</span><span><i class="health-dot is-disabled"></i> الوجهة معطلة</span></div></article>
</section>
<script src="assets/js/storage.js?v=2026071202"></script>
<?php sqlbak_page_end();
