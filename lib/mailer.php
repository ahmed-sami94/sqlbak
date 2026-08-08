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

function sqlbak_parse_recipients(string $jsonOrText): array
{
    $recipients = preg_split('/[\s,;]+/', trim($jsonOrText), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $clean = [];
    foreach ($recipients as $recipient) {
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('عنوان بريد غير صالح: ' . $recipient);
        }
        $clean[] = strtolower((string) $recipient);
    }
    return array_values(array_unique($clean));
}

function sqlbak_get_mail_recipients(array $settings, string $field): array
{
    if (!in_array($field, ['default_report_recipients_json', 'failure_recipients_json'], true)) {
        return [];
    }
    $raw = (string) ($settings[$field] ?? '[]');
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        return [];
    }
    $recipients = [];
    foreach ($decoded as $recipient) {
        if (is_string($recipient) && filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $recipients[] = strtolower($recipient);
        }
    }
    return array_values(array_unique($recipients));
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

function sqlbak_send_failure_alert(array $messageContext): void
{
    $settings = sqlbak_mail_settings();
    if (empty($settings['enabled']) || !(bool) ($settings['failure_alert_enabled'] ?? 0)) {
        return;
    }

    $recipients = sqlbak_get_mail_recipients($settings, 'failure_recipients_json');
    if ($recipients === []) {
        $recipients = sqlbak_get_mail_recipients($settings, 'default_report_recipients_json');
    }
    if ($recipients === []) {
        return;
    }

    $defaults = [
        'event_type' => 'unknown',
        'trace_id' => 'n/a',
        'database' => '-',
        'destination' => '-',
        'message' => '-',
    ];
    $context = array_merge($defaults, $messageContext);
    $prefix = trim((string) ($settings['failure_subject_prefix'] ?? 'SQLBak Alert'));
    $subject = $prefix . ' · ' . $context['event_type'];

    $text = sprintf(
        "SQLBak failure alert\nTrace: %s\nEvent: %s\nDatabase: %s\nDestination: %s\nDetails: %s\n",
        $context['trace_id'],
        $context['event_type'],
        $context['database'],
        $context['destination'],
        $context['message']
    );
    $html = '<div dir="rtl"><h2>' . sqlbak_h($subject) . '</h2><p><strong>الحدث:</strong> ' . sqlbak_h($context['event_type']) . '</p><p><strong>قاعدة البيانات:</strong> ' . sqlbak_h((string) $context['database']) . '</p><p><strong>الوجهة:</strong> ' . sqlbak_h((string) $context['destination']) . '</p><p><strong>التتبع:</strong> ' . sqlbak_h((string) $context['trace_id']) . '</p><p><strong>التفاصيل:</strong> ' . nl2br(sqlbak_h((string) $context['message'])) . '</p></div>';
    sqlbak_send_mail($settings, [
        'recipients' => $recipients,
        'subject' => $subject,
        'html' => $html,
        'text' => $text,
    ]);
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
