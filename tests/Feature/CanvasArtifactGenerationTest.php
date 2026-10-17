<?php
// Local transaction fixtures only: no providers, billing mutations or physical media.
require dirname(__DIR__,2).'/vendor/autoload.php'; (new think\App())->initialize();
use think\facade\Db;
use app\common\service\app\aigc_llm\agent\{AgentStore as S,AgentArtifactService as Artifacts};
use app\common\service\app\aigc_canvas\{AigcCanvasService as Canvas,CanvasConversationTools as Tools,CanvasProjectCompatibility as Compatibility,CanvasArtifactGeneration as Generation};
use app\common\service\open_platform\OpenStore;
set_exception_handler(static function(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);});
$checks=0;function check($ok,$message){global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
$db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&in_array($db['database'],['x_cn','z_cn'],true),'Local rollback only');
Db::startTrans();try {
    $tenant=1999999871;$user=$tenant;$owner=['tenant_id'=>$tenant,'user_id'=>$user];
    $p=Canvas::createProject($tenant,$user,['name'=>'artifact metadata fixture','viewport'=>['pages'=>[['id'=>'p1','name'=>'One','nodeIds'=>[]],['id'=>'p2','name'=>'Two','nodeIds'=>[]]],'currentCanvasId'=>'p2']]);
    $scope=['app'=>'aigc_canvas','project_id'=>$p['id'],'page_id'=>'p1'];
    $runId=S::table('run')->insertGetId($owner+['session_id'=>$tenant,'message_id'=>0,'request_id'=>bin2hex(random_bytes(12)),'payload_hash'=>hash('sha256','fixture'),'content'=>'Plan title is not the submitted prompt','preferences'=>S::encode(['_workspace'=>$scope]),'attachments'=>'[]','status'=>'success','phase'=>'done','reply'=>'','create_time'=>time(),'update_time'=>time()]);
    $run=S::owner('run',$tenant,$user)->where('id',$runId)->find();$storage=['storage_scope'=>'platform','storage_engine'=>'local','storage_domain'=>''];
    $ids=[];
    foreach (['video','image'] as $kind) {
        $refs=$kind==='video' ? [['type'=>'image','url'=>'https://example.com/one.png','role'=>'first_frame_image'],['type'=>'image','url'=>'https://example.com/one.png','role'=>'last_frame_image'],['type'=>'audio','url'=>'https://example.com/music.mp3','role'=>'reference_audio']] : [];
        $base=$owner+['prompt'=>'Actual '.$kind.' submitted prompt @图片1','negative_prompt'=>'bad quality','channel'=>'market_'.$kind.'_model:123','model'=>'actual-native-model','quality'=>$kind==='video'?'720p':'2k','ratio'=>'9:16','quantity'=>$kind==='video'?1:2,'reference_images'=>S::encode(['https://example.com/one.png']),'status'=>'success','create_time'=>time(),'delete_time'=>0];
        if($kind==='video')$base+=['duration'=>10,'reference_assets'=>S::encode($refs),'model_json'=>S::encode(['model_id'=>'market_video_model:123','generation_method'=>'start_end','q4_options'=>['audio'=>false,'seed'=>0],'secret'=>'NEVER_COPY','api_key'=>'NEVER_COPY']),'market_product_id'=>123,'market_sku_id'=>456];
        else $base['provider_params_json']=S::encode(['seed'=>0,'api_key'=>'NEVER_COPY','market_sku_id'=>789]);
        $task=Db::name('aigc_'.$kind.'_task')->insertGetId($base);
        $step=S::table('step')->insertGetId($owner+['run_id'=>$runId,'step_key'=>hash('sha256',$kind),'title'=>'Misleading title/default 5 sec','tool_code'=>'aigc_'.$kind,'task_id'=>$task,'status'=>'success','prepared'=>S::encode(['params'=>['prompt'=>'Proposal prompt is wrong','duration'=>5]]),'result'=>'{}','create_time'=>time(),'update_time'=>time()]);
        $file='of_'.bin2hex(random_bytes(16));OpenStore::table('file')->insert($owner+$storage+['file_id'=>$file,'uri'=>'uploads/fixture-'.$kind.'.'.($kind==='video'?'mp4':'png'),'name'=>'fixture','mime'=>$kind==='video'?'video/mp4':'image/png','size'=>0]+OpenStore::timestamp());
        $artifact=S::table('artifact')->insertGetId($owner+$storage+['session_id'=>$tenant,'run_id'=>$runId,'step_id'=>$step,'identity_hash'=>hash('sha256',$file),'title'=>'Fixture','kind'=>$kind,'data'=>S::encode(['file_id'=>$file]),'create_time'=>time()]);
        Tools::artifact($run,$artifact,$file,$kind,'Saved title');Tools::artifact($run,$artifact,$file,$kind,'Saved title');
        $row=Db::name('aigc_canvas_project')->where('id',$p['id'])->find();$nodes=S::json($row['nodes_json']);$node=end($nodes);$meta=$node['metadata'];$ids[]=$node['id'];
        check($meta['prompt']===$base['prompt']&&$meta['submittedPrompt']===$base['prompt'],'Prompt came from artifact URL/title/proposal');
        check($meta['channel']===$base['channel']&&$meta['quality']===$base['quality']&&$meta['ratio']==='9:16','Actual model/settings missing');
        check(count($meta['referenceImages'])===($kind==='video'?2:1),'Reference slots lost or duplicated');
        check(!str_contains(S::encode($meta),'NEVER_COPY'),'Private provider/model metadata leaked');
        if($kind==='video') {
            check($meta['duration']===10&&$meta['resolution']==='720P'&&$meta['market_sku_id']===456,'Video task settings replaced by defaults');
            check($meta['generation_method']==='start_end'&&$meta['referenceImages'][1]['role']==='last_frame_image'&&count($meta['referenceAudios'])===1,'Multimodal roles not retained');
            check($meta['agentGeneration']['options']['audio']===false&&$meta['agentGeneration']['options']['seed']===0,'False/zero native options lost');
        } else check($meta['quantity']===2&&$meta['market_sku_id']===789,'Image quantity/SKU not restored');
        // Reproduce the old node bug, then upgrade without changing page, media or geometry.
        $broken=$nodes;unset($broken[array_key_last($broken)]['metadata']['agentGeneration'],$broken[array_key_last($broken)]['metadata']['submittedPrompt']);
        $broken[array_key_last($broken)]['metadata']['prompt']='';$broken[array_key_last($broken)]['metadata']['referenceImages']=[];$broken[array_key_last($broken)]['metadata']['duration']=5;
        $old=array_replace($row,['nodes_json'=>$broken]);$fixed=Compatibility::adapt($old);$restored=end($fixed['nodes_json']);
        check($restored['metadata']['prompt']===$base['prompt']&&count($restored['metadata']['referenceImages'])>0,'Old result node not recovered');
        check($restored['position']===$node['position']&&$restored['width']===$node['width']&&$restored['metadata']['url']===$meta['url'],'Recovery changed media or geometry');
        check(Compatibility::adapt($fixed)===$fixed,'Recovery is not idempotent');
        foreach (['tenant_id','user_id','id'] as $key) { $foreign=$old;$foreign[$key]++;check(Compatibility::adapt($foreign)['nodes_json']===$broken,'Recovery crossed owner/project boundary'); }
        $wrongPage=$old;$vp=S::json($old['viewport_json']);$vp['pages'][0]['nodeIds']=[];$vp['pages'][1]['nodeIds']=array_column($broken,'id');$wrongPage['viewport_json']=$vp;
        check(Compatibility::adapt($wrongPage)['nodes_json']===$broken,'Recovery crossed page boundary');
        $edited=$old;$edited['nodes_json'][array_key_last($broken)]['metadata']['prompt']='User edited prompt';check(Compatibility::adapt($edited)['nodes_json']===$edited['nodes_json'],'Recovery overwrote user edits');
        // Persisted upgrade backs up the old graph and fences already-open tabs.
        Db::name('aigc_canvas_project')->where('id',$p['id'])->update(['nodes_json'=>S::encode($broken)]);
        $prior=S::json($row['viewport_json'])['agentRevision']??0;$upgraded=Compatibility::upgrade($old);
        check((S::json($upgraded['viewport_json'])['agentRevision']??0)===$prior+1,'Old tab revision not fenced');
        check(Db::name('aigc_canvas_project_compat_backup')->where($owner+['project_id'=>$p['id']])->count()===1,'Missing backup before repair');
        check($upgraded['update_time']===$old['update_time'],'Repair rewrote user timestamp');
        Db::name('aigc_'.$kind.'_task')->where('id',$task)->update(['user_id'=>$user+1]);
        check(Compatibility::adapt($old)['nodes_json']===$broken,'Foreign task leaked submitted prompt');
        Db::name('aigc_'.$kind.'_task')->where('id',$task)->update(['user_id'=>$user]);
    }
    $final=Canvas::projectDetail($tenant,$user,$p['id']);check(count($final['nodes'])===2,'Duplicate result nodes');check($final['viewport']['pages'][1]['nodeIds']===[]&&$final['viewport']['currentCanvasId']==='p2','Another page changed');
    echo "Canvas artifact generation: $checks checks passed; all fixtures rolled back, no model or media generation\n";
} finally { Db::rollback(); }
