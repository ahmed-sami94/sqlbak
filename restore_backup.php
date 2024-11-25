<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['loggedin'])) {
    header('Location: login.php');
    exit;
}

require 'db.php';

// Handle the restore process
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['filename'])) {
    $filename = $_GET['filename'];
    $backup_dir = '/var/www/html/sqlbak/backups/';
    $backup_path = $backup_dir . $filename;

    // Check if backup file exists
    if (!file_exists($backup_path)) {
        $message = "Backup file does not exist.";
        header("Location: list_backups.php?message=" . urlencode($message));
        exit;
    }

    // Fetch backup details
    $backup_query = $conn->prepare("SELECT * FROM `backups` WHERE filename = ?");
    if ($backup_query === false) {
        $message = "Error preparing query: " . htmlspecialchars($conn->error);
        header("Location: list_backups.php?message=" . urlencode($message));
        exit;
    }

    $backup_query->bind_param('s', $filename);
    $backup_query->execute();
    $backup_result = $backup_query->get_result();
    $backup_row = $backup_result->fetch_assoc();

    if ($backup_row) {
        $db_query = $conn->prepare("SELECT * FROM `databases` WHERE id = ?");
        if ($db_query === false) {
            $message = "Error preparing database query: " . htmlspecialchars($conn->error);
            header("Location: list_backups.php?message=" . urlencode($message));
            exit;
        }

        $db_query->bind_param('i', $backup_row['database_id']);
        $db_query->execute();
        $db_result = $db_query->get_result();
        $db_row = $db_result->fetch_assoc();

        if ($db_row) {
            $restore_command = sprintf(
                'mysql -h%s -P%d -u%s -p\'%s\' %s < %s',
                escapeshellarg($db_row['host']),
                (int) $db_row['port'],
                escapeshellarg($db_row['username']),
                escapeshellarg($db_row['password']),
                escapeshellarg($db_row['name']),
                escapeshellarg($backup_path)
            );

            // Log the restore command for debugging
            $log_content = "Restore command: " . $restore_command . "\n";
            file_put_contents('/var/www/html/sqlbak/restore_log.txt', $log_content, FILE_APPEND);

            // Execute the restore command
            exec($restore_command . ' 2>&1', $output, $result);

            // Log the output and result for debugging
            $log_content = "Command output: " . implode("\n", $output) . "\nResult: $result\n";
            file_put_contents('/var/www/html/sqlbak/restore_log.txt', $log_content, FILE_APPEND);

            if ($result === 0) {
                $message = "Restore successful!";
            } else {
                $message = "Restore failed! Command output:<br>" . implode("<br>", array_map('htmlspecialchars', $output));
            }
        } else {
            $message = "Database not found.";
        }
    } else {
        $message = "Backup not found.";
    }
} else {
    $message = "Invalid request.";
}

// Redirect to list_backups.php with message
header("Location: list_backups.php?message=" . urlencode($message));
exit;
?>

