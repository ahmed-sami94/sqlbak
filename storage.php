<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/destination_health.php';

sqlbak_require_operator();
$pdo = sqlbak_db();

function sqlbak_storage_remote_type_allowed(string $type): bool
{
    return in_array($type, ['ftp', 'sftp', 's3', 'dropbox'], true);
}

function sqlbak_storage_request(): array
{
    $type = (string) ($_POST['type'] ?? 'local');
    $name = trim((string) ($_POST['name'] ?? ''));
    $basePath = trim((string) ($_POST['base_path'] ?? ''));
    $s3Host = trim((string) ($_POST['s3_host'] ?? ''));
    $s3Bucket = trim((string) ($_POST['s3_bucket'] ?? ''));
    $host = trim((string) ($_POST['host'] ?? ''));
    $basePath = $basePath === '' ? '' : rtrim($basePath, '/');

    if (!in_array($type, ['local', 'ftp', 'sftp', 's3', 'dropbox'], true) || $name === '') {
        throw new InvalidArgumentException('Destination name/type is required.');
    }
    if ($type === 'local' && $basePath === '') {
        $basePath = sqlbak_backup_root();
    }
    if ($type === 's3' && $s3Bucket === '') {
        throw new InvalidArgumentException('S3 bucket is required.');
    }
    if (in_array($type, ['ftp', 'sftp'], true) && $basePath === '') {
        throw new InvalidArgumentException('Base path is required for this destination type.');
    }
    if ($type === 'dropbox' && $basePath === '') {
        $basePath = 'sqlbak';
    }
    if ($type === 's3' && $basePath === '') {
        $basePath = '';
    }
    if ($type === 's3' && $basePath !== '' && str_starts_with(trim($basePath), '/')) {
        $basePath = ltrim($basePath, '/');
    }
    if ($type === 's3' && $basePath !== '') {
        $basePath = rtrim($basePath, '/');
    }

    if ($type === 'local') {
        sqlbak_validate_local_destination($basePath);
    } else {
        sqlbak_validate_remote_destination($type, $host, $s3Host);
    }

    $options = match ($type) {
        'ftp' => ['passive' => isset($_POST['passive']), 'tls' => isset($_POST['tls']), 'auth_method' => 'password', 'host_fingerprint' => ''],
        'sftp' => ['passive' => false, 'tls' => false, 'auth_method' => (string) ($_POST['auth_method'] ?? 'password'), 'host_fingerprint' => trim((string) ($_POST['host_fingerprint'] ?? ''))],
        's3' => [
            's3_bucket' => $s3Bucket,
            's3_region' => trim((string) ($_POST['s3_region'] ?? 'us-east-1')),
            's3_path_style' => isset($_POST['s3_path_style']),
            's3_session_token' => trim((string) ($_POST['s3_session_token'] ?? '')),
        ],
        'dropbox' => [
            'dropbox_content_host' => trim((string) ($_POST['dropbox_content_host'] ?? 'https://content.dropboxapi.com/2')),
            'dropbox_api_host' => trim((string) ($_POST['dropbox_api_host'] ?? 'https://api.dropboxapi.com/2')),
        ],
        default => [],
    };
    if (isset($options['s3_region']) && $options['s3_region'] === '') {
        $options['s3_region'] = 'us-east-1';
    }

    return [
        'name' => $name,
        'display_order' => max(1, (int) ($_POST['display_order'] ?? 1)),
        'type' => $type,
        'host' => $type === 'local' ? null : ($type === 's3' ? ($s3Host === '' ? 'https://s3.amazonaws.com' : $s3Host) : $host),
        'port' => $type === 'local' ? null : (int) ($_POST['port'] ?? match ($type) {
            'sftp' => 22,
            's3' => 443,
            'dropbox' => 443,
            default => 21,
        }),
        'username' => $type === 'local' ? null : trim((string) ($_POST['username'] ?? '')),
        'base_path' => $basePath,
        'options_json' => json_encode($options, JSON_THROW_ON_ERROR),
    ];
}

