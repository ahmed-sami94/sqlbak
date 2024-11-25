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
require 'header.php';

function fetch_results($conn, $query) {
    $result = $conn->query($query);
    if (!$result) {
        die("Database query failed: " . htmlspecialchars($conn->error));
    }
    return $result;
}

// Handle deletion
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM `schedules` WHERE `id` = ?");
    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) {
        die("Error deleting schedule: " . htmlspecialchars($stmt->error));
    }
    header('Location: schedule.php');
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $database_id = intval($_POST['database_id']);
    $period = $_POST['period'];
    $frequency = intval($_POST['frequency']);
    $time = $_POST['time'] ?? null;
    $day_of_month = $_POST['day_of_month'] ?? null;
    $schedule_id = $_POST['schedule_id'] ?? null;

    if (!in_array($period, ['hourly', 'daily', 'monthly'])) {
        die("Invalid period selected.");
    }
    if ($frequency <= 0) {
        die("Frequency must be a positive number.");
    }

    if ($period === 'hourly') {
        $time = null;
        $day_of_month = null;
    } elseif ($period === 'daily' && empty($time)) {
        die("Time is required for daily backups.");
    } elseif ($period === 'monthly' && empty($day_of_month)) {
        die("Day of month is required for monthly backups.");
    } else {
        $day_of_month = is_numeric($day_of_month) ? intval($day_of_month) : null;
    }

    if ($schedule_id) {
        $stmt = $conn->prepare("UPDATE `schedules` SET database_id = ?, period = ?, frequency = ?, time = ?, day_of_month = ? WHERE id = ?");
        $stmt->bind_param('issssi', $database_id, $period, $frequency, $time, $day_of_month, $schedule_id);
    } else {
        $stmt = $conn->prepare("INSERT INTO `schedules` (database_id, period, frequency, time, day_of_month, last_run) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param('issss', $database_id, $period, $frequency, $time, $day_of_month);
    }

    if (!$stmt->execute()) {
        die("Error saving schedule: " . htmlspecialchars($stmt->error));
    }
    header('Location: schedule.php');
    exit();
}

// Fetch data
$databases_result = fetch_results($conn, "SELECT * FROM `databases`");
$schedules_query = "
    SELECT s.id, d.name AS database_name, s.period, s.frequency, s.time, s.day_of_month,
           (SELECT MAX(created_at) FROM `backups` WHERE database_id = s.database_id) AS last_backup
    FROM `schedules` s
    JOIN `databases` d ON s.database_id = d.id
    ORDER BY s.id DESC
";
$schedules_result = fetch_results($conn, $schedules_query);

$history_result = fetch_results($conn, "SELECT * FROM `backups` ORDER BY created_at DESC LIMIT 10");
$job_history_result = fetch_results($conn, "SELECT * FROM `backups` ORDER BY created_at DESC LIMIT 10");

