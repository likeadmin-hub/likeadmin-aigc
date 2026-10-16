<?php
// Local integration fixture. Config and material rows are always rolled back.
require dirname(__DIR__,2).'/vendor/autoload.php';
(new \think\App())->initialize();
use app\common\service\CanvasPortalService as S;
use app\common\service\ConfigService;
use app\common\model\file\TenantFile;
use app\common\model\tenant\Tenant;
use think\facade\Db;
function check($condition,$message) { if (!$condition) throw new RuntimeException($message); echo "PASS $message\n"; }
function rejects($fn) { try { $fn(); } catch (InvalidArgumentException $e) { return; } throw new RuntimeException('Invalid input accepted'); }
$request=request();$request->source=\app\common\enum\AdminTerminalEnum::TENANT;
$tenant=(int)Tenant::order('id')->value('id');if(!$tenant)throw new RuntimeException('No local tenant');
$request->tenantId=$tenant;
Db::startTrans();
try {
    ConfigService::set('pc_canvas_portal','state',[]);
    $config=S::defaults();
    check(count(S::public()['cards'])===12,'unconfigured templates have static copy and no remote media');
    check(S::public()['hero']['url']==='','unconfigured hero has no external asset');
    $config['cards'][0]['enabled']=0;
    $config['cards'][1]['section']='inspiration';
    $config['hero']['url']='https://untrusted.invalid/image.png';
    S::save(['config'=>$config,'revision'=>0]);
    check(count(S::public()['cards'])===11,'disabled cards excluded from public config');
    check(S::public()['cards'][0]['section']==='inspiration','section and order preserved');
    check(!isset(ConfigService::get('pc_canvas_portal','state')['config']['hero']['url']),'client supplied URLs never persisted');
    rejects(fn()=>S::save(['config'=>$config,'revision'=>0]));
    $bad=$config;$bad['cards'][1]['id']=$bad['cards'][0]['id'];rejects(fn()=>S::normalize($bad));
    $bad=$config;$bad['hero']['media_type']='audio';rejects(fn()=>S::normalize($bad));
    $bad=$config;$bad['hero']['file_id']=2147483600;rejects(fn()=>S::save(['config'=>$bad,'revision'=>1]));
    $file=TenantFile::where(['tenant_id'=>$tenant,'type'=>10])->findOrEmpty();
    if (!$file->isEmpty()) {
        $config['hero']['file_id']=(int)$file['id'];
        S::save(['config'=>$config,'revision'=>1]);
        check(S::public()['hero']['url']!=='','existing material resolved through storage service');
        $bad=$config;$bad['hero']['media_type']='video';rejects(fn()=>S::save(['config'=>$bad,'revision'=>2]));
        $other=Db::name('tenant')->insertGetId(['sn'=>'qa'.bin2hex(random_bytes(3)),'name'=>'Canvas portal isolation fixture','tactics'=>0,'create_time'=>time()]);
        $request->tenantId=$other;
        check(S::public()['hero']['url']==='','different tenant does not inherit configuration');
        rejects(fn()=>S::save(['config'=>$config,'revision'=>0]));
        $request->tenantId=$tenant;
        $originalDelete=$file['delete_time'];
        Db::name('tenant_file')->where('id',$file['id'])->update(['delete_time'=>time()]);
        check(S::public()['hero']['url']==='','deleted material is no longer exposed');
        Db::name('tenant_file')->where('id',$file['id'])->update(['delete_time'=>$originalDelete]);
    }
    $revision=S::editor()['revision'];
    S::save(['config'=>['hero'=>['media_type'=>'image'],'cards'=>[]],'revision'=>$revision]);
    check(S::public()['cards']===[],'explicit empty catalog remains empty');
    echo "PASS revision, validation, tenant scope, material kind and deletion checks\n";
} finally { $request->tenantId=$tenant; Db::rollback(); }
