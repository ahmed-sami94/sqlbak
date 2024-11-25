<?php
session_start();
if (!isset($_SESSION['loggedin'])) {
    header('Location: login.php');
    exit;
}

require 'db.php'; // Include database connection
require 'header.php'; // Include your HTML header

// Enable error reporting only in development environment
if (isset($_GET['debug']) && $_GET['debug'] == 'true') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0); // Hide errors in production
    ini_set('display_errors', 0);
}

$message = "";

// Fetch FTP configuration from the database
$ftp_config_query = "SELECT * FROM ftp_config LIMIT 1";
$ftp_config_result = $conn->query($ftp_config_query);

if ($ftp_config_result) {
    if ($ftp_config_result->num_rows > 0) {
        $ftp_config = $ftp_config_result->fetch_assoc();
        $server_ip = $ftp_config['server_ip'] ?? '';  // Set default empty string if not set
        $username = $ftp_config['username'] ?? '';    // Set default empty string if not set
        $password = $ftp_config['password'] ?? '';    // Set default empty string if not set
        $port = $ftp_config['port'] ?? 21;            // Default port 21 if not set
        $remote_dir_path = $ftp_config['remote_dir_path'] ?? '';  // Set default empty if not set
    } else {
        $message = "<p class='error'>No FTP configuration found in the database. Please add one.</p>";
    }
} else {
    $message = "<p class='error'>Error fetching FTP configuration: " . $conn->error . "</p>";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_config'])) {
        // Update FTP configuration in the database
        $server_ip = $_POST['server_ip'];
        $username = $_POST['username'];
        $password = $_POST['password'];
        $port = $_POST['port'];
        $remote_dir_path = $_POST['remote_dir'];

        // Check if FTP config exists in DB
        if (isset($ftp_config['id'])) {
            $update_query = "UPDATE ftp_config SET 
                                server_ip = ?, 
                                username = ?, 
                                password = ?, 
                                port = ?, 
                                remote_dir_path = ? 
                             WHERE id = ?";
            $stmt = $conn->prepare($update_query);
            $stmt->bind_param('sssisi', $server_ip, $username, $password, $port, $remote_dir_path, $ftp_config['id']);
            
            if ($stmt->execute()) {
                $message = "<p class='success'>Configuration updated successfully.</p>";
            } else {
                $message = "<p class='error'>Failed to update configuration: " . $conn->error . "</p>";
            }
        } else {
            $message = "<p class='error'>FTP configuration not found in the database. Please add one.</p>";
        }
    }

    if (isset($_POST['sync_now'])) {
        $local_dir = "/var/www/html/sqlbak/backups"; // Specify the correct local directory

        // Ensure the local directory exists
        if (!is_dir($local_dir)) {
            $message .= "<p class='error'>Local directory does not exist: $local_dir</p>";
        } else {
            // Sync files
            $files = array_diff(scandir($local_dir), ['.', '..']);
            if (empty($server_ip)) {
                $message .= "<p class='error'>FTP server IP is missing in configuration.</p>";
            } else {
                $ftp = ftp_connect($server_ip, $port, 30); // 30-second timeout

                if (!$ftp) {
                    $message .= "<p class='error'>Could not connect to FTP server at $server_ip:$port.</p>";
                } else {
                    ftp_pasv($ftp, true); // Enable passive mode

                    if (!ftp_login($ftp, $username, $password)) {
                        $message .= "<p class='error'>FTP login failed! Verify the username ($username) and password.</p>";
                    } else {
                        $message .= "<p class='success'>FTP login successful. Starting sync...</p>";

                        // Get list of already synced files
                        $existing_files = [];
                        if (ftp_chdir($ftp, $remote_dir_path)) {
                            $existing_files = ftp_nlist($ftp, ".");
                            if ($existing_files === false) {
                                $message .= "<p class='error'>Failed to retrieve file list from remote directory $remote_dir_path. It might be empty or the path is incorrect.</p>";
                            }
                        } else {
                            $message .= "<p class='error'>Failed to change directory to $remote_dir_path on the FTP server.</p>";
                        }

                        // Sync files
                        foreach ($files as $file) {
                            $local_file = $local_dir . '/' . $file;
                            if ($existing_files && !in_array($file, $existing_files)) {
                                if (ftp_put($ftp, $remote_dir_path . '/' . $file, $local_file, FTP_BINARY)) {
                                    $message .= "<p class='success'>File synced: $file</p>";
                                } else {
                                    $message .= "<p class='error'>Failed to sync file: $file</p>";
                                }
                            } else {
                                $message .= "<p class='info'>File already synced or cannot fetch the list: $file</p>";
                            }
                        }

                        ftp_close($ftp);
                    }
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="styles.css">
    <title>FTP Configuration and Sync</title>
</head>
<body>
    <div class="container">
        <h1>FTP Configuration and Sync</h1>
        <?= $message ?>

        <!-- Configuration Form -->
        <form method="POST">
            <h2>FTP Configuration</h2>
            <label for="server_ip">Server IP:</label>
            <input type="text" id="server_ip" name="server_ip" value="<?= htmlspecialchars($server_ip) ?>" required>

            <label for="username">Username:</label>
            <input type="text" id="username" name="username" value="<?= htmlspecialchars($username) ?>" required>

            <label for="password">Password:</label>
            <input type="password" id="password" name="password" value="<?= htmlspecialchars($password) ?>" required>

            <label for="port">Port:</label>
            <input type="number" id="port" name="port" value="<?= htmlspecialchars($port) ?>" required>

            <label for="remote_dir">Remote Upload Path:</label>
            <input type="text" id="remote_dir" name="remote_dir" value="<?= htmlspecialchars($remote_dir_path) ?>" required>

            <button type="submit" name="update_config">Save Configuration</button>
        </form>

        <!-- Sync Button -->
        <form method="POST">
            <button type="submit" name="sync_now">Sync Now</button>
        </form>
    </div>
</body>
</html>