function sqlbak_validate_remote_destination(string $type, string $host, string $s3Host): void
{
    if ($type === 'ftp' || $type === 'sftp') {
        $port = (int) ($_POST['port'] ?? 0);
        $username = trim((string) ($_POST['username'] ?? ''));
        if ($host === '' || $username === '' || $port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Invalid remote destination credentials for ' . strtoupper($type));
        }
        return;
    }

    if ($type === 's3') {
        $endpoint = $s3Host !== '' ? $s3Host : 'https://s3.amazonaws.com';
        $accessKey = trim((string) ($_POST['username'] ?? ''));
        $secret = trim((string) ($_POST['password'] ?? ''));
        if ($endpoint === '' || $accessKey === '' || $secret === '') {
            throw new InvalidArgumentException('S3 requires endpoint, access key, and secret.');
        }
        return;
    }

    if ($type === 'dropbox') {
        $token = trim((string) ($_POST['password'] ?? ''));
        if ($token === '') {
            throw new InvalidArgumentException('Dropbox access token is required.');
        }
        return;
    }
}

function sqlbak_validate_local_destination(string $path): void
{
    $root = rtrim(sqlbak_backup_root(), '/');
    $candidate = rtrim($path, '/');
    if ($candidate !== $root && !str_starts_with($candidate, $root . '/')) {
        throw new InvalidArgumentException('Local destination path must stay inside allowed backup root.');
    }
}

function sqlbak_destination_secret(int $destinationId): ?string
{
    $token = (string) ($_POST['password'] ?? '');
    if ($token !== '') {
        return sqlbak_encrypt(['password' => $token]);
    }
    $privateKey = (string) ($_POST['private_key'] ?? '');
    if ($privateKey !== '') {
        return sqlbak_encrypt([
            'private_key' => $privateKey,
            'passphrase' => (string) ($_POST['passphrase'] ?? ''),
        ]);
    }
    if ($destinationId < 1) {
        return sqlbak_encrypt([]);
    }
    $statement = sqlbak_db()->prepare('SELECT secret_encrypted FROM storage_destinations WHERE id=?');
    $statement->execute([$destinationId]);
    return $statement->fetchColumn() ?: null;
}

function sqlbak_save_destination(PDO $pdo, int $destinationId): int
{
    $destination = sqlbak_storage_request();
    $secret = sqlbak_destination_secret($destinationId);
    $values = array_values($destination);
    if ($destinationId > 0) {
        $statement = $pdo->prepare('UPDATE storage_destinations SET name=?,display_order=?,type=?,host=?,port=?,username=?,base_path=?,options_json=?,secret_encrypted=? WHERE id=?');
        $statement->execute([...$values, $secret, $destinationId]);
        if (!sqlbak_destination_exists($pdo, $destinationId)) {
            throw new RuntimeException('Destination was not updated. Please retry.');
        }
        return $destinationId;
    }

    $statement = $pdo->prepare("INSERT INTO storage_destinations (name,display_order,type,enabled,host,port,username,base_path,options_json,secret_encrypted,health_status) VALUES (?,?,?,1,?,?,?,?,?,?,'unknown')");
    $statement->execute([...$values, $secret]);
    return (int) $pdo->lastInsertId();
}

function sqlbak_destination_exists(PDO $pdo, int $destinationId): bool
{
    $statement = $pdo->prepare('SELECT 1 FROM storage_destinations WHERE id=?');
    $statement->execute([$destinationId]);
    return (bool) $statement->fetchColumn();
}

function sqlbak_destination_by_id(PDO $pdo, int $destinationId): array
{
    $statement = $pdo->prepare('SELECT * FROM storage_destinations WHERE id=?');
    $statement->execute([$destinationId]);
    $destination = $statement->fetch();
    if (!$destination) {
        throw new RuntimeException('Destination not found. Please refresh and try again.');
    }
    return $destination;
}

