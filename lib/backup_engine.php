<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/trace.php';
require_once __DIR__ . '/policy.php';
require_once __DIR__ . '/mailer.php';

function sqlbak_database_folder(string $databaseName): string
{
    $folder = preg_replace('/[^A-Za-z0-9_-]+/', '-', $databaseName) ?? '';
    if ($folder === '') {
        throw new RuntimeException('Ø§Ø³Ù… Ù‚Ø§Ø¹Ø¯Ø© Ø§Ù„Ø¨ÙŠØ§Ù†Ø§Øª ØºÙŠØ± ØµØ§Ù„Ø­ Ù„Ù…Ø³Ø§Ø± Ø§Ù„ØªØ®Ø²ÙŠÙ†.');
    }
    return trim($folder, '-');
}

function sqlbak_storage_path(string $basePath, string $relativePath): string
{
    $root = rtrim($basePath, '/');
    $path = $root . '/' . ltrim($relativePath, '/');
    if (str_contains($relativePath, '..') || !str_starts_with($path, $root . '/')) {
        throw new RuntimeException('Ù…Ø³Ø§Ø± Ø§Ù„ØªØ®Ø²ÙŠÙ† ØºÙŠØ± ØµØ§Ù„Ø­.');
    }
    return $path;
}

function sqlbak_create_directory(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0750, true) && !is_dir($path)) {
        throw new RuntimeException('ØªØ¹Ø°Ø± Ø¥Ù†Ø´Ø§Ø¡ Ù…Ø¬Ù„Ø¯ Ø§Ù„ØªØ®Ø²ÙŠÙ†.');
    }
}

function sqlbak_dump_database(array $database, string $targetPath): array
{
    $secret = sqlbak_decrypt($database['password_encrypted'] ?? null);
    $password = $secret['password'] ?? null;
    if (!is_string($password) || $password === '') {
        throw new RuntimeException('Ø¨ÙŠØ§Ù†Ø§Øª Ø§ØªØµØ§Ù„ Ù‚Ø§Ø¹Ø¯Ø© Ø§Ù„Ø¨ÙŠØ§Ù†Ø§Øª ØºÙŠØ± Ù…ÙƒØªÙ…Ù„Ø©.');
    }

    $optionFile = tempnam(sys_get_temp_dir(), 'sqlbak-mysql-');
    if ($optionFile === false) {
        throw new RuntimeException('ØªØ¹Ø°Ø± Ø¥Ù†Ø´Ø§Ø¡ Ù…Ù„Ù Ø§ØªØµØ§Ù„ Ù…Ø¤Ù‚Øª.');
    }

    file_put_contents($optionFile, "[client]\nuser=" . $database['username'] . "\npassword=" . $password . "\nhost=" . $database['host'] . "\nport=" . (int) $database['port'] . "\n");
    chmod($optionFile, 0600);
    $command = ['mysqldump', '--defaults-extra-file=' . $optionFile, '--single-transaction', '--quick', '--routines', '--triggers', '--events', '--hex-blob', '--no-tablespaces', '--skip-column-statistics', $database['name']];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        unlink($optionFile);
        throw new RuntimeException('ØªØ¹Ø°Ø± Ø¨Ø¯Ø¡ Ø¹Ù…Ù„ÙŠØ© Ø§Ù„Ù†Ø³Ø® Ø§Ù„Ø§Ø­ØªÙŠØ§Ø·ÙŠ.');
    }

    $gzip = gzopen($targetPath, 'wb9');
    if ($gzip === false) {
        proc_terminate($process);
        unlink($optionFile);
        throw new RuntimeException('ØªØ¹Ø°Ø± Ø¥Ù†Ø´Ø§Ø¡ Ù…Ù„Ù Ø§Ù„Ù†Ø³Ø®Ø© Ø§Ù„Ø§Ø­ØªÙŠØ§Ø·ÙŠØ©.');
    }

    while (!feof($pipes[1])) {
        $chunk = fread($pipes[1], 1048576);
        if ($chunk !== false && $chunk !== '') {
            gzwrite($gzip, $chunk);
        }
    }
    fclose($pipes[1]);
    $errorOutput = trim((string) stream_get_contents($pipes[2]));
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    gzclose($gzip);
    unlink($optionFile);

    if ($exitCode !== 0 || filesize($targetPath) === 0) {
        @unlink($targetPath);
        throw new RuntimeException('ÙØ´Ù„ mysqldump: ' . ($errorOutput !== '' ? $errorOutput : 'Ø±Ù…Ø² Ø§Ù„Ø®Ø±ÙˆØ¬ ' . $exitCode));
    }
    return ['size_bytes' => filesize($targetPath), 'checksum_sha256' => hash_file('sha256', $targetPath)];
}

