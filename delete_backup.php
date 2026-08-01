<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/backup_engine.php';
sqlbak_require_admin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: backups.php');
    exit;
}
sqlbak_verify_csrf();

$backupId = (int) ($_POST['backup_id'] ?? 0);
$pdo = sqlbak_db();
$copies = $pdo->prepare("SELECT c.*, d.* FROM backup_copies c JOIN storage_destinations d ON d.id = c.destination_id WHERE c.backup_id = ? AND c.status = 'success'");
$copies->execute([$backupId]);
try {
    foreach ($copies->fetchAll() as $copy) {
        sqlbak_delete_destination_copy($copy, $copy['relative_path']);
    }
    $pdo->prepare('DELETE FROM backups WHERE id = ? AND type = \'manual\'')->execute([$backupId]);
    sqlbak_audit('backup_deleted', 'backup', (string) $backupId);
    sqlbak_flash('success', 'تم حذف النسخة.');
} catch (Throwable $error) {
    sqlbak_flash('error', 'تعذر حذف النسخة: ' . $error->getMessage());
}
header('Location: backups.php');
