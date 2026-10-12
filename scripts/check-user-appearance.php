<?php
/** Integration regression: transaction-only fixtures, never modifies an existing account. */
require dirname(__DIR__) . '/runtime-loader.php';
(new \AigcRuntimeApp(dirname(__DIR__)))->initialize();
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
    $fileId=Db::name('tenant_file')->insertGetId(['tenant_id'=>$tenant,'source_id'=>$user,'source'=>1,'type'=>10,'name'=>'appearance-fixture','uri'=>'uploads/appearance-fixture.webp','storage_scope'=>'platform','storage_engine'=>'local']);
    $p=Appearance::defaults();$p['selected']='voxel';$p['preset_backgrounds']=[['preset_id'=>'voxel','background_id'=>$fileId]];
    $saved=Appearance::save($tenant,$user,3,$p);
    $check(Appearance::read($tenant,$user)===$saved,'Preset background reload');
    $check($saved['preferences']['preset_backgrounds'][0]['background_id']===$fileId,'Owned preset image');
    $legacy=$p;unset($legacy['preset_backgrounds']);
    $saved=Appearance::save($tenant,$user,4,$legacy);
    $check(count($saved['preferences']['preset_backgrounds'])===1,'Legacy client preserves background');
    foreach ([[$tenant+1,$user],[$tenant,$user+1]] as [$otherTenant,$otherUser]) {
        try { Appearance::save($otherTenant,$otherUser,0,$p);throw new LogicException('Foreign preset background accepted'); }
        catch(InvalidArgumentException $e) { $check(str_contains($e->getMessage(),'当前账号'),'Preset ownership rejection'); }
    }
    $p['preset_backgrounds']=[];
    $check(Appearance::save($tenant,$user,5,$p)['preferences']['preset_backgrounds']===[],'Reset preset background');
    $p['preset_backgrounds']=[['preset_id'=>'voxel','background_id'=>$fileId]];
    Appearance::save($tenant,$user,6,$p);
    Db::name('tenant_file')->where('id',$fileId)->update(['delete_time'=>time()]);
    $check(Appearance::read($tenant,$user)['preferences']['preset_backgrounds']===[],'Deleted image falls back to preset');
    $p['preset_backgrounds']=[['preset_id'=>'voxel','background_id'=>0,'name'=>'我的城市']];
    $saved=Appearance::save($tenant,$user,7,$p);
    $check(Appearance::read($tenant,$user)===$saved,'Name-only preset reload');
    $check($saved['preferences']['preset_backgrounds'][0]['name']==='我的城市','Name persists with default background');
    $check($saved['preferences']['preset_backgrounds'][0]['background_id']===0,'Default image remains selected');
    echo "PASS: preset name-only persistence with default background\n";
    echo "PASS: preset background persistence/reset, legacy clients, foreign ownership and deleted images\n";
    echo "PASS: defaults, persistence, tenant/user isolation, stale-write rejection, custom create/remove\n";
} finally { Db::rollback(); }
$check(!Db::name('user_appearance')->where('tenant_id',$tenant)->find(),'Fixture rollback');
echo "PASS: no fixture rows retained\n";
