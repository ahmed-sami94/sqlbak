<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';

$pdo = sqlbak_db();
$pdo->beginTransaction();
try {
    $rows = $pdo->query('SELECT id, password, password_encrypted FROM `databases` FOR UPDATE')->fetchAll();
    $update = $pdo->prepare('UPDATE `databases` SET password_encrypted = ?, password = NULL WHERE id = ?');
    foreach ($rows as $row) {
        if (!empty($row['password_encrypted'])) {
            continue;
        }
        if (!is_string($row['password']) || $row['password'] === '') {
            throw new RuntimeException('Database ' . $row['id'] . ' has no password to migrate.');
        }
        $update->execute([sqlbak_encrypt(['password' => $row['password']]), $row['id']]);
    }
    $pdo->commit();
    fwrite(STDOUT, "SQLBak database secrets migrated.\n");
} catch (Throwable $error) {
    $pdo->rollBack();
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
