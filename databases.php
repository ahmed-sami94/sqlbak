<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/layout.php';
sqlbak_require_operator();
$pdo = sqlbak_db();

function sqlbak_database_request(): array
{
    $destinationIds = array_values(array_unique(array_map('intval', $_POST['destination_ids'] ?? [])));
    $database = [
        'name' => trim((string) ($_POST['name'] ?? '')),
        'host' => trim((string) ($_POST['host'] ?? '')),
        'port' => (int) ($_POST['port'] ?? 3306),
        'username' => trim((string) ($_POST['username'] ?? '')),
        'password' => (string) ($_POST['password'] ?? ''),
        'destination_ids' => $destinationIds,
    ];
    if ($database['name'] === '' || $database['host'] === '' || $database['port'] < 1 || $database['username'] === '') {
        throw new InvalidArgumentException('أكمل بيانات اتصال قاعدة البيانات.');
    }
    return $database;
}

function sqlbak_upsert_database(PDO $pdo, int $databaseId, array $database): int
{
    if ($databaseId === 0 && $database['password'] === '') {
        throw new InvalidArgumentException('كلمة مرور قاعدة البيانات مطلوبة عند الإضافة.');
    }
    if ($databaseId > 0) {
        $passwordSql = $database['password'] === '' ? '' : ',password_encrypted=?';
        $values = [$database['name'], $database['host'], $database['port'], $database['username']];
        if ($database['password'] !== '') {
            $values[] = sqlbak_encrypt(['password' => $database['password']]);
        }
        $values[] = $databaseId;
        $pdo->prepare('UPDATE `databases` SET name=?,host=?,port=?,username=?' . $passwordSql . ' WHERE id=?')->execute($values);
    } else {
        $pdo->prepare('INSERT INTO `databases` (name,host,port,username,password_encrypted,enabled) VALUES (?,?,?,?,?,1)')->execute([$database['name'], $database['host'], $database['port'], $database['username'], sqlbak_encrypt(['password' => $database['password']])]);
        $databaseId = (int) $pdo->lastInsertId();
    }
    return $databaseId;
}

function sqlbak_sync_database_destinations(PDO $pdo, int $databaseId, array $destinationIds): void
{
    $pdo->prepare('DELETE FROM database_storage_destinations WHERE database_id=?')->execute([$databaseId]);
    $link = $pdo->prepare('INSERT INTO database_storage_destinations (database_id,destination_id) VALUES (?,?)');
    foreach ($destinationIds as $destinationId) {
        $link->execute([$databaseId, $destinationId]);
    }
}

function sqlbak_database_enabled(PDO $pdo, int $databaseId): bool
{
    $statement = $pdo->prepare('SELECT enabled FROM `databases` WHERE id=?');
    $statement->execute([$databaseId]);
    $enabled = $statement->fetchColumn();
    if ($enabled === false) {
        throw new RuntimeException('قاعدة البيانات غير موجودة. حدّث الصفحة وحاول مرة أخرى.');
    }
    return (bool) $enabled;
}

function sqlbak_toggle_database(PDO $pdo, int $databaseId): bool
{
    $enabled = !sqlbak_database_enabled($pdo, $databaseId);
    if ($enabled) {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM database_storage_destinations WHERE database_id=?');
        $statement->execute([$databaseId]);
        if ((int) $statement->fetchColumn() === 0) {
            throw new RuntimeException('اختر وجهة تخزين واحدة على الأقل قبل تفعيل قاعدة البيانات.');
        }
    }
    $pdo->prepare('UPDATE `databases` SET enabled=? WHERE id=?')->execute([$enabled ? 1 : 0, $databaseId]);
    sqlbak_audit($enabled ? 'database_enabled' : 'database_disabled', 'database', (string) $databaseId);
    return $enabled;
}

