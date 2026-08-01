<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';
sqlbak_start_session();
if (!empty($_SESSION['sqlbak_user'])) { header('Location: index.php'); exit; }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $stmt = sqlbak_db()->prepare('SELECT id, username, password, email FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    $legacyHash = hash('sha256', $password);
    $valid = $user && (password_verify($password, $user['password']) || hash_equals($user['password'], $legacyHash));
    if ($valid) {
        if (!password_get_info($user['password'])['algo']) {
            sqlbak_db()->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }
        session_regenerate_id(true);
        $_SESSION['sqlbak_user'] = ['id' => (int) $user['id'], 'username' => $user['username'], 'role' => 'admin'];
        header('Location: index.php'); exit;
    }
    $error = 'بيانات الدخول غير صحيحة.';
}
?>
<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>دخول SQLBak</title><link rel="stylesheet" href="styles/app.css"><style>.login{min-height:100vh;display:grid;place-items:center;padding:20px}.login-card{width:min(880px,100%);display:grid;grid-template-columns:1fr 1fr;border-radius:12px;overflow:hidden;background:#fff;box-shadow:0 20px 55px rgba(43,0,96,.2)}.login-brand{padding:42px;background:#2b0060;color:#fff}.login-brand h1{font-size:34px;margin:18px 0 8px}.login-brand p{color:rgba(255,255,255,.72)}.login-form{padding:38px}.login-form h2{margin-top:0;color:#2b0060}@media(max-width:700px){.login-card{grid-template-columns:1fr}.login-brand{padding:26px}}</style></head><body><main class="login"><section class="login-card"><div class="login-brand"><span class="brand-mark"><i class="fa fa-shield"></i></span><h1>SQLBak</h1><p>إدارة النسخ الاحتياطية لقواعد البيانات وحمايتها في كل وجهات التخزين.</p></div><form class="login-form" method="post"><h2>تسجيل الدخول</h2><p class="muted">استخدم حساب إدارة النسخ الاحتياطية.</p><?php if ($error): ?><div class="notice notice-error"><?= sqlbak_h($error) ?></div><?php endif; ?><div class="field"><label>اسم المستخدم</label><input name="username" required autofocus></div><div class="field" style="margin-top:12px"><label>كلمة المرور</label><input type="password" name="password" required></div><button class="button" style="margin-top:18px;width:100%"><i class="fa fa-sign-in"></i> دخول</button></form></section></main></body></html>
