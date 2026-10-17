<?php
// Disposable physical tables + local files. No provider, queue tick, live tenant writes or billing.
namespace app\common\service\app {
 final class AppAccessService { public static function assertTenantCanUse(...$args){return null;} }
}
namespace app\common\service\storage {
 final class StorageConfigService {
  public static function getEffectiveConfig($t=null):array{return ['scope'=>'tenant','default'=>'local','engine'=>['local'=>[]]];}
  public static function getEffectiveDefault($t=null):string{return 'local';}
  public static function getEffectiveDomain($t=null):string{return '';}
  public static function getStoredFileConfig(...$args):array{return self::getEffectiveConfig();}
  public static function getStorageDomain($c):string{return '';}
  public static function currentTenantId():int{return (int)request()->tenantId;}
 }
}
namespace app\common\service\app\aigc_llm\agent {
 final class AgentComposerSettings {public static function textModel(...$args):string{return 'offline-test';}}
 final class AgentCapabilityCatalog {
  public static function withAudioDefaults($t,$p){return $p;}
  public static function all(...$args):array{return [];}
  public static function models(...$args):array{return [];}
 }
}
namespace {
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
set_exception_handler(static function(\Throwable $e){fwrite(STDERR,get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString());exit(1);});
use think\facade\Db;
use app\common\service\app\aigc_canvas\{CanvasProjectReferenceService as R,CanvasAgentSkillConfigService as CSkills};
use app\common\service\app\aigc_llm\agent\{AgentWorkspaceScope as W,AgentSkillConfigService as Skills,AgentStore as S,AgentExecutionService as E};
use app\common\model\app\{TenantAppConfig};
use app\common\model\app\aigc_llm\AigcLlmConfig;
function check($ok,$message){if(!$ok)throw new \RuntimeException($message);}
function rejected($call){try{$call();}catch(\InvalidArgumentException|\app\common\service\open_platform\OpenException $e){return;}throw new \RuntimeException('Expected isolation rejection');}
$db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&in_array($db['database'],['x_cn','z_cn'],true),'Local isolated tables only');
$cfg=\think\facade\Config::get('database');$old=$cfg['connections']['mysql']['prefix'];$audit='auditrefs_'.getmypid().'_';
$tables=['tenant','app','tenant_app_config','aigc_llm_config','aigc_canvas_project','aigc_canvas_asset','aigc_canvas_project_compat_backup','aigc_short_drama_subject','aigc_short_drama_asset','tenant_file','file','open_platform_file','user_asset_state','aigc_image_result','aigc_video_result','aigc_music_result','aigc_llm_sensitive_word','aigc_llm_session','aigc_llm_message','aigc_llm_agent_run','aigc_llm_agent_step','aigc_llm_agent_event','aigc_llm_agent_artifact','aigc_llm_agent_preference','aigc_llm_agent_context'];
$created=[];$physical=[];
try{
 foreach($tables as $table){Db::execute("CREATE TABLE `{$audit}{$table}` LIKE `{$old}{$table}`");$created[]=$table;}
 $cfg['connections']['mysql']['prefix']=$audit;\think\facade\Config::set($cfg,'database');Db::connect(null,true);check(Db::connect()->getConfig('prefix')===$audit,'Table isolation failed');
 $t=1999999875;$u=$t;Db::name('tenant')->insert(['id'=>$t,'tactics'=>0,'sn'=>'auditrefs','create_time'=>time()]);request()->tenantId=$t;request()->userId=$u;
 $project=function($tenant,$user,$name,$nodes) {return Db::name('aigc_canvas_project')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'name'=>$name,'nodes_json'=>S::encode($nodes),'edges_json'=>'[]','viewport_json'=>S::encode(['pages'=>[['id'=>'p1','name'=>'Page','nodeIds'=>array_column($nodes,'id')]]]),'create_time'=>time(),'update_time'=>time(),'delete_time'=>0]);};
 $a=$project($t,$u,'A',[['id'=>'text_a','type'=>'text','metadata'=>['title'=>'项目 A 正文','content'=>'只能在 A 中读取的文本']]]);
 $b=$project($t,$u,'B',[['id'=>'text_b','type'=>'text','metadata'=>['content'=>'B 内容']]]);
 $foreign=$project($t,$u+1,'foreign',[]);$otherTenant=$project($t+1,$u,'other tenant',[]);
 $scope=['app'=>'aigc_canvas','project_id'=>$a,'page_id'=>'p1'];
 // Avoid a live project compatibility repair: all fixture documents are already native.
 $in=fn($call)=>W::within($scope,$call);
 check($in(fn()=>R::lists($t,$u,[]))['count']===1,'Project nodes not listed');
 check($in(fn()=>R::lists($t,$u,['scope'=>'subjects']))['lists']===[],'Default mention leaked subject library');
 rejected(fn()=>W::within(array_replace($scope,['project_id'=>$foreign]),fn()=>R::lists($t,$u,[])));
 rejected(fn()=>W::within(array_replace($scope,['project_id'=>$otherTenant]),fn()=>R::lists($t,$u,[])));
 rejected(fn()=>W::within(array_replace($scope,['project_id'=>$b]),fn()=>R::resolve($t,$u,['kind'=>'node','id'=>'text_a'])));
 rejected(fn()=>R::normalize([['kind'=>'asset','id'=>'1','url'=>'https://foreign.test/x.png']]));
 $text=$in(fn()=>R::resolve($t,$u,['kind'=>'node','id'=>'text_a']));
 check($text['mime']==='text/plain'&&str_ends_with($text['reference_name'],'.txt'),'Text references need a readable text document');
 $textFile=Db::name('open_platform_file')->where('file_id',$text['file_id'])->find();check(file_get_contents(public_path().$textFile['uri'])==='只能在 A 中读取的文本','Text snapshot bytes missing');
 echo "PASS current-project nodes, text snapshots and account/tenant/project rejection\n";
 $sourceUri='resource/audit-subject-'.getmypid().'.png';$sourcePath=public_path().$sourceUri;$physical[]=$sourcePath;
 if(!is_dir(dirname($sourcePath)))mkdir(dirname($sourcePath),0755,true);
 $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=');file_put_contents($sourcePath,$png);
 $subject=Db::name('aigc_short_drama_subject')->insertGetId(['tenant_id'=>0,'user_id'=>0,'name'=>'公开测试主体','image'=>$sourceUri,'description'=>'导入时说明','source'=>'public','status'=>1,'delete_time'=>0,'create_time'=>time(),'update_time'=>time()]);
 $private=Db::name('aigc_short_drama_subject')->insertGetId(['tenant_id'=>$t,'user_id'=>$u+1,'name'=>'其他账号主体','image'=>$sourceUri,'source'=>'user','status'=>1,'delete_time'=>0]);
 rejected(fn()=>$in(fn()=>R::importSubject($t,$u,['subject_id'=>$private,'request_id'=>'private-import-0123456789'])));
 $request=['subject_id'=>$subject,'request_id'=>'snapshot-import-0123456789'];
 $copy=$in(fn()=>R::importSubject($t,$u,$request));$again=$in(fn()=>R::importSubject($t,$u,$request));check($copy['id']===$again['id'],'Import retry duplicated snapshot');
 $id=(int)$copy['reference']['id'];$row=Db::name('aigc_canvas_asset')->where('id',$id)->find();check($row['uri']!==$sourceUri&&file_get_contents(public_path().$row['uri'])===$png,'Image was linked instead of copied');
 Db::name('aigc_short_drama_subject')->where('id',$subject)->update(['name'=>'改名','description'=>'改说明','image'=>'resource/missing.png','delete_time'=>time()]);unlink($sourcePath);
 $snapshot=$in(fn()=>R::resolve($t,$u,$copy['reference']));check($snapshot['reference_name']==='@公开测试主体'&&$snapshot['reference_description']==='导入时说明','Source changes altered snapshot');
 check(file_get_contents(public_path().$row['uri'])===$png,'Source deletion deleted snapshot');
 $in(fn()=>R::importSubject($t,$u,$request)); // Retry succeeds even when the source is gone.
 rejected(fn()=>W::within(array_replace($scope,['project_id'=>$b]),fn()=>R::resolve($t,$u,$copy['reference'])));
 // An editable asset row cannot grant a foreign owner's private file.
 $secret='uploads/audit-foreign.png';Db::name('tenant_file')->insert(['tenant_id'=>$t,'source_id'=>$u+1,'source'=>1,'uri'=>$secret,'name'=>'private','type'=>10,'storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>'','create_time'=>time()]);
 $fake=Db::name('aigc_canvas_asset')->insertGetId(['tenant_id'=>$t,'user_id'=>$u,'project_id'=>$a,'title'=>'伪造引用','uri'=>$secret,'asset_type'=>'subject','storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>'','status'=>'ready','meta_json'=>'{}','delete_time'=>0]);
 rejected(fn()=>$in(fn()=>R::resolve($t,$u,['kind'=>'asset','id'=>(string)$fake])));
 check($in(fn()=>R::lists($t,$u,['scope'=>'subjects']))['count']===1,'Foreign file exposed in mentions');
 echo "PASS explicit import, independent bytes/metadata, idempotency and forged-file rejection\n";
 // Exercise actual submit without ever executing a worker/provider.
 $params=['request_id'=>'reference-submit-0123456789','content'=>'读取当前项目主体，不生成','preferences'=>['mode'=>'ask','models'=>[]],'project_references'=>[$copy['reference']]];
 $run=$in(fn()=>E::submit($t,$u,$params));check(count($run['attachments'])===1&&$run['attachments'][0]['name']==='@公开测试主体','Project reference not delivered to conversation');
 check($in(fn()=>E::submit($t,$u,$params))['id']===$run['id'],'Retry created another run');
 rejected(fn()=>$in(fn()=>E::submit($t,$u,array_replace($params,['request_id'=>'direct-source-0123456789','project_references'=>[],'subject_ids'=>[$subject]]))));
 // Old run hashes/attachments are unchanged and remain replayable after source deletion.
 $legacy=['request_id'=>'legacy-source-0123456789','session_id'=>0,'content'=>'历史主体消息','attachments'=>[],'subject_ids'=>[$subject],'preferences'=>['mode'=>'ask','models'=>[]]];
 $hash=hash('sha256',S::encode([0,$legacy['content'],[],$scope,[$subject]]));
 $legacyId=S::table('run')->insertGetId(['tenant_id'=>$t,'user_id'=>$u,'session_id'=>$run['session_id'],'message_id'=>0,'request_id'=>$legacy['request_id'],'payload_hash'=>$hash,'content'=>$legacy['content'],'preferences'=>S::encode(['_workspace'=>$scope]),'attachments'=>S::encode([$snapshot]),'status'=>'success','phase'=>'chat','reply'=>'历史回复','create_time'=>time(),'update_time'=>time()]);
 check((int)$in(fn()=>E::submit($t,$u,$legacy))['id']===(int)$legacyId,'Legacy idempotent replay broken');
 check($in(fn()=>E::detail($t,$u,$legacyId))['reply']==='历史回复','Historical dialogue changed');
 echo "PASS actual submit attachment delivery, safe retries, direct-source rejection and legacy dialogue\n";
 // One-time skill compatibility snapshot, then entirely independent CRUD and defaults.
 AigcLlmConfig::create(['tenant_id'=>$t,'config_json'=>['agent_skills'=>['document'=>['name'=>'旧文档技能','prompt'=>'旧回填'],'image'=>['deleted'=>true]]]]);
 $skills=array_column(CSkills::detail($t),null,'id');check($skills['document']['name']==='旧文档技能'&&!isset($skills['image']),'Initial skill migration lost settings/deletions');
 $llm=AigcLlmConfig::where('tenant_id',$t)->find();$llm->save(['config_json'=>['agent_skills'=>['document'=>['name'=>'首页改名','prompt'=>'首页回填']]]]);
 check(array_column(CSkills::detail($t),null,'id')['document']['name']==='旧文档技能','Canvas retained live home dependency');
 $staleDisplay=\app\common\service\app\AppDisplayConfigService::detail($t,'aigc_canvas');
 CSkills::save($t,['skill_configs'=>[['id'=>'document','name'=>'画布文档','prompt'=>'画布回填']]]);
 \app\common\service\app\AppDisplayConfigService::save($t,'aigc_canvas',$staleDisplay);
 check(array_column(CSkills::detail($t),null,'id')['document']['name']==='画布文档','Stale display form overwrote independent skills');
 check(array_column(Skills::detail($t),null,'id')['document']['name']==='首页改名','Canvas save changed home skills');
 check(array_column(CSkills::detail($t+1),null,'id')['document']['name']==='文档创作','Skills leaked between tenants');
 CSkills::save($t,['skill_create'=>['app_code'=>'aigc_llm','name'=>'画布专用','prompt'=>'画布专用提示词']]);
 $custom=array_values(array_filter(CSkills::detail($t),fn($s)=>!$s['builtin']));check(count($custom)===1,'Independent skill create failed');
 rejected(fn()=>CSkills::save($t+1,['skill_configs'=>[['id'=>$custom[0]['id'],'enabled'=>0]]]));
 CSkills::save($t,['skill_delete_id'=>$custom[0]['id']]);check(!array_filter(CSkills::detail($t),fn($s)=>!$s['builtin']),'Independent skill deletion failed');
 echo "PASS skill snapshot migration, independent CRUD, tenant boundaries and home compatibility\n";
}finally{
 foreach(Db::name('tenant_file')->where(['tenant_id'=>1999999875,'source_id'=>1999999875])->column('uri') as $uri)if(str_starts_with($uri,'uploads/aigc_canvas/references/1999999875/'))$physical[]=public_path().$uri;
 foreach(glob(public_path().'uploads/aigc_canvas/references/1999999875/1999999875/*/*/*')?:[] as $path)if(is_file($path))$physical[]=$path;
 foreach($physical as $path)if(is_file($path))unlink($path);
 foreach(array_reverse($created) as $table)Db::execute("DROP TABLE `{$audit}{$table}`");
 $cfg['connections']['mysql']['prefix']=$old;\think\facade\Config::set($cfg,'database');Db::connect(null,true);
}
}
