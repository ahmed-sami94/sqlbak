<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/trace.php';
require_once __DIR__ . '/policy.php';

function sqlbak_database_folder(string $databaseName): string
{
    $folder = preg_replace('/[^A-Za-z0-9_-]+/', '-', $databaseName) ?? '';
    if ($folder === '') {
        throw new RuntimeException('اسم قاعدة البيانات غير صالح لمسار التخزين.');
    }
    return trim($folder, '-');
}

function sqlbak_storage_path(string $basePath, string $relativePath): string
{
    $root = rtrim($basePath, '/');
    $path = $root . '/' . ltrim($relativePath, '/');
    if (str_contains($relativePath, '..') || !str_starts_with($path, $root . '/')) {
        throw new RuntimeException('مسار التخزين غير صالح.');
    }
    return $path;
}

function sqlbak_create_directory(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0750, true) && !is_dir($path)) {
        throw new RuntimeException('تعذر إنشاء مجلد التخزين.');
    }
}

function sqlbak_dump_database(array $database, string $targetPath): array
{
    $secret = sqlbak_decrypt($database['password_encrypted'] ?? null);
    $password = $secret['password'] ?? null;
    if (!is_string($password) || $password === '') {
        throw new RuntimeException('بيانات اتصال قاعدة البيانات غير مكتملة.');
    }

    $optionFile = tempnam(sys_get_temp_dir(), 'sqlbak-mysql-');
    if ($optionFile === false) {
        throw new RuntimeException('تعذر إنشاء ملف اتصال مؤقت.');
    }

    file_put_contents($optionFile, "[client]\nuser=" . $database['username'] . "\npassword=" . $password . "\nhost=" . $database['host'] . "\nport=" . (int) $database['port'] . "\n");
    chmod($optionFile, 0600);
    $command = ['mysqldump', '--defaults-extra-file=' . $optionFile, '--single-transaction', '--quick', '--routines', '--triggers', '--events', '--hex-blob', '--no-tablespaces', '--skip-column-statistics', $database['name']];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        unlink($optionFile);
        throw new RuntimeException('تعذر بدء عملية النسخ الاحتياطي.');
    }

    $gzip = gzopen($targetPath, 'wb9');
    if ($gzip === false) {
        proc_terminate($process);
        unlink($optionFile);
        throw new RuntimeException('تعذر إنشاء ملف النسخة الاحتياطية.');
    }

    while (!feof($pipes[1])) {
        $chunk = fread($pipes[1], 1048576);
        if ($chunk !== false && $chunk !== '') {
            gzwrite($gzip, $chunk);
        }
    }
    fclose($pipes[1]);
    $errorOutput = trim((string) stream_get_contents($pipes[2]));
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    gzclose($gzip);
    unlink($optionFile);

    if ($exitCode !== 0 || filesize($targetPath) === 0) {
        @unlink($targetPath);
        throw new RuntimeException('فشل mysqldump: ' . ($errorOutput !== '' ? $errorOutput : 'رمز الخروج ' . $exitCode));
    }
    return ['size_bytes' => filesize($targetPath), 'checksum_sha256' => hash_file('sha256', $targetPath)];
}