function sqlbak_restore_database(array $database, string $sourcePath): void
{
    $secret = sqlbak_decrypt($database['password_encrypted'] ?? null);
    $password = $secret['password'] ?? null;
    if (!is_string($password) || $password === '') {
        throw new RuntimeException('Ø¨ÙŠØ§Ù†Ø§Øª Ø§ØªØµØ§Ù„ Ù‚Ø§Ø¹Ø¯Ø© Ø§Ù„Ø¨ÙŠØ§Ù†Ø§Øª ØºÙŠØ± Ù…ÙƒØªÙ…Ù„Ø©.');
    }

    $optionFile = tempnam(sys_get_temp_dir(), 'sqlbak-restore-');
    if ($optionFile === false) {
        throw new RuntimeException('ØªØ¹Ø°Ø± Ø¥Ù†Ø´Ø§Ø¡ Ù…Ù„Ù Ø§ØªØµØ§Ù„ Ù…Ø¤Ù‚Øª.');
    }

    file_put_contents($optionFile, "[client]\nuser={$database['username']}\npassword={$password}\nhost={$database['host']}\nport=" . (int) $database['port'] . "\n");
    chmod($optionFile, 0600);
    $process = proc_open(['mysql', '--defaults-extra-file=' . $optionFile, $database['name']], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        unlink($optionFile);
        throw new RuntimeException('ØªØ¹Ø°Ø± Ø¨Ø¯Ø¡ Ø¹Ù…Ù„ÙŠØ© Ø§Ù„Ø§Ø³ØªØ¹Ø§Ø¯Ø©.');
    }

    $input = str_ends_with(strtolower($sourcePath), '.gz') ? gzopen($sourcePath, 'rb') : fopen($sourcePath, 'rb');
    if ($input === false) {
        proc_terminate($process);
        unlink($optionFile);
        throw new RuntimeException('ØªØ¹Ø°Ø± Ù‚Ø±Ø§Ø¡Ø© Ù…Ù„Ù Ø§Ù„Ù†Ø³Ø®Ø©.');
    }
    while (!feof($input)) {
        $chunk = fread($input, 1048576);
        if ($chunk !== false && $chunk !== '') {
            fwrite($pipes[0], $chunk);
        }
    }
    fclose($input);
    fclose($pipes[0]);
    fclose($pipes[1]);
    $errorOutput = trim((string) stream_get_contents($pipes[2]));
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    unlink($optionFile);
    if ($exitCode !== 0) {
        throw new RuntimeException('ÙØ´Ù„Øª Ø§Ù„Ø§Ø³ØªØ¹Ø§Ø¯Ø©: ' . ($errorOutput !== '' ? $errorOutput : 'Ø±Ù…Ø² Ø§Ù„Ø®Ø±ÙˆØ¬ ' . $exitCode));
    }
}

function sqlbak_destination_client(array $destination): array
{
    $options = json_decode($destination['options_json'] ?? '{}', true) ?: [];
    return ['destination' => $destination, 'options' => $options, 'secret' => sqlbak_decrypt($destination['secret_encrypted'] ?? null)];
}

function sqlbak_destination_object_path(array $destination, string $relativePath): string
{
    return ltrim(rtrim((string) ($destination['base_path'] ?? ''), '/') . '/' . ltrim($relativePath, '/'), '/');
}

function sqlbak_http_request(string $method, string $url, array $headers = [], ?string $payload = null, ?string $writePath = null): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL extension is required for remote storage operations.');
    }

    $normalizedMethod = strtoupper($method);
    $normalizedHeaders = $headers;
    if (!isset($normalizedHeaders['Content-Type']) && !isset($normalizedHeaders['content-type'])) {
        $normalizedHeaders['Content-Type'] = 'application/octet-stream';
    }
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Unable to initialize cURL request.');
    }

    $writeHandle = null;
    if ($writePath !== null) {
        $writeHandle = fopen($writePath, 'wb');
        if ($writeHandle === false) {
            throw new RuntimeException('Cannot open destination stream for HTTP download.');
        }
    }

    $options = [
        CURLOPT_CUSTOMREQUEST => $normalizedMethod,
        CURLOPT_RETURNTRANSFER => $writeHandle === null,
        CURLOPT_HTTPHEADER => sqlbak_http_request_headers($normalizedHeaders),
        CURLOPT_TIMEOUT => 300,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_SSL_VERIFYPEER => true,
    ];
    if ($writeHandle !== null) {
        $options[CURLOPT_FILE] = $writeHandle;
    }

    if ($payload !== null && in_array($normalizedMethod, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        $options[CURLOPT_POSTFIELDS] = $payload;
    }

    curl_setopt_array($curl, $options);
    $result = curl_exec($curl);
    $curlError = curl_error($curl);
    $httpCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    if ($writeHandle !== null) {
        fclose($writeHandle);
    }

    if ($result === false && $curlError !== '') {
        throw new RuntimeException($curlError);
    }

    return [
        'status' => $httpCode,
        'body' => $result === false || $writePath !== null ? '' : (string) $result,
    ];
}

function sqlbak_http_request_headers(array $headers): array
{
    $normalized = [];
    foreach ($headers as $name => $value) {
        if (!is_string($name) || $name === '') {
            continue;
        }
        if (is_array($value)) {
            $normalized = array_merge($normalized, array_map(static fn ($v) => sprintf('%s: %s', $name, (string) $v), $value));
        } else {
            $normalized[] = sprintf('%s: %s', $name, (string) $value);
        }
    }
    return $normalized;
}

