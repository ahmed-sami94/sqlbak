<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/destination_health.php';

$pdo = sqlbak_db();
if ((int) $pdo->query("SELECT GET_LOCK('sqlbak_destination_health', 0)")->fetchColumn() !== 1) {
    exit;
}
try {
    foreach ($pdo->query('SELECT * FROM storage_destinations ORDER BY id')->fetchAll() as $destination) {
        sqlbak_run_destination_health_check($destination);
    }
} finally {
    $pdo->query("SELECT RELEASE_LOCK('sqlbak_destination_health')");
}
