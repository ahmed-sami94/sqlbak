<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/layout.php';

sqlbak_require_admin();
$pdo = sqlbak_db();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    sqlbak_verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'add') {
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $email = trim((string) ($_POST['email'] ?? ''));
            if ($username === '' || $password === '') {
                throw new InvalidArgumentException('اسم المستخدم وكلمة المرور مطلوبان.');
            }
            $pdo->prepare('INSERT INTO users (username,password,email) VALUES (?,?,?)')
                ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $email]);
            sqlbak_flash('success', 'تمت إضافة المستخدم.');
        } elseif ($action === 'delete') {
            $userId = (int) ($_POST['id'] ?? 0);
            if ($userId === (int) ($_SESSION['sqlbak_user']['id'] ?? 0)) {
                throw new RuntimeException('لا يمكن حذف الحساب المسجل حالياً.');
            }
            $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
            sqlbak_flash('success', 'تم حذف المستخدم.');
        } else {
            throw new InvalidArgumentException('إجراء المستخدم غير صالح.');
        }
    } catch (Throwable $error) {
        sqlbak_flash('error', $error->getMessage());
    }
    header('Location: users.php');
    exit;
}

$users = $pdo->query('SELECT id,username,email FROM users ORDER BY username')->fetchAll();
sqlbak_page_start('المستخدمون', 'users');
?>
<section class="grid-two">
    <article class="panel">
        <div class="panel-head"><div><h2>إضافة مستخدم</h2><p>حسابات SQLBak تملك صلاحية الإدارة الحالية.</p></div></div>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= sqlbak_h(sqlbak_csrf_token()) ?>">
            <input type="hidden" name="action" value="add">
            <div class="form-grid">
                <div class="field"><label>اسم المستخدم</label><input name="username" required></div>
                <div class="field"><label>البريد</label><input name="email" type="email"></div>
                <div class="field full"><label>كلمة المرور</label><input name="password" type="password" required></div>
            </div>
            <div class="form-actions"><button class="button" type="submit"><i class="fa fa-save"></i> إضافة وحفظ</button></div>
        </form>
    </article>
    <article class="panel">
        <div class="panel-head"><div><h2>الحسابات الحالية</h2><p>لا يمكن حذف الحساب المسجل حالياً.</p></div></div>
        <div class="table-wrap"><table><thead><tr><th>المستخدم</th><th>البريد</th><th></th></tr></thead><tbody>
        <?php foreach ($users as $user): ?>
            <tr><td><?= sqlbak_h($user['username']) ?></td><td><?= sqlbak_h($user['email']) ?></td><td>
            <?php if ((int) $user['id'] !== (int) ($_SESSION['sqlbak_user']['id'] ?? 0)): ?>
                <form method="post" onsubmit="return confirm('حذف المستخدم؟')"><input type="hidden" name="csrf" value="<?= sqlbak_h(sqlbak_csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $user['id'] ?>"><button class="icon-button danger-icon" type="submit" title="حذف"><i class="fa fa-trash"></i></button></form>
            <?php endif; ?>
            </td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </article>
</section>
<?php sqlbak_page_end();