function sqlbak_s3_client(array $client): array
{
    $destination = $client['destination'];
    $options = $client['options'];
    $secret = $client['secret'];
    $endpoint = trim((string) ($destination['host'] ?: 'https://s3.amazonaws.com'));
    if ($endpoint === '') {
        throw new RuntimeException('S3 endpoint is required.');
    }
    if (!str_contains($endpoint, '://')) {
        $endpoint = 'https://' . $endpoint;
    }
    $endpointParts = parse_url($endpoint);
    if (!is_array($endpointParts) || empty($endpointParts['host'])) {
        throw new RuntimeException('Invalid S3 endpoint format.');
    }
    $bucket = trim((string) ($options['s3_bucket'] ?? ''));
    if ($bucket === '') {
        throw new RuntimeException('S3 bucket is required.');
    }
    $region = trim((string) ($options['s3_region'] ?? 'us-east-1'));
    return [
        'endpoint' => rtrim((string) $endpointParts['scheme'], ':/') . '://' . $endpointParts['host'],
        'host' => $endpointParts['host'],
        'bucket' => $bucket,
        'region' => $region === '' ? 'us-east-1' : $region,
        'path_style' => (bool) ($options['s3_path_style'] ?? false),
        'session_token' => trim((string) ($secret['session_token'] ?? '')),
        'access_key' => (string) ($destination['username'] ?? ''),
        'secret_key' => trim((string) ($secret['password'] ?? '')),
    ];
}

function sqlbak_aws_hmac(string $key, string $message): string
{
    return hash_hmac('sha256', $message, $key, true);
}

function sqlbak_s3_request(
    array $destination,
    string $method,
    string $relativePath,
    string $payload = '',
    ?string $writePath = null
): array {
    $client = sqlbak_s3_client(sqlbak_destination_client($destination));
    if ($client['access_key'] === '' || $client['secret_key'] === '') {
        throw new RuntimeException('S3 access key and secret key are required.');
    }

    $bucket = $client['bucket'];
    $region = $client['region'];
    $host = $client['host'];
    $pathStyle = (bool) $client['path_style'];
    $sessionToken = $client['session_token'];
    $objectKey = sqlbak_destination_object_path($destination, $relativePath);
    $encodedPath = '/' . implode('/', array_map('rawurlencode', explode('/', trim($objectKey, '/'))));
    $requestHost = $pathStyle ? $host : $bucket . '.' . $host;
    $requestUri = $pathStyle ? '/' . rawurlencode($bucket) . $encodedPath : $encodedPath;
    $payloadHash = hash('sha256', $payload);
    $requestUrl = $client['endpoint'] . $requestUri;

    $normalizedDate = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Ymd\THis\Z');
    $dateStamp = substr($normalizedDate, 0, 8);
    $signedHeaders = ['host', 'x-amz-content-sha256', 'x-amz-date'];
    $canonicalHeaders = "host:{$requestHost}\n"
        . 'x-amz-content-sha256:' . $payloadHash . "\n"
        . 'x-amz-date:' . $normalizedDate . "\n";
    if ($sessionToken !== '') {
        $signedHeaders[] = 'x-amz-security-token';
        $canonicalHeaders .= 'x-amz-security-token:' . $sessionToken . "\n";
    }

    $canonicalRequest = strtoupper($method) . "\n"
        . $requestUri . "\n"
        . '' . "\n"
        . $canonicalHeaders . "\n"
        . implode(';', $signedHeaders) . "\n"
        . $payloadHash;

    $credentialScope = $dateStamp . '/' . $region . '/s3/aws4_request';
    $stringToSign = "AWS4-HMAC-SHA256\n"
        . $normalizedDate . "\n"
        . $credentialScope . "\n"
        . hash('sha256', $canonicalRequest);

    $kSecret = 'AWS4' . $client['secret_key'];
    $kDate = hash_hmac('sha256', $dateStamp, $kSecret, true);
    $kRegion = sqlbak_aws_hmac($kDate, $region);
    $kService = sqlbak_aws_hmac($kRegion, 's3');
    $kSigning = sqlbak_aws_hmac($kService, 'aws4_request');
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);
    $authorization = sprintf(
        'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
        $client['access_key'],
        $credentialScope,
        implode(';', $signedHeaders),
        $signature
    );

    $requestHeaders = [
        'Host' => $requestHost,
        'x-amz-date' => $normalizedDate,
        'x-amz-content-sha256' => $payloadHash,
        'Authorization' => $authorization,
    ];
    if ($sessionToken !== '') {
        $requestHeaders['x-amz-security-token'] = $sessionToken;
    }

    if ($writePath !== null && strtoupper($method) !== 'GET') {
        throw new RuntimeException('Only GET requests can stream response directly to disk for S3 restore/download.');
    }
    $response = sqlbak_http_request(strtoupper($method), $requestUrl, sqlbak_http_request_headers($requestHeaders), strtoupper($method) === 'GET' ? '' : $payload, $writePath);
    return ['status' => (int) $response['status'], 'body' => $response['body'] ?? ''];
}

function sqlbak_s3_put_object(array $destination, string $sourcePath, string $relativePath): void
{
    $payload = file_get_contents($sourcePath);
    if ($payload === false) {
        throw new RuntimeException('Unable to read local backup file for S3 upload.');
    }
    $result = sqlbak_s3_request($destination, 'PUT', $relativePath, $payload);
    if ($result['status'] < 200 || $result['status'] >= 300) {
        throw new SqlbakOperationException('VERIFY_FAILED', 'S3 upload failed: HTTP ' . $result['status']);
    }
}

function sqlbak_s3_get_object(array $destination, string $relativePath, string $targetPath): void
{
    $result = sqlbak_s3_request($destination, 'GET', $relativePath, '', $targetPath);
    if ($result['status'] !== 200) {
        @unlink($targetPath);
        throw new SqlbakOperationException('DOWNLOAD_FAILED', 'S3 download failed: HTTP ' . $result['status']);
    }
}

