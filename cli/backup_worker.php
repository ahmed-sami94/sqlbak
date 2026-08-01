<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/backup_engine.php';

try {
    sqlbak_run_worker();
} catch (Throwable $error) {
    error_log('SQLBak worker failed: ' . $error->getMessage());
    exit(1);
}
