<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use think\facade\Db;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use app\common\service\app\aigc_short_drama\canvas_agent\GraphService as Graph;
function operationRejected(callable $call, string $expected): void {
    try {$call();} catch (RuntimeException $error) {agentCheck($error->getMessage()===$expected,$expected);return;}
    throw new RuntimeException('Expected '.$expected);
}
Db::startTrans();
try {
    $id=Canvas::create(91001,92001,['title'=>'P1 graph operation compatibility'])['id'];
    $nodes=[['id'=>1,'type'=>'image','x'=>11,'y'=>22,'metadata'=>['agentGroupId'=>'legacy','content'=>'source']],['id'=>2,'type'=>'video','x'=>33,'y'=>44,'metadata'=>['agentGroupId'=>'legacy']]];
    $edges=[['from'=>1,'to'=>2]];
    Db::name(Graph::TABLE)->where('id',$id)->update(['nodes_json'=>json_encode($nodes),'edges_json'=>json_encode($edges)]);
    $group=['request_key'=>'group','expected_revision'=>0,'operations'=>[['op'=>'set_group','group_id'=>'run_1:shots','nodes'=>[['id'=>'1','expected_layout_revision'=>'0'],['id'=>'2','expected_layout_revision'=>'0']]]]];
    $result=Graph::patch(91001,92001,$id,$group);
    agentCheck(array_column(array_column($result['nodes'],'metadata'),'agentGroupId')===['run_1:shots','run_1:shots'],'group uses current four-node UI metadata contract');
    agentCheck(array_column($result['nodes'],'x')===[11,33] && $result['edges']===$edges,'group preserves old coordinates and from/to reference edge');
    agentCheck($result['nodes'][0]['metadata']['content']==='source','group preserves existing content');
    agentCheck(Graph::patch(91001,92001,$id,$group)===$result,'group replay returns stable receipt');
    $duplicate=['request_key'=>'duplicate-edge','expected_revision'=>1,'operations'=>[['op'=>'add_edge','edge'=>['from'=>'1','to'=>'2','kind'=>'reference']]]];
    operationRejected(fn()=>Graph::patch(91001,92001,$id,$duplicate),'EDGE_ALREADY_EXISTS');
    $roles=['request_key'=>'roles','expected_revision'=>1,'operations'=>[['op'=>'add_edge','edge'=>['from'=>1,'to'=>2,'role'=>'first_frame']],['op'=>'add_edge','edge'=>['from'=>1,'to'=>2,'role'=>'last_frame']]]];
    $result=Graph::patch(91001,92001,$id,$roles);
    agentCheck(count($result['edges'])===3,'same asset can retain distinct first/last frame semantic edges');
    $remove=['request_key'=>'remove','expected_revision'=>2,'operations'=>[['op'=>'remove_edge','edge'=>['from'=>1,'to'=>2]]]];
    operationRejected(fn()=>Graph::patch(91001,92001,$id,$remove),'AMBIGUOUS_EDGE');
    $remove['operations'][0]['edge']['role']='first_frame';
    $result=Graph::patch(91001,92001,$id,$remove);
    agentCheck(count($result['edges'])===2 && ($result['edges'][1]['role']??'')==='last_frame','precise removal leaves other reference roles intact');
    $ungroup=['request_key'=>'ungroup','expected_revision'=>3,'operations'=>[['op'=>'set_group','group_id'=>'','nodes'=>[['id'=>1,'expected_layout_revision'=>1]]]]];
    $result=Graph::patch(91001,92001,$id,$ungroup);
    agentCheck($result['nodes'][0]['metadata']['agentGroupId']==='' && $result['nodes'][1]['metadata']['agentGroupId']==='run_1:shots','ungroup only changes specified member');
    $ungroup['request_key']='stale-layout';$ungroup['expected_revision']=4;
    operationRejected(fn()=>Graph::patch(91001,92001,$id,$ungroup),'LAYOUT_VERSION_CONFLICT');
    agentCheck((int)Db::name(Graph::TABLE)->where('id',$id)->value('graph_revision')===4,'failed group operation does not advance graph');
} finally {Db::rollback();}
echo "NOT_RUN model capability checks and integrated graph writers; graph operation foundation only\n";