function sqlbak_s3_delete_object(array $destination, string $relativePath): void
{
    $result = sqlbak_s3_request($destination, 'DELETE', $relativePath);
    if ($result['status'] !== 204 && $result['status'] !== 200 && $result['status'] !== 202) {
        throw new SqlbakOperationException('DELETE_FAILED', 'S3 delete failed: HTTP ' . $result['status']);
    }
}

function sqlbak_dropbox_api_request(string $method, array $client, string $route, array $payload = [], ?string $writePath = null, ?string $body = null): array
{
    $token = trim((string) ($client['secret']['password'] ?? ''));
    if ($token === '') {
        throw new RuntimeException('Dropbox access token is required.');
    }

    $route = ltrim($route, '/');
    $apiHost = trim((string) ($client['destination']['host'] ?? 'https://api.dropboxapi.com/2'));
    if (!str_contains($apiHost, '://')) {
        $apiHost = 'https://' . $apiHost;
    }
    $contentHost = trim((string) ($client['options']['dropbox_content_host'] ?? 'https://content.dropboxapi.com/2'));
    if (!str_contains($contentHost, '://')) {
        $contentHost = 'https://' . $contentHost;
    }

    $usesContentApi = str_starts_with($route, 'files/upload') || str_starts_with($route, 'files/download');
    $endpoint = ($usesContentApi ? rtrim($contentHost, '/') : rtrim($apiHost, '/')) . '/' . $route;

    $headers = [
        'Authorization' => 'Bearer ' . $token,
    ];

    if ($writePath === null) {
        $headers['Content-Type'] = 'application/json';
        if (!empty($payload)) {
            $body = json_encode($payload, JSON_THROW_ON_ERROR);
        } elseif ($body === null) {
            $body = '';
        }
    } else {
        $headers['Content-Type'] = 'application/octet-stream';
        if (!empty($payload)) {
            $headers['Dropbox-API-Arg'] = json_encode($payload, JSON_THROW_ON_ERROR);
        }
        if ($body === null) {
            $body = '';
        }
    }

    $response = sqlbak_http_request($method, $endpoint, $headers, $body, $writePath);
    return ['status' => (int) $response['status'], 'body' => $response['body'] ?? ''];
}

function sqlbak_dropbox_path(array $destination, string $relativePath): string
{
    return '/' . ltrim(trim((string) $destination['base_path'], '/') . '/' . ltrim($relativePath, '/'), '/');
}

function sqlbak_dropbox_copy_upload(array $destination, string $sourcePath, string $relativePath): void
{
    $payload = file_get_contents($sourcePath);
    if ($payload === false) {
        throw new RuntimeException('Unable to read local backup file for Dropbox upload.');
    }

    $path = sqlbak_dropbox_path($destination, $relativePath);
    $client = sqlbak_destination_client($destination);
    $response = sqlbak_dropbox_api_request('POST', $client, 'files/upload', ['path' => $path], null, $payload);
    if ((int) $response['status'] !== 200) {
        throw new SqlbakOperationException('VERIFY_FAILED', 'Dropbox upload failed: HTTP ' . $response['status']);
    }
}

function sqlbak_dropbox_upload(array $destination, string $sourcePath, string $relativePath): void
{
    sqlbak_dropbox_copy_upload($destination, $sourcePath, $relativePath);
}

function sqlbak_dropbox_download(array $destination, string $relativePath, string $targetPath): void
{
    $path = sqlbak_dropbox_path($destination, $relativePath);
    $client = sqlbak_destination_client($destination);
    $response = sqlbak_dropbox_api_request('POST', $client, 'files/download', ['path' => $path], $targetPath);
    if ((int) $response['status'] !== 200) {
        @unlink($targetPath);
        throw new SqlbakOperationException('DOWNLOAD_FAILED', 'Dropbox download failed: HTTP ' . $response['status']);
    }
}

function sqlbak_dropbox_delete(array $destination, string $relativePath): void
{
    $path = sqlbak_dropbox_path($destination, $relativePath);
    $client = sqlbak_destination_client($destination);
    $response = sqlbak_dropbox_api_request('POST', $client, 'files/delete_v2', ['path' => $path]);
    if ((int) $response['status'] !== 200) {
        throw new SqlbakOperationException('DELETE_FAILED', 'Dropbox delete failed: HTTP ' . $response['status']);
    }
}

