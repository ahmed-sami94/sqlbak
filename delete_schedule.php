<?php
require 'db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $backup_id = $_POST['backup_id'];

    if (!is_numeric($backup_id)) {
        die("Invalid backup ID.");
    }

    // Prepare and execute the delete query
    $query = "DELETE FROM backups WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $backup_id);
    
    if ($stmt->execute()) {
        echo "Backup deleted successfully.";
    } else {
        echo "Error deleting backup: " . $conn->error;
    }

    $stmt->close();
}

header('Location: list_backups.php');
exit;

