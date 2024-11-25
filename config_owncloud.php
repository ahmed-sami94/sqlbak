<?php
session_start();
if (!isset($_SESSION['loggedin'])) {
    header('Location: login.php');
    exit;
}

require 'db.php';
require 'header.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
$upload_success = '';
$upload_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_config'])) {
        // Save configuration to database
        $owncloud_url = rtrim($_POST['owncloud_url'], '/'); // Remove trailing slash
        $username = $_POST['username'];
        $password = $_POST['password'];
        $local_dir_path = rtrim($_POST['local_dir_path'], '/'); // Remove trailing slash
        $remote_dir_path = rtrim($_POST['remote_dir_path'], '/'); // Remove trailing slash

        // Validate inputs
        if (filter_var($owncloud_url, FILTER_VALIDATE_URL) === false) {
            $upload_error = 'Invalid ownCloud URL.';
        } else {
            $stmt = $conn->prepare("REPLACE INTO `owncloud_config` (id, owncloud_url, username, password, local_dir_path, remote_dir_path) VALUES (1, ?, ?, ?, ?, ?)");
            $stmt->bind_param('sssss', $owncloud_url, $username, $password, $local_dir_path, $remote_dir_path);
            $stmt->execute();
            $stmt->close();

            $upload_success = 'Configuration saved successfully.';
        }
    } elseif (isset($_POST['upload_files'])) {
        // Trigger the file upload process
        $command = escapeshellcmd("php upload_to_owncloud.php");
        $output = shell_exec($command);
        
        if ($output === null) {
            $upload_error = 'Error: Failed to execute upload script.';
        } else {
            $upload_success = 'Files upload process triggered.';
        }
    }
}

// Fetch configuration
$stmt = $conn->prepare("SELECT * FROM `owncloud_config` WHERE id = 1");
$stmt->execute();
$result = $stmt->get_result();
$config = $result->fetch_assoc();
$stmt->close();
?>

<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" type="text/css" href="styles/style.css">
    <title>Configure ownCloud Connection</title>
    <style>
        form {
            background-color: #f9f9f9;
            padding: 20px;
            border: 1px solid #ccc;
        }
        input[type="text"], input[type="password"] {
            width: 100%;
            padding: 10px;
            margin: 5px 0 10px 0;
            border: 1px solid #ccc;
            box-sizing: border-box;
        }
        input[type="submit"] {
            padding: 10px 20px;
            border: none;
            background-color: #007bff;
            color: #fff;
            cursor: pointer;
            font-size: 16px;
        }
        input[type="submit"]:hover {
            background-color: #0056b3;
        }
    </style>
</head>
<body>

    <div class="content">
        <h1>Configure ownCloud Connection</h1>
        <?php if ($upload_success): ?>
            <p style="color: green;"><?php echo $upload_success; ?></p>
        <?php endif; ?>
        <?php if ($upload_error): ?>
            <p style="color: red;"><?php echo htmlspecialchars($upload_error); ?></p>
        <?php endif; ?>
        <form method="POST">
            <label for="owncloud_url">ownCloud URL:</label>
            <input type="text" name="owncloud_url" id="owncloud_url" value="<?php echo htmlspecialchars($config['owncloud_url'] ?? ''); ?>" required><br>
            <label for="username">Username:</label>
            <input type="text" name="username" id="username" value="<?php echo htmlspecialchars($config['username'] ?? ''); ?>" required><br>
            <label for="password">Password:</label>
            <input type="password" name="password" id="password" value="<?php echo htmlspecialchars($config['password'] ?? ''); ?>" required><br>
            <label for="local_dir_path">Local Directory Path:</label>
            <input type="text" name="local_dir_path" id="local_dir_path" value="<?php echo htmlspecialchars($config['local_dir_path'] ?? ''); ?>" required><br>
            <label for="remote_dir_path">Remote Directory Path:</label>
            <input type="text" name="remote_dir_path" id="remote_dir_path" value="<?php echo htmlspecialchars($config['remote_dir_path'] ?? ''); ?>" required><br>
            <input type="submit" name="save_config" value="Save Configuration">
            <input type="submit" name="upload_files" value="Upload Files">
        </form>
    </div>
</body>
</html>