function sqlbak_copy_to_destination(array $destination, string $sourcePath, string $relativePath): void
{
    $client = sqlbak_destination_client($destination);
    $type = $destination['type'];
    if ($type === 'local') {
        $root = realpath($destination['base_path']) ?: $destination['base_path'];
        $allowedRoot = realpath(sqlbak_backup_root()) ?: sqlbak_backup_root();
        if ($root !== $allowedRoot && !str_starts_with($root, $allowedRoot . '/')) {
            throw new RuntimeException('Destination path is not valid.');
        }
        $targetPath = sqlbak_storage_path($root, $relativePath);
        sqlbak_create_directory(dirname($targetPath));
        if (!copy($sourcePath, $targetPath) || filesize($targetPath) !== filesize($sourcePath)) {
            @unlink($targetPath);
            throw new RuntimeException('Failed to copy backup file to local destination.');
        }
        return;
    }
    if ($type === 'ftp') {
        $connection = sqlbak_ftp_connect($client);
        $targetPath = trim($destination['base_path'], '/') . '/' . ltrim($relativePath, '/');
        sqlbak_ftp_mkdir($connection, dirname($targetPath));
        $uploaded = ftp_put($connection, $targetPath, $sourcePath, FTP_BINARY);
        $verified = $uploaded && ftp_size($connection, $targetPath) === filesize($sourcePath);
        if (!$verified) {
            @ftp_delete($connection, $targetPath);
        }
        ftp_close($connection);
        if (!$verified) {
            throw new SqlbakOperationException('VERIFY_FAILED', 'Failed to upload backup file over FTP.');
        }
        return;
    }
    if ($type === 'sftp') {
        $sftp = sqlbak_sftp_client($client);
        $targetPath = rtrim($destination['base_path'], '/') . '/' . ltrim($relativePath, '/');
        $sftp->mkdir(dirname($targetPath), -1, true);
        $uploaded = $sftp->put($targetPath, $sourcePath, \phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE);
        if (!$uploaded || $sftp->filesize($targetPath) !== filesize($sourcePath)) {
            $sftp->delete($targetPath);
            throw new SqlbakOperationException('VERIFY_FAILED', 'Failed to upload backup file over SFTP.');
        }
        return;
    }
    if ($type === 's3') {
        sqlbak_s3_put_object($destination, $sourcePath, $relativePath);
        return;
    }
    if ($type === 'dropbox') {
        sqlbak_dropbox_copy_upload($destination, $sourcePath, $relativePath);
        return;
    }
    throw new RuntimeException('Unsupported destination type.');
}

function sqlbak_copy_from_destination(array $destination, string $relativePath, string $targetPath): void
{
    sqlbak_create_directory(dirname($targetPath));
    $client = sqlbak_destination_client($destination);
    if ($destination['type'] === 'local') {
        $root = realpath($destination['base_path']) ?: $destination['base_path'];
        $allowedRoot = realpath(sqlbak_backup_root()) ?: sqlbak_backup_root();
        if ($root !== $allowedRoot && !str_starts_with($root, $allowedRoot . '/')) {
            throw new RuntimeException('Destination path is not valid.');
        }
        $sourcePath = sqlbak_storage_path($root, $relativePath);
        if (!is_file($sourcePath) || !copy($sourcePath, $targetPath)) {
            @unlink($targetPath);
            throw new RuntimeException('Could not read backup file from local destination.');
        }
        return;
    }
    if ($destination['type'] === 'ftp') {
        $connection = sqlbak_ftp_connect($client);
        $remotePath = trim($destination['base_path'], '/') . '/' . ltrim($relativePath, '/');
        $downloaded = ftp_get($connection, $targetPath, $remotePath, FTP_BINARY);
        ftp_close($connection);
        if (!$downloaded) {
            @unlink($targetPath);
            throw new RuntimeException('Failed to download backup from FTP.');
        }
        return;
    }
    if ($destination['type'] === 'sftp') {
        $sftp = sqlbak_sftp_client($client);
        $remotePath = rtrim($destination['base_path'], '/') . '/' . ltrim($relativePath, '/');
        if (!$sftp->get($remotePath, $targetPath)) {
            @unlink($targetPath);
            throw new RuntimeException('Failed to download backup from SFTP.');
        }
        return;
    }
    if ($destination['type'] === 's3') {
        sqlbak_s3_get_object($destination, $relativePath, $targetPath);
        return;
    }
    if ($destination['type'] === 'dropbox') {
        sqlbak_dropbox_download($destination, $relativePath, $targetPath);
        return;
    }
    throw new RuntimeException('Unsupported destination type.');
}

function sqlbak_delete_destination_copy(array $destination, string $relativePath): void
{
    $client = sqlbak_destination_client($destination);
    if ($destination['type'] === 'local') {
        $targetPath = sqlbak_storage_path($destination['base_path'], $relativePath);
        if (is_file($targetPath) && !unlink($targetPath)) {
            throw new RuntimeException('Failed to delete local backup copy.');
        }
        return;
    }
    if ($destination['type'] === 'ftp') {
        $connection = sqlbak_ftp_connect($client);
        $deleted = ftp_delete($connection, trim($destination['base_path'], '/') . '/' . ltrim($relativePath, '/'));
        ftp_close($connection);
        if (!$deleted) {
            throw new RuntimeException('Failed to delete backup copy from FTP.');
        }
        return;
    }
    if ($destination['type'] === 'sftp') {
        $sftp = sqlbak_sftp_client($client);
        if (!$sftp->delete(rtrim($destination['base_path'], '/') . '/' . ltrim($relativePath, '/'))) {
            throw new RuntimeException('Failed to delete backup copy from SFTP.');
        }
        return;
    }
    if ($destination['type'] === 's3') {
        sqlbak_s3_delete_object($destination, $relativePath);
        return;
    }
    if ($destination['type'] === 'dropbox') {
        sqlbak_dropbox_delete($destination, $relativePath);
        return;
    }
    throw new RuntimeException('Unsupported destination type.');
}
function sqlbak_ftp_connect(array $client)
{
    $destination = $client['destination'];
    $connection = !empty($client['options']['tls'])
        ? @ftp_ssl_connect($destination['host'], (int) $destination['port'], 20)
        : @ftp_connect($destination['host'], (int) $destination['port'], 20);
    if ($connection === false || !@ftp_login($connection, $destination['username'], $client['secret']['password'] ?? '')) {
        throw new SqlbakOperationException('AUTH_FAILED', 'ØªØ¹Ø°Ø± Ø§Ù„Ø§ØªØµØ§Ù„ Ø£Ùˆ ØªØ³Ø¬ÙŠÙ„ Ø§Ù„Ø¯Ø®ÙˆÙ„ Ø¥Ù„Ù‰ ÙˆØ¬Ù‡Ø© FTP.');
    }
    ftp_pasv($connection, $client['options']['passive'] ?? true);
    return $connection;
}

