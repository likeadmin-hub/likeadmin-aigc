<?php
// Tenant-owned draft/public snapshots and ownership validation. All fixture writes roll back.
require dirname(__DIR__).'/vendor/autoload.php';
(new \think\App())->initialize();
use app\common\service\PcHomeShortcutsService as S;
use app\common\service\ConfigService;
use app\common\model\tenant\Tenant;
use think\facade\Db;
$check=static function($actual,$expected){if($actual!==$expected)throw new RuntimeException(json_encode([$actual,$expected],JSON_UNESCAPED_UNICODE));};
$reject=static function($fn){try{$fn();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Invalid input accepted');};
$request=request();$request->source=\app\common\enum\AdminTerminalEnum::TENANT;
$tenant=(int)Tenant::order('id')->value('id');if(!$tenant)throw new RuntimeException('No local tenant for rollback fixture');
$request->tenantId=$tenant;
Db::startTrans();
try {
    ConfigService::set('pc_home_shortcuts','state',[]);
    $config=S::defaults();
    $check(count(S::editor()['draft']['cards']),7);
    $check(S::public(),null);
    $config['cards'][0]['copy']['en']['title']='My studio';
    $draft=S::save(['config'=>$config,'revision'=>0],false);
    $check($draft['revision'],1);$check(S::public(),null);
    $published=S::save(['config'=>$config,'revision'=>1],true);
    $check(S::public()['cards'][0]['copy']['en']['title'],'My studio');
    $config['cards'][0]['copy']['en']['title']='Unpublished draft';
    S::save(['config'=>$config,'revision'=>2],false);
    $check(S::public()['cards'][0]['copy']['en']['title'],'My studio');
    $reject(fn()=>S::save(['config'=>$config,'revision'=>2],true));
    $bad=$config;$bad['cards']=array_slice($bad['cards'],0,6);$reject(fn()=>S::normalize($bad));
    $bad=$config;$bad['cards'][0]['id']='card-2';$reject(fn()=>S::normalize($bad));
    $bad=$config;$bad['cards'][0]['app_code']='../invalid';$reject(fn()=>S::normalize($bad));
    $bad=$config;$bad['cards'][0]['app_code']='missing_fixture_app';$reject(fn()=>S::save(['config'=>$bad,'revision'=>3],true));
    $bad=$config;$bad['cards'][0]['backgrounds']['common']=['mode'=>'image','file_id'=>2147483600];$reject(fn()=>S::save(['config'=>$bad,'revision'=>3],true));
    $stored=ConfigService::get('pc_home_shortcuts','state',[]);$check(isset($stored['published']['cards'][0]['path']),false);
    // Another tenant/request cannot read this snapshot; use an unused tenant id without writing to it.
    $request->tenantId=1900000001;$check(S::public(),null);$request->tenantId=$tenant;
    $check(S::public()['cards'][0]['copy']['en']['title'],'My studio');
    echo "PASS: defaults, draft isolation, publish, revision conflicts, tenant isolation, fixed layout, route whitelist, unavailable tools, media ownership\n";
}finally{Db::rollback();}
