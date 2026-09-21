<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use think\facade\Db;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
Db::startTrans();
try {
    $id=Canvas::create(91001,92001,['title'=>'Versioned manual authority'])['id'];
    $node=['id'=>1,'type'=>'text','x'=>10,'y'=>20,'metadata'=>['content'=>'editable','status'=>'success','asset_owner'=>92002,'cost'=>0,'canvasRunId'=>999,'active_generation_id'=>999,'content_revision'=>999,'layout_revision'=>999]];
    $doc=Canvas::save(91001,92001,['id'=>$id,'expected_revision'=>0,'nodes'=>[$node]]);
    $meta=$doc['nodes'][0]['metadata'];
    agentCheck(!array_intersect(['status','asset_owner','cost','canvasRunId','active_generation_id'],array_keys($meta)),'versioned save cannot forge server metadata on new node');
    agentCheck($meta['content_revision']===1 && $meta['layout_revision']===1,'new node versions allocated by server');
    $node=$doc['nodes'][0];
    $node['metadata']=array_replace($node['metadata'],['status'=>'running','asset_owner'=>92001,'cost'=>5,'canvasRunId'=>100,'active_generation_id'=>100,'content_revision'=>7,'layout_revision'=>9]);
    Db::name('aigc_short_drama_canvas')->where('id',$id)->update(['nodes_json'=>json_encode([$node])]);
    $forged=$node;$forged['x']=99;
    $forged['metadata']=array_replace($forged['metadata'],['content'=>'user changed text','status'=>'success','asset_owner'=>92002,'cost'=>0,'canvasRunId'=>999,'active_generation_id'=>999,'content_revision'=>999,'layout_revision'=>999]);
    $doc=Canvas::save(91001,92001,['id'=>$id,'expected_revision'=>1,'nodes'=>[$forged]]);
    $meta=$doc['nodes'][0]['metadata'];
    agentCheck($meta['status']==='running' && $meta['cost']===5 && $meta['asset_owner']===92001,'ordinary save preserves authoritative status, cost and asset ownership');
    agentCheck($meta['canvasRunId']===100 && $meta['active_generation_id']===100,'ordinary save cannot replace authoritative active generation');
    agentCheck($meta['content_revision']===8 && $meta['layout_revision']===10 && $meta['content']==='user changed text' && $doc['nodes'][0]['x']===99,'allowed content/layout edits advance separate server versions');
    $doc=Canvas::save(91001,92001,['id'=>$id,'expected_revision'=>2,'nodes'=>$doc['nodes']]);
    agentCheck($doc['nodes'][0]['metadata']['content_revision']===8 && $doc['nodes'][0]['metadata']['layout_revision']===10,'unchanged manual node does not spuriously advance node versions');
    foreach ([[$doc['nodes'][0],$doc['nodes'][0]],[$doc['nodes'][0],['broken'=>true]]] as $invalid) {
        $rejected=false;
        try {Canvas::save(91001,92001,['id'=>$id,'expected_revision'=>3,'nodes'=>$invalid]);} catch (RuntimeException $error) {$rejected=in_array($error->getMessage(),['NODE_ID_CONFLICT','INVALID_NODE_TYPE'],true);}
        agentCheck($rejected,'malformed versioned graph rejected without dropping nodes');
    }
    agentCheck(Canvas::current(91001,92001,$id)['graph_revision']===3,'invalid graph never changes stored revision');
    // A legitimate legacy manual run can bind, but status comes from its owned row.
    $id2=Canvas::create(91001,92001,['title'=>'Manual task binding'])['id'];
    $run=Db::name('aigc_short_drama_canvas_run')->insertGetId(['tenant_id'=>91001,'user_id'=>92001,'canvas_id'=>$id2,'node_id'=>'1','node_type'=>'text','status'=>'running','request_json'=>'{}','result_json'=>'{}']);
    $result=Canvas::save(91001,92001,['id'=>$id2,'expected_revision'=>0,'nodes'=>[['id'=>1,'type'=>'text','metadata'=>['canvasRunId'=>$run,'status'=>'success']]]]);
    agentCheck($result['nodes'][0]['metadata']['canvasRunId']===$run && $result['nodes'][0]['metadata']['status']==='running','legitimate manual run binding uses authoritative run status');
} finally {Db::rollback();}
echo "NOT_RUN legacy schema-v1 authority cleanup and asset input ownership validation; enabled versioned document boundary only\n";
