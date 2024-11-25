<?php
session_start();
if (!isset($_SESSION['loggedin'])) {
    header('Location: login.php');
    exit;
}

require 'db.php';
require 'header.php';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $database_id = $_POST['database_id'];
    
    if (!empty($database_id)) {
        // Begin a transaction
        $conn->begin_transaction();

        // Delete associated backups
        $stmt = $conn->prepare("DELETE FROM `backups` WHERE database_id = ?");
        if ($stmt === false) {
            $conn->rollback();
            die('Prepare failed: ' . htmlspecialchars($conn->error));
        }
        $stmt->bind_param('i', $database_id);
        if (!$stmt->execute()) {
            $conn->rollback();
            die('Error deleting backups: ' . htmlspecialchars($stmt->error));
        }
        $stmt->close();

        // Delete the database record
        $stmt = $conn->prepare("DELETE FROM `databases` WHERE id = ?");
        if ($stmt === false) {
            $conn->rollback();
            die('Prepare failed: ' . htmlspecialchars($conn->error));
        }
        $stmt->bind_param('i', $database_id);
        if ($stmt->execute()) {
            $conn->commit();
            echo "<script>alert('Database deleted successfully!'); window.location.href='delete_database.php';</script>";
        } else {
            $conn->rollback();
            echo "<script>alert('Error: " . htmlspecialchars($stmt->error) . "'); window.location.href='delete_database.php';</script>";
        }

        $stmt->close();
    } else {
        echo "<script>alert('No database selected.'); window.location.href='delete_database.php';</script>";
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
    <title>Delete Database</title>
    <script>
        function confirmDelete() {
            return confirm('Are you sure you want to delete this database? This action cannot be undone.');
        }
    </script>
</head>
<body>
    
    <div class="content">
        <h1>Delete Database</h1>
        <form method="POST" onsubmit="return confirmDelete();">
            <label for="database_id">Select Database:</label>
            <select name="database_id" id="database_id">
                <option value="">Select Database</option>
                <?php while ($db_row = $databases_result->fetch_assoc()): ?>
                <option value="<?php echo htmlspecialchars($db_row['id']); ?>"><?php echo htmlspecialchars($db_row['name']); ?></option>
                <?php endwhile; ?>
            </select><br>
            <input type="submit" value="Delete Database">
        </form>
    </div>
</body>
</html>

