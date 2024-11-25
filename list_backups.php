<?php
// Enable error reporting for debugging
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

// Initialize filter variables
$filter_database_id = isset($_GET['database_id']) ? (int)$_GET['database_id'] : 0;
$filter_start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$filter_end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';
$filter_note = isset($_GET['note']) ? $_GET['note'] : '';
$view_limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10; // Default to 10 records

// Build the query with filters for database backups
$query = "SELECT b.id, d.name AS database_name, b.filename, b.created_at, b.note 
          FROM `backups` b
          JOIN `databases` d ON b.database_id = d.id
          WHERE 1=1";

// Add database filter if set
if ($filter_database_id > 0) {
    $query .= " AND b.database_id = " . $filter_database_id;
}

// Add date range filter if set
if (!empty($filter_start_date) && !empty($filter_end_date)) {
    $query .= " AND b.created_at BETWEEN '" . $filter_start_date . "' AND '" . $filter_end_date . "'";
}

// Add note search filter if set
if (!empty($filter_note)) {
    $query .= " AND b.note LIKE '%" . $conn->real_escape_string($filter_note) . "%'";
}

// Add order by
$query .= " ORDER BY b.created_at DESC";

// Apply the limit
$query .= " LIMIT " . $view_limit;

// Execute the query and handle errors
$result = $conn->query($query);

if ($result === false) {
    die('Query failed: ' . htmlspecialchars($conn->error));
}

// Retrieve message from URL parameters
$message = isset($_GET['message']) ? htmlspecialchars($_GET['message']) : '';

// Fetch databases for the dropdown
$databases_query = "SELECT * FROM `databases`";
$databases_result = $conn->query($databases_query);

if (!$databases_result) {
    die('Error executing query: ' . htmlspecialchars($conn->error));
}

// Fetch backup files from directory
$backup_dir = '/var/www/html/sqlbak/backups';
$backup_files = [];

if (is_dir($backup_dir)) {
    $backup_files = array_diff(scandir($backup_dir), array('.', '..'));
} else {
    echo "<div class='error'>Backup directory does not exist.</div>";
}

// Pagination options
$pagination_options = [10, 20, 50, 100, 'All'];
?>
<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" type="text/css" href="styles/style.css">
    <title>List Backups</title>
    <style>
        .filter-form {
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .filter-form label {
            margin-right: 10px;
        }
        .filter-form input, .filter-form select {
            margin-right: 10px;
        }
        .filter-form input[type="submit"] {
            margin-right: 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        table, th, td {
            border: 1px solid black;
        }
        th, td {
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #f2f2f2;
        }
        .action-links a {
            margin-right: 10px;
            text-decoration: none;
            color: #007bff;
        }
        .action-links a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>

    <div class="content">
        <h1>List of Backups</h1>

        <!-- Display message -->
        <?php if (!empty($message)): ?>
            <div class="message">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <!-- Filter Form -->
        <form method="GET" class="filter-form">
            <div>
                <label for="database_id">Database:</label>
                <select name="database_id" id="database_id">
                    <option value="">Select Database</option>
                    <?php while ($db_row = $databases_result->fetch_assoc()): ?>
                        <option value="<?php echo htmlspecialchars($db_row['id']); ?>" <?php echo $filter_database_id == $db_row['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($db_row['name']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div>
                <label for="start_date">Start Date:</label>
                <input type="date" name="start_date" id="start_date" value="<?php echo htmlspecialchars($filter_start_date); ?>">
            </div>
            <div>
                <label for="end_date">End Date:</label>
                <input type="date" name="end_date" id="end_date" value="<?php echo htmlspecialchars($filter_end_date); ?>">
            </div>
            <div>
                <label for="note">Note:</label>
                <input type="text" name="note" id="note" placeholder="Search notes" value="<?php echo htmlspecialchars($filter_note); ?>">
            </div>
            <div>
                <label for="limit">Show:</label>
                <select name="limit" id="limit">
                    <?php foreach ($pagination_options as $option): ?>
                        <option value="<?php echo $option; ?>" <?php echo $view_limit == $option ? 'selected' : ''; ?>>
                            <?php echo $option === 'All' ? 'All' : htmlspecialchars($option); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <input type="submit" value="Filter">
            </div>
        </form>

        <!-- Database Backups Table -->
        <h2>Backups from Database</h2>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Database</th>
                    <th>Filename</th>
                    <th>Date</th>
                    <th>Note</th>
                    <th>Download</th>
                    <th>Restore</th>
                    <th>Delete</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($result->num_rows > 0): ?>
                    <?php while ($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['id']); ?></td>
                        <td><?php echo htmlspecialchars($row['database_name']); ?></td>
                        <td><?php echo htmlspecialchars($row['filename']); ?></td>
                        <td><?php echo htmlspecialchars($row['created_at']); ?></td>
                        <td><?php echo htmlspecialchars($row['note']); ?></td>
                        <td>
                            <a href="download.php?filename=<?php echo urlencode($row['filename']); ?>">Download</a>
                        </td>
                        <td>
                            <a href="restore_backup.php?filename=<?php echo urlencode($row['filename']); ?>" onclick="return confirm('Are you sure you want to restore this backup?');">Restore</a>
                        </td>
                        <td>
                            <a href="delete_backup.php?id=<?php echo urlencode($row['id']); ?>" onclick="return confirm('Are you sure you want to delete this backup?');">Delete</a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8">No backups found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Backups from Folder Table -->
        <h2>Backups from Folder</h2>
        <table>
            <thead>
                <tr>
                    <th>Filename</th>
                    <th>Date</th>
                    <th>Download</th>
                    <th>Restore</th>
                    <th>Delete</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($backup_files)): ?>
                    <?php foreach ($backup_files as $file): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($file); ?></td>
                        <td><?php echo date('Y-m-d H:i:s', filemtime($backup_dir . '/' . $file)); ?></td>
                        <td>
                            <a href="download.php?filename=<?php echo urlencode($file); ?>">Download</a>
                        </td>
                        <td>
                            <a href="restore_backup.php?filename=<?php echo urlencode($file); ?>" onclick="return confirm('Are you sure you want to restore this backup?');">Restore</a>
                        </td>
                        <td>
                            <a href="delete_backup.php?filename=<?php echo urlencode($file); ?>" onclick="return confirm('Are you sure you want to delete this backup?');">Delete</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5">No backup files found in the directory.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</body>
</html>

