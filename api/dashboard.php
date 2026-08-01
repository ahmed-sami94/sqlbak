<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
sqlbak_start_session();
if (empty($_SESSION['sqlbak_user'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'authentication_required']);
    exit;
}
header('Content-Type: application/json; charset=utf-8');

$pdo = sqlbak_db();
$stats = [
    'databases' => (int) $pdo->query('SELECT COUNT(*) FROM `databases` WHERE enabled=1')->fetchColumn(),
    'destinations' => (int) $pdo->query('SELECT COUNT(*) FROM storage_destinations WHERE enabled=1')->fetchColumn(),
    'successful' => (int) $pdo->query("SELECT COUNT(*) FROM backups WHERE status='success' AND completed_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)")->fetchColumn(),
    'failed' => (int) $pdo->query("SELECT COUNT(*) FROM backups WHERE status IN ('failed','partial') AND completed_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)")->fetchColumn(),
    'bytes' => (int) $pdo->query("SELECT COALESCE(SUM(size_bytes),0) FROM backups WHERE status IN ('success','partial') AND completed_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)")->fetchColumn(),
];
$total24 = $stats['successful'] + $stats['failed'];
$stats['success_rate'] = $total24 > 0 ? round(($stats['successful'] / $total24) * 100, 1) : 0;

$trendRows = $pdo->query("SELECT DATE(created_at) AS day,status,COUNT(*) AS jobs FROM backups WHERE created_at>=DATE_SUB(CURDATE(),INTERVAL 13 DAY) GROUP BY DATE(created_at),status ORDER BY day")->fetchAll();
$trend = [];
for ($offset = 13; $offset >= 0; $offset--) {
    $day = date('Y-m-d', strtotime('-' . $offset . ' days'));
    $trend[$day] = ['day' => $day, 'success' => 0, 'partial' => 0, 'failed' => 0];
}
foreach ($trendRows as $row) {
    if (isset($trend[$row['day']][$row['status']])) {
        $trend[$row['day']][$row['status']] = (int) $row['jobs'];
    }
}

$destinations = $pdo->query("SELECT d.id,d.name,d.type,d.display_order,d.enabled,d.health_status,d.last_test_message,d.last_error_code,d.last_tested_at,d.last_latency_ms,COUNT(CASE WHEN c.status='success' AND c.completed_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) THEN 1 END) AS successful_copies,COUNT(CASE WHEN c.status='failed' AND c.completed_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) THEN 1 END) AS failed_copies,COALESCE(SUM(CASE WHEN c.status='success' AND c.completed_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) THEN c.size_bytes ELSE 0 END),0) AS bytes FROM storage_destinations d LEFT JOIN backup_copies c ON c.destination_id=d.id GROUP BY d.id ORDER BY d.display_order,d.id")->fetchAll();
$failures = $pdo->query("SELECT trace_id,event_type,phase,error_code,message,created_at FROM operation_events WHERE level='error' ORDER BY created_at DESC,id DESC LIMIT 8")->fetchAll();

echo json_encode(['stats' => $stats, 'trend' => array_values($trend), 'destinations' => $destinations, 'failures' => $failures, 'refresh_seconds' => sqlbak_setting('dashboard_refresh_seconds', 30), 'generated_at' => date('Y-m-d H:i:s')], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