function sqlbak_ftp_mkdir($connection, string $directory): void
{
    $current = '';
    foreach (array_filter(explode('/', trim($directory, '/'))) as $segment) {
        $current .= '/' . $segment;
        @ftp_mkdir($connection, $current);
    }
}

function sqlbak_sftp_client(array $client)
{
    $autoload = SQLBAK_ROOT . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new SqlbakOperationException('SFTP_LIBRARY_MISSING', 'Ø¯Ø¹Ù… SFTP ØºÙŠØ± Ù…Ø«Ø¨Øª.');
    }
    require_once $autoload;
    $destination = $client['destination'];
    $sftp = new \phpseclib3\Net\SFTP($destination['host'], (int) $destination['port'], 20);
    $serverKey = $sftp->getServerPublicHostKey();
    if (!is_string($serverKey) || $serverKey === '') {
        throw new SqlbakOperationException('CONNECTION_FAILED', 'ØªØ¹Ø°Ø± Ø§Ù„Ø§ØªØµØ§Ù„ Ø¨Ø®Ø§Ø¯Ù… SFTP Ø¹Ù„Ù‰ Ø§Ù„Ù…Ù†ÙØ° Ø§Ù„Ù…Ø­Ø¯Ø¯.');
    }
    $fingerprint = $client['options']['host_fingerprint'] ?? '';
    if ($fingerprint !== '' && hash('sha256', $serverKey) !== $fingerprint) {
        throw new SqlbakOperationException('HOST_KEY_MISMATCH', 'Ø¨ØµÙ…Ø© Ø®Ø§Ø¯Ù… SFTP Ù„Ø§ ØªØ·Ø§Ø¨Ù‚ Ø§Ù„Ø¨ØµÙ…Ø© Ø§Ù„Ù…Ø­ÙÙˆØ¸Ø©.');
    }
    $authenticated = ($client['options']['auth_method'] ?? 'password') === 'key'
        ? $sftp->login($destination['username'], \phpseclib3\Crypt\PublicKeyLoader::load($client['secret']['private_key'] ?? '', $client['secret']['passphrase'] ?? false))
        : $sftp->login($destination['username'], $client['secret']['password'] ?? '');
    if (!$authenticated) {
        throw new SqlbakOperationException('AUTH_FAILED', 'ØªØ¹Ø°Ø± ØªØ³Ø¬ÙŠÙ„ Ø§Ù„Ø¯Ø®ÙˆÙ„ Ø¥Ù„Ù‰ SFTP.');
    }
    return $sftp;
}


function sqlbak_claim_backup(int $backupId): ?array
{
    $pdo = sqlbak_db();
    $pdo->beginTransaction();
    $statement = $pdo->prepare('SELECT b.*, d.name, d.host, d.port, d.username, d.password_encrypted FROM backups b JOIN `databases` d ON d.id=b.database_id WHERE b.id=? FOR UPDATE');
    $statement->execute([$backupId]);
    $job = $statement->fetch();
    if (!$job || $job['status'] !== 'queued') {
        $pdo->rollBack();
        return null;
    }
    $traceId = $job['trace_id'] ?: sqlbak_trace_id();
    $pdo->prepare("UPDATE backups SET status='running',trace_id=?,started_at=NOW() WHERE id=?")->execute([$traceId, $backupId]);
    $pdo->commit();
    $job['trace_id'] = $traceId;
    return $job;
}

function sqlbak_backup_destinations(int $databaseId): array
{
    $statement = sqlbak_db()->prepare('SELECT d.* FROM storage_destinations d JOIN database_storage_destinations link ON link.destination_id=d.id WHERE link.database_id=? AND d.enabled=1 ORDER BY d.display_order,d.id');
    $statement->execute([$databaseId]);
    return $statement->fetchAll();
}

