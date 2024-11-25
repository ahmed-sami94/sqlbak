<?php
session_start();
if (!isset($_SESSION['loggedin'])) {
    header('Location: login.php');
    exit;
}

require 'db.php';

$query = "SELECT id, name FROM databases";
$result = $conn->query($query);

if ($result === false) {
    die('Error fetching databases: ' . htmlspecialchars($conn->error));
}
?>

<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" type="text/css" href="styles/style.css">
    <title>Select Database</title>
</head>
<body>
    <header class="header">
        <img src="images/logo.png" alt="Logo" style="width: 150px; height: 150px;">
    </header>
    <nav class="navbar">
        <!-- Your navigation links here -->
    </nav>
    <div class="content">
        <h1>Select Database</h1>
        <form action="modify_database.php" method="GET">
            <label for="database_id">Select Database:</label>
            <select name="database_id" id="database_id" required>
                <option value="">Select Database</option>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <option value="<?php echo htmlspecialchars($row['id']); ?>">
                        <?php echo htmlspecialchars($row['name']); ?>
                    </option>
                <?php endwhile; ?>
            </select>
            <input type="submit" value="Edit Database">
        </form>
    </div>
</body>
</html>

