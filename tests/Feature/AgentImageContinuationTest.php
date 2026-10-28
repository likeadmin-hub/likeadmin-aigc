<?php
// Offline transport; real context/tool authorization, transactional fixture data only.
namespace app\common\service\power {
 final class MarketTextModelRuntimeService {
  public static array $requests=[];
  public static function resolveAgentModel(...$args):array{return ['context_window'=>128000];}
  public static function resolveModel(...$args):array{throw new \InvalidArgumentException('No external enhancement model');}
  public static function modelGroups(...$args):array{return [];}
  public static function generate(int $tenant,int $user,array $params,?callable $onEvent=null):array{
   self::$requests[]=$params;return ['content'=>'已读取现有参考图，未执行生成。'];
  }
 }
}
namespace {
require dirname(__DIR__,2).'/vendor/autoload.php';(new \think\App())->initialize();
set_exception_handler(static function(\Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString());exit(1);});
use think\facade\Db;
use app\common\model\app\aigc_llm\{AigcLlmSession,AigcLlmMessage};
use app\common\service\app\aigc_llm\agent\{AgentStore as S,AgentContextService as C,AgentTopicService as T,AgentExecutionService as E,AgentWorkspaceScope as W};
use app\common\service\app\aigc_canvas\{CanvasProjectReferenceService as R,CanvasConversationTools as Tools};
use app\common\service\open_platform\{OpenStore,OpenAssets};
use app\common\service\power\MarketTextModelRuntimeService as Fake;
function check($ok,$message){if(!$ok)throw new \RuntimeException($message);}
function denied($call){try{$call();}catch(\InvalidArgumentException|\app\common\service\open_platform\OpenException){return;}throw new \RuntimeException('Expected access rejection');}
$db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&in_array($db['database'],['x_cn','z_cn'],true),'Local rollback test only');
Db::startTrans();try{
 $t=1999999867;$u=$t;$now=time();
 $session=static fn($scope=[])=>AigcLlmSession::create(['tenant_id'=>$t,'user_id'=>$u,'title'=>'image continuity','workspace_key'=>W::key($scope),'model_code'=>'fixture','status'=>'idle','delete_time'=>0,'create_time'=>$now,'update_time'=>$now])->id;
 $make=static function($sid,$content,$scope=[],$attachments=[])use($t,$u,$now){
  $base=['tenant_id'=>$t,'user_id'=>$u,'session_id'=>$sid,'create_time'=>$now,'update_time'=>$now,'delete_time'=>0,'status'=>'done','token_usage_json'=>[],'finish_reason'=>''];
  $m=AigcLlmMessage::create($base+['role'=>'user','content'=>$content,'seq'=>1,'parent_user_message_id'=>0]);
  $a=AigcLlmMessage::create($base+['role'=>'assistant','content'=>'','seq'=>2,'parent_user_message_id'=>$m['id']]);
  $id=S::table('run')->insertGetId(['tenant_id'=>$t,'user_id'=>$u,'session_id'=>$sid,'message_id'=>$a['id'],'request_id'=>'image-recall-'.bin2hex(random_bytes(10)),'payload_hash'=>str_repeat('a',64),'content'=>$content,'preferences'=>S::encode(['mode'=>'ask','models'=>['text'=>'fixture'],'_workspace'=>$scope]),'attachments'=>S::encode($attachments),'status'=>'success','phase'=>'chat','reply'=>'','create_time'=>$now,'update_time'=>$now]);
  return S::run($t,$u,(int)$id);
 };
 $file=static function($suffix,$owner=null)use($t,$u){
  $id='of_'.bin2hex(random_bytes(16));
  OpenStore::table('file')->insert(['tenant_id'=>$t,'user_id'=>$owner??$u,'file_id'=>$id,'uri'=>'uploads/image-continuation-'.$suffix.'.png','name'=>$suffix.'.png','mime'=>'image/png','size'=>1,'storage_scope'=>'platform','storage_engine'=>'local','storage_domain'=>'']+OpenStore::timestamp());
  return $id;
 };
 $image=$file('first');$otherImage=$file('second');$foreignImage=$file('foreign',$u+1);
 $rootSession=$session();$root=T::resolve($make($rootSession,'生成樱花树下情侣的照片'),fn()=>['action'=>'new']);
 S::table('artifact')->insert(['tenant_id'=>$t,'user_id'=>$u,'session_id'=>$rootSession,'run_id'=>$root['id'],'step_id'=>0,'identity_hash'=>hash('sha256',$image),'title'=>'樱花情侣','kind'=>'image','data'=>S::encode(['file_id'=>$image]),'create_time'=>$now]);
 C::created($root,$image);
 // The former classifier decision was new; source-backed continuity must override it.
 $follow=T::resolve($make($rootSession,'可以用这张图片给我生成一段5秒的视频吗？'),fn()=>['action'=>'new']);
 check(T::state($follow)['root_run_id']===$root['id'],'Image-to-video request started a new topic');
 check(C::build($follow)['focus']['file_ids']===[$image],'Generated image not restored to context');
 check(C::authorizedAsset($follow,$image)['file_id']===$image,'Generated reference cannot be used by tools');
 $chat=new \ReflectionMethod(E::class,'chat');
 $transport=static function($run,$expected)use($t,$u,$chat){
  Fake::$requests=[];S::update($run,['status'=>'running','lease_token'=>'image-test','lease_until'=>time()+1500]);
  $run=S::run($t,$u,(int)$run['id']);W::within(W::fromRun($run),fn()=>$chat->invoke(null,$run));
  check(count(Fake::$requests)===1,'Unexpected extra model call');
  $images=[];foreach(Fake::$requests[0]['messages'] as $m)foreach(is_array($m['content'])?$m['content']:[] as $block)if(($block['type']??'')==='image_url')$images[]=$block['image_url']['url'];
  check($images===[$expected]&&!empty(Fake::$requests[0]['requires_vision']),'Image missing at final multimodal transport');
  check(S::table('step')->where('run_id',$run['id'])->count()===0,'Read-only chat submitted a generation task');
 };
 $transport($follow,OpenAssets::detail($t,$u,$image)['url']);
 // A historical mistaken split can be traversed, without rewriting its public answer.
 $broken=$make($rootSession,'可以用这张图片生成视频吗？');$p=S::json($broken['preferences']);$p['_topic']=['decision'=>'new','root_run_id'=>$broken['id']];S::update($broken,['preferences'=>S::encode($p),'reply'=>'旧版本未找到图片']);
 $retry=T::resolve($make($rootSession,'请用这张图片继续'),fn()=>['action'=>'new']);
 check(T::state($retry)['root_run_id']===$root['id'],'Historical mistaken split still hides image');
 $reset=T::resolve($make($rootSession,'重新开始，不用这张图片'),fn()=>['action'=>'continue','topic_id'=>$root['id']]);
 check(T::state($reset)['decision']==='new'&&C::build($reset)['focus_assets']===[],'Explicit reset inherited old image');
 $unrelated=T::resolve($make($rootSession,'写会议通知'),fn()=>['action'=>'new']);
 $ambiguous=T::resolve($make($rootSession,'分析这张图片'),fn()=>['action'=>'clarify']);
 check(T::state($ambiguous)['decision']==='clarify','Reference crossed unrelated topic');
 $upload=T::resolve($make($rootSession,'分析这张图片',[],[['file_id'=>$otherImage]]),fn()=>['action'=>'new']);
 check(C::build($upload)['focus']['file_ids']===[$otherImage],'Explicit upload lost priority');
 $isolated=T::resolve($make($session(),'用这张图片生成视频'),fn()=>['action'=>'new']);
 check(C::build($isolated)['focus_assets']===[],'Image crossed sessions');denied(fn()=>C::readAsset($isolated,$image));
 echo "PASS home: generated-image continuation, transport image_url, old split recovery, resets, uploads and session isolation\n";

 $nodes=[['id'=>'native','type'=>'image','metadata'=>['image'=>'uploads/image-continuation-first.png']],['id'=>'elsewhere','type'=>'image','metadata'=>['image'=>'uploads/image-continuation-second.png']],['id'=>'forged','type'=>'image','metadata'=>['image'=>'uploads/image-continuation-foreign.png','agentFileId'=>$foreignImage]],['id'=>'empty','type'=>'image','metadata'=>[]]];
 $viewport=['pages'=>[['id'=>'p1','name'=>'One','nodeIds'=>['native','forged','empty']],['id'=>'p2','name'=>'Two','nodeIds'=>['elsewhere']]]];
 $project=Db::name('aigc_canvas_project')->insertGetId(['tenant_id'=>$t,'user_id'=>$u,'name'=>'media recall','nodes_json'=>S::encode($nodes),'edges_json'=>'[]','viewport_json'=>S::encode($viewport),'create_time'=>$now,'update_time'=>$now,'delete_time'=>0]);
 $scope=['app'=>'aigc_canvas','project_id'=>$project,'page_id'=>'p1'];$sid=$session($scope);
 $canvas=T::resolve($make($sid,'分析这张图，反推提示词。',$scope),fn()=>['action'=>'new']);
 $before=Db::name('aigc_canvas_project')->where('id',$project)->find();
 $media=R::pageMedia($canvas);check(array_keys($media)===['native'],'Page resolver exposed foreign or other-page media');
 $page=Tools::operate($canvas,['action'=>'read_page']);$pageIndex=array_column($page['nodes'],null,'id');
 $native=$media['native']['file_id'];check($pageIndex['native']['file_id']===$native&&$pageIndex['forged']['file_id']==='','Native node file missing or forged file exposed');
 check(C::build($canvas)['focus']['file_ids']===[$native],'Unique canvas image not available without reupload');
 check(C::readAsset($canvas,$native)['url']===$media['native']['url'],'Canvas tool cannot read resolved node');
 $transport($canvas,$media['native']['url']);
 check(Db::name('aigc_canvas_project')->where('id',$project)->find()===$before,'Reading media changed saved canvas');
 denied(fn()=>C::readAsset($canvas,$foreignImage));denied(fn()=>C::readAsset($canvas,$otherImage));
 denied(fn()=>R::pageMedia(array_replace($canvas,['tenant_id'=>$t+1])));denied(fn()=>R::pageMedia(array_replace($canvas,['user_id'=>$u+1])));
 $wrong=$canvas;$prefs=S::json($wrong['preferences']);$prefs['_workspace']['page_id']='p2';$wrong['preferences']=S::encode($prefs);denied(fn()=>R::pageMedia($wrong));
 // The same native image on two nodes is one candidate; two distinct images require choice.
 $viewport['pages'][0]['nodeIds'][]='elsewhere';Db::name('aigc_canvas_project')->where('id',$project)->update(['viewport_json'=>S::encode($viewport)]);
 $many=T::resolve($make($session($scope),'分析这张图片',$scope),fn()=>['action'=>'new']);
 $context=C::build($many);check(count($context['page_assets'])===2&&$context['focus_assets']===[],'Multiple canvas images guessed a focus');
 $explicit=T::resolve($make($sid,'重新开始，分析这张图片',$scope,[['file_id'=>$otherImage]]),fn()=>['action'=>'new']);
 check(C::build($explicit)['focus']['file_ids']===[$otherImage],'Canvas page overrode explicit attachment');
 $viewport['pages'][0]['nodeIds']=[];Db::name('aigc_canvas_project')->where('id',$project)->update(['viewport_json'=>S::encode($viewport)]);
 denied(fn()=>C::readAsset($canvas,$native));check(C::build(S::run($t,$u,$canvas['id']))['focus_assets']===[],'Deleted node stayed authorized through cached focus');
 echo "PASS canvas: native images, page tools, multimodal transport, graph unchanged, ambiguity, attachment priority and live ownership/page checks\n";
}finally{Db::rollback();}
}
