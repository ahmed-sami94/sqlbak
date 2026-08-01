<?php
declare(strict_types=1);

require_once __DIR__ . '/backup_engine.php';
require_once __DIR__ . '/trace.php';

function sqlbak_probe_destination(array $destination): array
{
    $startedAt = microtime(true);
    $probeName = '.sqlbak-health-' . bin2hex(random_bytes(6));
    $payload = 'sqlbak-health-' . date(DATE_ATOM);
    match ($destination['type']) {
        'local' => sqlbak_probe_local($destination, $probeName, $payload),
        'ftp' => sqlbak_probe_ftp($destination, $probeName, $payload),
        'sftp' => sqlbak_probe_sftp($destination, $probeName, $payload),
        default => throw new SqlbakOperationException('UNSUPPORTED_DESTINATION', 'نوع وجهة التخزين غير مدعوم.'),
    };
    return ['latency_ms' => (int) round((microtime(true) - $startedAt) * 1000), 'message' => 'الاتصال والكتابة والقراءة والحذف تعمل بنجاح.'];
}

function sqlbak_probe_local(array $destination, string $probeName, string $payload): void
{
    $basePath = rtrim($destination['base_path'], '/');
    $allowedRoot = realpath(sqlbak_backup_root()) ?: sqlbak_backup_root();
    $resolvedBase = realpath($basePath) ?: $basePath;
    if ($resolvedBase !== $allowedRoot && !str_starts_with($resolvedBase, $allowedRoot . '/')) {
        throw new SqlbakOperationException('PATH_NOT_ALLOWED', 'المسار المحلي خارج مجلد النسخ المسموح.');
    }
    sqlbak_create_directory($basePath);
    $probePath = $basePath . '/' . $probeName;
    if (file_put_contents($probePath, $payload) === false || file_get_contents($probePath) !== $payload) {
        @unlink($probePath);
        throw new SqlbakOperationException('VERIFY_FAILED', 'تعذر التحقق من الكتابة في المسار المحلي.');
    }
    @unlink($probePath);
}

function sqlbak_probe_ftp(array $destination, string $probeName, string $payload): void
{
    $client = sqlbak_destination_client($destination);
    $connection = sqlbak_ftp_connect($client);
    $basePath = rtrim($destination['base_path'], '/');
    if (@ftp_nlist($connection, $basePath) === false) {
        ftp_close($connection);
        throw new SqlbakOperationException('PATH_NOT_FOUND', 'مسار FTP غير موجود أو غير قابل للقراءة.');
    }
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $payload);
    rewind($stream);
    $remotePath = $basePath . '/' . $probeName;
    $uploaded = ftp_fput($connection, $remotePath, $stream, FTP_BINARY);
    fclose($stream);
    $verified = $uploaded && ftp_size($connection, $remotePath) === strlen($payload);
    @ftp_delete($connection, $remotePath);
    ftp_close($connection);
    if (!$verified) {
        throw new SqlbakOperationException('WRITE_FAILED', 'تعذر الكتابة أو التحقق من ملف اختبار FTP.');
    }
}

function sqlbak_probe_sftp(array $destination, string $probeName, string $payload): void
{
    $client = sqlbak_destination_client($destination);
    $sftp = sqlbak_sftp_client($client);
    $basePath = rtrim($destination['base_path'], '/');
    if (!$sftp->is_dir($basePath)) {
        throw new SqlbakOperationException('PATH_NOT_FOUND', 'مسار SFTP غير موجود: ' . $basePath);
    }
    $remotePath = $basePath . '/' . $probeName;
    if (!$sftp->put($remotePath, $payload) || $sftp->get($remotePath) !== $payload) {
        $sftp->delete($remotePath);
        throw new SqlbakOperationException('WRITE_FAILED', 'تعذر الكتابة أو القراءة من مسار SFTP.');
    }
    if (!$sftp->delete($remotePath)) {
        throw new SqlbakOperationException('DELETE_FAILED', 'نجح الاختبار لكن تعذر حذف ملف اختبار SFTP.');
    }
}

function sqlbak_run_destination_health_check(array $destination): array
{
    $traceId = sqlbak_trace_id();
    if (!(bool) $destination['enabled']) {
        sqlbak_db()->prepare("UPDATE storage_destinations SET health_status='disabled' WHERE id=?")->execute([$destination['id']]);
        return ['status' => 'disabled', 'message' => 'الوجهة معطلة.', 'trace_id' => $traceId];
    }
    try {
        $probe = sqlbak_probe_destination($destination);
        sqlbak_store_health_success($destination, $traceId, $probe);
        return ['status' => 'success', 'message' => $probe['message'], 'latency_ms' => $probe['latency_ms'], 'trace_id' => $traceId];
    } catch (Throwable $error) {
        $errorCode = sqlbak_error_code($error);
        sqlbak_store_health_failure($destination, $traceId, $errorCode, $error->getMessage());
        return ['status' => 'failed', 'message' => $error->getMessage(), 'error_code' => $errorCode, 'trace_id' => $traceId];
    }
}

function sqlbak_store_health_success(array $destination, string $traceId, array $probe): void
{
    $pdo = sqlbak_db();
    $pdo->prepare("UPDATE storage_destinations SET health_status='success',last_test_status='success',last_test_message=?,last_error_code=NULL,last_tested_at=NOW(),last_latency_ms=?,last_success_at=NOW(),consecutive_failures=0 WHERE id=?")
        ->execute([$probe['message'], $probe['latency_ms'], $destination['id']]);
    $pdo->prepare("INSERT INTO destination_health_checks (destination_id,trace_id,status,message,latency_ms) VALUES (?,?,'success',?,?)")
        ->execute([$destination['id'], $traceId, $probe['message'], $probe['latency_ms']]);
    sqlbak_record_event(['trace_id' => $traceId, 'destination_id' => $destination['id'], 'event_type' => 'destination_health', 'phase' => 'probe', 'message' => $probe['message'], 'context' => ['latency_ms' => $probe['latency_ms']]]);
}

function sqlbak_store_health_failure(array $destination, string $traceId, string $errorCode, string $message): void
{
    $pdo = sqlbak_db();
    $pdo->prepare("UPDATE storage_destinations SET health_status='failed',last_test_status='failed',last_test_message=?,last_error_code=?,last_tested_at=NOW(),last_latency_ms=NULL,consecutive_failures=consecutive_failures+1 WHERE id=?")
        ->execute([$message, $errorCode, $destination['id']]);
    $pdo->prepare("INSERT INTO destination_health_checks (destination_id,trace_id,status,error_code,message) VALUES (?,?,'failed',?,?)")
        ->execute([$destination['id'], $traceId, $errorCode, $message]);
    sqlbak_record_event(['trace_id' => $traceId, 'destination_id' => $destination['id'], 'event_type' => 'destination_health', 'phase' => 'probe', 'level' => 'error', 'error_code' => $errorCode, 'message' => $message]);
}
