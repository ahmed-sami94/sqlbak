<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/backup_engine.php';
sqlbak_require_operator();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: backups.php');
    exit;
}
sqlbak_verify_csrf();

$copyId = (int) ($_POST['copy_id'] ?? 0);
$traceId = sqlbak_trace_id();
$pdo = sqlbak_db();
$statement = $pdo->prepare("SELECT c.id AS copy_id,c.backup_id,c.destination_id,c.relative_path,c.checksum_sha256,c.status AS copy_status,sd.*,db.id AS database_id,db.name AS database_name,db.host AS db_host,db.port AS db_port,db.username AS db_username,db.password_encrypted AS db_password_encrypted FROM backup_copies c JOIN backups b ON b.id=c.backup_id JOIN storage_destinations sd ON sd.id=c.destination_id JOIN `databases` db ON db.id=b.database_id WHERE c.id=? AND c.status='success'");
$statement->execute([$copyId]);
$copy = $statement->fetch();
if (!$copy) {
    sqlbak_flash('error', 'النسخة المحددة غير متاحة للاستعادة.');
    header('Location: backups.php');
    exit;
}

$restorePath = sqlbak_backup_root() . '/.staging/restore-' . $copyId . '-' . bin2hex(random_bytes(6)) . '.sql.gz';
try {
    $pdo->prepare("INSERT INTO backups (database_id,trace_id,filename,note,type,status) VALUES (?,?,'','نسخة أمان قبل الاستعادة','pre_restore','queued')")->execute([$copy['database_id'], $traceId]);
    $safetyBackupId = (int) $pdo->lastInsertId();
    sqlbak_run_backup($safetyBackupId);
    $safetyCopies = $pdo->prepare("SELECT COUNT(*) FROM backup_copies WHERE backup_id=? AND status='success'");
    $safetyCopies->execute([$safetyBackupId]);
    if ((int) $safetyCopies->fetchColumn() < 1) {
        throw new SqlbakOperationException('SAFETY_BACKUP_FAILED', 'تعذر إنشاء نسخة أمان ناجحة؛ تم إيقاف الاستعادة.');
    }
    sqlbak_copy_from_destination($copy, $copy['relative_path'], $restorePath);
    if ($copy['checksum_sha256'] && !hash_equals($copy['checksum_sha256'], hash_file('sha256', $restorePath))) {
        throw new SqlbakOperationException('CHECKSUM_MISMATCH', 'بصمة النسخة لا تطابق السجل؛ تم إيقاف الاستعادة.');
    }
    sqlbak_restore_database(['password_encrypted' => $copy['db_password_encrypted'], 'username' => $copy['db_username'], 'host' => $copy['db_host'], 'port' => $copy['db_port'], 'name' => $copy['database_name']], $restorePath);
    sqlbak_record_event(['trace_id' => $traceId, 'backup_id' => $copy['backup_id'], 'copy_id' => $copyId, 'destination_id' => $copy['destination_id'], 'event_type' => 'restore', 'phase' => 'restore', 'message' => 'تمت الاستعادة من النسخة المحددة بنجاح.']);
    sqlbak_audit('backup_restored', 'backup_copy', (string) $copyId, ['backup_id' => $copy['backup_id'], 'destination_id' => $copy['destination_id'], 'trace_id' => $traceId]);
    sqlbak_flash('success', 'تمت الاستعادة من Server ' . (int) $copy['display_order'] . ' بنجاح. Trace: ' . $traceId);
} catch (Throwable $error) {
    sqlbak_record_event(['trace_id' => $traceId, 'backup_id' => $copy['backup_id'], 'copy_id' => $copyId, 'destination_id' => $copy['destination_id'], 'event_type' => 'restore', 'phase' => 'restore', 'level' => 'error', 'error_code' => sqlbak_error_code($error), 'message' => $error->getMessage()]);
    sqlbak_flash('error', 'فشلت الاستعادة: ' . $error->getMessage() . ' Trace: ' . $traceId);
} finally {
    if (is_file($restorePath)) {
        @unlink($restorePath);
    }
}
header('Location: backups.php');
