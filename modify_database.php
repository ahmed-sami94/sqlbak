<?php
session_start();
if (!isset($_SESSION['loggedin'])) {
    header('Location: login.php');
    exit;
}

require 'db.php';

if (isset($_GET['database_id']) && !empty($_GET['database_id'])) {
    $database_id = (int)$_GET['database_id'];

    // Fetch the details of the selected database
    $stmt = $conn->prepare("SELECT * FROM databases WHERE id = ?");
    $stmt->bind_param('i', $database_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        die('Database not found.');
    }
    
    $db_row = $result->fetch_assoc();
    $stmt->close();
} else {
    die('No database selected.');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'];
    $host = $_POST['host'];
    $port = (int)$_POST['port']; // Ensure port is an integer
    $username = $_POST['username'];
    $password = $_POST['password'];

    if (empty($name) || empty($host) || $port <= 0 || empty($username) || empty($password)) {
        die('All fields are required and port must be a positive integer.');
    }

    // Update the database details
    $stmt = $conn->prepare("UPDATE databases SET name = ?, host = ?, port = ?, username = ?, password = ? WHERE id = ?");
    $stmt->bind_param('ssissi', $name, $host, $port, $username, $password, $database_id);

    if ($stmt->execute()) {
        echo "<script>alert('Database updated successfully!'); window.location.href='select_database.php';</script>";
    } else {
        echo "<script>alert('Error: " . htmlspecialchars($stmt->error) . "');</script>";
    }

    $stmt->close();
}
?>

<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" type="text/css" href="styles/style.css">
    <title>Modify Database</title>
</head>
<body>
    <header class="header">
        <img src="images/logo.png" alt="Logo" style="width: 150px; height: 150px;">
    </header>
    <nav class="navbar">
        <!-- Your navigation links here -->
    </nav>
    <div class="content">
        <h1>Modify Database</h1>
        <form method="POST">
            <input type="hidden" name="database_id" value="<?php echo htmlspecialchars($database_id); ?>">
            <label for="name">Database Name:</label>
            <input type="text" name="name" id="name" value="<?php echo htmlspecialchars($db_row['name']); ?>" required><br>
            <label for="host">Host:</label>
            <input type="text" name="host" id="host" value="<?php echo htmlspecialchars($db_row['host']); ?>" required><br>
            <label for="port">Port:</label>
            <input type="number" name="port" id="port" value="<?php echo htmlspecialchars($db_row['port']); ?>" required><br>
            <label for="username">Username:</label>
            <input type="text" name="username" id="username" value="<?php echo htmlspecialchars($db_row['username']); ?>" required><br>
            <label for="password">Password:</label>
            <input type="password" name="password" id="password" value="<?php echo htmlspecialchars($db_row['password']); ?>" required><br>
            <input type="submit" value="Update Database">
        </form>
    </div>
</body>
</html>

