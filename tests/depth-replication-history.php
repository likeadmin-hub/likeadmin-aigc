<?php
use app\common\service\app\aigc_depth_replication\DepthJobs;
use think\facade\Db;

require dirname(__DIR__).'/runtime-loader.php';
(new AigcRuntimeApp(dirname(__DIR__)))->initialize();
set_exception_handler(static function (Throwable $e) { fwrite(STDERR, $e->getMessage().PHP_EOL); exit(1); });
if (getenv('DEPTH_TEST_DB') !== config('database.connections.mysql.database') || !getenv('DEPTH_TEST_DB')) {
    throw new RuntimeException('Set DEPTH_TEST_DB to the local test database.');
}
function check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function denied(callable $operation): void {
    try { $operation(); } catch (InvalidArgumentException $e) { return; }
    throw new RuntimeException('Expected the job operation to be rejected');
}
$tenant = 1;
$user = random_int(1000000000, 2000000000);
$source = ['uri'=>'uploads/depth-test.mp4', 'name'=>'Sample', 'storage_engine'=>'local', 'storage_scope'=>'tenant', 'storage_domain'=>''];
$job = static function (string $status, int $parent = 0) use ($tenant, $user, $source): int {
    return Db::name(DepthJobs::TABLE)->insertGetId([
        'tenant_id'=>$tenant, 'user_id'=>$user, 'request_id'=>bin2hex(random_bytes(12)), 'input_hash'=>str_repeat('a',64),
        'kind'=>$parent ? 'render' : 'analyze', 'analysis_id'=>$parent, 'status'=>$status,
        'source_json'=>json_encode($source), 'settings_json'=>'{}', 'result_json'=>'{}', 'create_time'=>time(), 'update_time'=>time()
    ]);
};
$cache = null;
Db::startTrans();
try {
    $parent = $job('success');
    $render = $job('success', $parent);
    $failed = $job('failed', $parent);
    $folder = DepthJobs::folder($parent);
    check(!file_exists($folder), 'Unexpected existing fixture directory');
    $cache = $folder.'/cache';
    mkdir($cache, 0770, true);
    file_put_contents($cache.'/index.json', '{"pts":[0],"shots":[0]}');
    file_put_contents($cache.'/0.jpg', 'original');
    file_put_contents($cache.'/0.png', 'basis');
    denied(fn() => DepthJobs::delete($tenant, $user + 1, $parent));
    denied(fn() => DepthJobs::delete($tenant + 1, $user, $parent));
    foreach (['queued','running'] as $state) {
        $active = $job($state);
        denied(fn() => DepthJobs::delete($tenant, $user, $active));
    }
    $legacy = ['kind'=>'render', 'analysis_id'=>$parent, 'request_id'=>bin2hex(random_bytes(12))];
    $pending = DepthJobs::create($tenant, $user, $legacy);
    DepthJobs::delete($tenant, $user, $parent);
    DepthJobs::delete($tenant, $user, $parent);
    denied(fn() => DepthJobs::owned($tenant, $user, $parent));
    denied(fn() => DepthJobs::create($tenant, $user, array_merge($legacy, ['request_id'=>bin2hex(random_bytes(12))])));
    $visible = array_column(DepthJobs::lists($tenant, $user, 0)['lists'], 'id');
    check(!in_array($parent, $visible) && in_array($render, $visible), 'Unexpected visible history');
    $child = DepthJobs::owned($tenant, $user, $render);
    check(DepthJobs::dto($child, true, true)['index']['shots'] === [0], 'Re-edit lost the source index');
    check(DepthJobs::frames($child, 0)['frames'][0]['basis'] === base64_encode('basis'), 'Lost source frame');
    check((int)DepthJobs::analysisFor(DepthJobs::owned($tenant, $user, $pending['id']))['id'] === $parent, 'Worker lost its source');
    $retry = ['kind'=>'render', 'source_job_id'=>$failed, 'request_id'=>bin2hex(random_bytes(12))];
    $resubmitted = DepthJobs::create($tenant, $user, $retry);
    check($resubmitted['analysis_id'] === $parent && $resubmitted['id'] === DepthJobs::create($tenant, $user, $retry)['id'], 'Incorrect retry');
    DepthJobs::delete($tenant, $user, $failed);
    DepthJobs::delete($tenant, $user, $render);
    denied(fn() => DepthJobs::owned($tenant, $user, $render));
    check(is_file($cache.'/0.png'), 'Deletion removed shared analysis');
} finally {
    Db::rollback();
    if ($cache && is_dir($cache)) {
        foreach (['0.jpg','0.png','index.json'] as $name) if (is_file($cache.'/'.$name)) unlink($cache.'/'.$name);
        rmdir($cache);
        rmdir(dirname($cache));
    }
}
echo "Depth history deletion, ownership, pending work, preview and re-export verified\n";