function sqlbak_restore_database(array $database, string $sourcePath): void
{
    $secret = sqlbak_decrypt($database['password_encrypted'] ?? null);
    $password = $secret['password'] ?? null;
    if (!is_string($password) || $password === '') {
        throw new RuntimeException('بيانات اتصال قاعدة البيانات غير مكتملة.');
    }

    $optionFile = tempnam(sys_get_temp_dir(), 'sqlbak-restore-');
    if ($optionFile === false) {
        throw new RuntimeException('تعذر إنشاء ملف اتصال مؤقت.');
    }

    file_put_contents($optionFile, "[client]\nuser={$database['username']}\npassword={$password}\nhost={$database['host']}\nport=" . (int) $database['port'] . "\n");
    chmod($optionFile, 0600);
    $process = proc_open(['mysql', '--defaults-extra-file=' . $optionFile, $database['name']], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        unlink($optionFile);
        throw new RuntimeException('تعذر بدء عملية الاستعادة.');
    }

    $input = str_ends_with(strtolower($sourcePath), '.gz') ? gzopen($sourcePath, 'rb') : fopen($sourcePath, 'rb');
    if ($input === false) {
        proc_terminate($process);
        unlink($optionFile);
        throw new RuntimeException('تعذر قراءة ملف النسخة.');
    }
    while (!feof($input)) {
        $chunk = fread($input, 1048576);
        if ($chunk !== false && $chunk !== '') {
            fwrite($pipes[0], $chunk);
        }
    }
    fclose($input);
    fclose($pipes[0]);
    fclose($pipes[1]);
    $errorOutput = trim((string) stream_get_contents($pipes[2]));
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    unlink($optionFile);
    if ($exitCode !== 0) {
        throw new RuntimeException('فشلت الاستعادة: ' . ($errorOutput !== '' ? $errorOutput : 'رمز الخروج ' . $exitCode));
    }
}

function sqlbak_destination_client(array $destination): array
{
    $options = json_decode($destination['options_json'] ?? '{}', true) ?: [];
    return ['destination' => $destination, 'options' => $options, 'secret' => sqlbak_decrypt($destination['secret_encrypted'] ?? null)];
}

function sqlbak_ftp_connect(array $client)
{
    $destination = $client['destination'];
    $connection = !empty($client['options']['tls'])
        ? @ftp_ssl_connect($destination['host'], (int) $destination['port'], 20)
        : @ftp_connect($destination['host'], (int) $destination['port'], 20);
    if ($connection === false || !@ftp_login($connection, $destination['username'], $client['secret']['password'] ?? '')) {
        throw new SqlbakOperationException('AUTH_FAILED', 'تعذر الاتصال أو تسجيل الدخول إلى وجهة FTP.');
    }
    ftp_pasv($connection, $client['options']['passive'] ?? true);
    return $connection;
}

function sqlbak_ftp_mkdir($connection, string $directory): void
{
    $current = '';
    foreach (array_filter(explode('/', trim($directory, '/'))) as $segment) {
        $current .= '/' . $segment;
        @ftp_mkdir($connection, $current);
    }
}

function sqlbak_sftp_client(array $client)
{
    $autoload = SQLBAK_ROOT . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new SqlbakOperationException('SFTP_LIBRARY_MISSING', 'دعم SFTP غير مثبت.');
    }
    require_once $autoload;
    $destination = $client['destination'];
    $sftp = new \phpseclib3\Net\SFTP($destination['host'], (int) $destination['port'], 20);
    $serverKey = $sftp->getServerPublicHostKey();
    if (!is_string($serverKey) || $serverKey === '') {
        throw new SqlbakOperationException('CONNECTION_FAILED', 'تعذر الاتصال بخادم SFTP على المنفذ المحدد.');
    }
    $fingerprint = $client['options']['host_fingerprint'] ?? '';
    if ($fingerprint !== '' && hash('sha256', $serverKey) !== $fingerprint) {
        throw new SqlbakOperationException('HOST_KEY_MISMATCH', 'بصمة خادم SFTP لا تطابق البصمة المحفوظة.');
    }
    $authenticated = ($client['options']['auth_method'] ?? 'password') === 'key'
        ? $sftp->login($destination['username'], \phpseclib3\Crypt\PublicKeyLoader::load($client['secret']['private_key'] ?? '', $client['secret']['passphrase'] ?? false))
        : $sftp->login($destination['username'], $client['secret']['password'] ?? '');
    if (!$authenticated) {
        throw new SqlbakOperationException('AUTH_FAILED', 'تعذر تسجيل الدخول إلى SFTP.');
    }
    return $sftp;
}

