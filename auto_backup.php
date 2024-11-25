<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'db.php';

$note = "auto backup"; // Auto backup note

// Fetch all databases
$databases_query = "SELECT * FROM `databases`";
$databases_result = $conn->query($databases_query);

if (!$databases_result) {
    die("Error executing query: " . $conn->error);
}

while ($db_row = $databases_result->fetch_assoc()) {
    $database_id = $db_row['id'];
    $backup_file = 'backup_' . $db_row['name'] . '_' . date('Ymd_His') . '.sql';
    $backup_dir = '/var/www/html/sqlbak/backups/';
    $backup_path = $backup_dir . $backup_file;

    $command = sprintf(
        'mysqldump --no-tablespaces --skip-column-statistics --skip-comments --skip-add-locks -h%s -P%d -u%s -p\'%s\' %s > %s',
        $db_row['host'],
        $db_row['port'],
        $db_row['username'],
        $db_row['password'],
        $db_row['name'],
        $backup_path
    );

    // Execute the command
    exec($command . ' 2>&1', $output, $result);

    if ($result === 0) {
        // Clean up warnings and unnecessary lines from the backup file
        exec("sed -i '/^mysqldump: \[Warning\]/d' $backup_path");
        exec("sed -i '/^-- Dump completed on/d' $backup_path");

        // Insert backup info into the database
        $stmt = $conn->prepare("INSERT INTO `backups` (database_id, filename, note) VALUES (?, ?, ?)");
        $stmt->bind_param('iss', $database_id, $backup_file, $note);
        $stmt->execute();
    } else {
        // Optionally, handle errors or log them
        error_log("Backup failed for database " . $db_row['name']);
    }
}

?>

