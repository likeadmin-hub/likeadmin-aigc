<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use think\facade\Db;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use app\common\service\app\aigc_short_drama\canvas_agent\GraphService as Graph;
function rejectsGraph(callable $action,string $code): void {try{$action();}catch(RuntimeException $e){agentCheck($e->getMessage()===$code,$code);return;}throw new RuntimeException('Expected '.$code);}
Db::startTrans();
try {
 $doc=Canvas::create(91001,92001,['title'=>'P1 isolated graph']);$id=$doc['id'];
 $add=['request_key'=>'p1-add','expected_revision'=>0,'operations'=>[['op'=>'add_node','node'=>['id'=>1,'type'=>'text','x'=>10,'y'=>20,'metadata'=>['content'=>'original']]]]];
 $first=Graph::patch(91001,92001,$id,$add);
 for($i=0;$i<10;$i++)agentCheck(Graph::patch(91001,92001,$id,$add)===$first,'G03 same receipt replay '.$i);
 agentCheck(Db::name(Graph::RECEIPTS)->where('canvas_id',$id)->count()===1,'G03 one receipt');
 $different=$add;$different['operations'][0]['node']['metadata']['content']='changed';
 rejectsGraph(fn()=>Graph::patch(91001,92001,$id,$different),'IDEMPOTENCY_CONFLICT');
 $stale=$add;$stale['request_key']='stale';
 rejectsGraph(fn()=>Graph::patch(91001,92001,$id,$stale),'VERSION_CONFLICT');
 rejectsGraph(fn()=>Graph::patch(91002,92001,$id,$add),'CANVAS_NOT_FOUND');
 $bad=['request_key'=>'forged','expected_revision'=>1,'operations'=>[['op'=>'add_node','node'=>['id'=>2,'type'=>'image','metadata'=>['status'=>'success','asset_owner'=>92002,'cost'=>0]]]]];
 rejectsGraph(fn()=>Graph::patch(91001,92001,$id,$bad),'SERVER_FIELD_FORBIDDEN');
 $overflow=['request_key'=>'overflow','expected_revision'=>1,'operations'=>[]];
 for($i=2;$i<=201;$i++)$overflow['operations'][]=['op'=>'add_node','node'=>['id'=>$i,'type'=>'text','metadata'=>[]]];
 rejectsGraph(fn()=>Graph::patch(91001,92001,$id,$overflow),'CANVAS_CAPACITY_EXCEEDED');
 agentCheck((int)Db::name(Graph::TABLE)->where('id',$id)->value('graph_revision')===1,'G08 overflow rolls back whole mutation');
 $edit=['request_key'=>'edit','expected_revision'=>1,'operations'=>[['op'=>'update_node_content','node_id'=>'1','expected_content_revision'=>1,'patch'=>['content'=>'edited']]]];
 $edited=Graph::patch(91001,92001,$id,$edit);
 agentCheck($edited['nodes'][0]['metadata']['content_revision']===2,'content revision increments');
 agentCheck($edited['nodes'][0]['x']===10,'content edit preserves layout');
} finally {Db::rollback();}
echo "NOT_RUN P1 integrated save/background writers, parallel processes, browser CAS, generation idempotency and recovery\n";
