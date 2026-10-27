<?php
// Run only against the local development database. Every fixture is rolled back.
require dirname(__DIR__).'/vendor/autoload.php';
$app=new think\App(dirname(__DIR__).'/'); $app->initialize();
use think\facade\Db;
use app\common\service\distribution\PosterService;
use app\common\service\distribution\PosterLayout;
if (!in_array((string)config('database.connections.mysql.hostname'),['127.0.0.1','localhost','host.docker.internal'],true)) throw new RuntimeException('Local database required');
function check($condition,$message){if(!$condition)throw new RuntimeException($message);}
function rejected(callable $f){try{$f();}catch(Exception $e){return;}throw new RuntimeException('Expected rejection');}
$tenant=(int)Db::name('tenant_file')->where('type',10)->where('source',0)->whereNull('delete_time')->value('tenant_id');
$asset=(int)Db::name('tenant_file')->where(['tenant_id'=>$tenant,'type'=>10,'source'=>0])->whereNull('delete_time')->value('id');
if(!$tenant||!$asset)throw new RuntimeException('Need an existing local tenant image fixture');
request()->tenantId=$tenant; request()->source=app\common\enum\AdminTerminalEnum::TENANT;
$layout=['schema_version'=>1,'canvas'=>['width'=>1080,'height'=>1440,'background_asset_id'=>$asset],'layers'=>[
 ['id'=>'code','type'=>'invite_code','x'=>.65,'y'=>.7,'w'=>.25,'h'=>.1875,'z'=>20,'user_move'=>true,'user_resize'=>true,'user_edit'=>false],
 ['id'=>'text','type'=>'text','x'=>.1,'y'=>.1,'w'=>.5,'h'=>.1,'z'=>1,'text'=>'Fixture','font_size'=>32,'color'=>'#333333','user_edit'=>true,'user_move'=>false,'user_resize'=>false]
]];
Db::startTrans();
try {
 $draft=PosterService::save($tenant,['name'=>'POSTER TEST','layout'=>$layout]);check($draft['status']==0,'New template must be draft');
 rejected(fn()=>PosterService::detail($tenant+100000,(int)$draft['id']));
 rejected(fn()=>PosterService::save($tenant,['id'=>$draft['id'],'revision'=>0,'name'=>'Stale','layout'=>$layout]));
 $bad=$layout;$bad['canvas']['background_asset_id']=2147483647;rejected(fn()=>PosterService::save($tenant,['name'=>'Bad asset','layout'=>$bad]));
 $published=PosterService::publish($tenant,1,$draft);check($published['published_version']>0,'Publish snapshot missing');
 $snapshot=Db::name('distribution_poster_version')->where('id',$published['published_version'])->value('layout_json');
 $updated=$layout;$updated['layers'][1]['text']='Changed draft';
 $edited=PosterService::save($tenant,['id'=>$draft['id'],'revision'=>$draft['revision'],'name'=>'Changed','layout'=>$updated]);
 check(Db::name('distribution_poster_version')->where('id',$published['published_version'])->value('layout_json')===$snapshot,'Published snapshot mutated');
 $copy=PosterService::mutate($tenant,'copy',$edited);check($copy['id']!=$edited['id']&&!$copy['published_version']&&!$copy['status'],'Copy must be independent draft');
 PosterService::mutate($tenant,'delete',$copy);rejected(fn()=>PosterService::detail($tenant,(int)$copy['id']));
 $stopped=PosterService::mutate($tenant,'setStatus',array_merge($edited,['status'=>0]));check(PosterService::detail($tenant,(int)$edited['id'])['status']==0,'Disable failed');
 $textEdit=$layout;$textEdit['layers'][1]['text']='User text';PosterLayout::validateUserEdit($layout,$textEdit);
 $textEdit['layers'][1]['x']=.2;rejected(fn()=>PosterLayout::validateUserEdit($layout,$textEdit));
 echo "PASS: tenant isolation, optimistic locking, asset ownership, immutable publish, copy, soft delete, status, edit permissions\n";
} finally {Db::rollback();}
