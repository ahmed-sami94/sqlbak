<?php
declare(strict_types=1);

require_once __DIR__ . '/mailer.php';

function sqlbak_report_next_run(array $schedule, ?DateTimeImmutable $after = null): DateTimeImmutable
{
    $after ??= new DateTimeImmutable('now', new DateTimeZone('Africa/Cairo'));
    [$hour, $minute] = array_map('intval', explode(':', (string) $schedule['run_time']));
    $candidate = $after->setTime($hour, $minute);
    if ($schedule['frequency'] === 'daily') {
        return $candidate > $after ? $candidate : $candidate->modify('+1 day');
    }
    if ($schedule['frequency'] === 'weekly') {
        $daysAhead = ((int) $schedule['weekday'] - (int) $after->format('N') + 7) % 7;
        $candidate = $candidate->modify('+' . $daysAhead . ' days');
        return $candidate > $after ? $candidate : $candidate->modify('+7 days');
    }
    $day = min(28, max(1, (int) $schedule['day_of_month']));
    $candidate = $after->setDate((int) $after->format('Y'), (int) $after->format('m'), $day)->setTime($hour, $minute);
    if ($candidate > $after) {
        return $candidate;
    }
    $nextMonth = $after->modify('first day of next month');
    return $nextMonth->setDate((int) $nextMonth->format('Y'), (int) $nextMonth->format('m'), $day)->setTime($hour, $minute);
}

function sqlbak_report_period(string $frequency, ?DateTimeImmutable $now = null): array
{
    $now ??= new DateTimeImmutable('now', new DateTimeZone('Africa/Cairo'));
    if ($frequency === 'daily') {
        return [$now->setTime(0, 0), $now];
    }
    $end = $now->setTime(0, 0);
    $start = match ($frequency) {
        'weekly' => $end->modify('-7 days'),
        'monthly' => $end->modify('first day of this month')->modify('-1 month'),
        default => throw new InvalidArgumentException('تكرار التقرير غير صالح.'),
    };
    if ($frequency === 'monthly') {
        $end = $end->modify('first day of this month');
    }
    return [$start, $end];
}

function sqlbak_report_recipients(array $schedule): array
{
    $recipients = json_decode($schedule['custom_recipients_json'] ?? '[]', true) ?: [];
    $userIds = array_map('intval', json_decode($schedule['user_ids_json'] ?? '[]', true) ?: []);
    if ($userIds !== []) {
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $statement = sqlbak_db()->prepare("SELECT email FROM users WHERE id IN ({$placeholders}) AND email<>''");
        $statement->execute($userIds);
        $recipients = [...$recipients, ...array_column($statement->fetchAll(), 'email')];
    }
    if ($recipients === []) {
        $mailSettings = sqlbak_mail_settings();
        $recipients = json_decode($mailSettings['default_report_recipients_json'] ?? '[]', true) ?: [];
    }
    return array_values(array_unique(array_filter($recipients, fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))));
}