function sqlbak_copy_to_destination(array $destination, string $sourcePath, string $relativePath): void
{
    $client = sqlbak_destination_client($destination);
    $type = $destination['type'];
    if ($type === 'local') {
        $root = realpath($destination['base_path']) ?: $destination['base_path'];
        $allowedRoot = realpath(sqlbak_backup_root()) ?: sqlbak_backup_root();
        if ($root !== $allowedRoot && !str_starts_with($root, $allowedRoot . '/')) {
            throw new RuntimeException('وجهة التخزين المحلية خارج المسار المسموح.');
        }
        $targetPath = sqlbak_storage_path($root, $relativePath);
        sqlbak_create_directory(dirname($targetPath));
        if (!copy($sourcePath, $targetPath) || filesize($targetPath) !== filesize($sourcePath)) {
            @unlink($targetPath);
            throw new RuntimeException('تعذر حفظ النسخة في التخزين المحلي.');
        }
        return;
    }
    if ($type === 'ftp') {
        $connection = sqlbak_ftp_connect($client);
        $targetPath = trim($destination['base_path'], '/') . '/' . ltrim($relativePath, '/');
        sqlbak_ftp_mkdir($connection, dirname($targetPath));
        $uploaded = ftp_put($connection, $targetPath, $sourcePath, FTP_BINARY);
        $verified = $uploaded && ftp_size($connection, $targetPath) === filesize($sourcePath);
        if (!$verified) {
            @ftp_delete($connection, $targetPath);
        }
        ftp_close($connection);
        if (!$verified) {
            throw new SqlbakOperationException('VERIFY_FAILED', 'فشل رفع النسخة أو التحقق من حجمها على FTP.');
        }
        return;
    }
    if ($type === 'sftp') {
        $sftp = sqlbak_sftp_client($client);
        $targetPath = rtrim($destination['base_path'], '/') . '/' . ltrim($relativePath, '/');
        $sftp->mkdir(dirname($targetPath), -1, true);
        $uploaded = $sftp->put($targetPath, $sourcePath, \phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE);
        if (!$uploaded || $sftp->filesize($targetPath) !== filesize($sourcePath)) {
            $sftp->delete($targetPath);
            throw new SqlbakOperationException('VERIFY_FAILED', 'فشل رفع النسخة أو التحقق من حجمها على SFTP.');
        }
        return;
    }
    throw new RuntimeException('نوع وجهة التخزين غير مدعوم.');
}

function sqlbak_copy_from_destination(array $destination, string $relativePath, string $targetPath): void
{
    sqlbak_create_directory(dirname($targetPath));
    $client = sqlbak_destination_client($destination);
    if ($destination['type'] === 'local') {
        $root = realpath($destination['base_path']) ?: $destination['base_path'];
        $allowedRoot = realpath(sqlbak_backup_root()) ?: sqlbak_backup_root();
        if ($root !== $allowedRoot && !str_starts_with($root, $allowedRoot . '/')) {
            throw new RuntimeException('وجهة التخزين المحلية خارج المسار المسموح.');
        }
        $sourcePath = sqlbak_storage_path($root, $relativePath);
        if (!is_file($sourcePath) || !copy($sourcePath, $targetPath)) {
            @unlink($targetPath);
            throw new RuntimeException('ملف النسخة غير موجود في التخزين المحلي.');
        }
        return;
    }
    if ($destination['type'] === 'ftp') {
        $connection = sqlbak_ftp_connect($client);
        $remotePath = trim($destination['base_path'], '/') . '/' . ltrim($relativePath, '/');
        $downloaded = ftp_get($connection, $targetPath, $remotePath, FTP_BINARY);
        ftp_close($connection);
        if (!$downloaded) {
            @unlink($targetPath);
            throw new RuntimeException('تعذر تنزيل النسخة من FTP.');
        }
        return;
    }
    if ($destination['type'] === 'sftp') {
        $sftp = sqlbak_sftp_client($client);
        $remotePath = rtrim($destination['base_path'], '/') . '/' . ltrim($relativePath, '/');
        if (!$sftp->get($remotePath, $targetPath)) {
            @unlink($targetPath);
            throw new RuntimeException('تعذر تنزيل النسخة من SFTP.');
        }
        return;
    }
    throw new RuntimeException('نوع وجهة التخزين غير مدعوم.');
}

