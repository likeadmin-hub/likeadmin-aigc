<?php

declare(strict_types=1);

try {
    $host = getenv('PHP_DATABASE_HOSTNAME') ?: 'mysql';
    $port = getenv('PHP_DATABASE_HOSTPORT') ?: '3306';
    $database = getenv('PHP_DATABASE_DATABASE') ?: 'likeadmin_aigc_saas';
    $username = getenv('PHP_DATABASE_USERNAME') ?: 'likeadmin';
    $password = getenv('PHP_DATABASE_PASSWORD') ?: '';
    $prefix = getenv('PHP_DATABASE_PREFIX') ?: 'la_';

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $prefix)) {
        throw new RuntimeException('Invalid database prefix');
    }

    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database),
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $pdo->query(sprintf('SELECT 1 FROM `%sconfig` LIMIT 1', $prefix));
    fwrite(STDOUT, "ok\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "not ready: {$e->getMessage()}\n");
    exit(1);
}
