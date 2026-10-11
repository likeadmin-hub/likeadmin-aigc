<?php
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\OpenStore as S;
use app\common\service\open_platform\OpenToolRegistry as R;
use app\common\service\open_platform\OpenToolAdapter as A;
use app\common\service\open_platform\OpenToolResourceOwnership as Ownership;
use app\common\model\app\aigc_music\AigcMusicAsset as Asset;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$db=config('database.connections.mysql');check($db['hostname']==='127.0.0.1'&&$db['database']==='z_cn','Local rollback fixtures only');
Db::startTrans();try{
    foreach([0,1] as $t){$config=S::settings($t);$config['apps'][]='aigc_music';app\common\service\open_platform\OpenManagement::persistSettings($t,true,$config);}
    request()->tenantId=1;request()->userId=1;request()->userInfo=['tenant_id'=>1,'user_id'=>1,'terminal'=>4];request()->source=app\common\enum\AdminTerminalEnum::USER;
    $owned=Asset::create(['tenant_id'=>1,'user_id'=>1,'title'=>'fixture','uri'=>'uploads/opf-music-fixture.mp3','url'=>'https://example.com/opf-music-fixture.mp3','storage_scope'=>'tenant','storage_engine'=>'local','status'=>1,'delete_time'=>0]+S::timestamp());
    $foreign=Asset::create(['tenant_id'=>1,'user_id'=>2,'title'=>'foreign fixture','uri'=>'uploads/opf-foreign.mp3','status'=>1,'delete_time'=>0]+S::timestamp());
    $file=app\common\model\file\TenantFile::create(['tenant_id'=>1,'source_id'=>1,'source'=>app\common\enum\FileEnum::SOURCE_USER,'uri'=>'uploads/opf-owned-audio.mp3','storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>'','name'=>'Fixture audio','create_time'=>time()]);
    $registered=app\common\service\app\aigc_music\AigcMusicAssetService::registerExisting(1,1,['uri'=>$file['uri'],'storage_scope'=>'platform','storage_engine'=>'aliyun','storage_domain'=>'https://127.0.0.1']);
    check($registered['storage_scope']==='tenant'&&$registered['storage_engine']==='local'&&$registered['storage_domain']==='','Registered audio accepted client storage credentials or scope');
    $denied=false;try{app\common\service\app\aigc_music\AigcMusicAssetService::registerExisting(1,2,['uri'=>$file['uri']]);}catch(app\common\service\open_platform\OpenException $e){$denied=$e->httpStatus===404;}check($denied,'Audio registration accepted foreign file');
    foreach(['source_asset_id','source_id','asset_id','reference_asset_id','ref_asset_id','voice_asset_id'] as $field){
        $op=['app_code'=>'aigc_music_cover','controller'=>'Generate'];Ownership::check(1,1,$op,[$field=>(int)$owned['id']]);
        foreach([[1,1,(int)$foreign['id']],[2,1,(int)$owned['id']],[1,2,(int)$owned['id']]] as [$tenant,$user,$id]){$denied=false;try{Ownership::check($tenant,$user,$op,[$field=>$id]);}catch(app\common\service\open_platform\OpenException $e){$denied=$e->httpStatus===404;}check($denied,'Music cover asset alias bypassed owner: '.$field);}
    }
    $op=R::match('POST','/api/v1/tools/aigc_music/persona/create');check(!$op['billable'],'Local profile creation treated as paid generation');
    $wallets=fn()=>[(string)Db::name('user')->where('id',1)->value('user_money'),(string)Db::name('tenant')->where('id',1)->value('point_balance')];$before=$wallets();
    $params=['name'=>'Synthetic test profile','reference_asset_id'=>(int)$owned['id'],'authorization_confirmed'=>1];app\common\service\open_platform\OpenSchema::validate($params,$op['input']);
    $budget=A::budget(request(),$op,$params);check($budget['user']===0&&$budget['tenant']===0,'Free local profile consumed generation budget');
    $result=A::call(request(),$op,$params);check((int)$result['tenant_id']===1&&(int)$result['user_id']===1&&(int)$result['reference_asset_id']===(int)$owned['id'],'Local native profile flow did not preserve ownership');
    $denied=false;try{A::call(request(),$op,array_replace($params,['reference_asset_id'=>(int)$foreign['id']]));}catch(app\common\service\open_platform\OpenException $e){$denied=$e->httpStatus===404;}check($denied,'Native profile linked foreign audio');
    check($wallets()===$before,'Local profile created a charge');
    echo "PASS 6 numeric music-cover aliases and native profile references enforce tenant/user ownership; documented local profile creates without generation budget or charge\n";
}finally{Db::rollback();}
