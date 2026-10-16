<?php
require dirname(__DIR__,4).'/vendor/autoload.php';
use app\common\service\app\aigc_canvas\{CanvasProjectCompatibility as Compat,CanvasHistoryImportService as History};
$n=0;$check=static function($ok,$why)use(&$n){$n++;if(!$ok)throw new RuntimeException($why);};
$old=['id'=>31,'tenant_id'=>7,'user_id'=>9,'name'=>'保留项目','nodes_json'=>[
 ['id'=>'subject1','type'=>'subject','position'=>['x'=>10,'y'=>20],'data'=>['threeViewImage'=>'/assets/a.png','taskId'=>77,'storage_scope'=>'tenant','storage_engine'=>'local','custom'=>['keep'=>true]]],
 ['id'=>'script1','type'=>'script','data'=>['outputContent'=>'原剧本文字']],
 ['id'=>'music1','type'=>'music','data'=>['audio_uri'=>'/music.mp3']],
], 'edges_json'=>[['id'=>'e1','source'=>'subject1','target'=>'script1']], 'viewport_json'=>['x'=>12,'y'=>13,'k'=>.8], 'create_time'=>123];
$new=Compat::adapt($old);
$check($new['id']===31 && $new['create_time']===123,'project identity changed');
$check(array_column($new['nodes_json'],'id')===['subject1','script1','music1'],'node identities changed');
$check(array_column($new['nodes_json'],'type')===['image','text','audio'],'legacy types not adapted');
$check($new['nodes_json'][0]['metadata']['taskId']===77 && $new['nodes_json'][0]['metadata']['custom']['keep'],'task or metadata lost');
$check($new['nodes_json'][1]['metadata']['content']==='原剧本文字','text output lost');
$check($new['edges_json'][0]['fromNodeId']==='subject1','edge source lost');
$check($new['viewport_json']['pages'][0]['nodeIds']===['subject1','script1','music1'],'page membership lost');
$check(Compat::adapt($new)===$new,'adaptation is not idempotent');
$modern=$new;$modern['viewport_json']=['currentCanvasId'=>'b','agentRevision'=>6,'pages'=>[['id'=>'a','name'=>'A','nodeIds'=>['subject1']],['id'=>'b','name'=>'B','nodeIds'=>['script1','music1']]]];
$check(Compat::adapt($modern)===$modern,'modern project modified');
$unindexed=$modern;$unindexed['viewport_json']['pages'][1]['nodeIds']=['script1'];
$check(Compat::adapt($unindexed)['viewport_json']['pages'][1]['nodeIds']===['script1','music1'],'unindexed legacy node lost');
$reply=History::message(['role'=>'assistant','content'=>'{"tool_calls":[{}]}','content_json'=>['assistant_reply'=>'这是历史回复'],'status'=>'success']);
$check($reply['content']==='这是历史回复' && $reply['status']==='done','assistant prose not preserved');
$pending=History::message(['role'=>'assistant','content'=>'处理中','status'=>'running']);
$check($pending['status']==='stopped' && str_contains($pending['content'],'未自动恢复'),'old task falsely resumed');
$check(History::message(['role'=>'user','content'=>'{"这是":"用户原文"}'])['content']==='{"这是":"用户原文"}','user content changed');
echo "Project compatibility: $n checks passed; no database, provider or billing calls\n";
