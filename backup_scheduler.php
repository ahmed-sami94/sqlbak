<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'db.php';

// Get current time and day of month
$current_time = date('H:i');
$current_day_of_month = date('j');

// Fetch schedules that match the current time or day
$schedules_query = "
    SELECT * FROM `schedules`
    WHERE (
        (`period` = 'hourly') OR
        (`period` = 'daily' AND `time` = ?) OR
        (`period` = 'monthly' AND `day_of_month` = ?)
    )
";
$stmt = $conn->prepare($schedules_query);
$stmt->bind_param('si', $current_time, $current_day_of_month);
$stmt->execute();
$schedules_result = $stmt->get_result();

while ($schedule = $schedules_result->fetch_assoc()) {
    $database_id = $schedule['database_id'];
    $backup_filename = "{$BACKUP_DIR}/backup_{$database_id}_" . date('Ymd_His') . ".sql";
    $backup_command = "mysqldump --no-tablespaces --skip-column-statistics -u {$MYSQL_USER} -p'{$MYSQL_PASS}' {$database_id} > {$backup_filename}";

    // Execute the backup command
    exec($backup_command);

    // Log the backup process
    $log_message = "Backup for database ID {$database_id} created: {$backup_filename}";
    error_log($log_message, 3, '/var/log/backup.log');

    // Update the last_run timestamp in the database
    $update_stmt = $conn->prepare("UPDATE `schedules` SET last_run = NOW() WHERE id = ?");
    $update_stmt->bind_param('i', $schedule['id']);
    $update_stmt->execute();
}
?>