function sqlbak_run_destination_copy(array $job, array $destination, string $stagingPath, string $relativePath, array $dumpDetails): bool
{
    $pdo = sqlbak_db();
    $startedAt = microtime(true);
    $pdo->prepare("INSERT INTO backup_copies (backup_id,destination_id,trace_id,relative_path,status,started_at) VALUES (?,?,?,?, 'running',NOW())")
        ->execute([$job['id'], $destination['id'], $job['trace_id'], $relativePath]);
    $copyId = (int) $pdo->lastInsertId();
    try {
        sqlbak_copy_to_destination($destination, $stagingPath, $relativePath);
        $duration = (int) round((microtime(true) - $startedAt) * 1000);
        $pdo->prepare("UPDATE backup_copies SET status='success',size_bytes=?,checksum_sha256=?,completed_at=NOW(),duration_ms=?,error_message=NULL,error_code=NULL WHERE id=?")
            ->execute([$dumpDetails['size_bytes'], $dumpDetails['checksum_sha256'], $duration, $copyId]);
        sqlbak_record_event(['trace_id' => $job['trace_id'], 'backup_id' => $job['id'], 'copy_id' => $copyId, 'destination_id' => $destination['id'], 'policy_rule_id' => $job['policy_rule_id'], 'event_type' => 'backup_copy', 'phase' => 'upload', 'message' => 'ØªÙ… Ø­ÙØ¸ Ø§Ù„Ù†Ø³Ø®Ø© ÙÙŠ Ø§Ù„ÙˆØ¬Ù‡Ø© Ø¨Ù†Ø¬Ø§Ø­.', 'context' => ['duration_ms' => $duration, 'size_bytes' => $dumpDetails['size_bytes']]]);
        sqlbak_prune_destination($destination, $job);
        return true;
    } catch (Throwable $error) {
        $errorCode = sqlbak_error_code($error);
        $duration = (int) round((microtime(true) - $startedAt) * 1000);
        $pdo->prepare("UPDATE backup_copies SET status='failed',completed_at=NOW(),duration_ms=?,error_message=?,error_code=? WHERE id=?")
            ->execute([$duration, $error->getMessage(), $errorCode, $copyId]);
        sqlbak_record_event(['trace_id' => $job['trace_id'], 'backup_id' => $job['id'], 'copy_id' => $copyId, 'destination_id' => $destination['id'], 'policy_rule_id' => $job['policy_rule_id'], 'event_type' => 'backup_copy', 'phase' => 'upload', 'level' => 'error', 'error_code' => $errorCode, 'message' => $error->getMessage(), 'context' => ['duration_ms' => $duration]]);
        try {
            sqlbak_send_failure_alert([
                'event_type' => 'backup_copy_failed',
                'trace_id' => (string) $job['trace_id'],
                'database' => (string) $job['name'],
                'destination' => (string) $destination['name'],
                'message' => $error->getMessage(),
                'error_code' => $errorCode,
                'backup_id' => (int) $job['id'],
                'destination_id' => (int) $destination['id'],
            ]);
        } catch (Throwable $mailError) {
            sqlbak_record_event([
                'trace_id' => $job['trace_id'],
                'backup_id' => $job['id'],
                'copy_id' => $copyId,
                'destination_id' => $destination['id'],
                'policy_rule_id' => $job['policy_rule_id'],
                'event_type' => 'mail_alert',
                'phase' => 'failure_alert',
                'level' => 'warning',
                'error_code' => sqlbak_error_code($mailError),
                'message' => 'Failed to send backup copy failure email notification.',
                'context' => ['mail_error' => $mailError->getMessage()],
            ]);
        }
        return false;
    }
}

function sqlbak_run_backup(int $backupId): void
{
    $job = sqlbak_claim_backup($backupId);
    if ($job === null) {
        return;
    }
    $folder = sqlbak_database_folder($job['name']);
    $filename = $folder . '_' . date('Ymd_His') . '.sql.gz';
    $relativePath = $folder . '/' . $filename;
    $stagingPath = sqlbak_backup_root() . '/.staging/' . $filename . '.part';
    sqlbak_create_directory(dirname($stagingPath));
    try {
        sqlbak_record_event(['trace_id' => $job['trace_id'], 'backup_id' => $job['id'], 'policy_rule_id' => $job['policy_rule_id'], 'event_type' => 'backup_job', 'phase' => 'dump', 'message' => 'Ø¨Ø¯Ø£ Ø¥Ù†Ø´Ø§Ø¡ Ù…Ù„Ù Ù‚Ø§Ø¹Ø¯Ø© Ø§Ù„Ø¨ÙŠØ§Ù†Ø§Øª.']);
        $dumpDetails = sqlbak_dump_database($job, $stagingPath);
        $destinations = sqlbak_backup_destinations((int) $job['database_id']);
        if ($destinations === []) {
            throw new SqlbakOperationException('NO_ACTIVE_DESTINATION', 'Ù„Ø§ ØªÙˆØ¬Ø¯ ÙˆØ¬Ù‡Ø© ØªØ®Ø²ÙŠÙ† Ù…ÙØ¹Ù„Ø© ÙˆÙ…Ø±ØªØ¨Ø·Ø© Ø¨Ù‚Ø§Ø¹Ø¯Ø© Ø§Ù„Ø¨ÙŠØ§Ù†Ø§Øª.');
        }
        $successfulCopies = 0;
        foreach ($destinations as $destination) {
            $successfulCopies += sqlbak_run_destination_copy($job, $destination, $stagingPath, $relativePath, $dumpDetails) ? 1 : 0;
        }
        $status = $successfulCopies === count($destinations) ? 'success' : ($successfulCopies > 0 ? 'partial' : 'failed');
        $message = $status === 'success' ? null : 'Ù†Ø¬Ø­ ' . $successfulCopies . ' Ù…Ù† ' . count($destinations) . ' ÙˆØ¬Ù‡Ø§Øª.';
        sqlbak_db()->prepare('UPDATE backups SET filename=?,status=?,size_bytes=?,checksum_sha256=?,completed_at=NOW(),error_message=? WHERE id=?')
            ->execute([$filename, $status, $dumpDetails['size_bytes'], $dumpDetails['checksum_sha256'], $message, $backupId]);
        sqlbak_update_policy_result($job, $status, $message);
    } catch (Throwable $error) {
        $errorCode = sqlbak_error_code($error);
        sqlbak_db()->prepare("UPDATE backups SET status='failed',completed_at=NOW(),error_message=? WHERE id=?")->execute([$error->getMessage(), $backupId]);
        sqlbak_update_policy_result($job, 'failed', $error->getMessage());
        sqlbak_record_event(['trace_id' => $job['trace_id'], 'backup_id' => $job['id'], 'policy_rule_id' => $job['policy_rule_id'], 'event_type' => 'backup_job', 'phase' => 'job', 'level' => 'error', 'error_code' => $errorCode, 'message' => $error->getMessage()]);
        try {
            sqlbak_send_failure_alert([
                'event_type' => 'backup_job_failed',
                'trace_id' => (string) $job['trace_id'],
                'database' => (string) $job['name'],
                'destination' => 'all',
                'message' => $error->getMessage(),
                'error_code' => $errorCode,
                'backup_id' => (int) $job['id'],
                'destination_id' => 0,
            ]);
        } catch (Throwable $mailError) {
            sqlbak_record_event([
                'trace_id' => $job['trace_id'],
                'backup_id' => $job['id'],
                'policy_rule_id' => $job['policy_rule_id'],
                'event_type' => 'mail_alert',
                'phase' => 'failure_alert',
                'level' => 'warning',
                'error_code' => sqlbak_error_code($mailError),
                'message' => 'Failed to send backup job failure email notification.',
                'context' => ['mail_error' => $mailError->getMessage()],
            ]);
        }
    } finally {
        if (is_file($stagingPath)) {
            @unlink($stagingPath);
        }
    }
}

