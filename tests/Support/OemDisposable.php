<?php
declare(strict_types=1);
if (getenv('OEM_DISPOSABLE') !== '1' || !str_starts_with((string)getenv('PHP_DATABASE_DATABASE'), 'oem_') || getenv('PHP_DATABASE_HOSTNAME') !== 'oem-mysql') {
    throw new RuntimeException('Refusing a non-disposable OEM database');
}
require dirname(__DIR__, 2) . '/vendor/autoload.php';
(new think\App(dirname(__DIR__, 2)))->setBaseEnvName('oem-disposable')->setEnvName('oem-disposable')->initialize();
set_exception_handler(static function (Throwable $e): void { fwrite(STDERR, (string)$e . PHP_EOL); exit(1); });
if ((think\facade\Db::query('SELECT DATABASE() AS db')[0]['db'] ?? '') !== getenv('PHP_DATABASE_DATABASE')) {
    throw new RuntimeException('OEM database context mismatch');
}
