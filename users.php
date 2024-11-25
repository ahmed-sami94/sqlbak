<?php
session_start();
require 'db.php';
require 'header.php';

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['loggedin']) || !$_SESSION['loggedin']) {
    header('Location: login.php');
    exit;
}

$username = $_SESSION['username'] ?? 'Guest';

// Handle user actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $user_id = $_POST['user_id'] ?? null;
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $email = $_POST['email'] ?? '';

    try {
        if ($action === 'add') {
            // Hash the password using SHA-256
            $hashed_password = hash('sha256', $password);

            // Prepare and execute the INSERT statement
            $stmt = $conn->prepare("INSERT INTO `users` (username, password, email) VALUES (?, ?, ?)");
            if (!$stmt) {
                throw new Exception("Prepare statement failed: " . $conn->error);
            }
            $stmt->bind_param('sss', $username, $hashed_password, $email);
            $stmt->execute();
            $stmt->close();
        } elseif ($action === 'update' && $user_id) {
            // Update username and email
            if (!empty($password)) {
                $hashed_password = hash('sha256', $password);
                $stmt = $conn->prepare("UPDATE `users` SET username = ?, password = ?, email = ? WHERE id = ?");
                if (!$stmt) {
                    throw new Exception("Prepare statement failed: " . $conn->error);
                }
                $stmt->bind_param('sssi', $username, $hashed_password, $email, $user_id);
            } else {
                $stmt = $conn->prepare("UPDATE `users` SET username = ?, email = ? WHERE id = ?");
                if (!$stmt) {
                    throw new Exception("Prepare statement failed: " . $conn->error);
                }
                $stmt->bind_param('ssi', $username, $email, $user_id);
            }
            $stmt->execute();
            $stmt->close();
        } elseif ($action === 'delete' && $user_id) {
            $stmt = $conn->prepare("DELETE FROM `users` WHERE id = ?");
            if (!$stmt) {
                throw new Exception("Prepare statement failed: " . $conn->error);
            }
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $stmt->close();
        }

        // Redirect to refresh the page
        header('Location: users.php');
        exit;

    } catch (Exception $e) {
        echo "Error: " . $e->getMessage();
        exit;
    }
}

// Fetch users
$users_query = "SELECT * FROM `users`";
$users_result = $conn->query($users_query);
if (!$users_result) {
    die("Database query failed: " . $conn->error);
}

?>

<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" type="text/css" href="styles/style.css">
    <title>Manage Users</title>
</head>
<body>

    <h1>Manage Users</h1>
    <form method="POST">
        <input type="hidden" name="user_id" id="user_id">
        <input type="hidden" name="action" id="action">
        
        <label for="username">Username:</label>
        <input type="text" name="username" id="username" required>
        
        <label for="password">Password:</label>
        <input type="password" name="password" id="password">
        
        <label for="email">Email:</label>
        <input type="email" name="email" id="email" required>
        
        <input type="button" value="Add User" onclick="submitForm('add')">
        <input type="button" value="Update User" onclick="submitForm('update')">
        <input type="button" value="Delete User" onclick="submitForm('delete')">
    </form>

    <h2>Existing Users</h2>
    <table border="1">
        <tr>
            <th>ID</th>
            <th>Username</th>
            <th>Email</th>
            <th>Actions</th>
        </tr>
        <?php while ($user_row = $users_result->fetch_assoc()): ?>
        <tr>
            <td><?php echo htmlspecialchars($user_row['id']); ?></td>
            <td><?php echo htmlspecialchars($user_row['username']); ?></td>
            <td><?php echo htmlspecialchars($user_row['email']); ?></td>
            <td>
                <a href="javascript:editUser(<?php echo htmlspecialchars($user_row['id']); ?>, '<?php echo htmlspecialchars($user_row['username']); ?>', '<?php echo htmlspecialchars($user_row['email']); ?>')">Edit</a>
                <a href="javascript:deleteUser(<?php echo htmlspecialchars($user_row['id']); ?>)">Delete</a>
            </td>
        </tr>
        <?php endwhile; ?>
    </table>

    <script>
    function submitForm(action) {
        document.getElementById('action').value = action;
        document.forms[0].submit();
    }

    function editUser(id, username, email) {
        document.getElementById('user_id').value = id;
        document.getElementById('username').value = username;
        document.getElementById('email').value = email;
    }

    function deleteUser(id) {
        if (confirm("Are you sure you want to delete this user?")) {
            document.getElementById('user_id').value = id;
            submitForm('delete');
        }
    }
    </script>
</body>
</html>

