<?php
require 'db.php';

// Fetch configuration
$stmt = $conn->prepare("SELECT * FROM `owncloud_config` WHERE id = 1");
$stmt->execute();
$config = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($config) {
    $owncloud_url = $config['owncloud_url'];
    $username = $config['username'];
    $password = $config['password'];
    $local_dir_path = $config['local_dir_path'];
    $remote_dir_path = $config['remote_dir_path'];

    // Debugging: Log configuration
    file_put_contents('/tmp/upload_debug.log', "Configuration Loaded\n", FILE_APPEND);
    file_put_contents('/tmp/upload_debug.log', print_r($config, true), FILE_APPEND);

    if (is_dir($local_dir_path)) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($local_dir_path));

        $uploaded_files = [];
        foreach ($files as $file) {
            if ($file->isDir()) continue;

            $local_file_path = $file->getRealPath();
            $remote_file_path = $remote_dir_path . '/' . basename($local_file_path);

            $ch = curl_init();
            $file_handle = fopen($local_file_path, 'r');
            if ($file_handle === false) {
                // Debugging: Log file open error
                file_put_contents('/tmp/upload_debug.log', "Cannot open file: $local_file_path\n", FILE_APPEND);
                continue; // Skip files that can't be opened
            }

            curl_setopt($ch, CURLOPT_URL, $owncloud_url . $remote_file_path);
            curl_setopt($ch, CURLOPT_PUT, true);
            curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . $password);
            curl_setopt($ch, CURLOPT_INFILE, $file_handle);
            curl_setopt($ch, CURLOPT_INFILESIZE, filesize($local_file_path));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            $response = curl_exec($ch);
            if (curl_errno($ch)) {
                // Debugging: Log cURL errors
                file_put_contents('/tmp/upload_debug.log', "cURL Error: " . curl_error($ch) . "\n", FILE_APPEND);
                continue; // Skip files that fail to upload
            } else {
                $uploaded_files[] = basename($local_file_path);
            }

            fclose($file_handle);
            curl_close($ch);
        }

        if (!empty($uploaded_files)) {
            $last_upload_time = date('Y-m-d H:i:s');
            $last_uploaded_files = implode(', ', $uploaded_files);

            // Update last upload time and file names
            $stmt = $conn->prepare("UPDATE `owncloud_config` SET last_upload_time = ?, last_uploaded_files = ? WHERE id = 1");
            $stmt->bind_param('ss', $last_upload_time, $last_uploaded_files);
            $stmt->execute();
            $stmt->close();
        } else {
            // Debugging: Log no files uploaded
            file_put_contents('/tmp/upload_debug.log', "No files uploaded\n", FILE_APPEND);
        }
    } else {
        // Debugging: Log directory not found
        file_put_contents('/tmp/upload_debug.log', "Local directory not found: $local_dir_path\n", FILE_APPEND);
    }
} else {
    // Debugging: Log configuration fetch error
    file_put_contents('/tmp/upload_debug.log', "Configuration not found\n", FILE_APPEND);
}
?>

