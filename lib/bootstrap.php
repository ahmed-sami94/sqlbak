<?php
declare(strict_types=1);

const SQLBAK_ROOT = __DIR__ . '/..';
date_default_timezone_set((string) (getenv('TZ') ?: 'Africa/Cairo'));

function sqlbak_env(string $name, ?string $default = null): ?string
{
    $value = getenv($name);
    return $value === false || $value === '' ? $default : $value;
}

function sqlbak_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = sqlbak_env('SQLBAK_DB_HOST', 'mariadb');
    $database = sqlbak_env('SQLBAK_DB_NAME', 'backup_app');
    $user = sqlbak_env('SQLBAK_DB_USER', 'backup');
    $password = sqlbak_env('SQLBAK_DB_PASS', 'backup');
    $pdo = new PDO("mysql:host={$host};dbname={$database};charset=utf8mb4", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function sqlbak_backup_root(): string
{
    return rtrim((string) sqlbak_env('SQLBAK_BACKUP_ROOT', '/var/backups/sqlbak'), '/');
}

function sqlbak_secret_key(): string
{
    $encoded = sqlbak_env('SQLBAK_APP_KEY');
    if ($encoded === null) {
        $keyFile = sqlbak_env('SQLBAK_APP_KEY_FILE', sqlbak_backup_root() . '/.sqlbak_key');
        if ($keyFile !== null && is_readable($keyFile)) {
            $encoded = trim((string) file_get_contents($keyFile));
        }
    }
    $key = $encoded === null ? false : base64_decode($encoded, true);
    if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        throw new RuntimeException('SQLBAK_APP_KEY must be a base64-encoded 32-byte key.');
    }
    return $key;
}

function sqlbak_encrypt(array $secret): string
{
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $ciphertext = sodium_crypto_secretbox(json_encode($secret, JSON_THROW_ON_ERROR), $nonce, sqlbak_secret_key());
    return base64_encode($nonce . $ciphertext);
}

function sqlbak_decrypt(?string $encoded): array
{
    if ($encoded === null || $encoded === '') {
        return [];
    }
    $payload = base64_decode($encoded, true);
    if ($payload === false || strlen($payload) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        throw new RuntimeException('Stored secret is invalid.');
    }
    $nonce = substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $ciphertext = substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain = sodium_crypto_secretbox_open($ciphertext, $nonce, sqlbak_secret_key());
    if ($plain === false) {
        throw new RuntimeException('Stored secret cannot be decrypted.');
    }
    return json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
}

function sqlbak_start_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')]);
        session_start();
    }
}

function sqlbak_require_login(): void
{
    sqlbak_start_session();
    if (empty($_SESSION['sqlbak_user'])) {
        header('Location: login.php');
        exit;
    }
}

function sqlbak_require_admin(): void
{
    sqlbak_require_login();
    if (($_SESSION['sqlbak_user']['role'] ?? 'admin') !== 'admin') {
        http_response_code(403);
        exit('ليس لديك صلاحية لهذا الإجراء.');
    }
}

function sqlbak_csrf_token(): string
{
    sqlbak_start_session();
    if (empty($_SESSION['sqlbak_csrf'])) {
        $_SESSION['sqlbak_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['sqlbak_csrf'];
}

function sqlbak_verify_csrf(): void
{
    sqlbak_start_session();
    $token = $_POST['csrf'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['sqlbak_csrf'] ?? '', $token)) {
        http_response_code(419);
        exit('انتهت صلاحية الطلب. أعد المحاولة.');
    }
}

function sqlbak_flash(string $type, string $message): void
{
    sqlbak_start_session();
    $_SESSION['sqlbak_flash'] = ['type' => $type, 'message' => $message];
}

function sqlbak_pull_flash(): ?array
{
    sqlbak_start_session();
    $flash = $_SESSION['sqlbak_flash'] ?? null;
    unset($_SESSION['sqlbak_flash']);
    return $flash;
}

function sqlbak_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function sqlbak_setting(string $key, int $fallback): int
{
    $stmt = sqlbak_db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $fallback : max(1, (int) $value);
}

function sqlbak_audit(string $event, string $entityType, ?string $entityId = null, array $details = []): void
{
    $userId = $_SESSION['sqlbak_user']['id'] ?? null;
    $stmt = sqlbak_db()->prepare('INSERT INTO audit_log (user_id, event_name, entity_type, entity_id, details_json) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$userId, $event, $entityType, $entityId, $details === [] ? null : json_encode($details, JSON_THROW_ON_ERROR)]);
}
