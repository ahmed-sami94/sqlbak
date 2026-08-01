<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function sqlbak_policy_next_run(array $policy, ?DateTimeImmutable $after = null): DateTimeImmutable
{
    $after ??= new DateTimeImmutable('now', new DateTimeZone('Africa/Cairo'));
    $type = $policy['schedule_type'];
    if ($type === 'interval') {
        return $after->modify('+' . max(15, (int) $policy['interval_minutes']) . ' minutes');
    }

    [$hour, $minute] = array_map('intval', explode(':', (string) ($policy['run_time'] ?? '08:00')));
    $candidate = $after->setTime($hour, $minute);
    if ($type === 'daily') {
        return $candidate > $after ? $candidate : $candidate->modify('+1 day');
    }
    if ($type === 'weekly') {
        $daysAhead = ((int) $policy['weekday'] - (int) $after->format('N') + 7) % 7;
        $candidate = $candidate->modify('+' . $daysAhead . ' days');
        return $candidate > $after ? $candidate : $candidate->modify('+7 days');
    }

    $day = min(28, max(1, (int) $policy['day_of_month']));
    $candidate = $after->setDate((int) $after->format('Y'), (int) $after->format('m'), $day)->setTime($hour, $minute);
    if ($candidate > $after) {
        return $candidate;
    }
    $nextMonth = $after->modify('first day of next month');
    return $nextMonth->setDate((int) $nextMonth->format('Y'), (int) $nextMonth->format('m'), $day)->setTime($hour, $minute);
}

function sqlbak_policy_schedule_label(array $policy): string
{
    return match ($policy['schedule_type']) {
        'interval' => 'كل ' . (int) $policy['interval_minutes'] . ' دقيقة',
        'daily' => 'يومياً ' . substr((string) $policy['run_time'], 0, 5),
        'weekly' => 'أسبوعياً · اليوم ' . (int) $policy['weekday'] . ' · ' . substr((string) $policy['run_time'], 0, 5),
        'monthly' => 'شهرياً · يوم ' . (int) $policy['day_of_month'] . ' · ' . substr((string) $policy['run_time'], 0, 5),
        default => 'غير معروف',
    };
}

function sqlbak_validate_policy(array $policy): void
{
    if (!in_array($policy['schedule_type'], ['interval', 'daily', 'weekly', 'monthly'], true)) {
        throw new InvalidArgumentException('نوع الجدولة غير صالح.');
    }
    if ($policy['schedule_type'] === 'interval' && ((int) $policy['interval_minutes'] < 15 || (int) $policy['interval_minutes'] > 10080)) {
        throw new InvalidArgumentException('الفاصل يجب أن يكون بين 15 دقيقة و7 أيام.');
    }
    if ($policy['schedule_type'] === 'weekly' && ((int) $policy['weekday'] < 1 || (int) $policy['weekday'] > 7)) {
        throw new InvalidArgumentException('يوم الأسبوع غير صالح.');
    }
    if ($policy['schedule_type'] === 'monthly' && ((int) $policy['day_of_month'] < 1 || (int) $policy['day_of_month'] > 28)) {
        throw new InvalidArgumentException('يوم الشهر يجب أن يكون بين 1 و28.');
    }
    if ((int) $policy['retention_count'] < 1 || (int) $policy['retention_count'] > 1000) {
        throw new InvalidArgumentException('الاحتفاظ يجب أن يكون بين 1 و1000 نسخة.');
    }
}
