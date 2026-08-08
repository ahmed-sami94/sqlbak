<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/layout.php';
sqlbak_require_operator();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    sqlbak_verify_csrf();
    $databaseId = (int) ($_POST['database_id'] ?? 0);
    $note = trim((string) ($_POST['note'] ?? 'نسخة يدوية'));
    $check = sqlbak_db()->prepare('SELECT id FROM `databases` WHERE id = ? AND enabled = 1'); $check->execute([$databaseId]);
    if (!$check->fetchColumn()) { sqlbak_flash('error', 'اختر قاعدة بيانات مفعلة.'); header('Location: manual_backup.php'); exit; }
    sqlbak_db()->prepare("INSERT INTO backups (database_id, filename, note, type, status) VALUES (?, '', ?, 'manual', 'queued')")->execute([$databaseId, $note]);
    sqlbak_audit('backup_queued', 'database', (string)$databaseId, ['type' => 'manual']);
    sqlbak_flash('success', 'تمت إضافة النسخة اليدوية إلى قائمة التنفيذ.'); header('Location: backups.php'); exit;
}
$databases = sqlbak_db()->query('SELECT id,name FROM `databases` WHERE enabled = 1 ORDER BY name')->fetchAll();
sqlbak_page_start('نسخة احتياطية يدوية', 'backups');
?>
<section class="panel" style="max-width:760px"><div class="panel-head"><div><h2>إنشاء نسخة الآن</h2><p>تُنفذ النسخة عبر نفس محرك المهام الآمن وتُرسل إلى كل الوجهات المحددة.</p></div></div><form method="post"><input type="hidden" name="csrf" value="<?= sqlbak_h(sqlbak_csrf_token()) ?>"><div class="form-grid"><div class="field"><label>قاعدة البيانات</label><select name="database_id" required><?php foreach ($databases as $database): ?><option value="<?= (int)$database['id'] ?>"><?= sqlbak_h($database['name']) ?></option><?php endforeach; ?></select></div><div class="field"><label>ملاحظة</label><input name="note" placeholder="سبب أو وصف اختياري"></div></div><div class="form-actions"><button class="button gold"><i class="fa fa-play"></i> بدء النسخة</button><a class="button secondary" href="backups.php">إلغاء</a></div></form></section>
<?php sqlbak_page_end();