function sqlbak_update_policy_result(array $job, string $status, ?string $message): void
{
    if (empty($job['policy_rule_id'])) {
        return;
    }
    $successAt = in_array($status, ['success', 'partial'], true) ? date('Y-m-d H:i:s') : null;
    sqlbak_db()->prepare('UPDATE backup_policy_rules SET last_status=?,last_error=?,last_success_at=COALESCE(?,last_success_at) WHERE id=?')
        ->execute([$status, $message, $successAt, $job['policy_rule_id']]);
}

function sqlbak_prune_destination(array $destination, array $job): void
{
    $retention = sqlbak_setting('default_retention_count', 24);
    if (!empty($job['policy_rule_id'])) {
        $statement = sqlbak_db()->prepare('SELECT retention_count FROM backup_policy_rules WHERE id=?');
        $statement->execute([$job['policy_rule_id']]);
        $retention = (int) ($statement->fetchColumn() ?: $retention);
    }
    $copies = sqlbak_db()->prepare("SELECT c.id,c.relative_path FROM backup_copies c JOIN backups b ON b.id=c.backup_id WHERE b.database_id=? AND c.destination_id=? AND c.status='success' AND b.policy_rule_id <=> ? ORDER BY b.completed_at DESC,b.id DESC");
    $copies->execute([$job['database_id'], $destination['id'], $job['policy_rule_id']]);
    foreach (array_slice($copies->fetchAll(), $retention) as $copy) {
        sqlbak_delete_destination_copy($destination, $copy['relative_path']);
        sqlbak_db()->prepare("UPDATE backup_copies SET status='deleted' WHERE id=?")->execute([$copy['id']]);
    }
}

function sqlbak_enqueue_due_backups(): void
{
    $pdo = sqlbak_db();
    $policies = $pdo->query("SELECT p.* FROM backup_policy_rules p JOIN `databases` d ON d.id=p.database_id WHERE p.enabled=1 AND d.enabled=1 AND (p.next_run_at IS NULL OR p.next_run_at<=NOW()) FOR UPDATE")->fetchAll();
    foreach ($policies as $policy) {
        $traceId = sqlbak_trace_id();
        $pdo->prepare("INSERT INTO backups (database_id,policy_rule_id,trace_id,scheduled_for,filename,note,type,status) VALUES (?,?,?,?,? ,?,'automatic','queued')")
            ->execute([$policy['database_id'], $policy['id'], $traceId, $policy['next_run_at'], '', 'Ø³ÙŠØ§Ø³Ø©: ' . $policy['name']]);
        $nextRun = sqlbak_policy_next_run($policy)->format('Y-m-d H:i:s');
        $pdo->prepare("UPDATE backup_policy_rules SET next_run_at=?,last_run_at=NOW(),last_status='queued',last_error=NULL WHERE id=?")
            ->execute([$nextRun, $policy['id']]);
    }
}

function sqlbak_run_worker(): void
{
    $pdo = sqlbak_db();
    if ((int) $pdo->query("SELECT GET_LOCK('sqlbak_backup_worker',0)")->fetchColumn() !== 1) {
        return;
    }
    try {
        $pdo->beginTransaction();
        sqlbak_enqueue_due_backups();
        $pdo->commit();
        $backupId = $pdo->query("SELECT id FROM backups WHERE status='queued' ORDER BY created_at,id LIMIT 1")->fetchColumn();
        if ($backupId !== false) {
            sqlbak_run_backup((int) $backupId);
        }
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->query("SELECT RELEASE_LOCK('sqlbak_backup_worker')");
    }
}
