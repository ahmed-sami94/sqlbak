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

$message = ""; // Variable to hold messages

// Fetch databases for the dropdown
$databases_query = "SELECT * FROM `databases`";
$databases_result = $conn->query($databases_query);

if (!$databases_result) {
    $message = "Error executing query: " . $conn->error;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $database_id = $_POST['database_id'];
    $note = $_POST['note'];

    if (!empty($database_id)) {
        $db_query = $conn->prepare("SELECT * FROM `databases` WHERE id = ?");
        $db_query->bind_param('i', $database_id);
        $db_query->execute();
        $db_result = $db_query->get_result();
        $db_row = $db_result->fetch_assoc();

        if ($db_row) {
            $backup_file = 'backup_' . $db_row['name'] . '_' . date('Ymd_His') . '.sql';
            $backup_dir = '/var/www/html/sqlbak/backups/';
            $backup_path = $backup_dir . $backup_file;

            $command = sprintf(
                'mysqldump --no-tablespaces --skip-column-statistics --skip-comments --skip-add-locks -h%s -P%d -u%s -p\'%s\' %s > %s',
                $db_row['host'],
                $db_row['port'],
                $db_row['username'],
                $db_row['password'],
                $db_row['name'],
                $backup_path
            );

            // Execute the command
            exec($command . ' 2>&1', $output, $result);

            if ($result === 0) {
                // Clean up warnings and unnecessary lines from the backup file
                exec("sed -i '/^mysqldump: \[Warning\]/d' $backup_path");
                exec("sed -i '/^-- Dump completed on/d' $backup_path");

                // Insert backup info into the database
                $stmt = $conn->prepare("INSERT INTO `backups` (database_id, filename, note) VALUES (?, ?, ?)");
                $stmt->bind_param('iss', $database_id, $backup_file, $note);
                $stmt->execute();
                $message = "<p class='success'>Backup successful! Backup file: <a href='/sqlbak/backups/$backup_file'>$backup_file</a></p>";
            } else {
                $message = "<p class='error'>Backup failed! Check the command output:<br>";
                foreach ($output as $line) {
                    $message .= htmlspecialchars($line) . "<br>";
                }
                $message .= "</p>";
            }
        } else {
            $message = "<p class='error'>Database not found.</p>";
        }
    } else {
        $message = "<p class='error'>No database selected.</p>";
    }
} else {
    $message = "<p class='error'>Invalid request method.</p>";
}
?>
<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" type="text/css" href="styles/style.css">
    <title>Manual Backup</title>
</head>
<body>

    <div class="content">
        <h1>Manual Backup</h1>
        <form method="POST">
            <label for="database_id">Select Database:</label>
            <select name="database_id" id="database_id">
                <?php while ($db_row = $databases_result->fetch_assoc()): ?>
                <option value="<?php echo htmlspecialchars($db_row['id']); ?>"><?php echo htmlspecialchars($db_row['name']); ?></option>
                <?php endwhile; ?>
            </select><br>
            <label for="note">Note:</label>
            <textarea name="note" id="note" placeholder="Add a note"></textarea><br>
            <input type="submit" value="Backup Now">
        </form>
        <!-- Display message -->
        <div class="message">
            <?php echo $message; ?>
        </div>
    </div>
</body>
</html>

