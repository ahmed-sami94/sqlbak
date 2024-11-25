<?php
session_start();
if (!isset($_SESSION['loggedin'])) {
    header('Location: login.php');
    exit;
}

if (isset($_GET['file'])) {
    $file = $_GET['file'];
    $file_path = '/var/www/html/sqlbak/backups/' . $file;

    if (file_exists($file_path)) {
        // Set headers to force download
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($file_path) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($file_path));

        // Read the file and output it
        readfile($file_path);
        exit;
    } else {
        echo "File not found.";
    }
} else {
    echo "No file specified.";
}
?>

