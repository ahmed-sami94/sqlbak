<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'db.php'; // Ensure this file contains valid database connection code

// Define the current date and time
$current_date_time = date('Y-m-d H:i:s');
$current_date = date('Y-m-d');
$current_time = date('H:i:s');

// Define the query
$schedules_query = "
    SELECT s.*, d.name AS database_name, d.host, d.port, d.username, d.password 
    FROM `schedules` s
    JOIN `databases` d ON s.database_id = d.id
    WHERE 
        (
            (s.period = 'hourly' AND TIMESTAMPDIFF(SECOND, COALESCE(s.last_run, '1970-01-01 00:00:00'), ?) >= 3600)
            OR (s.period = 'daily' AND (
                DATE(?) > DATE(COALESCE(s.last_run, '1970-01-01'))
                OR (s.time IS NOT NULL AND TIME(?) >= s.time AND DATE(COALESCE(s.last_run, '1970-01-01')) = DATE(?))
            ))
            OR (s.period = 'monthly' AND (
                MONTH(?) > MONTH(COALESCE(s.last_run, '1970-01-01'))
                AND YEAR(?) = YEAR(COALESCE(s.last_run, '1970-01-01'))
            ))
        )
        AND (s.frequency IS NULL OR s.frequency > 0)
";

// Prepare the statement
$stmt = $conn->prepare($schedules_query);

if (!$stmt) {
    file_put_contents('/var/www/html/sqlbak/debug_query.txt', 'Error preparing query: ' . htmlspecialchars($conn->error) . "\n", FILE_APPEND);
    exit;
}

// Bind parameters
$stmt->bind_param(
    'ssssss',
    $current_date_time, // For hourly period
    $current_date,      // For daily period date comparison
    $current_time,      // For daily period time comparison
    $current_date,      // For daily period date comparison
    $current_date,      // For monthly period month comparison
    $current_date       // For monthly period year comparison
);

// Execute the query
$stmt->execute();
$result = $stmt->get_result();

if (!$result) {
    file_put_contents('/var/www/html/sqlbak/debug_query.txt', 'Error executing query: ' . htmlspecialchars($stmt->error) . "\n", FILE_APPEND);
    exit;
}

while ($row = $result->fetch_assoc()) {
    // Process the result as needed
}

$stmt->close();
$conn->close();
?>

