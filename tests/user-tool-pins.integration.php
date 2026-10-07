<?php
// Application DB integration: fixture writes are always rolled back.
require dirname(__DIR__) . '/vendor/autoload.php';
(new \think\App())->initialize();
use app\common\service\UserToolPinsService as Pins;
use think\facade\Db;
$request = new \app\Request();
$check = static function ($actual, $expected) { if ($actual !== $expected) throw new RuntimeException(json_encode([$actual,$expected])); };
$check($request->input(['pinned'=>true], '', null, null)['pinned'], true);
$check($request->input(['pinned'=>false], '', null, null)['pinned'], false);
$tenant = random_int(1500000000, 1900000000);
$check(Db::name('user_tool_pins')->whereIn('tenant_id', [$tenant,$tenant+1])->count(), 0);
Db::startTrans();
try {
    $check(Pins::read($tenant,7), ['ids'=>[], 'initialized'=>false]);
    $check(Pins::initialize($tenant,7,['image','image'])['ids'], ['image']);
    $check(Pins::set($tenant,7,'video',true)['ids'], ['image','video']);
    $check(Pins::set($tenant,7,'video',true)['ids'], ['image','video']);
    $check(Pins::set($tenant,7,'image',false)['ids'], ['video']);
    $check(Pins::read($tenant+1,7)['ids'], []);
    $check(Pins::read($tenant,8)['ids'], []);
    $check(Pins::set($tenant,7,'video',false)['ids'], []);
    $check(Pins::initialize($tenant,7,['image'])['ids'], []);
    foreach ([[''],['../tool'],[1],array_fill(0,201,'image')] as $bad) {
        try { Pins::normalize($bad); throw new RuntimeException('Invalid input accepted'); }
        catch (InvalidArgumentException $e) {}
    }
    try { Pins::read(0,7); throw new RuntimeException('Invalid identity accepted'); }
    catch (InvalidArgumentException $e) {}
    echo "PASS: account/tenant isolation, once-only import, ordered idempotent pin/unpin, device updates, empty state, invalid input\n";
} finally { Db::rollback(); }
