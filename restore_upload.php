<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/upload_restore.php';

sqlbak_require_operator();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: backups.php');
    exit;
}
sqlbak_verify_csrf();

$pdo = sqlbak_db();
$traceId = sqlbak_trace_id();
$stagingPath = null;
$createdDatabase = null;
try {
    $upload = sqlbak_upload_restore_file();
    $mode = (string) ($_POST['restore_mode'] ?? 'existing');
    if ($mode === 'existing') {
        $database = sqlbak_template_database($pdo, (int) ($_POST['database_id'] ?? 0));
        sqlbak_safety_backup($database, $traceId);
    } elseif ($mode === 'new') {
        $template = sqlbak_template_database($pdo, (int) ($_POST['template_database_id'] ?? 0));
        $database = sqlbak_create_database_from_template($template, sqlbak_new_database_name());
        $createdDatabase = $database;
    } else {
        throw new InvalidArgumentException('نوع الاستعادة غير صالح.');
    }
    $stagingPath = sqlbak_uploaded_restore_path($upload, $traceId);
    if (!move_uploaded_file($upload['tmp_path'], $stagingPath)) {
        throw new RuntimeException('تعذر نقل الملف المرفوع إلى مساحة الاستعادة المؤقتة.');
    }
    chmod($stagingPath, 0600);
    sqlbak_sql_file_is_safe($stagingPath, $upload['gzip']);
    sqlbak_restore_database($database, $stagingPath);
    $databaseId = $mode === 'new' ? sqlbak_register_imported_database($pdo, $database) : (int) $database['id'];
    sqlbak_record_event(['trace_id' => $traceId, 'backup_id' => null, 'event_type' => 'upload_restore', 'phase' => 'restore', 'message' => 'تمت استعادة الملف المرفوع بنجاح.', 'context' => ['database_id' => $databaseId, 'filename' => $upload['name'], 'size_bytes' => $upload['size_bytes']]]);
    sqlbak_audit('uploaded_sql_restored', 'database', (string) $databaseId, ['trace_id' => $traceId, 'filename' => $upload['name']]);
    sqlbak_flash('success', 'تمت استعادة الملف إلى قاعدة البيانات بنجاح. Trace: ' . $traceId);
} catch (Throwable $error) {
    if ($createdDatabase) {
        try {
            sqlbak_drop_created_database($createdDatabase);
        } catch (Throwable $cleanupError) {
            sqlbak_record_event(['trace_id' => $traceId, 'event_type' => 'upload_restore', 'phase' => 'cleanup', 'level' => 'warning', 'error_code' => sqlbak_error_code($cleanupError), 'message' => $cleanupError->getMessage()]);
        }
    }
    sqlbak_record_event(['trace_id' => $traceId, 'event_type' => 'upload_restore', 'phase' => 'restore', 'level' => 'error', 'error_code' => sqlbak_error_code($error), 'message' => $error->getMessage()]);
    sqlbak_flash('error', 'فشلت استعادة الملف المرفوع: ' . $error->getMessage() . ' Trace: ' . $traceId);
} finally {
    if ($stagingPath && is_file($stagingPath)) {
        unlink($stagingPath);
    }
}
header('Location: backups.php');