function sqlbak_delete_destination_copy(array $destination, string $relativePath): void
{
    $client = sqlbak_destination_client($destination);
    if ($destination['type'] === 'local') {
        $targetPath = sqlbak_storage_path($destination['base_path'], $relativePath);
        if (is_file($targetPath) && !unlink($targetPath)) {
            throw new RuntimeException('تعذر حذف النسخة القديمة.');
        }
        return;
    }
    if ($destination['type'] === 'ftp') {
        $connection = sqlbak_ftp_connect($client);
        $deleted = ftp_delete($connection, trim($destination['base_path'], '/') . '/' . ltrim($relativePath, '/'));
        ftp_close($connection);
        if (!$deleted) {
            throw new RuntimeException('تعذر حذف النسخة القديمة من FTP.');
        }
        return;
    }
    if ($destination['type'] === 'sftp') {
        $sftp = sqlbak_sftp_client($client);
        if (!$sftp->delete(rtrim($destination['base_path'], '/') . '/' . ltrim($relativePath, '/'))) {
            throw new RuntimeException('تعذر حذف النسخة القديمة من SFTP.');
        }
        return;
    }
    throw new RuntimeException('نوع وجهة التخزين غير مدعوم.');
}

function sqlbak_claim_backup(int $backupId): ?array
{
    $pdo = sqlbak_db();
    $pdo->beginTransaction();
    $statement = $pdo->prepare('SELECT b.*, d.name, d.host, d.port, d.username, d.password_encrypted FROM backups b JOIN `databases` d ON d.id=b.database_id WHERE b.id=? FOR UPDATE');
    $statement->execute([$backupId]);
    $job = $statement->fetch();
    if (!$job || $job['status'] !== 'queued') {
        $pdo->rollBack();
        return null;
    }
    $traceId = $job['trace_id'] ?: sqlbak_trace_id();
    $pdo->prepare("UPDATE backups SET status='running',trace_id=?,started_at=NOW() WHERE id=?")->execute([$traceId, $backupId]);
    $pdo->commit();
    $job['trace_id'] = $traceId;
    return $job;
}

function sqlbak_backup_destinations(int $databaseId): array
{
    $statement = sqlbak_db()->prepare('SELECT d.* FROM storage_destinations d JOIN database_storage_destinations link ON link.destination_id=d.id WHERE link.database_id=? AND d.enabled=1 ORDER BY d.display_order,d.id');
    $statement->execute([$databaseId]);
    return $statement->fetchAll();
}

