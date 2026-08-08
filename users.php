<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/layout.php';

sqlbak_require_admin();
$pdo = sqlbak_db();

function sqlbak_allowed_user_roles(): array
{
    return ['admin', 'operator', 'viewer'];
}

function sqlbak_allowed_user_statuses(): array
{
    return ['active', 'suspended'];
}

function sqlbak_find_user(PDO $pdo, int $userId): ?array
{
    $statement = $pdo->prepare('SELECT id, username, email, role, status FROM users WHERE id = ? LIMIT 1');
    $statement->execute([$userId]);
    $user = $statement->fetch();
    return $user === false ? null : $user;
}

function sqlbak_validate_user_payload(string $username, string $role, string $status, ?string $email = null): void
{
    if ($username === '') {
        throw new InvalidArgumentException('اسم المستخدم مطلوب.');
    }
    if (!in_array($role, sqlbak_allowed_user_roles(), true)) {
        throw new InvalidArgumentException('نوع الصلاحية غير صحيح.');
    }
    if (!in_array($status, sqlbak_allowed_user_statuses(), true)) {
        throw new InvalidArgumentException('حالة المستخدم غير صحيحة.');
    }
    if ($email !== null && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('صيغة البريد الإلكتروني غير صالحة.');
    }
}

function sqlbak_user_input(string $field): string
{
    return trim((string) ($_POST[$field] ?? ''));
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    sqlbak_verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $currentUserId = (int) ($_SESSION['sqlbak_user']['id'] ?? 0);

    try {
        if ($action === 'add') {
            $username = sqlbak_user_input('username');
            $email = sqlbak_user_input('email');
            $password = (string) ($_POST['password'] ?? '');
            $role = sqlbak_user_input('role');
            $status = sqlbak_user_input('status');
            sqlbak_validate_user_payload($username, $role, $status, $email);
            if ($password === '') {
                throw new InvalidArgumentException('كلمة المرور مطلوبة للمستخدم الجديد.');
            }

            $pdo->prepare('INSERT INTO users (username,password,email,role,status) VALUES (?,?,?,?,?)')
                ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $email === '' ? null : $email, $role, $status]);

            sqlbak_flash('success', 'تم إضافة المستخدم بنجاح.');
        } elseif ($action === 'update') {
            $userId = (int) ($_POST['id'] ?? 0);
            $target = sqlbak_find_user($pdo, $userId);
            if (!$target) {
                throw new RuntimeException('المستخدم غير موجود.');
            }

            $username = sqlbak_user_input('username');
            $email = sqlbak_user_input('email');
            $role = sqlbak_user_input('role');
            $status = sqlbak_user_input('status');
            $password = (string) ($_POST['password'] ?? '');
            sqlbak_validate_user_payload($username, $role, $status, $email);

            $isSelf = $userId === $currentUserId;
            if ($isSelf && $role !== 'admin') {
                throw new RuntimeException('لا يمكن خفض صلاحيتك أثناء تسجيل الدخول من هذا الحساب.');
            }
            if ($isSelf && $status !== 'active') {
                throw new RuntimeException('لا يمكن إيقاف حسابك بنفسك.');
            }

            if ($password === '') {
                $statement = $pdo->prepare('UPDATE users SET username=?, email=?, role=?, status=? WHERE id=?');
                $statement->execute([$username, $email === '' ? null : $email, $role, $status, $userId]);
            } else {
                $statement = $pdo->prepare('UPDATE users SET username=?, email=?, role=?, status=?, password=? WHERE id=?');
                $statement->execute([$username, $email === '' ? null : $email, $role, $status, password_hash($password, PASSWORD_DEFAULT), $userId]);
            }

            sqlbak_flash('success', 'تم حفظ بيانات المستخدم بنجاح.');
            if ($isSelf) {
                $_SESSION['sqlbak_user']['role'] = $role;
            }
        } elseif ($action === 'delete') {
            $userId = (int) ($_POST['id'] ?? 0);
            if ($userId === $currentUserId) {
                throw new RuntimeException('لا يمكن حذف حسابك أنت.');
            }
            if ($userId === 0) {
                throw new InvalidArgumentException('المستخدم غير صحيح.');
            }
            $target = sqlbak_find_user($pdo, $userId);
            if (!$target) {
                throw new RuntimeException('المستخدم غير موجود.');
            }
            $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
            sqlbak_flash('success', 'تم حذف المستخدم بنجاح.');
        } else {
            throw new InvalidArgumentException('الإجراء غير صالح.');
        }
    } catch (Throwable $error) {
        sqlbak_flash('error', $error->getMessage());
    }

    header('Location: users.php');
    exit;
}

$editUser = null;
if (isset($_GET['edit'])) {
    $editUser = sqlbak_find_user($pdo, (int) $_GET['edit']);
}

