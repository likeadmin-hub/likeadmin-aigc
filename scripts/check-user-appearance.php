<?php
/** Integration regression: transaction-only fixtures, never modifies an existing account. */
require dirname(__DIR__) . '/vendor/autoload.php';
(new \think\App())->initialize();
use app\common\service\UserAppearanceService as Appearance;
use think\facade\Db;
$check = static function ($ok, $message) { if (!$ok) throw new RuntimeException($message); };
$tenant = 2000000001; $user = 2000000001;
$check(!Db::name('user_appearance')->whereIn('tenant_id',[$tenant,$tenant+1])->find(), 'Fixture identity already occupied');
Db::startTrans();
try {
    $p=Appearance::defaults();
    $check(Appearance::read($tenant,$user)['revision']===0,'New user defaults');
    $p['selected']='voxel';
    $saved=Appearance::save($tenant,$user,0,$p);
    $check($saved['revision']===1,'First revision');
    $check(Appearance::read($tenant,$user)===$saved,'Second-device read');
    $check(Appearance::read($tenant+1,$user)['preferences']['selected']==='default','Tenant isolation');
    $check(Appearance::read($tenant,$user+1)['preferences']['selected']==='default','User isolation');
    try { Appearance::save($tenant,$user,0,Appearance::defaults()); throw new LogicException('Stale write accepted'); }
    catch(RuntimeException $e){$check(str_contains($e->getMessage(),'其他设备'),'Expected conflict error');}
    $check(Appearance::read($tenant,$user)===$saved,'Rejected write preserves data');
    $p['custom']=[['id'=>'custom-check001','name'=>'测试皮肤','mode'=>'light','primary'=>'#123456','secondary'=>'#aabbcc','background_id'=>0]];
    $p['selected']='custom-check001';
    Appearance::save($tenant,$user,1,$p);
    $check(Appearance::read($tenant,$user)['preferences']['custom'][0]['name']==='测试皮肤','Custom round trip');
    Appearance::save($tenant,$user,2,Appearance::defaults());
    $check(Appearance::read($tenant,$user)['preferences']['custom']===[],'Remove custom');
    echo "PASS: defaults, persistence, tenant/user isolation, stale-write rejection, custom create/remove\n";
} finally { Db::rollback(); }
$check(!Db::name('user_appearance')->where('tenant_id',$tenant)->find(),'Fixture rollback');
echo "PASS: no fixture rows retained\n";
