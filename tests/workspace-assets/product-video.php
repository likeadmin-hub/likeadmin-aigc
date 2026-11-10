<?php
// Local rollback-only fixtures: no model calls, uploads or physical-file deletion.
require dirname(__DIR__,2).'/vendor/autoload.php';
use app\common\service\{WorkspaceAssetService as Library,WorkspaceMediaPickerService as Picker,WorkspaceProductVideoAssetService as Products};
use app\common\service\app\aigc_product_video\ProductVideoPolicy as P;
use app\common\service\power\MarketVideoRuntimeService;
use think\facade\Db;
(new think\App())->initialize();
$config=config('database.connections.mysql');
if(getenv('WORKSPACE_ASSET_TEST')!=='1'||$config['hostname']!=='127.0.0.1'||$config['database']!=='x_cn')throw new RuntimeException('Explicit local fixture opt-in required');
$checks=0;
function pvAssetCheck(bool $ok,string $why): void {global $checks;$checks++;if(!$ok)throw new RuntimeException($why);}
function pvAssetReject(callable $call,string $why): void {try{$call();}catch(InvalidArgumentException){pvAssetCheck(true,$why);return;}throw new RuntimeException($why);}
$tenant=1;$user=1;$owner=['tenant_id'=>$tenant,'user_id'=>$user];
$marker='product-assets-fixture-'.bin2hex(random_bytes(6));
$meta=['storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>'','width'=>720,'height'=>1280,'duration'=>5,'mime_type'=>'video/mp4','file_size'=>1234];
$create=static function(array $videos,array $changes=[])use($owner,$marker){
    $snapshot=['mode'=>'normal','prompt'=>$marker,'images'=>[],'video_selection'=>['model_id'=>'fixture-market-model','quality'=>'720P','duration'=>5,'ratio'=>'9:16']];
    return Db::name('aigc_product_video_job')->insertGetId($changes+$owner+['project_id'=>0,'project_revision'=>1,'action'=>'video','status'=>'success',
        'idempotency_key'=>'pv_asset_'.bin2hex(random_bytes(16)),'input_hash'=>str_repeat('a',64),
        'request_json'=>P::encode(['snapshot'=>$snapshot,'selection'=>$snapshot['video_selection']+['provider_secret'=>'never-expose']]),
        'result_json'=>P::encode(['videos'=>$videos]),'receipt_json'=>'{}','create_time'=>2100000000,'update_time'=>time()]);
};
Db::startTrans();
try{
    $id=$create([['video_uri'=>'uploads/product-fixture-first.mp4']+$meta,['video_uri'=>'uploads/product-fixture-second.mp4']+$meta]);
    $refs=['product_video:task:'.$id.':1','product_video:task:'.$id.':2'];
    $create([['video_uri'=>'uploads/not-a-final-video.mp4']+$meta],['action'=>'plan']);
    $create([['video_uri'=>'uploads/not-completed.mp4']+$meta],['status'=>'running']);
    $create([['video_uri'=>'uploads/deleted.mp4']+$meta],['delete_time'=>time()]);
    $foreign=$create([['video_uri'=>'uploads/foreign.mp4']+$meta],['user_id'=>999999999]);
    $items=Library::taskOutputs($tenant,$user,'product_video',1)['lists'];
    $own=array_values(array_filter($items,static fn($item)=>in_array($item['ref'],$refs,true)));
    pvAssetCheck(count($own)===2,'Every final output must enter the asset library without backfilling or duplicate uploads');
    pvAssetCheck(count(array_filter($items,static fn($item)=>str_contains($item['raw']['prompt'],$marker)))===2,'Helper, pending, deleted and other-owner jobs must not enter assets');
    pvAssetCheck($own[0]['raw']['params']['quality']==='720P'&&!array_key_exists('provider_secret',$own[0]['raw']['params']),'Preview includes only public submitted specifications');
    pvAssetCheck($own[0]['raw']['creation_source']===['app_code'=>'aigc_product_video','task_id'=>(string)$id],'Work source remains the owning product video tool');
    pvAssetReject(fn()=>Library::normalize(['refs'=>['product_video:result:'.$id],'favorite'=>1]),'Product jobs cannot impersonate generic result tables');
    pvAssetReject(fn()=>Library::normalize(['refs'=>['product_video:task:'.$id],'favorite'=>1]),'Multiple outputs require an exact output index');
    Library::update($tenant,$user,['refs'=>$refs,'favorite'=>1]);
    $saved=array_values(array_filter(Library::read($tenant,$user)['supplemental'],static fn($item)=>in_array($item['ref'],$refs,true)));
    pvAssetCheck(count($saved)===2,'Independent favorite states preserve every output reference');
    pvAssetReject(fn()=>Library::update($tenant,$user+1,['refs'=>[$refs[0]],'favorite'=>1]),'Library writes must be user isolated');
    pvAssetReject(fn()=>Library::update($tenant+1,$user,['refs'=>[$refs[0]],'favorite'=>1]),'Library writes must be tenant isolated');
    $page=Picker::page($tenant,$user,['tab'=>'assets','types'=>'video','search'=>$marker]);
    pvAssetCheck(array_column($page['lists'],'id')===$refs,'The shared media picker must include every product output, without helper records');
    $resolved=Picker::resolve($tenant,$user,$refs[1]);
    pvAssetCheck($resolved['uri']==='uploads/product-fixture-second.mp4'&&$resolved['storage_engine']==='local'&&$resolved['width']===720,'Reference resolution must preserve the owned canonical URI and storage metadata');
    pvAssetReject(fn()=>Picker::resolve($tenant,$user+1,$refs[0]),'Picker cannot resolve another user output');
    Library::update($tenant,$user,['refs'=>[$refs[0]],'action'=>'trash']);
    pvAssetCheck(array_column(Picker::page($tenant,$user,['tab'=>'assets','types'=>'video','search'=>$marker])['lists'],'id')===[$refs[1]],'Trashing one output must not hide its sibling');
    pvAssetReject(fn()=>Picker::resolve($tenant,$user,$refs[0]),'Trashed outputs cannot be reused');
    Library::update($tenant,$user,['refs'=>[$refs[0]],'action'=>'restore']);
    pvAssetCheck(Picker::resolve($tenant,$user,$refs[0])['uri']==='uploads/product-fixture-first.mp4','Restoring one output must preserve its stable identity');
    pvAssetCheck((int)Db::name('aigc_product_video_job')->where('id',$id)->value('delete_time')===0,'Library metadata cannot delete the original task or physical media');
    Db::name('aigc_product_video_job')->where('id',$id)->update(['delete_time'=>time()]);
    pvAssetCheck(Products::owned($tenant,$user,$refs[0])===null,'Explicitly deleted source tasks must disappear from saved assets');
    pvAssetReject(fn()=>Picker::resolve($tenant,$user,$refs[0]),'Explicitly deleted source tasks cannot be referenced');

    $many=[];for($i=1;$i<=23;$i++)$many[]=['video_uri'=>'uploads/product-fixture-page-'.$i.'.mp4']+$meta;
    $multi=$create($many);$expected=[];for($i=1;$i<=23;$i++)$expected[]='product_video:task:'.$multi.':'.$i;
    // A saved output also makes an unshelved source discoverable under the existing library contract.
    Library::update($tenant,$user,['refs'=>[$expected[0]],'favorite'=>1]);
    $first=Picker::page($tenant,$user,['tab'=>'assets','types'=>'video','search'=>$marker]);
    $next=Picker::page($tenant,$user,['tab'=>'assets','types'=>'video','search'=>$marker,'cursor'=>$first['next_cursor']]);
    pvAssetCheck(count($first['lists'])===20&&array_column(array_merge($first['lists'],$next['lists']),'id')===$expected,'Pagination must resume inside one multi-output job with no duplicate or missing work');
    $policy=new ReflectionMethod(MarketVideoRuntimeService::class,'requiresDurableResultStorage');
    pvAssetCheck($policy->invoke(null,'aigc_product_video')===true&&$policy->invoke(null,'aigc_short_drama')===true&&$policy->invoke(null,'aigc_video')===false,'Composed product videos require managed storage without changing the native-video storage policy');
    echo "PASS product-video library, owned picker, multi-output pagination, favorites and visibility ($checks checks)\n";
}finally{Db::rollback();}