$users = $pdo->query('SELECT id, username, email, role, status, COALESCE(created_at, NOW()) AS created_at FROM users ORDER BY role, username')->fetchAll();

sqlbak_page_start('المستخدمين', 'users');
?>
<section class="grid-two">
    <article class="panel">
        <div class="panel-head"><div><h2><?= $editUser ? 'تعديل المستخدم' : 'إضافة مستخدم جديد' ?></h2><p>قم بإدارة صلاحية المستخدمين والوصول.</p></div></div>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= sqlbak_h(sqlbak_csrf_token()) ?>">
            <input type="hidden" name="action" value="<?= $editUser ? 'update' : 'add' ?>">
            <input type="hidden" name="id" value="<?= (int) ($editUser['id'] ?? 0) ?>">
            <div class="form-grid">
                <div class="field"><label>اسم المستخدم</label><input name="username" required value="<?= sqlbak_h($editUser['username'] ?? '') ?>"></div>
                <div class="field"><label>البريد الإلكتروني</label><input name="email" type="email" value="<?= sqlbak_h($editUser['email'] ?? '') ?>"></div>
                <div class="field full"><label>كلمة المرور</label><input name="password" type="password" <?= $editUser ? '' : 'required' ?> placeholder="اتركه فارغًا للإبقاء على نفس الكلمة للمستخدم الحالي عند التعديل"></div>
                <div class="field"><label>الصلاحية</label>
                    <select name="role">
                        <?php foreach (sqlbak_allowed_user_roles() as $role): ?>
                            <option value="<?= sqlbak_h($role) ?>" <?= (($editUser['role'] ?? 'viewer') === $role ? 'selected' : '') ?>><?= ucfirst($role) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field"><label>الحالة</label>
                    <select name="status">
                        <?php foreach (sqlbak_allowed_user_statuses() as $status): ?>
                            <option value="<?= sqlbak_h($status) ?>" <?= (($editUser['status'] ?? 'active') === $status ? 'selected' : '') ?>><?= $status === 'active' ? 'فعال' : 'معلق' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-actions">
                <button class="button" type="submit"><i class="fa fa-save"></i> <?= $editUser ? 'حفظ التعديلات' : 'إضافة المستخدم' ?></button>
                <?php if ($editUser): ?>
                    <a class="button secondary" href="users.php">إلغاء</a>
                <?php endif; ?>
            </div>
        </form>
    </article>
    <article class="panel">
        <div class="panel-head"><div><h2>ملخص الأدوار</h2><p>توزيع صلاحيات المستخدمين في النظام.</p></div></div>
        <ul class="muted-list">
            <li><strong>admin</strong>: إدارة كاملة للنظام والإعدادات والمستخدمين.</li>
            <li><strong>operator</strong>: إدارة النسخ والـ destinations والتشغيل اليومي.</li>
            <li><strong>viewer</strong>: صلاحية قراءة فقط.</li>
        </ul>
        <ul class="muted-list">
            <li><strong>active</strong>: حساب فعّال.</li>
            <li><strong>suspended</strong>: حساب معطّل ولن يتم تسجيل دخوله.</li>
        </ul>
    </article>
</section>
<article class="panel">
    <div class="panel-head"><div><h2>قائمة المستخدمين</h2><p>تحكم كامل بالمستخدمين المرتبطين بالنظام.</p></div></div>
    <div class="table-wrap"><table><thead><tr><th>المستخدم</th><th>البريد</th><th>الصلاحية</th><th>الحالة</th><th>تاريخ الإنشاء</th><th>إجراءات</th></tr></thead><tbody>
        <?php foreach ($users as $user): ?>
            <tr>
                <td><?= sqlbak_h($user['username']) ?></td>
                <td><?= sqlbak_h($user['email'] ?? '-') ?></td>
                <td><?= sqlbak_h($user['role']) ?></td>
                <td><?= sqlbak_h($user['status']) ?></td>
                <td><?= sqlbak_h((string) ($user['created_at'] ?? '-')) ?></td>
                <td>
                    <div class="table-actions">
                        <a class="icon-button" href="?edit=<?= (int) $user['id'] ?>" title="تعديل"><i class="fa fa-pencil"></i></a>
                        <?php if ((int) $user['id'] !== (int) ($_SESSION['sqlbak_user']['id'] ?? 0)): ?>
                            <form method="post" onsubmit="return confirm('حذف المستخدم؟')">
                                <input type="hidden" name="csrf" value="<?= sqlbak_h(sqlbak_csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                                <button class="icon-button danger-icon" type="submit" title="حذف"><i class="fa fa-trash"></i></button>
                            </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody></table></div>
</article>
<?php sqlbak_page_end();

