<?php
session_start();
if (!isset($_SESSION['loggedin'])) {
    header('Location: login.php');
    exit;
}

require 'db.php';

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'update'; // Default to update if not set
    $schedule_id = intval($_POST['schedule_id'] ?? 0);
    $database_id = intval($_POST['database_id']);
    $period = $_POST['period'];
    $frequency = intval($_POST['frequency']);
    $time = $_POST['time'] ?? null; // Handle optional time
    $day_of_month = $_POST['day_of_month'] ?? null; // Handle optional day_of_month

    // Debug output
    echo "Action: $action<br>";
    echo "Schedule ID: $schedule_id<br>";
    echo "Database ID: $database_id<br>";
    echo "Period: $period<br>";
    echo "Frequency: $frequency<br>";
    echo "Time: $time<br>";
    echo "Day of Month: $day_of_month<br>";

    // Validate input
    if (!in_array($period, ['hourly', 'daily', 'monthly'])) {
        die("Invalid period selected.");
    }
    if ($frequency <= 0) {
        die("Frequency must be a positive number.");
    }

    // Adjust time and day_of_month based on period
    if ($period === 'hourly') {
        $time = null;
        $day_of_month = null;
    } elseif ($period === 'daily' && empty($time)) {
        die("Time is required for daily backups.");
    } elseif ($period === 'monthly' && empty($day_of_month)) {
        die("Day of month is required for monthly backups.");
    } else {
        // Ensure day_of_month is an integer or null
        $day_of_month = is_numeric($day_of_month) ? intval($day_of_month) : null;
    }

    if ($action === 'update') {
        // Update the schedule
        $stmt = $conn->prepare("UPDATE `schedules` SET database_id = ?, period = ?, frequency = ?, time = ?, day_of_month = ? WHERE id = ?");
        $stmt->bind_param('issssi', $database_id, $period, $frequency, $time, $day_of_month, $schedule_id);
        if ($stmt->execute()) {
            header('Location: schedule.php');
            exit();
        } else {
            die("Error updating schedule: " . $stmt->error);
        }
    } elseif ($action === 'add') {
        // Add a new schedule
        $stmt = $conn->prepare("INSERT INTO `schedules` (database_id, period, frequency, time, day_of_month) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param('issss', $database_id, $period, $frequency, $time, $day_of_month);
        if ($stmt->execute()) {
            header('Location: schedule.php');
            exit();
        } else {
            die("Error adding schedule: " . $stmt->error);
        }
    } else {
        die("Invalid action.");
    }
} else {
    die("Invalid request method.");
}
?>

