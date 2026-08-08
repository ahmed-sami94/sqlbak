<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

if (sqlbak_is_installed() && ($_GET['force'] ?? '') !== '1') {
    header('Location: login.php');
    exit;
}

function sqlbak_install_read_file(string $path): string
{
    if (!is_file($path) || is_dir($path)) {
        throw new RuntimeException('File not found: ' . $path);
    }
    $content = file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException('Could not read SQL file: ' . $path);
    }
    return $content;
}

function sqlbak_split_sql(string $sql): array
{
    $sql = preg_replace('/\\/\\*.*?\\*\\//s', '', $sql);
    $sql = preg_replace('/^--.*$/m', '', $sql);

    $statements = [];
    $buffer = '';
    $inSingle = false;
    $inDouble = false;
    $inBacktick = false;
    $inLineComment = false;
    $length = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $sql[$i + 1] ?? '';

        if ($inLineComment) {
            if ($char === "\n") {
                $inLineComment = false;
                $buffer .= $char;
            }
            continue;
        }

        if (!$inSingle && !$inDouble && !$inBacktick) {
            if ($char === '-' && $next === '-' && (($sql[$i - 1] ?? "\n") === "\n" || $i === 0)) {
                $inLineComment = true;
                continue;
            }
            if ($char === '\'' && !$inDouble && !$inBacktick) {
                $inSingle = true;
                $buffer .= $char;
                continue;
            }
            if ($char === '"' && !$inSingle && !$inBacktick) {
                $inDouble = true;
                $buffer .= $char;
                continue;
            }
            if ($char === '`' && !$inSingle && !$inDouble) {
                $inBacktick = true;
                $buffer .= $char;
                continue;
            }
            if ($char === ';') {
                $statement = trim($buffer);
                if ($statement !== '') {
                    $statements[] = $statement . ';';
                }
                $buffer = '';
                continue;
            }
        } elseif ($inSingle) {
            if ($char === "'" && $sql[$i - 1] !== '\\') {
                $inSingle = false;
            }
        } elseif ($inDouble) {
            if ($char === '"' && $sql[$i - 1] !== '\\') {
                $inDouble = false;
            }
        } elseif ($inBacktick) {
            if ($char === '`' && $sql[$i - 1] !== '\\') {
                $inBacktick = false;
            }
        }

        $buffer .= $char;
    }

    $tail = trim($buffer);
    if ($tail !== '') {
        $statements[] = $tail;
    }
    return $statements;
}

function sqlbak_install_apply_sql(PDO $pdo, string $path): void
{
    $rawSql = sqlbak_install_read_file($path);
    foreach (sqlbak_split_sql($rawSql) as $statement) {
        if (str_starts_with(strtoupper($statement), 'BEGIN') || str_starts_with(strtoupper($statement), 'START TRANSACTION')) {
            $pdo->beginTransaction();
            continue;
        }
        if (str_starts_with(strtoupper($statement), 'COMMIT') || str_starts_with(strtoupper($statement), 'ROLLBACK')) {
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
            continue;
        }
        $pdo->exec($statement);
    }
    if ($pdo->inTransaction()) {
        $pdo->commit();
    }
}

function sqlbak_install_write_env(array $values): void
{
    $path = SQLBAK_ROOT . '/.env';
    $payload = '';
    ksort($values);
    foreach ($values as $key => $value) {
        $payload .= $key . '=' . $value . PHP_EOL;
    }
    if (file_put_contents($path, $payload, LOCK_EX) === false) {
        throw new RuntimeException('Could not write .env file.');
    }
}

function sqlbak_install_generate_key(): string
{
    return sodium_crypto_secretbox_keygen()
        ? base64_encode(sodium_crypto_secretbox_keygen())
        : base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
}

function sqlbak_install_apply_migrations(PDO $pdo): void
{
    $paths = glob(SQLBAK_ROOT . '/migrations/*.sql') ?: [];
    sort($paths, SORT_STRING);
    foreach ($paths as $path) {
        sqlbak_install_apply_sql($pdo, $path);
    }
}

function sqlbak_install_sync_admin_user(PDO $pdo, string $username, string $email, string $password): void
{
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $pdo->prepare(
        'INSERT INTO users (username, password, email, role, status) VALUES (?, ?, ?, \'admin\', \'active\') '
        . 'ON DUPLICATE KEY UPDATE password=VALUES(password), email=VALUES(email), role=\'admin\', status=\'active\''
    )->execute([$username, $passwordHash, $email]);
}

