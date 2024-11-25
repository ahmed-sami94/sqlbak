<?php
session_start();
if (!isset($_SESSION['loggedin'])) {
    header('Location: login.php');
    exit;
}

if (isset($_GET['filename'])) {
    $filename = basename($_GET['filename']);
    $filepath = '/var/www/html/sqlbak/backups/' . $filename;

    if (file_exists($filepath)) {
        if (unlink($filepath)) {
            header('Location: list_backups.php?message=Backup deleted successfully.');
        } else {
            header('Location: list_backups.php?message=Failed to delete backup.');
        }
    } else {
        echo "File does not exist.";
    }
} elseif (isset($_GET['id'])) {
    require 'db.php';
    $id = (int)$_GET['id'];
    
    // Retrieve the filename from the database
    $query = "SELECT filename FROM `backups` WHERE id = " . $id;
    $result = $conn->query($query);

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $filename = $row['filename'];
        $filepath = '/var/www/html/sqlbak/backups/' . $filename;

        if (unlink($filepath)) {
            // Delete the record from the database
            $delete_query = "DELETE FROM `backups` WHERE id = " . $id;
            if ($conn->query($delete_query)) {
                header('Location: list_backups.php?message=Backup deleted successfully.');
            } else {
                header('Location: list_backups.php?message=Failed to delete backup from database.');
            }
        } else {
            header('Location: list_backups.php?message=Failed to delete backup file.');
        }
    } else {
        header('Location: list_backups.php?message=Backup not found in database.');
    }
} else {
    echo "Filename or ID is not specified.";
}
?>