function sqlbak_run_destination_copy(array $job, array $destination, string $stagingPath, string $relativePath, array $dumpDetails): bool
{
    $pdo = sqlbak_db();
    $startedAt = microtime(true);
    $pdo->prepare("INSERT INTO backup_copies (backup_id,destination_id,trace_id,relative_path,status,started_at) VALUES (?,?,?,?, 'running',NOW())")
        ->execute([$job['id'], $destination['id'], $job['trace_id'], $relativePath]);
    $copyId = (int) $pdo->lastInsertId();
    try {
        sqlbak_copy_to_destination($destination, $stagingPath, $relativePath);
        $duration = (int) round((microtime(true) - $startedAt) * 1000);
        $pdo->prepare("UPDATE backup_copies SET status='success',size_bytes=?,checksum_sha256=?,completed_at=NOW(),duration_ms=?,error_message=NULL,error_code=NULL WHERE id=?")
            ->execute([$dumpDetails['size_bytes'], $dumpDetails['checksum_sha256'], $duration, $copyId]);
        sqlbak_record_event(['trace_id' => $job['trace_id'], 'backup_id' => $job['id'], 'copy_id' => $copyId, 'destination_id' => $destination['id'], 'policy_rule_id' => $job['policy_rule_id'], 'event_type' => 'backup_copy', 'phase' => 'upload', 'message' => 'تم حفظ النسخة في الوجهة بنجاح.', 'context' => ['duration_ms' => $duration, 'size_bytes' => $dumpDetails['size_bytes']]]);
        sqlbak_prune_destination($destination, $job);
        return true;
    } catch (Throwable $error) {
        $errorCode = sqlbak_error_code($error);
        $duration = (int) round((microtime(true) - $startedAt) * 1000);
        $pdo->prepare("UPDATE backup_copies SET status='failed',completed_at=NOW(),duration_ms=?,error_message=?,error_code=? WHERE id=?")
            ->execute([$duration, $error->getMessage(), $errorCode, $copyId]);
        sqlbak_record_event(['trace_id' => $job['trace_id'], 'backup_id' => $job['id'], 'copy_id' => $copyId, 'destination_id' => $destination['id'], 'policy_rule_id' => $job['policy_rule_id'], 'event_type' => 'backup_copy', 'phase' => 'upload', 'level' => 'error', 'error_code' => $errorCode, 'message' => $error->getMessage(), 'context' => ['duration_ms' => $duration]]);
        return false;
    }
}

function sqlbak_run_backup(int $backupId): void
{
    $job = sqlbak_claim_backup($backupId);
    if ($job === null) {
        return;
    }
    $folder = sqlbak_database_folder($job['name']);
    $filename = $folder . '_' . date('Ymd_His') . '.sql.gz';
    $relativePath = $folder . '/' . $filename;
    $stagingPath = sqlbak_backup_root() . '/.staging/' . $filename . '.part';
    sqlbak_create_directory(dirname($stagingPath));
    try {
        sqlbak_record_event(['trace_id' => $job['trace_id'], 'backup_id' => $job['id'], 'policy_rule_id' => $job['policy_rule_id'], 'event_type' => 'backup_job', 'phase' => 'dump', 'message' => 'بدأ إنشاء ملف قاعدة البيانات.']);
        $dumpDetails = sqlbak_dump_database($job, $stagingPath);
        $destinations = sqlbak_backup_destinations((int) $job['database_id']);
        if ($destinations === []) {
            throw new SqlbakOperationException('NO_ACTIVE_DESTINATION', 'لا توجد وجهة تخزين مفعلة ومرتبطة بقاعدة البيانات.');
        }
        $successfulCopies = 0;
        foreach ($destinations as $destination) {
            $successfulCopies += sqlbak_run_destination_copy($job, $destination, $stagingPath, $relativePath, $dumpDetails) ? 1 : 0;
        }
        $status = $successfulCopies === count($destinations) ? 'success' : ($successfulCopies > 0 ? 'partial' : 'failed');
        $message = $status === 'success' ? null : 'نجح ' . $successfulCopies . ' من ' . count($destinations) . ' وجهات.';
        sqlbak_db()->prepare('UPDATE backups SET filename=?,status=?,size_bytes=?,checksum_sha256=?,completed_at=NOW(),error_message=? WHERE id=?')
            ->execute([$filename, $status, $dumpDetails['size_bytes'], $dumpDetails['checksum_sha256'], $message, $backupId]);
        sqlbak_update_policy_result($job, $status, $message);
    } catch (Throwable $error) {
        $errorCode = sqlbak_error_code($error);
        sqlbak_db()->prepare("UPDATE backups SET status='failed',completed_at=NOW(),error_message=? WHERE id=?")->execute([$error->getMessage(), $backupId]);
        sqlbak_update_policy_result($job, 'failed', $error->getMessage());
        sqlbak_record_event(['trace_id' => $job['trace_id'], 'backup_id' => $job['id'], 'policy_rule_id' => $job['policy_rule_id'], 'event_type' => 'backup_job', 'phase' => 'job', 'level' => 'error', 'error_code' => $errorCode, 'message' => $error->getMessage()]);
    } finally {
        if (is_file($stagingPath)) {
            @unlink($stagingPath);
        }
    }
}