$defaultBackupRoot = rtrim(dirname(__DIR__) . '/backups', '/');
$state = [
    'db_host' => 'localhost',
    'db_port' => '3306',
    'db_name' => 'backup_app',
    'db_admin_user' => 'root',
    'db_admin_pass' => '',
    'app_db_user' => 'backup',
    'app_db_pass' => '',
    'admin_username' => 'admin',
    'admin_email' => 'admin@localhost',
    'admin_password' => '',
    'admin_password_confirmation' => '',
    'backup_root' => $defaultBackupRoot,
];
$success = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $state = [
        'db_host' => trim((string) ($_POST['db_host'] ?? 'localhost')),
        'db_port' => (string) max(1, (int) ($_POST['db_port'] ?? 3306)),
        'db_name' => trim((string) ($_POST['db_name'] ?? 'backup_app')),
        'db_admin_user' => trim((string) ($_POST['db_admin_user'] ?? 'root')),
        'db_admin_pass' => (string) ($_POST['db_admin_pass'] ?? ''),
        'app_db_user' => trim((string) ($_POST['app_db_user'] ?? 'backup')),
        'app_db_pass' => (string) ($_POST['app_db_pass'] ?? ''),
        'admin_username' => trim((string) ($_POST['admin_username'] ?? 'admin')),
        'admin_email' => trim((string) ($_POST['admin_email'] ?? 'admin@localhost')),
        'admin_password' => (string) ($_POST['admin_password'] ?? ''),
        'admin_password_confirmation' => (string) ($_POST['admin_password_confirmation'] ?? ''),
        'backup_root' => trim((string) ($_POST['backup_root'] ?? $defaultBackupRoot)),
    ];

    try {
        if ($state['db_name'] === '' || $state['db_admin_user'] === '' || $state['app_db_user'] === '' || $state['admin_username'] === '') {
            throw new InvalidArgumentException('Database and credential details are required.');
        }
        if ($state['admin_password'] === '' || $state['admin_password'] !== $state['admin_password_confirmation']) {
            throw new InvalidArgumentException('Admin credentials do not match.');
        }
        if (strlen($state['admin_password']) < 8) {
            throw new InvalidArgumentException('Admin password must be at least 8 characters.');
        }

        $adminPort = (int) $state['db_port'];
        if ($adminPort < 1 || $adminPort > 65535) {
            throw new InvalidArgumentException('Invalid MySQL port.');
        }

        $adminPdo = new PDO(
            'mysql:host=' . $state['db_host'] . ';port=' . $adminPort . ';charset=utf8mb4',
            $state['db_admin_user'],
            $state['db_admin_pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );

        $quotedDb = '`' . str_replace('`', '``', $state['db_name']) . '`';
        $adminPdo->exec('CREATE DATABASE IF NOT EXISTS ' . $quotedDb . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $adminPdo->exec('USE ' . $quotedDb);
        $adminPdo->exec(
            'CREATE USER IF NOT EXISTS ' . $adminPdo->quote($state['app_db_user']) . '@\'%\'' . ' IDENTIFIED BY ' . $adminPdo->quote($state['app_db_pass'])
        );
        $adminPdo->exec('GRANT ALL PRIVILEGES ON ' . $quotedDb . '.* TO ' . $adminPdo->quote($state['app_db_user']) . '@\'%\'');
        $adminPdo->exec('FLUSH PRIVILEGES');

        $appPdo = new PDO(
            'mysql:host=' . $state['db_host'] . ';port=' . $adminPort . ';dbname=' . $state['db_name'] . ';charset=utf8mb4',
            $state['app_db_user'],
            $state['app_db_pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        sqlbak_install_apply_sql($appPdo, SQLBAK_ROOT . '/sql/backup_app.sql');
        sqlbak_install_apply_migrations($appPdo);
        sqlbak_install_sync_admin_user($appPdo, $state['admin_username'], $state['admin_email'], $state['admin_password']);

        $backupRoot = rtrim($state['backup_root'], "/\\");
        if (!is_dir($backupRoot)) {
            if (!mkdir($backupRoot, 0770, true) && !is_dir($backupRoot)) {
                throw new RuntimeException('Cannot create backup root directory.');
            }
        }

        $sqlbakTz = (string) (sqlbak_env('TZ') ?: 'Africa/Cairo');
        sqlbak_install_write_env([
            'TZ' => $sqlbakTz,
            'SQLBAK_DB_HOST' => $state['db_host'],
            'SQLBAK_DB_PORT' => (string) $adminPort,
            'SQLBAK_DB_NAME' => $state['db_name'],
            'SQLBAK_DB_USER' => $state['app_db_user'],
            'SQLBAK_DB_PASS' => $state['app_db_pass'],
            'SQLBAK_APP_KEY' => sqlbak_install_generate_key(),
            'SQLBAK_BACKUP_ROOT' => $backupRoot,
        ]);
        $success = 'SQLBak installed. Open login with admin credentials.';
    } catch (Throwable $err) {
        $error = $err->getMessage();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SQLBak Install</title>
    <style>
        body {font-family: Arial,Helvetica,sans-serif; background:#f4f0fa; color:#29243a; margin:0; padding:32px}
        .wrap {max-width:980px; margin:0 auto; background:#fff; padding:26px; border-radius:14px; box-shadow:0 20px 50px rgba(43,0,96,.12)}
        .grid {display:grid; grid-template-columns:1fr 1fr; gap:14px}
        .grid .full {grid-column:1 / span 2}
        h1 {margin:0 0 6px}
        label {display:block; margin-bottom:7px; font-size:13px}
        input,button {width:100%; border-radius:8px; border:1px solid #d3c6e9; padding:10px; font-size:14px}
        .actions {display:flex; gap:8px; margin-top:8px}
        button {background:#2b0060; color:#fff; border:0; padding:11px 14px; cursor:pointer; border-radius:9px}
        .muted {color:#615874; font-size:13px}
        .notice {padding:10px; border-radius:8px; margin:10px 0}
        .ok {background:#e9f7ee; color:#1e6a38}
        .err {background:#fde8e8; color:#902b2b}
        .help {margin-top:16px; border-left:4px solid #2b0060; background:#f8f6fc; padding:10px 14px; border-radius:10px}
        .help code {display:block; white-space:pre-wrap}
    </style>
</head>
<body>
<div class="wrap">
    <h1>SQLBak Installer</h1>
    <p class="muted"><strong>Run this once:</strong> install MySQL, run this installer, then open <code>login.php</code>.</p>
    <?php if ($success): ?>
        <div class="notice ok"><?= sqlbak_h($success) ?></div>
        <div class="actions"><a href="login.php"><button type="button">Go to login</button></a></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="notice err"><?= sqlbak_h($error) ?></div>
    <?php endif; ?>
    <form method="post">
        <h3>MySQL / App database</h3>
        <div class="grid">
            <div><label>MySQL host</label><input name="db_host" required value="<?= sqlbak_h($state['db_host']) ?>"></div>
            <div><label>MySQL port</label><input name="db_port" type="number" min="1" max="65535" value="<?= sqlbak_h($state['db_port']) ?>"></div>
            <div><label>Database name</label><input name="db_name" required value="<?= sqlbak_h($state['db_name']) ?>"></div>
            <div><label>MySQL admin username</label><input name="db_admin_user" required value="<?= sqlbak_h($state['db_admin_user']) ?>"></div>
            <div class="full"><label>MySQL admin password</label><input name="db_admin_pass" type="password" autocomplete="new-password" placeholder="if exists"></div>
            <div><label>App DB username</label><input name="app_db_user" required value="<?= sqlbak_h($state['app_db_user']) ?>"></div>
            <div><label>App DB password</label><input name="app_db_pass" type="password" autocomplete="new-password" placeholder="strong password"></div>
            <div class="full"><label>Backup root directory</label><input name="backup_root" required value="<?= sqlbak_h($state['backup_root']) ?>"></div>
        </div>
        <h3>Initial admin user</h3>
        <div class="grid">
            <div><label>Admin username</label><input name="admin_username" required value="<?= sqlbak_h($state['admin_username']) ?>"></div>
            <div><label>Admin email</label><input type="email" name="admin_email" value="<?= sqlbak_h($state['admin_email']) ?>"></div>
            <div><label>Admin password</label><input name="admin_password" type="password" required autocomplete="new-password"></div>
            <div><label>Confirm password</label><input name="admin_password_confirmation" type="password" required autocomplete="new-password"></div>
        </div>
        <div class="actions"><button type="submit">Install SQLBak</button></div>
    </form>
    <div class="help">
        <strong>Quick install checklist</strong>
        <ol>
            <li>Install MySQL and make sure the service is running.</li>
            <li>Use an admin MySQL account (eg. <code>root</code>) to connect and grant privileges.</li>
            <li>Submit credentials on this page. SQLBak will create database, app user, schema, default roles, and your first admin user.</li>
            <li>Open <strong><code>login.php</code></strong> with the created admin username/password.</li>
        </ol>
        <strong>Minimal recommended DB commands (optional)</strong>
        <code>CREATE DATABASE backup_app CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;</code>
        <code>CREATE USER 'backup'@'%' IDENTIFIED BY 'strong_password';</code>
        <code>GRANT ALL PRIVILEGES ON backup_app.* TO 'backup'@'%';</code>
    </div>
</div>
</body>
</html>
