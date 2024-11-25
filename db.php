<?php
$servername = "11.11.11.11";  // Ensure this is correct
$username = "backup";
$password = "backup";
$dbname = "backup_app";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>

