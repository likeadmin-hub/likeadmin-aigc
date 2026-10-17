<?php
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
set_exception_handler(static function(\Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);});
use app\common\service\app\aigc_canvas\{CanvasBuiltinPresetService as Builtin,CanvasTemplateService as Templates};
use app\common\service\CanvasPortalService as Portal;
use app\common\service\ConfigService;
use think\facade\Db;
$n=0;$check=static function($ok,$why)use(&$n){if(!$ok)throw new RuntimeException($why);$n++;};
$reject=static function($fn)use($check){try{$fn();}catch(InvalidArgumentException $e){$check(true,'rejected');return;}throw new RuntimeException('Invalid builtin accepted');};
$fixture=['schema_version'=>1,'id'=>'builtin-fixture','version'=>1,'title'=>'Fixture','cover'=>'https://cdn.example.com/a.png','graph'=>['nodes'=>[['id'=>'n','type'=>'image','position'=>['x'=>0,'y'=>0],'metadata'=>['model_id'=>987,'taskId'=>'private','image'=>'https://cdn.example.com/a.png','prompt'=>'test']]],'edges'=>[]]];
$entry=Builtin::validate($fixture);
$check(isset($entry['graph']['nodes'][0]['metadata']['mediaItems'][0]['url']),'Builtin media missing');
$check(!isset($entry['graph']['nodes'][0]['metadata']['model_id'],$entry['graph']['nodes'][0]['metadata']['taskId'],$entry['graph']['nodes'][0]['metadata']['mediaItems'][0]['template_file']),'Runtime ownership or model IDs shipped');
foreach(['http://s.cn/a.png','https://localhost/a.png','https://cdn.example.com/a.png?token=secret','https://127.0.0.1/a.png','https://user:password@cdn.example.com/a.png']as$url)$reject(static fn()=>Builtin::publicUrl($url));
$bad=$fixture;$bad['id']='tenant-id';$reject(static fn()=>Builtin::validate($bad));
$db=config('database.connections.mysql');$check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&in_array($db['database'],['x_cn','z_cn'],true),'Local rollback only');
request()->source=app\common\enum\AdminTerminalEnum::TENANT;request()->tenantId=1;
Db::startTrans();
try{
 $builtins=Builtin::cards();
 $check(count($builtins)>0,'No source-delivered presets');
 ConfigService::set('pc_canvas_portal','state',['config'=>['hero'=>['media_type'=>'image'],'cards'=>[]],'revision'=>1]);
 $check(count(Portal::public()['cards'])===count($builtins),'Explicit empty tenant catalog overwrites builtin catalog');
 $reject(static fn()=>Portal::normalize(['hero'=>[],'cards'=>[$builtins[0]]]));
 $other=Db::name('tenant')->insertGetId(['sn'=>'bp'.bin2hex(random_bytes(4)),'name'=>'Builtin isolation fixture','tactics'=>0,'create_time'=>time()]);
 request()->tenantId=$other;
 $ids=array_column(Portal::public()['cards'],'id');
 $check(in_array($builtins[0]['id'],$ids,true),'Fresh tenant without seed rows lacks builtin');
 $p=Templates::apply($other,1999999755,['template_id'=>$builtins[0]['id'],'request_id'=>'builtin-isolation-request-0001']);
 $check(count($p['nodes'])===$builtins[0]['node_count'],'Builtin instantiation failed');
 $before=$p['nodes'];$again=Templates::apply($other,1999999755,['template_id'=>$builtins[0]['id'],'request_id'=>'builtin-isolation-request-0001']);
 $check($again['id']===$p['id']&&$again['nodes']==$before,'Builtin retry creates or changes project');
 $check(empty(ConfigService::get('pc_canvas_portal','state',[])),'Builtin read mutates tenant config');
 echo "PASS $n source catalog, public media, portable identity, fresh-tenant visibility, idempotency and readonly checks; database rolled back\n";
}finally{request()->tenantId=1;Db::rollback();}