function sqlbak_save_database(PDO $pdo, int $databaseId, array $database): int
{
    $requiresDestination = $databaseId === 0 || sqlbak_database_enabled($pdo, $databaseId);
    if ($requiresDestination && $database['destination_ids'] === []) {
        throw new InvalidArgumentException('اختر وجهة تخزين واحدة على الأقل لقاعدة البيانات المفعلة.');
    }
    $pdo->beginTransaction();
    try {
        $databaseId = sqlbak_upsert_database($pdo, $databaseId, $database);
        sqlbak_sync_database_destinations($pdo, $databaseId, $database['destination_ids']);
        sqlbak_audit('database_destinations_updated', 'database', (string) $databaseId, ['destination_ids' => $database['destination_ids']]);
        $pdo->commit();
        return $databaseId;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    sqlbak_verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $redirectUrl = 'databases.php';
    try {
        if ($action === 'save') {
            $databaseId = sqlbak_save_database($pdo, (int) ($_POST['id'] ?? 0), sqlbak_database_request());
            sqlbak_flash('success', 'تم حفظ قاعدة البيانات ووجهاتها.');
            $redirectUrl = 'databases.php?edit=' . $databaseId . '#database-form';
        } elseif ($action === 'toggle') {
            $databaseId = (int) ($_POST['id'] ?? 0);
            $enabled = sqlbak_toggle_database($pdo, $databaseId);
            sqlbak_flash('success', $enabled ? 'تم تفعيل قاعدة البيانات.' : 'تم إيقاف قاعدة البيانات مؤقتاً.');
            $redirectUrl = 'databases.php?edit=' . $databaseId . '#database-form';
        } elseif ($action === 'delete') {
            $databaseId = (int) ($_POST['id'] ?? 0);
            $pdo->prepare('DELETE FROM `databases` WHERE id=?')->execute([$databaseId]);
            sqlbak_audit('database_deleted', 'database', (string) $databaseId);
            sqlbak_flash('success', 'تم حذف إعداد قاعدة البيانات.');
        }
    } catch (Throwable $error) {
        sqlbak_flash('error', $error->getMessage());
        if ($action === 'save' && isset($_POST['id']) && (int) $_POST['id'] > 0) {
            $redirectUrl = 'databases.php?edit=' . (int) $_POST['id'] . '#database-form';
        }
    }
    header('Location: ' . $redirectUrl);
    exit;
}

$edit = null;
if (isset($_GET['edit'])) {
    $statement = $pdo->prepare('SELECT * FROM `databases` WHERE id=?');
    $statement->execute([(int) $_GET['edit']]);
    $edit = $statement->fetch() ?: null;
}
$destinations = $pdo->query('SELECT id,name,type,display_order,enabled,health_status FROM storage_destinations ORDER BY display_order,id')->fetchAll();
$selected = [];
if ($edit) {
    $statement = $pdo->prepare('SELECT destination_id FROM database_storage_destinations WHERE database_id=?');
    $statement->execute([$edit['id']]);
    $selected = array_map('intval', array_column($statement->fetchAll(), 'destination_id'));
}
$databases = $pdo->query('SELECT d.*,COUNT(link.destination_id) AS destinations FROM `databases` d LEFT JOIN database_storage_destinations link ON link.database_id=d.id GROUP BY d.id ORDER BY d.name')->fetchAll();
sqlbak_page_start($edit ? 'تعديل قاعدة البيانات' : 'قواعد البيانات', 'databases');
?>
<span id="database-form"></span>
<?php if ($edit): ?>
    <form method="post" class="entity-state-action">
        <input type="hidden" name="csrf" value="<?= sqlbak_h(sqlbak_csrf_token()) ?>">
        <input type="hidden" name="action" value="toggle">
        <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
        <button class="button secondary" type="submit"><i class="fa fa-<?= $edit['enabled'] ? 'pause' : 'play' ?>"></i> <?= $edit['enabled'] ? 'إيقاف قاعدة البيانات مؤقتاً' : 'تفعيل قاعدة البيانات' ?></button>
    </form>
<?php endif; ?>
<section class="grid-two"><article class="panel"><div class="panel-head"><div><h2><?= $edit ? 'تحديث الاتصال' : 'إضافة قاعدة بيانات' ?></h2><p>يُشفر سر الاتصال وتُسجل تغييرات الوجهات.</p></div></div><form method="post"><input type="hidden" name="csrf" value="<?= sqlbak_h(sqlbak_csrf_token()) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>"><div class="form-grid"><div class="field"><label>اسم القاعدة</label><input name="name" required value="<?= sqlbak_h($edit['name'] ?? '') ?>"></div><div class="field"><label>الخادم</label><input name="host" required value="<?= sqlbak_h($edit['host'] ?? '') ?>"></div><div class="field"><label>المنفذ</label><input name="port" type="number" min="1" max="65535" value="<?= (int) ($edit['port'] ?? 3306) ?>"></div><div class="field"><label>اسم المستخدم</label><input name="username" required value="<?= sqlbak_h($edit['username'] ?? '') ?>"></div><div class="field full"><label>كلمة المرور <?= $edit ? '(فارغة للاحتفاظ بها)' : '' ?></label><input name="password" type="password" <?= $edit ? '' : 'required' ?>></div><div class="field full"><label>وجهات التخزين</label><select name="destination_ids[]" multiple size="5" required><?php foreach ($destinations as $destination): ?><option value="<?= (int) $destination['id'] ?>" <?= in_array((int) $destination['id'], $selected, true) ? 'selected' : '' ?>>Server <?= (int) $destination['display_order'] ?> · <?= sqlbak_h($destination['name']) ?> (<?= sqlbak_h($destination['type']) ?> · <?= sqlbak_h($destination['enabled'] ? $destination['health_status'] : 'disabled') ?>)</option><?php endforeach; ?></select></div></div><div class="form-actions"><label class="switch"><input type="checkbox" name="enabled" <?= !$edit || $edit['enabled'] ? 'checked' : '' ?>><span></span> مفعلة</label><button class="button"><i class="fa fa-save"></i> حفظ</button><?php if ($edit): ?><a class="button secondary" href="databases.php">إلغاء</a><?php endif; ?></div></form></article><article class="panel"><div class="panel-head"><div><h2>قواعد مضافة</h2><p>قاعدة مفعلة يجب أن تحتوي على وجهة واحدة على الأقل.</p></div></div><div class="table-wrap"><table><thead><tr><th>القاعدة</th><th>الخادم</th><th>الوجهات</th><th></th></tr></thead><tbody><?php foreach ($databases as $database): ?><tr><td><?= sqlbak_h($database['name']) ?></td><td><?= sqlbak_h($database['host']) ?>:<?= (int) $database['port'] ?></td><td><?= (int) $database['destinations'] ?></td><td class="table-actions"><a class="icon-button" href="?edit=<?= (int) $database['id'] ?>"><i class="fa fa-pencil"></i></a><form method="post" onsubmit="return confirm('حذف إعداد القاعدة؟')"><input type="hidden" name="csrf" value="<?= sqlbak_h(sqlbak_csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $database['id'] ?>"><button class="icon-button danger-icon"><i class="fa fa-trash"></i></button></form></td></tr><?php endforeach; ?></tbody></table></div></article></section>
<?php sqlbak_page_end();
