<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/reports.php';
$pdo = sqlbak_db();
if ((int) $pdo->query("SELECT GET_LOCK('sqlbak_report_worker',0)")->fetchColumn() !== 1) {
    exit;
}
try {
    $schedules = $pdo->query("SELECT * FROM report_schedules WHERE enabled=1 AND (next_run_at IS NULL OR next_run_at<=NOW()) ORDER BY next_run_at,id")->fetchAll();
    foreach ($schedules as $schedule) {
        try {
            sqlbak_send_report($schedule);
        } catch (Throwable $error) {
            error_log('SQLBak report failed: ' . $error->getMessage());
        }
        $nextRun = sqlbak_report_next_run($schedule)->format('Y-m-d H:i:s');
        $pdo->prepare('UPDATE report_schedules SET next_run_at=? WHERE id=?')->execute([$nextRun, $schedule['id']]);
    }
} finally {
    $pdo->query("SELECT RELEASE_LOCK('sqlbak_report_worker')");
}
