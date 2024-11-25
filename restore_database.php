<?php
session_start();
if (!isset($_SESSION['loggedin'])) {
    header('Location: login.php');
    exit;
}

require 'db.php';
require 'header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $database_id = $_POST['database_id'];
    $upload_file = $_FILES['sql_file']['tmp_name'];
    $original_filename = $_FILES['sql_file']['name'];

    if (!empty($database_id) && !empty($upload_file)) {
        // Fetch the database connection details
        $stmt = $conn->prepare("SELECT name, host, port, username, password FROM `databases` WHERE id = ?");
        $stmt->bind_param('i', $database_id);
        $stmt->execute();
        $stmt->bind_result($database_name, $db_host, $db_port, $db_user, $db_password);
        $stmt->fetch();
        $stmt->close();

        if ($database_name && $db_host && $db_port && $db_user && $db_password) {
            // Drop all tables
            $drop_tables_command = sprintf(
                'mysql -h %s -P %d -u %s -p\'%s\' %s -e "SET FOREIGN_KEY_CHECKS = 0; SHOW TABLES;" 2>&1 | grep -v Tables_in | awk \'{print "DROP TABLE IF EXISTS " $1 ";"}\' | mysql -h %s -P %d -u %s -p\'%s\' %s 2>&1',
                escapeshellarg($db_host),
                $db_port,
                escapeshellarg($db_user),
                escapeshellarg($db_password),
                escapeshellarg($database_name),
                escapeshellarg($db_host),
                $db_port,
                escapeshellarg($db_user),
                escapeshellarg($db_password),
                escapeshellarg($database_name)
            );

            $output = [];
            $result_code = null;

            // Drop all tables
            exec($drop_tables_command, $output, $result_code);

            // Restore database
            $restore_command = sprintf(
                'mysql -h %s -P %d -u %s -p\'%s\' %s < %s 2>&1',
                escapeshellarg($db_host),
                $db_port,
                escapeshellarg($db_user),
                escapeshellarg($db_password),
                escapeshellarg($database_name),
                escapeshellarg($upload_file)
            );

            exec($restore_command, $output, $result_code);

            // Output result
            $output_message = implode("\n", $output);

            if ($result_code === 0) {
                echo "<script>alert('Database restored successfully from file: " . htmlspecialchars($original_filename) . "'); window.location.href='restore_database.php';</script>";
            } else {
                echo "<script>alert('Error: Database restoration failed. Output: " . htmlspecialchars($output_message) . "'); window.location.href='restore_database.php';</script>";
            }
        } else {
            echo "<script>alert('Error: Selected database details are missing.'); window.location.href='restore_database.php';</script>";
        }
    } else {
        echo "<script>alert('Please select a database and upload a SQL file.'); window.location.href='restore_database.php';</script>";
    }
}

// Fetch databases for the dropdown
$databases_query = "SELECT * FROM `databases`";
$databases_result = $conn->query($databases_query);

if ($databases_result === false) {
    die('Query failed: ' . htmlspecialchars($conn->error));
}
?>

<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" type="text/css" href="styles/style.css">
    <title>Restore Database</title>
</head>
<body>

    <div class="content">
        <h1>Restore Database</h1>
        <form method="POST" enctype="multipart/form-data">
            <label for="database_id">Select Database:</label>
            <select name="database_id" id="database_id">
                <option value="">Select Database</option>
                <?php while ($db_row = $databases_result->fetch_assoc()): ?>
                <option value="<?php echo htmlspecialchars($db_row['id']); ?>"><?php echo htmlspecialchars($db_row['name']); ?></option>
                <?php endwhile; ?>
            </select><br><br>

            <label for="sql_file">Select SQL File:</label>
            <input type="file" name="sql_file" id="sql_file" accept=".sql"><br><br>

            <input type="submit" value="Restore Database">
        </form>
    </div>
</body>
</html>

