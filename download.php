<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/backup_engine.php';
sqlbak_require_login();

$copyId = (int) ($_GET['copy_id'] ?? 0);
$statement = sqlbak_db()->prepare("SELECT c.id AS copy_id,c.relative_path,c.status AS copy_status,sd.*,b.filename FROM backup_copies c JOIN backups b ON b.id=c.backup_id JOIN storage_destinations sd ON sd.id=c.destination_id WHERE c.id=? AND c.status='success'");
$statement->execute([$copyId]);
$copy = $statement->fetch();
if (!$copy) {
    http_response_code(404);
    exit('النسخة المحددة غير متاحة.');
}

$downloadPath = sqlbak_backup_root() . '/.staging/download-' . $copyId . '-' . bin2hex(random_bytes(6)) . '.sql.gz';
try {
    sqlbak_copy_from_destination($copy, $copy['relative_path'], $downloadPath);
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . basename($copy['filename'] ?: $copy['relative_path']) . '"');
    header('Content-Length: ' . filesize($downloadPath));
    readfile($downloadPath);
} catch (Throwable $error) {
    http_response_code(404);
    echo sqlbak_h($error->getMessage());
} finally {
    if (is_file($downloadPath)) {
        @unlink($downloadPath);
    }
}
