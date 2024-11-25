<?php
session_start();
if (!isset($_SESSION['loggedin'])) {
    header('Location: login.php');
    exit;
}

require 'db.php';
require 'header.php';

// Fetch databases for selection
$query = "SELECT id, name FROM `databases`";
$result = $conn->query($query);

if ($result === false) {
    die('Error fetching databases: ' . htmlspecialchars($conn->error));
}

// Handle form submission for updating the database
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['database_id'])) {
    $database_id = (int)$_POST['database_id'];
    $name = $_POST['name'];
    $host = $_POST['host'];
    $port = (int)$_POST['port'];
    $username = $_POST['username'];
    $password = $_POST['password'];

    // Validate form input
    if (empty($name) || empty($host) || $port <= 0 || $port > 65535 || empty($username)) {
        $error_message = 'All fields are required, and the port must be a positive integer between 1 and 65535.';
    } else {
        // If password is empty, don't update it
        if (empty($password)) {
            $stmt = $conn->prepare("UPDATE `databases` SET name = ?, host = ?, port = ?, username = ? WHERE id = ?");
            $stmt->bind_param('ssisi', $name, $host, $port, $username, $database_id);
        } else {
            // Update the password if it's provided
            $stmt = $conn->prepare("UPDATE `databases` SET name = ?, host = ?, port = ?, username = ?, password = ? WHERE id = ?");
            $stmt->bind_param('sssisi', $name, $host, $port, $username, $password, $database_id);
        }

        if ($stmt->execute()) {
            $success_message = 'Database updated successfully!';
        } else {
            $error_message = 'Error: ' . htmlspecialchars($stmt->error);
        }

        $stmt->close();
    }
}

// Fetch selected database details if a database_id is provided
$db_row = [];
if (isset($_POST['database_id']) && !empty($_POST['database_id'])) {
    $database_id = (int)$_POST['database_id'];
    $stmt = $conn->prepare("SELECT * FROM `databases` WHERE id = ?");
    $stmt->bind_param('i', $database_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $db_row = $result->fetch_assoc();
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" type="text/css" href="styles/style.css">
    <title>Update Database</title>
    <style>
        form {
            background-color: #f9f9f9;
            padding: 20px;
            border: 1px solid #ccc;
        }
    </style>
</head>
<body>

    <div class="content">
        <h1>Update Database</h1>
        
        <?php if (isset($success_message)): ?>
            <div style="color: green;"><?php echo $success_message; ?></div>
        <?php elseif (isset($error_message)): ?>
            <div style="color: red;"><?php echo $error_message; ?></div>
        <?php endif; ?>

        <form method="POST">
            <label for="database_id">Select Database:</label>
            <select name="database_id" id="database_id" onchange="this.form.submit()">
                <option value="">Select Database</option>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <option value="<?php echo htmlspecialchars($row['id']); ?>" <?php echo (isset($db_row['id']) && $db_row['id'] == $row['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($row['name']); ?>
                    </option>
                <?php endwhile; ?>
            </select><br>

            <?php if (!empty($db_row)): ?>
                <input type="hidden" name="database_id" value="<?php echo htmlspecialchars($db_row['id']); ?>">
                <label for="name">Database Name:</label>
                <input type="text" name="name" id="name" value="<?php echo htmlspecialchars($db_row['name']); ?>" required><br>
                <label for="host">Host:</label>
                <input type="text" name="host" id="host" value="<?php echo htmlspecialchars($db_row['host']); ?>" required><br>
                <label for="port">Port:</label>
                <input type="number" name="port" id="port" value="<?php echo htmlspecialchars($db_row['port']); ?>" required><br>
                <label for="username">Username:</label>
                <input type="text" name="username" id="username" value="<?php echo htmlspecialchars($db_row['username']); ?>" required><br>
                <label for="password">Password:</label>
                <input type="password" name="password" id="password" value="" placeholder="Leave empty if not changing"><br>
                <input type="submit" value="Update Database">
            <?php endif; ?>
        </form>
    </div>
</body>
</html>

