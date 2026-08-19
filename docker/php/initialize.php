<?php

declare(strict_types=1);

function requiredEnv(string $name): string
{
    $value = trim((string)getenv($name));
    if ($value === '' || str_starts_with($value, 'CHANGE_ME')) {
        throw new RuntimeException("Environment variable {$name} must be configured");
    }
    return $value;
}

try {
    $host = getenv('PHP_DATABASE_HOSTNAME') ?: 'mysql';
    $port = getenv('PHP_DATABASE_HOSTPORT') ?: '3306';
    $database = getenv('PHP_DATABASE_DATABASE') ?: 'likeadmin_aigc_saas';
    $username = getenv('PHP_DATABASE_USERNAME') ?: 'likeadmin';
    $password = requiredEnv('PHP_DATABASE_PASSWORD');
    $prefix = getenv('PHP_DATABASE_PREFIX') ?: 'la_';
    $adminUser = requiredEnv('PLATFORM_ADMIN_USER');
    $adminPassword = requiredEnv('PLATFORM_ADMIN_PASSWORD');
    $salt = requiredEnv('PHP_PROJECT_UNIQUE_IDENTIFICATION');

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $prefix)) {
        throw new RuntimeException('Invalid database prefix');
    }
    if (!preg_match('/^[a-zA-Z0-9_.@-]{3,32}$/', $adminUser)) {
        throw new RuntimeException('PLATFORM_ADMIN_USER must be 3-32 letters, numbers, dots, underscores, @ or hyphens');
    }
    if (strlen($adminPassword) < 12) {
        throw new RuntimeException('PLATFORM_ADMIN_PASSWORD must contain at least 12 characters');
    }
    if (strlen($salt) < 16) {
        throw new RuntimeException('PROJECT_UNIQUE_IDENTIFICATION must contain at least 16 characters');
    }

    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database),
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $adminTable = "`{$prefix}admin`";
    $deptTable = "`{$prefix}admin_dept`";
    $count = (int)$pdo->query("SELECT COUNT(*) FROM {$adminTable}")->fetchColumn();
    if ($count > 0) {
        fwrite(STDOUT, "Database is already initialized; platform administrator was not changed.\n");
        exit(0);
    }

    $time = time();
    $passwordHash = md5($salt . md5($adminPassword . $salt));

    $pdo->beginTransaction();
    $statement = $pdo->prepare(
        "INSERT INTO {$adminTable} " .
        '(`id`, `root`, `name`, `avatar`, `account`, `password`, `login_time`, `login_ip`, `multipoint_login`, `disable`, `create_time`, `update_time`, `delete_time`) ' .
        "VALUES (1, 1, :name, '', :account, :password, :login_time, '', 1, 0, :create_time, :update_time, NULL)"
    );
    $statement->execute([
        'name' => $adminUser,
        'account' => $adminUser,
        'password' => $passwordHash,
        'login_time' => $time,
        'create_time' => $time,
        'update_time' => $time,
    ]);
    $pdo->exec("INSERT INTO {$deptTable} (`admin_id`, `dept_id`) VALUES (1, 1)");
    $pdo->commit();

    fwrite(STDOUT, "Database initialization completed.\n");
    exit(0);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Initialization failed: {$e->getMessage()}\n");
    exit(1);
}
