<?php
session_start();
require 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'];
    $password = hash('sha256', $_POST['password']); // Use SHA-256 hashing

    $query = $conn->prepare("SELECT * FROM users WHERE username = ?");
    if ($query === false) {
        die("Query preparation failed: " . $conn->error);
    }

    $query->bind_param('s', $username);
    $query->execute();
    $result = $query->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $stored_hash = $user['password'];

        if ($stored_hash === $password) {
            $_SESSION['loggedin'] = true;
            header('Location: index.php');
            exit;
        } else {
            echo "Invalid login! Password mismatch.";
        }
    } else {
        echo "Invalid login! User not found.";
    }

    $query->close();
    $conn->close();
}
?>
<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" type="text/css" href="styles/style.css">
    <title>Login</title>
</head>
<body>
    <header class="header">
        <a href="index.php">
            <img src="images/logo.png" alt="Logo" style="width: 150px; height: 150px;">
        </a>
    </header>

    <div class="login-container">
        <div class="login-box">
            <h1>Login</h1>
            <form method="POST">
                <input type="text" name="username" placeholder="Username" required><br>
                <input type="password" name="password" placeholder="Password" required><br>
                <input type="submit" value="Login">
            </form>
        </div>
    </div>
</body>
</html>