function sqlbak_report_summary(DateTimeImmutable $start, DateTimeImmutable $end): array
{
    $pdo = sqlbak_db();
    $parameters = [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
    $summary = $pdo->prepare("SELECT COUNT(*) AS jobs,SUM(status='success') AS successful,SUM(status='partial') AS partial,SUM(status='failed') AS failed,COALESCE(SUM(size_bytes),0) AS bytes FROM backups WHERE created_at>=? AND created_at<?");
    $summary->execute($parameters);
    $jobs = $pdo->prepare("SELECT b.id,d.name AS database_name,b.filename,b.type,b.status,b.size_bytes,b.created_at,b.completed_at,b.error_message,b.trace_id FROM backups b JOIN `databases` d ON d.id=b.database_id WHERE b.created_at>=? AND b.created_at<? ORDER BY b.created_at DESC LIMIT 250");
    $jobs->execute($parameters);
    $destinations = $pdo->prepare("SELECT d.name,d.display_order,d.type,COUNT(c.id) AS backups,SUM(c.status='success') AS successful,SUM(c.status='failed') AS failed FROM storage_destinations d LEFT JOIN backup_copies c ON c.destination_id=d.id AND c.completed_at>=? AND c.completed_at<? GROUP BY d.id ORDER BY d.display_order,d.id");
    $destinations->execute($parameters);
    return ['totals' => $summary->fetch(), 'jobs' => $jobs->fetchAll(), 'destinations' => $destinations->fetchAll()];
}

function sqlbak_report_html(array $report, DateTimeImmutable $start, DateTimeImmutable $end): string
{
    $destinations = sqlbak_report_destination_rows($report['destinations']);
    $totals = $report['totals'];
    $cards = sqlbak_report_summary_cards($totals);
    return '<div dir="rtl" style="max-width:720px;margin:auto;background:#f6f3f8;color:#29243a;font-family:Arial,sans-serif"><div style="padding:24px;background:linear-gradient(135deg,#2b0060,#5d2a86);color:#fff"><div style="color:#ffcf6a;font-size:12px;font-weight:bold">SQLBak</div><h1 style="margin:5px 0;font-size:24px">ملخص النسخ الاحتياطية</h1><p style="margin:0;color:#eee">' . $start->format('Y-m-d H:i') . ' — ' . $end->format('Y-m-d H:i') . '</p></div><div style="padding:18px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>' . $cards . '</tr></table><h2 style="margin:24px 0 10px;color:#2b0060;font-size:18px">ملخص وجهات التخزين</h2><table width="100%" cellpadding="9" cellspacing="0" style="border-collapse:collapse;background:#fff;border:1px solid #e8e2ef"><tr style="background:#f1edf5;color:#2b0060"><th align="right">الوجهة</th><th>النسخ</th><th>ناجحة</th><th>فاشلة</th></tr>' . $destinations . '</table><p style="margin:20px 0 0;color:#756f80;font-size:12px">هذا ملخص فقط؛ راجع SQLBak للتفاصيل والسجل الكامل.</p></div></div>';
}

function sqlbak_report_summary_cards(array $totals): string
{
    $cards = [['المهام', $totals['jobs'], '#2b0060'], ['ناجحة', $totals['successful'], '#198754'], ['جزئية', $totals['partial'], '#9b6800'], ['فاشلة', $totals['failed'], '#c33c54']];
    $html = '';
    foreach ($cards as [$label, $value, $color]) {
        $html .= '<td width="25%" style="padding:4px"><div style="padding:13px 7px;background:#fff;border-top:4px solid ' . $color . ';text-align:center"><strong style="display:block;color:' . $color . ';font-size:24px">' . (int) $value . '</strong><span style="color:#756f80;font-size:11px">' . $label . '</span></div></td>';
    }
    return $html;
}

function sqlbak_report_destination_rows(array $destinations): string
{
    $html = '';
    foreach ($destinations as $destination) {
        $html .= '<tr><td>Server ' . (int) $destination['display_order'] . ' · ' . sqlbak_h($destination['name']) . '</td><td align="center" style="font-weight:bold">' . (int) $destination['backups'] . '</td><td align="center" style="color:#198754;font-weight:bold">' . (int) $destination['successful'] . '</td><td align="center" style="color:#c33c54;font-weight:bold">' . (int) $destination['failed'] . '</td></tr>';
    }
    return $html;
}

function sqlbak_send_report(array $schedule): int
{
    [$start, $end] = sqlbak_report_period($schedule['frequency']);
    $recipients = sqlbak_report_recipients($schedule);
    if ($recipients === []) {
        throw new SqlbakOperationException('NO_RECIPIENTS', 'لا يوجد مستلمون صالحون لهذا التقرير.');
    }
    $traceId = sqlbak_trace_id();
    $pdo = sqlbak_db();
    $scheduleId = (int) ($schedule['id'] ?? 0);
    $pdo->prepare("INSERT INTO report_deliveries (schedule_id,trace_id,period_start,period_end,recipients_json,status,started_at) VALUES (?,?,?,?,?,'running',NOW())")
        ->execute([$scheduleId ?: null, $traceId, $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), json_encode($recipients, JSON_THROW_ON_ERROR)]);
    $deliveryId = (int) $pdo->lastInsertId();
    try {
        $report = sqlbak_report_summary($start, $end);
        $mailSettings = sqlbak_mail_settings();
        if (!(bool) $mailSettings['enabled']) {
            throw new SqlbakOperationException('MAIL_DISABLED', 'إرسال البريد معطل من الإعدادات.');
        }
        sqlbak_send_mail($mailSettings, ['recipients' => $recipients, 'subject' => 'SQLBak · ' . $schedule['name'], 'html' => sqlbak_report_html($report, $start, $end), 'text' => 'SQLBak report ' . $start->format('Y-m-d') . ' - ' . $end->format('Y-m-d') . '. Jobs: ' . (int) $report['totals']['jobs']]);
        $pdo->prepare("UPDATE report_deliveries SET status='success',sent_at=NOW() WHERE id=?")->execute([$deliveryId]);
        if ($scheduleId > 0) {
            $pdo->prepare("UPDATE report_schedules SET last_status='success',last_error=NULL,last_sent_at=NOW() WHERE id=?")->execute([$scheduleId]);
        }
        return $deliveryId;
    } catch (Throwable $error) {
        $errorCode = sqlbak_error_code($error);
        $pdo->prepare("UPDATE report_deliveries SET status='failed',error_code=?,error_message=? WHERE id=?")->execute([$errorCode, $error->getMessage(), $deliveryId]);
        if ($scheduleId > 0) {
            $pdo->prepare("UPDATE report_schedules SET last_status='failed',last_error=? WHERE id=?")->execute([$error->getMessage(), $scheduleId]);
        }
        sqlbak_record_event(['trace_id' => $traceId, 'event_type' => 'report_delivery', 'phase' => 'smtp', 'level' => 'error', 'error_code' => $errorCode, 'message' => $error->getMessage(), 'context' => ['delivery_id' => $deliveryId]]);
        throw $error;
    }
}