function sqlbak_update_policy_result(array $job, string $status, ?string $message): void
{
    if (empty($job['policy_rule_id'])) {
        return;
    }
    $successAt = in_array($status, ['success', 'partial'], true) ? date('Y-m-d H:i:s') : null;
    sqlbak_db()->prepare('UPDATE backup_policy_rules SET last_status=?,last_error=?,last_success_at=COALESCE(?,last_success_at) WHERE id=?')
        ->execute([$status, $message, $successAt, $job['policy_rule_id']]);
}

function sqlbak_prune_destination(array $destination, array $job): void
{
    $retention = sqlbak_setting('default_retention_count', 24);
    if (!empty($job['policy_rule_id'])) {
        $statement = sqlbak_db()->prepare('SELECT retention_count FROM backup_policy_rules WHERE id=?');
        $statement->execute([$job['policy_rule_id']]);
        $retention = (int) ($statement->fetchColumn() ?: $retention);
    }
    $copies = sqlbak_db()->prepare("SELECT c.id,c.relative_path FROM backup_copies c JOIN backups b ON b.id=c.backup_id WHERE b.database_id=? AND c.destination_id=? AND c.status='success' AND b.policy_rule_id <=> ? ORDER BY b.completed_at DESC,b.id DESC");
    $copies->execute([$job['database_id'], $destination['id'], $job['policy_rule_id']]);
    foreach (array_slice($copies->fetchAll(), $retention) as $copy) {
        sqlbak_delete_destination_copy($destination, $copy['relative_path']);
        sqlbak_db()->prepare("UPDATE backup_copies SET status='deleted' WHERE id=?")->execute([$copy['id']]);
    }
}

function sqlbak_enqueue_due_backups(): void
{
    $pdo = sqlbak_db();
    $policies = $pdo->query("SELECT p.* FROM backup_policy_rules p JOIN `databases` d ON d.id=p.database_id WHERE p.enabled=1 AND d.enabled=1 AND (p.next_run_at IS NULL OR p.next_run_at<=NOW()) FOR UPDATE")->fetchAll();
    foreach ($policies as $policy) {
        $traceId = sqlbak_trace_id();
        $pdo->prepare("INSERT INTO backups (database_id,policy_rule_id,trace_id,scheduled_for,filename,note,type,status) VALUES (?,?,?,?,? ,?,'automatic','queued')")
            ->execute([$policy['database_id'], $policy['id'], $traceId, $policy['next_run_at'], '', 'سياسة: ' . $policy['name']]);
        $nextRun = sqlbak_policy_next_run($policy)->format('Y-m-d H:i:s');
        $pdo->prepare("UPDATE backup_policy_rules SET next_run_at=?,last_run_at=NOW(),last_status='queued',last_error=NULL WHERE id=?")
            ->execute([$nextRun, $policy['id']]);
    }
}

function sqlbak_run_worker(): void
{
    $pdo = sqlbak_db();
    if ((int) $pdo->query("SELECT GET_LOCK('sqlbak_backup_worker',0)")->fetchColumn() !== 1) {
        return;
    }
    try {
        $pdo->beginTransaction();
        sqlbak_enqueue_due_backups();
        $pdo->commit();
        $backupId = $pdo->query("SELECT id FROM backups WHERE status='queued' ORDER BY created_at,id LIMIT 1")->fetchColumn();
        if ($backupId !== false) {
            sqlbak_run_backup((int) $backupId);
        }
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->query("SELECT RELEASE_LOCK('sqlbak_backup_worker')");
    }
}
