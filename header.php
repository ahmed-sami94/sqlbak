<?php
// Ensure session_start() is not called here if it's already called in index.php
?>
<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" type="text/css" href="styles/style.css">
    <title>Backup System</title>
</head>
<body>
    <div class="header">
        <a href="index.php"><img src="images/logo.png" alt="Logo" style="width: 150px; height: auto;"></a>
        <div class="user-info">Welcome to sqlbak | Developed by Ahmed Sami  | <a href="logout.php">Logout</a></div>
    </div>
    <nav class="navbar">
        <a href="list_backups.php">List Backups</a>
        <a href="manual_backup.php">Manual Backup</a>
        <a href="schedule.php">Schedule Backup</a>
        <a href="add_database.php">Add Database</a>
        <a href="delete_database.php">Delete Database</a>
        <a href="update_database.php">Update Database</a>
        <a href="config_owncloud.php">Configure ownCloud</a>
        <a href="upload_to_ftp.php">Configure FTP</a>
        <a href="restore_database.php">Restore Database</a>
        <a href="users.php">Manage Users</a>
        <a href="logout.php">Logout</a>
    </nav>
    <div class="content">

