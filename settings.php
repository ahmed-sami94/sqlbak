<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/mailer.php';

sqlbak_require_admin();
$pdo = sqlbak_db();

function sqlbak_save_setting(string $key, string $value): void
{
    sqlbak_db()->prepare('INSERT INTO app_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([$key, $value]);
}

function sqlbak_mail_encryption(): string
{
    $encryption = (string) ($_POST['encryption'] ?? 'starttls');
    if (!in_array($encryption, ['none', 'starttls', 'smtps'], true)) {
        throw new InvalidArgumentException('نوع تشفير SMTP غير صالح.');
    }
    return $encryption;
}

function sqlbak_default_report_recipients_json(): string
{
    $recipients = preg_split('/[\s,;]+/', trim((string) ($_POST['default_report_recipients'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    foreach ($recipients as $recipient) {
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('بريد مستلم التقرير غير صالح: ' . $recipient);
        }
    }
    return json_encode(array_values(array_unique($recipients)), JSON_THROW_ON_ERROR);
}

function sqlbak_mail_setting_values(array $existing): array
{
    $password = (string) ($_POST['smtp_password'] ?? '');
    $encryptedPassword = $password === '' ? $existing['smtp_password_encrypted'] : sqlbak_encrypt(['password' => $password]);
    return [
        trim((string) ($_POST['smtp_host'] ?? '')) ?: null,
        min(65535, max(1, (int) ($_POST['smtp_port'] ?? 587))),
        sqlbak_mail_encryption(),
        isset($_POST['auth_enabled']) ? 1 : 0,
        trim((string) ($_POST['smtp_username'] ?? '')) ?: null,
        $encryptedPassword,
        trim((string) ($_POST['from_email'] ?? '')) ?: null,
        trim((string) ($_POST['from_name'] ?? '')) ?: null,
        min(120, max(5, (int) ($_POST['timeout_seconds'] ?? 20))),
        trim((string) ($_POST['imap_host'] ?? '')) ?: null,
        min(65535, max(1, (int) ($_POST['imap_port'] ?? 993))),
        min(65535, max(1, (int) ($_POST['pop3_port'] ?? 995))),
        sqlbak_default_report_recipients_json(),
    ];
}

function sqlbak_save_mail_settings(PDO $pdo): void
{
    $pdo->prepare('UPDATE sqlbak_mail_settings SET smtp_host=?,smtp_port=?,encryption=?,auth_enabled=?,smtp_username=?,smtp_password_encrypted=?,from_email=?,from_name=?,timeout_seconds=?,imap_host=?,imap_port=?,pop3_port=?,default_report_recipients_json=? WHERE id=1')->execute(sqlbak_mail_setting_values(sqlbak_mail_settings()));
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    sqlbak_verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'save_general') {
            sqlbak_save_setting('dashboard_refresh_seconds', (string) min(300, max(10, (int) ($_POST['dashboard_refresh_seconds'] ?? 30))));
            sqlbak_save_setting('health_stale_minutes', (string) min(120, max(5, (int) ($_POST['health_stale_minutes'] ?? 10))));
            sqlbak_flash('success', 'تم حفظ الإعدادات العامة.');
        } elseif ($action === 'save_mail') {
            sqlbak_save_mail_settings($pdo);
            sqlbak_flash('success', 'تم حفظ إعدادات SMTP بشكل مشفر.');
        } elseif ($action === 'toggle_mail') {
            $pdo->exec('UPDATE sqlbak_mail_settings SET enabled=1-enabled WHERE id=1');
            sqlbak_flash('success', 'تم تحديث حالة إرسال البريد.');
        } elseif ($action === 'test_mail') {
            $traceId = sqlbak_trace_id();
            try {
                $test = sqlbak_test_mail(trim((string) ($_POST['test_recipient'] ?? '')));
                $pdo->prepare("UPDATE sqlbak_mail_settings SET last_test_status='success',last_test_message=?,last_tested_at=NOW(),last_latency_ms=? WHERE id=1")->execute([$test['message'], $test['latency_ms']]);
                sqlbak_flash('success', $test['message'] . ' Trace: ' . $traceId);
            } catch (Throwable $error) {
                $pdo->prepare("UPDATE sqlbak_mail_settings SET last_test_status='failed',last_test_message=?,last_tested_at=NOW(),last_latency_ms=NULL WHERE id=1")->execute([$error->getMessage()]);
                sqlbak_record_event(['trace_id' => $traceId, 'event_type' => 'smtp_test', 'phase' => 'smtp', 'level' => 'error', 'error_code' => sqlbak_error_code($error), 'message' => $error->getMessage()]);
                throw $error;
            }
        }
    } catch (Throwable $error) {
        sqlbak_flash('error', $error->getMessage());
    }
    header('Location: settings.php');
    exit;
}

$mail = sqlbak_mail_settings();
$defaultReportRecipients = implode(', ', json_decode($mail['default_report_recipients_json'] ?? '[]', true) ?: []);
$testRecipient = (string) (json_decode($mail['default_report_recipients_json'] ?? '[]', true)[0] ?? '');
$refreshSeconds = sqlbak_setting('dashboard_refresh_seconds', 30);
$staleMinutes = sqlbak_setting('health_stale_minutes', 10);
sqlbak_page_start('الإعدادات', 'settings');
?>
<section class="settings-grid">
    <article class="panel"><div class="panel-head"><div><h2>إعدادات عامة</h2><p>تحديث اللوحة ومراقبة صحة الوجهات.</p></div></div><form method="post"><input type="hidden" name="csrf" value="<?= sqlbak_h(sqlbak_csrf_token()) ?>"><input type="hidden" name="action" value="save_general"><div class="form-grid"><div class="field"><label>تحديث لوحة التحكم بالثواني</label><input name="dashboard_refresh_seconds" type="number" min="10" max="300" value="<?= $refreshSeconds ?>"></div><div class="field"><label>اعتبار فحص الوجهة قديماً بعد (دقيقة)</label><input name="health_stale_minutes" type="number" min="5" max="120" value="<?= $staleMinutes ?>"></div><div class="field full"><label>المنطقة الزمنية</label><input value="Africa/Cairo" readonly></div></div><div class="form-actions"><button class="button"><i class="fa fa-save"></i> حفظ</button></div></form></article>
    <article class="panel"><div class="panel-head"><div><h2>حالة SMTP</h2><p>آخر اختبار حقيقي لإرسال البريد.</p></div></div><div class="smtp-status"><span class="health-dot is-<?= $mail['last_test_status'] === 'success' ? 'success' : ($mail['last_test_status'] === 'failed' ? 'failed' : 'unknown') ?>"></span><div><strong><?= sqlbak_h($mail['last_test_status'] ?: 'لم يختبر') ?></strong><p><?= sqlbak_h($mail['last_test_message'] ?: 'احفظ الإعدادات ثم أرسل رسالة اختبار.') ?></p><small dir="ltr"><?= sqlbak_h($mail['last_tested_at'] ?? '-') ?> · <?= $mail['last_latency_ms'] ? (int) $mail['last_latency_ms'] . ' ms' : '-' ?></small></div></div></article>
</section>
<section class="panel">
    <div class="panel-head"><div><h2>إعدادات البريد</h2><p>مطابقة لإعدادات WoodERP: المرسل والخادم الصادر والوارد ومستلمو التقارير.</p></div><span class="status status-<?= $mail['enabled'] ? 'success' : 'disabled' ?>"><?= $mail['enabled'] ? 'مفعّل' : 'متوقف' ?></span></div>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= sqlbak_h(sqlbak_csrf_token()) ?>">
        <input type="hidden" name="action" value="save_mail">
        <div class="form-grid three">
            <div class="field full"><p class="mail-section-title">بيانات المرسل</p></div>
            <div class="field"><label>اسم المرسل</label><input name="from_name" value="<?= sqlbak_h($mail['from_name'] ?? '') ?>"></div>
            <div class="field"><label>بريد المرسل</label><input name="from_email" type="email" value="<?= sqlbak_h($mail['from_email'] ?? '') ?>"></div>
            <div class="field"><label>اسم المستخدم</label><input name="smtp_username" value="<?= sqlbak_h($mail['smtp_username'] ?? '') ?>"></div>
            <div class="field"><label>كلمة المرور</label><input name="smtp_password" type="password" placeholder="فارغة للاحتفاظ بالحالية"></div>
            <div class="field full"><p class="mail-section-title">الخادم الصادر SMTP</p></div>
            <div class="field"><label>الخادم</label><input name="smtp_host" value="<?= sqlbak_h($mail['smtp_host'] ?? '') ?>" placeholder="smtp.example.com"></div>
            <div class="field"><label>المنفذ</label><input name="smtp_port" type="number" min="1" max="65535" value="<?= (int) $mail['smtp_port'] ?>"></div>
            <div class="field"><label>التشفير</label><select name="encryption"><option value="none" <?= $mail['encryption'] === 'none' ? 'selected' : '' ?>>بدون</option><option value="starttls" <?= $mail['encryption'] === 'starttls' ? 'selected' : '' ?>>STARTTLS</option><option value="smtps" <?= $mail['encryption'] === 'smtps' ? 'selected' : '' ?>>SSL / SMTPS</option></select></div>
            <div class="field"><label>مهلة الاتصال بالثواني</label><input name="timeout_seconds" type="number" min="5" max="120" value="<?= (int) $mail['timeout_seconds'] ?>"></div>
            <div class="field"><label><input type="checkbox" name="auth_enabled" <?= $mail['auth_enabled'] ? 'checked' : '' ?>> مصادقة SMTP</label></div>
            <div class="field full"><p class="mail-section-title">الخادم الوارد</p></div>
            <div class="field"><label>خادم IMAP</label><input name="imap_host" value="<?= sqlbak_h($mail['imap_host'] ?? '') ?>" placeholder="mail.example.com"></div>
            <div class="field"><label>منفذ IMAP</label><input name="imap_port" type="number" min="1" max="65535" value="<?= (int) ($mail['imap_port'] ?? 993) ?>"></div>
            <div class="field"><label>منفذ POP3</label><input name="pop3_port" type="number" min="1" max="65535" value="<?= (int) ($mail['pop3_port'] ?? 995) ?>"></div>
            <div class="field full"><label>مستلمو تقارير النسخ الافتراضيون</label><textarea name="default_report_recipients" placeholder="admin@example.com, backup@example.com"><?= sqlbak_h($defaultReportRecipients) ?></textarea></div>
        </div>
        <div class="form-actions"><button class="button" type="submit"><i class="fa fa-save"></i> حفظ إعدادات البريد</button></div>
    </form>
    <div class="form-actions"><form method="post"><input type="hidden" name="csrf" value="<?= sqlbak_h(sqlbak_csrf_token()) ?>"><input type="hidden" name="action" value="toggle_mail"><button class="button secondary" type="submit"><i class="fa fa-power-off"></i> <?= $mail['enabled'] ? 'إيقاف إرسال البريد' : 'تفعيل إرسال البريد' ?></button></form></div>
    <form method="post" class="smtp-test-form"><input type="hidden" name="csrf" value="<?= sqlbak_h(sqlbak_csrf_token()) ?>"><input type="hidden" name="action" value="test_mail"><div class="field"><label>مستلم رسالة الاختبار</label><input name="test_recipient" type="email" required value="<?= sqlbak_h($testRecipient) ?>" placeholder="admin@example.com"></div><button class="button secondary"><i class="fa fa-paper-plane"></i> إرسال اختبار</button></form>
</section>
<?php sqlbak_page_end();
