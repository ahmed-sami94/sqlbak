<?php
declare(strict_types=1);

require_once __DIR__ . '/trace.php';
require_once SQLBAK_ROOT . '/PHPMailer/src/Exception.php';
require_once SQLBAK_ROOT . '/PHPMailer/src/PHPMailer.php';
require_once SQLBAK_ROOT . '/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailerException;

function sqlbak_mail_settings(): array
{
    $settings = sqlbak_db()->query('SELECT * FROM sqlbak_mail_settings WHERE id=1')->fetch();
    if (!$settings) {
        throw new SqlbakOperationException('MAIL_NOT_CONFIGURED', 'إعدادات البريد غير موجودة.');
    }
    return $settings;
}

function sqlbak_configured_mailer(array $settings): PHPMailer
{
    if (!$settings['smtp_host'] || !$settings['from_email']) {
        throw new SqlbakOperationException('MAIL_NOT_CONFIGURED', 'أكمل خادم SMTP وبريد المرسل أولاً.');
    }
    $mailer = new PHPMailer(true);
    $mailer->CharSet = 'UTF-8';
    $mailer->isSMTP();
    $mailer->Host = $settings['smtp_host'];
    $mailer->Port = (int) $settings['smtp_port'];
    $mailer->Timeout = (int) $settings['timeout_seconds'];
    $mailer->SMTPAuth = (bool) $settings['auth_enabled'];
    $mailer->Username = (string) $settings['smtp_username'];
    $secret = sqlbak_decrypt($settings['smtp_password_encrypted'] ?? null);
    $mailer->Password = (string) ($secret['password'] ?? '');
    $mailer->SMTPSecure = match ($settings['encryption']) {
        'starttls' => PHPMailer::ENCRYPTION_STARTTLS,
        'smtps' => PHPMailer::ENCRYPTION_SMTPS,
        default => '',
    };
    $mailer->SMTPAutoTLS = $settings['encryption'] !== 'none';
    $mailer->setFrom($settings['from_email'], $settings['from_name'] ?: 'SQLBak');
    return $mailer;
}

function sqlbak_send_mail(array $settings, array $message): void
{
    if ($message['recipients'] === []) {
        throw new SqlbakOperationException('NO_RECIPIENTS', 'لا يوجد مستلمون صالحون.');
    }
    try {
        $mailer = sqlbak_configured_mailer($settings);
        foreach ($message['recipients'] as $recipient) {
            $mailer->addAddress($recipient);
        }
        $mailer->isHTML(true);
        $mailer->Subject = $message['subject'];
        $mailer->Body = $message['html'];
        $mailer->AltBody = $message['text'];
        $mailer->send();
    } catch (MailerException $error) {
        throw new SqlbakOperationException('SMTP_SEND_FAILED', $error->getMessage());
    }
}

function sqlbak_test_mail(string $recipient): array
{
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('أدخل بريد اختبار صالحاً.');
    }
    $settings = sqlbak_mail_settings();
    $startedAt = microtime(true);
    sqlbak_send_mail($settings, ['recipients' => [$recipient], 'subject' => 'SQLBak SMTP Test', 'html' => '<div dir="rtl"><h2>اختبار SQLBak</h2><p>تم الاتصال بخادم SMTP وإرسال الرسالة بنجاح.</p></div>', 'text' => 'SQLBak SMTP test succeeded.']);
    return ['latency_ms' => (int) round((microtime(true) - $startedAt) * 1000), 'message' => 'تم إرسال رسالة الاختبار بنجاح.'];
}
