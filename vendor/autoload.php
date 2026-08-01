<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'phpseclib3\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
    $file = __DIR__ . '/phpseclib3/' . $relative . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