// Handle editing an existing schedule
$edit_schedule = null;
if (isset($_GET['edit'])) {
    $schedule_id = intval($_GET['edit']);
    $stmt = $conn->prepare("SELECT * FROM `schedules` WHERE `id` = ?");
    $stmt->bind_param('i', $schedule_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $edit_schedule = $result->fetch_assoc();
    if (!$edit_schedule) {
        die("Schedule not found.");
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" type="text/css" href="styles/style.css">
    <title>Schedule Backup</title>
    <script>
    function toggleFields() {
        var period = document.getElementById("period").value;
        var timeField = document.getElementById("time");
        var dayOfMonthField = document.getElementById("day_of_month");

        if (period === "hourly") {
            timeField.style.display = "none";
            dayOfMonthField.style.display = "none";
        } else {
            timeField.style.display = "inline";
            dayOfMonthField.style.display = (period === "monthly") ? "inline" : "none";
        }
    }
    </script>
</head>
<body onload="toggleFields()">

    <div class="content">
        <h1><?php echo $edit_schedule ? 'Edit Schedule' : 'Add New Schedule'; ?></h1>
        <form method="POST" action="schedule.php">
            <input type="hidden" name="schedule_id" value="<?php echo htmlspecialchars($edit_schedule['id'] ?? ''); ?>">
            <label for="database_id">Database:</label>
            <select name="database_id" id="database_id">
                <?php while ($db_row = $databases_result->fetch_assoc()): ?>
                <option value="<?php echo htmlspecialchars($db_row['id']); ?>" <?php echo ($edit_schedule && $edit_schedule['database_id'] == $db_row['id']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($db_row['name']); ?>
                </option>
                <?php endwhile; ?>
            </select>
            <br>
            <label for="period">Period:</label>
            <select name="period" id="period" onchange="toggleFields()">
                <option value="hourly" <?php echo ($edit_schedule && $edit_schedule['period'] === 'hourly') ? 'selected' : ''; ?>>Hourly</option>
                <option value="daily" <?php echo ($edit_schedule && $edit_schedule['period'] === 'daily') ? 'selected' : ''; ?>>Daily</option>
                <option value="monthly" <?php echo ($edit_schedule && $edit_schedule['period'] === 'monthly') ? 'selected' : ''; ?>>Monthly</option>
            </select>
            <br>
            <label for="frequency">Frequency (days):</label>
            <input type="number" name="frequency" id="frequency" value="<?php echo htmlspecialchars($edit_schedule['frequency'] ?? ''); ?>" required>
            <br>
            <label for="time">Time:</label>
            <input type="time" name="time" id="time" value="<?php echo htmlspecialchars($edit_schedule['time'] ?? ''); ?>" <?php echo ($edit_schedule && $edit_schedule['period'] !== 'daily') ? 'style="display:none"' : ''; ?>>
            <br>
            <label for="day_of_month">Day of Month:</label>
            <input type="number" name="day_of_month" id="day_of_month" value="<?php echo htmlspecialchars($edit_schedule['day_of_month'] ?? ''); ?>" <?php echo ($edit_schedule && $edit_schedule['period'] !== 'monthly') ? 'style="display:none"' : ''; ?>>
            <br>
            <button type="submit"><?php echo $edit_schedule ? 'Update Schedule' : 'Add Schedule'; ?></button>
        </form>
        <h2>Existing Schedules</h2>
        <table border="1">
            <tr>
                <th>ID</th>
                <th>Database</th>
                <th>Period</th>
                <th>Frequency</th>
                <th>Time</th>
                <th>Day of Month</th>
                <th>Last Backup</th>
                <th>Actions</th>
            </tr>
            <?php while ($schedule_row = $schedules_result->fetch_assoc()): ?>
            <tr>
                <td><?php echo htmlspecialchars($schedule_row['id'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($schedule_row['database_name'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($schedule_row['period'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($schedule_row['frequency'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($schedule_row['time'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($schedule_row['day_of_month'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($schedule_row['last_backup'] ?? 'No backups yet'); ?></td>
                <td>
                    <a href="schedule.php?edit=<?php echo htmlspecialchars($schedule_row['id']); ?>">Edit</a> | 
                    <a href="schedule.php?delete=<?php echo htmlspecialchars($schedule_row['id']); ?>" onclick="return confirm('Are you sure you want to delete this schedule?');">Delete</a>
                </td>
            </tr>
            <?php endwhile; ?>
        </table>

        <h2>Backup History</h2>
        <table border="1">
            <tr>
                <th>ID</th>
                <th>Database</th>
                <th>Backup Date</th>
            </tr>
            <?php while ($history_row = $history_result->fetch_assoc()): ?>
            <tr>
                <td><?php echo htmlspecialchars($history_row['id'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($history_row['database_id'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($history_row['created_at'] ?? ''); ?></td>
            </tr>
            <?php endwhile; ?>
        </table>

        <h2>Last 10 Backup Jobs</h2>
        <table border="1">
            <tr>
                <th>ID</th>
                <th>Database</th>
                <th>Job Date</th>
                <th>Status</th>
            </tr>
            <?php while ($job_row = $job_history_result->fetch_assoc()): ?>
            <tr>
                <td><?php echo htmlspecialchars($job_row['id'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($job_row['database_id'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($job_row['created_at'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($job_row['status'] ?? ''); ?></td>
            </tr>
            <?php endwhile; ?>
        </table>
    </div>
</body>
</html>

