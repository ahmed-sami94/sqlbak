<?php
session_start();
if (!isset($_SESSION['loggedin'])) {
    header('Location: login.php');
    exit;
}

require 'db.php';
require 'header.php';

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $name = $_POST['name'];
    $host = $_POST['host'];
    $port = (int)$_POST['port']; // Ensure port is an integer
    $username = $_POST['username'];
    $password = $_POST['password'];
    
    // Check if all fields are filled
    if (empty($name) || empty($host) || empty($username) || empty($password) || $port <= 0) {
        die('All fields are required and port must be a positive integer.');
    }

    // Prepare SQL statement
    $stmt = $conn->prepare("INSERT INTO `databases` (name, host, port, username, password) VALUES (?, ?, ?, ?, ?)");
    
    if ($stmt === false) {
        die('Prepare failed: ' . htmlspecialchars($conn->error));
    }

    // Bind parameters
    $stmt->bind_param('ssiss', $name, $host, $port, $username, $password);
    
    // Execute statement
    if ($stmt->execute()) {
        echo "<script>alert('Database added successfully!'); window.location.href='add_database.php';</script>";
    } else {
        echo "<script>alert('Error: " . htmlspecialchars($stmt->error) . "'); window.location.href='add_database.php';</script>";
    }

    // Close statement
    $stmt->close();
}
?>

<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" type="text/css" href="styles/style.css">
    <title>Add Database</title>
</head>
<body>
   
    <div class="content">
        <h1>Add Database</h1>
        <form method="POST">
            <label for="name">Database Name:</label>
            <input type="text" name="name" id="name" placeholder="Database Name" required><br>
            <label for="host">Host:</label>
            <input type="text" name="host" id="host" placeholder="Host" required><br>
            <label for="port">Port:</label>
            <input type="number" name="port" id="port" placeholder="Port" required><br>
            <label for="username">Username:</label>
            <input type="text" name="username" id="username" placeholder="Username" required><br>
            <label for="password">Password:</label>
            <input type="password" name="password" id="password" placeholder="Password" required><br>
            <input type="submit" value="Add Database">
        </form>
    </div>
</body>
</html>