function sqlbak_storage_size_label(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $size = (float) $bytes;
    $unit = 0;
    while ($size >= 1024 && $unit < count($units) - 1) {
        $size /= 1024;
        $unit++;
    }
    return number_format($size, $unit === 0 ? 0 : 1) . ' ' . $units[$unit];
}

function sqlbak_local_destination_space(array $destination): ?array
{
    if ($destination['type'] !== 'local' || !is_dir($destination['base_path'])) {
        return null;
    }
    $total = disk_total_space($destination['base_path']);
    $available = disk_free_space($destination['base_path']);
    if ($total === false || $available === false || $total < 1) {
        return null;
    }
    $used = $total - $available;
    return ['total' => (int) $total, 'available' => (int) $available, 'used' => (int) $used, 'used_percent' => (int) round(($used / $total) * 100)];
}

function sqlbak_toggle_destination(PDO $pdo, int $destinationId): bool
{
    $destination = sqlbak_destination_by_id($pdo, $destinationId);
    $enabled = !(bool) $destination['enabled'];
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE storage_destinations SET enabled=?,health_status=? WHERE id=?')
            ->execute([$enabled ? 1 : 0, $enabled ? 'unknown' : 'disabled', $destinationId]);
        sqlbak_audit($enabled ? 'storage_destination_resumed' : 'storage_destination_paused', 'storage_destination', (string) $destinationId);
        $pdo->commit();
        return $enabled;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function sqlbak_storage_error_message(Throwable $error): string
{
    if ($error instanceof PDOException && (string) $error->getCode() === '23000') {
        return 'Destination name is already in use.';
    }
    return $error->getMessage();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    sqlbak_verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $destinationId = (int) ($_POST['id'] ?? 0);
    $redirectUrl = 'storage.php';
    try {
        if ($action === 'save') {
            $destinationId = sqlbak_save_destination($pdo, $destinationId);
            sqlbak_flash('success', 'Destination saved and databases linked.');
            $redirectUrl = 'storage.php?edit=' . $destinationId . '&saved=1#storage-form';
        } elseif ($action === 'toggle') {
            $enabled = sqlbak_toggle_destination($pdo, $destinationId);
            sqlbak_flash('success', $enabled ? 'Destination resumed.' : 'Destination paused. It will stay off until resumed.');
        } elseif ($action === 'delete') {
            $usage = $pdo->prepare('SELECT (SELECT COUNT(*) FROM database_storage_destinations WHERE destination_id=?) AS links,(SELECT COUNT(*) FROM backup_copies WHERE destination_id=?) AS copies');
            $usage->execute([$destinationId, $destinationId]);
            $counts = $usage->fetch();
            if ((int) $counts['links'] > 0 || (int) $counts['copies'] > 0) {
                throw new RuntimeException('Destination is used by a database or copies. Unlink first, then delete.');
            }
            $pdo->prepare('DELETE FROM storage_destinations WHERE id=?')->execute([$destinationId]);
            sqlbak_flash('success', 'Destination deleted.');
        } elseif ($action === 'test') {
            $health = sqlbak_run_destination_health_check(sqlbak_destination_by_id($pdo, $destinationId));
            sqlbak_flash($health['status'] === 'success' ? 'success' : 'error', $health['message'] . ' Trace: ' . $health['trace_id']);
        } elseif ($action === 'test_all') {
            $failed = 0;
            foreach ($pdo->query('SELECT * FROM storage_destinations ORDER BY display_order,id')->fetchAll() as $destination) {
                $failed += sqlbak_run_destination_health_check($destination)['status'] === 'failed' ? 1 : 0;
            }
            sqlbak_flash($failed === 0 ? 'success' : 'error', $failed === 0 ? 'All destination checks are passing.' : 'Failed to test ' . $failed . ' destination(s).');
        }
    } catch (Throwable $error) {
        $message = $action === 'save' ? sqlbak_storage_error_message($error) : $error->getMessage();
        sqlbak_flash('error', $message);
        if ($action === 'save' && $destinationId > 0) {
            $redirectUrl = 'storage.php?edit=' . $destinationId . '#storage-form';
        }
    }
    header('Location: ' . $redirectUrl);
    exit;
}

$edit = null;
if (isset($_GET['edit'])) {
    $statement = $pdo->prepare('SELECT * FROM storage_destinations WHERE id=?');
    $statement->execute([(int) $_GET['edit']]);
    $edit = $statement->fetch() ?: null;
}
$options = $edit ? (json_decode($edit['options_json'] ?? '{}', true) ?: []) : [];
$editType = $edit['type'] ?? '';
$hostInputValue = $editType === 's3'
    ? ($edit['host'] ?? 'https://s3.amazonaws.com')
    : ($editType === 'dropbox' ? ($options['dropbox_api_host'] ?? 'https://api.dropboxapi.com/2') : ($edit['host'] ?? ''));
$destinations = $pdo->query("SELECT d.*,COUNT(DISTINCT link.database_id) AS database_count,COALESCE(copy_stats.backup_count,0) AS backup_count FROM storage_destinations d LEFT JOIN database_storage_destinations link ON link.destination_id=d.id LEFT JOIN (SELECT destination_id,COUNT(*) AS backup_count FROM backup_copies WHERE status='success' GROUP BY destination_id) copy_stats ON copy_stats.destination_id=d.id GROUP BY d.id ORDER BY d.display_order,d.id")->fetchAll();
foreach ($destinations as &$destination) {
    $destination['space'] = sqlbak_local_destination_space($destination);
}
unset($destination);
$nextOrder = $destinations === [] ? 1 : max(array_column($destinations, 'display_order')) + 1;
sqlbak_page_start($edit ? 'Edit destination' : 'Storage destinations', 'storage');
?>
<section class="destination-overview">
    <?php foreach ($destinations as $destination): ?>
        <article class="destination-card">
            <div class="destination-title">
                <span class="server-number">Server <?= (int) $destination['display_order'] ?></span>
                <span class="health-dot is-<?= sqlbak_h($destination['health_status']) ?>" title="<?= sqlbak_h($destination['last_test_message'] ?? 'No status') ?>"></span>
                <div><strong><?= sqlbak_h($destination['name']) ?></strong><small><?= strtoupper(sqlbak_h($destination['type'])) ?> · <?= (int) $destination['database_count'] ?> databases</small></div>
            </div>
            <div class="destination-health">
                <span class="status status-<?= $destination['enabled'] ? ($destination['health_status'] === 'success' ? 'success' : ($destination['health_status'] === 'failed' ? 'failed' : 'queued')) : 'disabled' ?>"><?= $destination['enabled'] ? sqlbak_h($destination['health_status']) : 'Disabled' ?></span>
                <b><?= $destination['last_latency_ms'] !== null ? (int) $destination['last_latency_ms'] . ' ms' : '-' ?></b>
                <small dir="ltr"><?= sqlbak_h($destination['last_tested_at'] ?? 'Never') ?></small>
            </div>
            <div class="storage-metrics">
                <div><small>Databases</small><strong><i class="fa fa-database"></i> <?= (int) $destination['database_count'] ?></strong></div>
                <div><small>Backup copies</small><strong><i class="fa fa-history"></i> <?= (int) $destination['backup_count'] ?></strong><span>Successful only</span></div>
                <?php if ($destination['space']): $space = $destination['space']; ?>
                    <div class="storage-space">
                        <small>Used: <?= sqlbak_storage_size_label($space['used']) ?> · <?= $space['used_percent'] ?>%</small>
                        <progress value="<?= $space['used_percent'] ?>" max="100"></progress>
                        <strong dir="ltr">Free <?= sqlbak_storage_size_label($space['available']) ?> / <?= sqlbak_storage_size_label($space['total']) ?></strong>
                    </div>
                <?php else: ?>
                    <div class="storage-space is-remote">
                        <small>Remote space</small>
                        <strong><?= $destination['type'] === 'local' ? 'Could not inspect local space.' : 'Unknown for ' . strtoupper(sqlbak_h($destination['type'])) ?></strong>
                        <span>This view only shows size for local paths.</span>
                    </div>
                <?php endif; ?>
            </div>
            <?php if ($destination['last_test_message']): ?><p class="<?= $destination['health_status'] === 'failed' ? 'inline-error' : 'muted-line' ?>"><?= sqlbak_h($destination['last_error_code'] ? $destination['last_error_code'] . ' · ' . $destination['last_test_message'] : $destination['last_test_message']) ?></p><?php endif; ?>
            <div class="table-actions">
                <form method="post"><input type="hidden" name="csrf" value="<?= sqlbak_h(sqlbak_csrf_token()) ?>"><input type="hidden" name="action" value="test"><input type="hidden" name="id" value="<?= (int) $destination['id'] ?>"><button class="icon-button" type="submit" title="Run health check"><i class="fa fa-refresh"></i></button></form>
                <form method="post"><input type="hidden" name="csrf" value="<?= sqlbak_h(sqlbak_csrf_token()) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $destination['id'] ?>"><button class="icon-button" type="submit" title="<?= $destination['enabled'] ? 'Pause' : 'Resume' ?>"><i class="fa fa-<?= $destination['enabled'] ? 'pause' : 'play' ?>"></i></button></form>
                <a class="icon-button" href="?edit=<?= (int) $destination['id'] ?>" title="Edit"><i class="fa fa-pencil"></i></a>
                <form method="post" onsubmit="return confirm('Delete destination?')"><input type="hidden" name="csrf" value="<?= sqlbak_h(sqlbak_csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $destination['id'] ?>"><button class="icon-button danger-icon" type="submit" title="Delete"><i class="fa fa-trash"></i></button></form>
            </div>
        </article>
    <?php endforeach; ?>
</section>
<section class="grid-two">
    <article class="panel" id="storage-form">
        <div class="panel-head"><div><h2><?= $edit ? 'Edit destination' : 'Create destination' ?></h2><p>Configure destination connectivity, health checks, upload/download, and fail/cleanup checks.</p></div><form method="post"><input type="hidden" name="csrf" value="<?= sqlbak_h(sqlbak_csrf_token()) ?>"><input type="hidden" name="action" value="test_all"><button class="button secondary"><i class="fa fa-refresh"></i> Run all checks</button></form></div>
        <form method="post" data-storage-form>
            <input type="hidden" name="csrf" value="<?= sqlbak_h(sqlbak_csrf_token()) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
            <div class="form-grid">
                <div class="field"><label>Name</label><input name="name" required value="<?= sqlbak_h($edit['name'] ?? '') ?>"></div>
                <div class="field"><label>Display order</label><input name="display_order" type="number" min="1" value="<?= (int) ($edit['display_order'] ?? $nextOrder) ?>"></div>
                <div class="field">
                    <label>Type</label>
                    <select name="type" data-destination-type>
                        <option value="local" <?= ($edit['type'] ?? '') === 'local' ? 'selected' : '' ?>>Local</option>
                        <option value="ftp" <?= ($edit['type'] ?? '') === 'ftp' ? 'selected' : '' ?>>FTP / FTPS</option>
                        <option value="sftp" <?= ($edit['type'] ?? '') === 'sftp' ? 'selected' : '' ?>>SFTP</option>
                        <option value="s3" <?= ($edit['type'] ?? '') === 's3' ? 'selected' : '' ?>>S3</option>
                        <option value="dropbox" <?= ($edit['type'] ?? '') === 'dropbox' ? 'selected' : '' ?>>Dropbox</option>
                    </select>
                </div>
                <div class="field remote-field"><label>Host</label><input name="host" value="<?= sqlbak_h($hostInputValue) ?>"></div>
                <div class="field remote-field"><label>Port</label><input name="port" type="number" min="1" max="65535" value="<?= (int) ($edit['port'] ?? 22) ?>"></div>
                <div class="field remote-field"><label>Username (access key for S3)</label><input name="username" value="<?= sqlbak_h($edit['username'] ?? '') ?>"></div>
                <div class="field remote-field"><label>Secret / Password token</label><input name="password" type="password"></div>
                <div class="field full"><label>Base path / Bucket prefix</label><input name="base_path" <?= ($edit['type'] ?? 'local') === 's3' ? '' : 'required' ?> value="<?= sqlbak_h($edit['base_path'] ?? (sqlbak_backup_root())) ?>"></div>

                <div class="field ftp-field"><label>Passive mode</label><label><input type="checkbox" name="passive" <?= ($options['passive'] ?? true) ? 'checked' : '' ?>> Passive</label></div>
                <div class="field ftp-field"><label>FTPS / TLS</label><label><input type="checkbox" name="tls" <?= ($options['tls'] ?? false) ? 'checked' : '' ?>> Use TLS</label></div>

                <div class="field sftp-field"><label>SFTP auth method</label>
                    <select name="auth_method"><option value="password" <?= ($options['auth_method'] ?? '') === 'password' ? 'selected' : '' ?>>Password</option><option value="key" <?= ($options['auth_method'] ?? '') === 'key' ? 'selected' : '' ?>>Private key</option></select>
                </div>
                <div class="field sftp-field"><label>Host fingerprint (SHA-256)</label><input name="host_fingerprint" value="<?= sqlbak_h($options['host_fingerprint'] ?? '') ?>"></div>
                <div class="field sftp-field full"><label>Private key</label><textarea name="private_key" placeholder="Private key content (for key-based auth)"></textarea><input name="passphrase" type="password" placeholder="Private-key passphrase"></div>

                <div class="field s3-field"><label>S3 host / endpoint</label><input name="s3_host" value="<?= sqlbak_h($edit['host'] ?? 'https://s3.amazonaws.com') ?>" placeholder="https://s3.amazonaws.com"></div>
                <div class="field s3-field"><label>S3 bucket</label><input name="s3_bucket" value="<?= sqlbak_h($options['s3_bucket'] ?? '') ?>" placeholder="my-bucket"></div>
                <div class="field s3-field"><label>S3 region</label><input name="s3_region" value="<?= sqlbak_h($options['s3_region'] ?? 'us-east-1') ?>"></div>
                <div class="field s3-field"><label>Path-style addressing</label><label><input type="checkbox" name="s3_path_style" <?= ($options['s3_path_style'] ?? false) ? 'checked' : '' ?>> Use path style</label></div>
                <div class="field s3-field"><label>Session token (optional)</label><input name="s3_session_token" value="<?= sqlbak_h($options['s3_session_token'] ?? '') ?>" placeholder="Optional temporary token"></div>

                <div class="field dropbox-field"><label>Dropbox API host</label><input name="dropbox_api_host" value="<?= sqlbak_h($options['dropbox_api_host'] ?? 'https://api.dropboxapi.com/2') ?>"></div>
                <div class="field dropbox-field"><label>Dropbox content host</label><input name="dropbox_content_host" value="<?= sqlbak_h($options['dropbox_content_host'] ?? 'https://content.dropboxapi.com/2') ?>"></div>
            </div>
            <div class="form-actions"><button class="button" type="submit" data-storage-submit><i class="fa fa-save"></i> <?= $edit ? 'Update destination' : 'Create destination' ?></button><?php if ($edit): ?><a class="button secondary" href="storage.php">Cancel</a><?php endif; ?></div>
        </form>
    </article>
    <article class="panel">
        <div class="panel-head"><div><h2>Legend</h2><p>Legend for destination statuses.</p></div></div>
        <div class="legend-list"><span><i class="health-dot is-success"></i> Healthy</span><span><i class="health-dot is-failed"></i> Failed</span><span><i class="health-dot is-unknown"></i> Not checked yet</span><span><i class="health-dot is-disabled"></i> Disabled</span></div>
    </article>
</section>
<script src="assets/js/storage.js?v=2026071202"></script>
<?php sqlbak_page_end();
