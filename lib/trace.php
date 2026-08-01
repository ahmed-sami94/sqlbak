<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

final class SqlbakOperationException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}

function sqlbak_trace_id(): string
{
    return bin2hex(random_bytes(16));
}

function sqlbak_error_code(Throwable $error): string
{
    if ($error instanceof SqlbakOperationException) {
        return $error->errorCode;
    }
    $message = strtolower($error->getMessage());
    return match (true) {
        str_contains($message, 'auth'), str_contains($message, 'login'), str_contains($message, 'دخول') => 'AUTH_FAILED',
        str_contains($message, 'timed out'), str_contains($message, 'timeout') => 'CONNECTION_TIMEOUT',
        str_contains($message, 'permission'), str_contains($message, 'صلاحية') => 'PERMISSION_DENIED',
        str_contains($message, 'not found'), str_contains($message, 'غير موجود') => 'PATH_NOT_FOUND',
        str_contains($message, 'fingerprint'), str_contains($message, 'بصمة') => 'HOST_KEY_MISMATCH',
        default => 'UNEXPECTED_ERROR',
    };
}

function sqlbak_record_event(array $event): void
{
    $statement = sqlbak_db()->prepare('INSERT INTO operation_events (trace_id,backup_id,copy_id,destination_id,policy_rule_id,event_type,phase,level,error_code,message,context_json) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $statement->execute([
        $event['trace_id'],
        $event['backup_id'] ?? null,
        $event['copy_id'] ?? null,
        $event['destination_id'] ?? null,
        $event['policy_rule_id'] ?? null,
        $event['event_type'],
        $event['phase'],
        $event['level'] ?? 'info',
        $event['error_code'] ?? null,
        substr((string) $event['message'], 0, 1000),
        empty($event['context']) ? null : json_encode($event['context'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
    ]);
}
