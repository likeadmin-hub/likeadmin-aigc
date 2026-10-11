<?php
declare(strict_types=1);

// Used only by the disposable database/container integration test.
if (getenv('INSTALL_PARITY_DISPOSABLE') !== '1') {
    throw new RuntimeException('This test requires a disposable database');
}
$root = dirname(__DIR__);
$database = (string)getenv('PHP_DATABASE_DATABASE');
if (!str_starts_with($database, 'parity_')) {
    throw new RuntimeException('Refusing a non-test database');
}
$prefix = (string)getenv('PHP_DATABASE_PREFIX');
if (($argv[1] ?? '') === 'install') {
    define('INSTALL_ROOT', $root . '/public/install');
    require INSTALL_ROOT . '/model.php';
    $pdo = new PDO('mysql:host=mysql;dbname=' . $database . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $installer = new installModel();
    foreach (['dbh' => $pdo, 'prefix' => $prefix] as $name => $value) {
        $property = new ReflectionProperty($installer, $name);
        $property->setValue($installer, $value);
    }
    if (!$installer->createTable('8.0', ['admin_user' => 'parity_admin', 'admin_password' => 'disposable-test-only'])) {
        throw new RuntimeException('Browser installer failed');
    }
    echo "PASS real browser installer ($prefix)\n";
    exit(0);
}

require $root . '/vendor/autoload.php';
(new think\App($root))->initialize();
if ((think\facade\Db::query('SELECT DATABASE() AS db')[0]['db'] ?? '') !== $database) {
    throw new RuntimeException('Wrong database connection');
}
use app\common\service\app\aigc_short_drama\ShortDramaCanvasPosterJobService as Posters;
use think\facade\Db;

$poster = Posters::enqueue(9, 8, 7, '6', 'uploads/test.mp4');
$duplicate = Posters::enqueue(9, 8, 7, '6', 'uploads/test.mp4');
$frame = Posters::enqueue(9, 8, 7, '6', 'uploads/test.mp4', '', '', '', 1.5);
if ($poster <= 0 || $duplicate !== $poster || $frame === $poster) {
    throw new RuntimeException('Poster/frame idempotency failed');
}
$jobs = Posters::claim('parity-worker', 180, 2);
if (count($jobs) !== 2) {
    throw new RuntimeException('Poster/frame claim failed');
}
foreach ($jobs as $job) {
    Posters::retry($job, new RuntimeException('test retry'));
    $row = Db::name('aigc_short_drama_canvas_poster_job')->where('id', $job['id'])->find();
    if ($row['status'] !== 'retrying' || $row['lease_token'] !== '' || (int)$row['attempts'] !== 1) {
        throw new RuntimeException('Retry lost task state');
    }
}
if (Posters::recoverExpired() !== 0) {
    throw new RuntimeException('Retry incorrectly recovered as expired');
}
Db::name('aigc_short_drama_canvas_poster_job')->whereIn('id', [$poster, $frame])->delete();
echo "PASS poster/frame enqueue, deduplication, lease claim and retry ($prefix)\n";
