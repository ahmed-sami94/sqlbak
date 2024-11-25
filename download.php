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
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filepath));
        flush(); // Flush system output buffer
        readfile($filepath);
        exit;
    } else {
        echo "File does not exist.";
    }
} else {
    echo "Filename is not specified.";
}
?>

