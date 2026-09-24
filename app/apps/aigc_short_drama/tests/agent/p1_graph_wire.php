<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use think\facade\Db;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use app\common\service\app\aigc_short_drama\canvas_agent\GraphService as Graph;

function rejectsWire(callable $action, string $code): void {
    try { $action(); } catch (RuntimeException $error) {
        agentCheck($error->getMessage() === $code, $code);
        return;
    }
    throw new RuntimeException('Expected '.$code);
}
Db::startTrans();
try {
    $id = Canvas::create(91001, 92001, ['title'=>'P1 wire validation'])['id'];
    // IDs allocated under the graph lock must not reuse a deleted node ID.
    Db::name(Graph::TABLE)->where('id', $id)->update(['removed_node_ids_json'=>'["40"]']);
    $request = ['request_key'=>'allocated', 'expected_revision'=>'0', 'operations'=>[
        ['op'=>'add_node','node'=>['type'=>'text','x'=>12,'y'=>34,'metadata'=>['content'=>'first']]],
        ['op'=>'add_node','node'=>['type'=>'image','x'=>56,'y'=>78,'metadata'=>[]]],
    ]];
    $result = Graph::patch(91001,92001,$id,$request);
    agentCheck(array_column($result['nodes'],'id') === [41,42], 'server allocates safe IDs past tombstones within one patch');
    $request['expected_revision'] = 0;
    agentCheck(Graph::patch(91001,92001,$id,$request) === $result, 'string/integer base revision replays identical allocation receipt');
    $edit = ['request_key'=>'wire-edit','expected_revision'=>'1','operations'=>[
        ['op'=>'update_node_content','node_id'=>'41','expected_content_revision'=>'1','patch'=>['content'=>'changed']],
        ['op'=>'move_nodes','nodes'=>[['id'=>'42','x'=>90,'expected_layout_revision'=>'1']]],
    ]];
    $updated = Graph::patch(91001,92001,$id,$edit);
    agentCheck($updated['nodes'][0]['metadata']['content'] === 'changed' && $updated['nodes'][1]['x'] === 90, 'numeric-string content/layout revisions support actual request contract');
    $edit['operations'][0]['expected_content_revision'] = 1;
    $edit['operations'][1]['nodes'][0]['expected_layout_revision'] = 1;
    agentCheck(Graph::patch(91001,92001,$id,$edit) === $updated, 'normalized nested revisions preserve idempotency');
    foreach ([null,true,1.0,'01','-1','1e0','4294967296',[]] as $bad) {
        $invalid = $request; $invalid['expected_revision'] = $bad;
        rejectsWire(fn()=>Graph::patch(91001,92001,$id,$invalid),'EXPECTED_REVISION_REQUIRED');
    }
    foreach ([null,0,-1,INF] as $width) {
        $invalid = ['request_key'=>'bad-geometry','expected_revision'=>2,'operations'=>[
            ['op'=>'add_node','node'=>['type'=>'text','width'=>$width]],
        ]];
        // INF is rejected by JSON hashing before geometry validation; exercise finite invalid geometry here.
        if (is_float($width) && !is_finite($width)) continue;
        rejectsWire(fn()=>Graph::patch(91001,92001,$id,$invalid),'INVALID_GEOMETRY');
    }
    $invalid = ['request_key'=>'bad-move','expected_revision'=>2,'operations'=>[['op'=>'move_nodes','nodes'=>[]]]];
    rejectsWire(fn()=>Graph::patch(91001,92001,$id,$invalid),'INVALID_LAYOUT');
    agentCheck((int)Db::name(Graph::TABLE)->where('id',$id)->value('graph_revision') === 2, 'invalid wire requests leave graph revision unchanged');
    agentCheck(Db::name(Graph::RECEIPTS)->where('canvas_id',$id)->count() === 2, 'invalid wire requests leave no receipts');
} finally { Db::rollback(); }
echo "NOT_RUN HTTP patch route; GraphService remains unexposed until P1 integration passes\n";
