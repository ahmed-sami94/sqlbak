<?php
declare(strict_types=1);

require_once __DIR__ . '/backup_engine.php';

const SQLBAK_UPLOAD_RESTORE_MAX_BYTES = 524288000;
const SQLBAK_UPLOAD_RESTORE_MAX_EXPANDED_BYTES = 5368709120;

function sqlbak_upload_restore_file(): array
{
    $upload = $_FILES['sql_file'] ?? null;
    if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('تعذر رفع ملف SQL. تحقق من حجم الملف واتصال الشبكة.');
    }
    $name = (string) ($upload['name'] ?? '');
    $isGzip = str_ends_with(strtolower($name), '.sql.gz');
    if (!$isGzip && !str_ends_with(strtolower($name), '.sql')) {
        throw new InvalidArgumentException('يسمح فقط بملفات .sql و .sql.gz.');
    }
    $size = (int) ($upload['size'] ?? 0);
    if ($size < 1 || $size > SQLBAK_UPLOAD_RESTORE_MAX_BYTES || !is_uploaded_file((string) $upload['tmp_name'])) {
        throw new InvalidArgumentException('الحد الأقصى لملف الاستعادة هو 500 MB.');
    }
    if ($isGzip && file_get_contents((string) $upload['tmp_name'], false, null, 0, 2) !== "\x1f\x8b") {
        throw new InvalidArgumentException('ملف .sql.gz لا يحمل توقيع GZip صحيحاً.');
    }
    return ['name' => basename($name), 'tmp_path' => (string) $upload['tmp_name'], 'size_bytes' => $size, 'gzip' => $isGzip];
}

function sqlbak_sql_file_is_safe(string $path, bool $gzip): void
{
    $stream = $gzip ? gzopen($path, 'rb') : fopen($path, 'rb');
    if ($stream === false) {
        throw new RuntimeException('تعذر قراءة ملف SQL المرفوع.');
    }
    $expandedBytes = 0;
    while (($line = $gzip ? gzgets($stream) : fgets($stream)) !== false) {
        $expandedBytes += strlen($line);
        if ($expandedBytes > SQLBAK_UPLOAD_RESTORE_MAX_EXPANDED_BYTES) {
            $gzip ? gzclose($stream) : fclose($stream);
            throw new InvalidArgumentException('حجم SQL بعد فك الضغط يتجاوز الحد الآمن.');
        }
        if (preg_match('/^\s*(?:USE|CREATE\s+(?:DATABASE|SCHEMA)|DROP\s+(?:DATABASE|SCHEMA))\b/i', $line)) {
            $gzip ? gzclose($stream) : fclose($stream);
            throw new InvalidArgumentException('الملف يحتوي أوامر تبديل أو إنشاء أو حذف قاعدة بيانات غير مسموح بها.');
        }
    }
    $gzip ? gzclose($stream) : fclose($stream);
}

function sqlbak_uploaded_restore_path(array $upload, string $traceId): string
{
    $directory = sqlbak_backup_root() . '/.staging';
    sqlbak_create_directory($directory);
    $required = ((int) $upload['size_bytes'] * 2) + 134217728;
    $available = disk_free_space($directory);
    if ($available === false || $available < $required) {
        throw new RuntimeException('المساحة المؤقتة غير كافية لاستعادة هذا الملف.');
    }
    return $directory . '/upload-' . $traceId . ($upload['gzip'] ? '.sql.gz' : '.sql');
}

function sqlbak_safety_backup(array $database, string $traceId): void
{
    $pdo = sqlbak_db();
    $pdo->prepare("INSERT INTO backups (database_id,trace_id,filename,note,type,status) VALUES (?,?,'','نسخة أمان قبل الاستعادة','pre_restore','queued')")->execute([$database['id'], $traceId]);
    $backupId = (int) $pdo->lastInsertId();
    sqlbak_run_backup($backupId);
    $copies = $pdo->prepare("SELECT COUNT(*) FROM backup_copies WHERE backup_id=? AND status='success'");
    $copies->execute([$backupId]);
    if ((int) $copies->fetchColumn() < 1) {
        throw new SqlbakOperationException('SAFETY_BACKUP_FAILED', 'تعذر إنشاء نسخة أمان ناجحة؛ تم إيقاف الاستعادة.');
    }
}

function sqlbak_template_database(PDO $pdo, int $templateId): array
{
    $statement = $pdo->prepare('SELECT * FROM `databases` WHERE id=?');
    $statement->execute([$templateId]);
    $database = $statement->fetch();
    if (!$database) {
        throw new InvalidArgumentException('قاعدة قالب الاتصال غير موجودة.');
    }
    return $database;
}

function sqlbak_new_database_name(): string
{
    $name = trim((string) ($_POST['new_database_name'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9_$]{1,50}$/', $name)) {
        throw new InvalidArgumentException('اسم قاعدة البيانات الجديدة يجب أن يحتوي حروفاً إنجليزية أو أرقاماً أو _ أو $.');
    }
    return $name;
}

function sqlbak_create_database_from_template(array $template, string $name): array
{
    $secret = sqlbak_decrypt($template['password_encrypted']);
    $pdo = new PDO('mysql:host=' . $template['host'] . ';port=' . (int) $template['port'] . ';charset=utf8mb4', $template['username'], $secret['password'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    return [...$template, 'id' => 0, 'name' => $name];
}

function sqlbak_drop_created_database(array $database): void
{
    $secret = sqlbak_decrypt($database['password_encrypted']);
    $pdo = new PDO('mysql:host=' . $database['host'] . ';port=' . (int) $database['port'] . ';charset=utf8mb4', $database['username'], $secret['password'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('DROP DATABASE IF EXISTS `' . $database['name'] . '`');
}

function sqlbak_register_imported_database(PDO $pdo, array $database): int
{
    $statement = $pdo->prepare('INSERT INTO `databases` (name,host,port,username,password_encrypted,enabled) VALUES (?,?,?,?,?,0)');
    $statement->execute([$database['name'], $database['host'], $database['port'], $database['username'], $database['password_encrypted']]);
    return (int) $pdo->lastInsertId();
}
