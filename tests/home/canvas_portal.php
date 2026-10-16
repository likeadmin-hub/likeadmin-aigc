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
    unset($config['hero']['items']); // Exercise previously saved single-media settings.
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
    $revision = S::editor()['revision'];
    $cards = array_slice(S::defaults()['cards'], 0, 2);
    $cards[0]['title'] = '后台模板配置验收';
    $beforeHero = S::public()['hero'];
    S::saveSection('cards', ['config'=>['cards'=>$cards, 'hero'=>['file_id'=>2147483600]], 'revision'=>$revision]);
    check(S::public()['cards'][0]['title']==='后台模板配置验收', 'template publication reaches public catalog');
    check(S::public()['hero']===$beforeHero, 'template editor cannot overwrite hero');
    check(!isset(S::sectionEditor('cards')['config']['hero']), 'template response excludes hero config');
    rejects(fn()=>S::saveSection('cards', ['config'=>['cards'=>[]], 'revision'=>$revision]));
    S::saveSection('hero', ['config'=>['hero'=>['media_type'=>'image'], 'cards'=>[]], 'revision'=>$revision+1]);
    check(count(S::public()['cards'])===2, 'website editor cannot overwrite templates');
    check(!isset(S::sectionEditor('hero')['config']['cards']), 'website response excludes template config');
    $revision = S::editor()['revision'];
    $image = TenantFile::where(['tenant_id'=>$tenant,'type'=>10])->findOrEmpty();
    $video = TenantFile::where(['tenant_id'=>$tenant,'type'=>20])->findOrEmpty();
    $items = [];
    if (!$image->isEmpty()) $items[] = ['media_type'=>'image','file_id'=>(int)$image['id']];
    if (!$video->isEmpty()) $items[] = ['media_type'=>'video','file_id'=>(int)$video['id'], 'poster_id'=>$image->isEmpty()?0:(int)$image['id']];
    S::saveSection('hero', ['config'=>['hero'=>['enabled'=>false,'items'=>$items]], 'revision'=>$revision]);
    $hero = S::public()['hero'];
    check($hero['enabled']===false && count($hero['items'])===count($items), 'animation switch preserves ordered mixed media');
    foreach ($hero['items'] as $item) check($item['url']!=='', 'background media resolves tenant storage');
    $bad = ['hero'=>['items'=>array_fill(0,21,['media_type'=>'image'])], 'cards'=>[]];
    rejects(fn()=>S::normalize($bad));
    rejects(fn()=>S::saveSection('hero', ['config'=>['hero'=>['items'=>[['file_id'=>2147483600]]]], 'revision'=>$revision+1]));
    S::saveSection('hero', ['config'=>['hero'=>['enabled'=>true,'items'=>[]]], 'revision'=>$revision+1]);
    check(S::public()['hero']['items']===[] && S::public()['hero']['url']==='', 'clearing media never resurrects legacy background');
    check(S::public()['hero']['enabled']===true, 'empty media retains dots and sweep animation');
    ConfigService::set('pc_canvas_portal','state', ['config'=>['hero'=>['media_type'=>'image','file_id'=>$image->isEmpty()?0:(int)$image['id'],'poster_id'=>0],'cards'=>[]]]);
    check(count(S::public()['hero']['items'])===($image->isEmpty()?0:1), 'legacy single background becomes an ordered material list');
    echo "PASS revision, validation, tenant scope, material kind and deletion checks\n";
} finally { $request->tenantId=$tenant; Db::rollback(); }
