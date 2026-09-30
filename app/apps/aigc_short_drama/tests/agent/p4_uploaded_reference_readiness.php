<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use app\common\service\app\aigc_short_drama\AigcShortDramaService as Drama;
use app\common\service\app\aigc_short_drama\canvas_agent\GraphService as Graph;
use think\facade\Db;

Db::startTrans();
try {
    $tenant=91081; $user=92081;
    $canvas=Canvas::create($tenant,$user,['title'=>'upload reference regression'])['id'];
    $nodes=[];$assets=[];
    foreach (['image','video','audio'] as $i=>$type) {
        $id=(string)($i+1);$uri='uploads/regression/reference-'.$canvas.'.'.$type;
        $assets[$type]=Drama::registerAsset($tenant,$user,['canvas_id'=>$canvas,'node_id'=>$id,'node_type'=>$type,'uri'=>$uri,'storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>'']);
        $nodes[]=['id'=>$id,'type'=>$type,'title'=>$type,'x'=>0,'y'=>0,'metadata'=>['source'=>'upload','uri'=>$uri,'url'=>$uri]];
    }
    $again=Drama::registerAsset($tenant,$user,['canvas_id'=>$canvas,'node_id'=>'3','node_type'=>'audio','uri'=>$nodes[2]['metadata']['uri']]);
    agentCheck($again['id']===$assets['audio']['id'],'registration retries reuse the same owned node asset');
    $nodes[1]['metadata']['mediaSource']='upload';unset($nodes[1]['metadata']['source']);
    $document=Db::name(Graph::TABLE)->where('id',$canvas)->find();
    $projected=Graph::registeredUploadNodes($document,$nodes);
    foreach ($projected as $i=>$node) {
        agentCheck($node['metadata']['status']==='success' && $node['metadata']['asset_id']===$assets[$node['type']]['id'],'registered '.$node['type'].' restores server-owned readiness');
        $state=Canvas::agentAutoDependencyState([$node,['id'=>'99','type'=>'video','metadata'=>[]]],[['from'=>$node['id'],'to'=>'99','kind'=>'reference']],'99');
        agentCheck($state['state']==='ready' && count($state['references'])===1,'uploaded '.$node['type'].' can be referenced without generation');
    }
    foreach (['tenant_id','user_id','id'] as $field) {
        $foreign=$document;$foreign[$field]=(int)$foreign[$field]+1;
        $result=Graph::registeredUploadNodes($foreign,$nodes);
        agentCheck(empty($result[0]['metadata']['asset_id']),'upload lookup isolates '.$field);
    }
    $wrong=$nodes;$wrong[0]['metadata']['uri']='uploads/not-registered.png';$wrong[1]['id']='88';$wrong[2]['type']='image';
    $result=Graph::registeredUploadNodes($document,$wrong);
    foreach ($result as $node) agentCheck(empty($node['metadata']['asset_id']),'URI, node and media type cannot borrow another binding');
    $generated=$nodes[0];$generated['metadata']['canvasRunId']=77;$generated['metadata']['status']='running';
    agentCheck(Graph::registeredUploadNodes($document,[$generated])[0]===$generated,'upload recovery never overrides generation state');
    Db::name('aigc_short_drama_asset')->where('id',$assets['image']['id'])->update(['delete_time'=>time()]);
    agentCheck(empty(Graph::registeredUploadNodes($document,[$nodes[0]])[0]['metadata']['asset_id']),'deleted asset cannot restore readiness');

    // Reproduce the real schema-v2 save that used to strip upload status and ID.
    $audio=$nodes[2];$audio['metadata']['asset_id']=999999;$audio['metadata']['status']='success';
    $saved=Canvas::save($tenant,$user,['id'=>$canvas,'expected_revision'=>0,'nodes'=>[$audio,['id'=>'99','type'=>'video','title'=>'target','x'=>500,'y'=>0,'metadata'=>[]]],'edges'=>[['from'=>'3','to'=>'99','kind'=>'reference']]]);
    agentCheck($saved['nodes'][0]['metadata']['asset_id']===$assets['audio']['id'],'save derives asset ID from registered ownership, ignoring forged ID');
    $stored=Db::name(Graph::TABLE)->where('id',$canvas)->find();
    $raw=json_decode($stored['nodes_json'],true);
    agentCheck($raw[0]['metadata']['status']==='success','revision-checked save persists upload readiness');
    unset($raw[0]['metadata']['asset_id'],$raw[0]['metadata']['status']);
    Db::name(Graph::TABLE)->where('id',$canvas)->update(['nodes_json'=>json_encode($raw)]);
    $read=Canvas::current($tenant,$user,$canvas);
    agentCheck($read['nodes'][0]['metadata']['asset_id']===$assets['audio']['id'],'legacy broken document recovers on refresh without re-upload');
    $method=new ReflectionMethod(Canvas::class,'withGraphReferenceInputs');$method->setAccessible(true);
    $legacy=Db::name(Graph::TABLE)->where('id',$canvas)->find();
    $params=$method->invoke(null,$legacy,'99',[]);
    agentCheck($params['reference_assets'][0]['asset_id']===$assets['audio']['id'],'quote and submit resolve legacy upload through same gate');
    $empty=['id'=>'1','type'=>'image','metadata'=>[]];
    agentCheck(Canvas::agentAutoDependencyState([$empty],[['from'=>'1','to'=>'99','kind'=>'reference']],'99')['state']==='waiting','unfinished generated inputs still wait');
    $empty['metadata']['status']='failed';
    agentCheck(Canvas::agentAutoDependencyState([$empty],[['from'=>'1','to'=>'99','kind'=>'reference']],'99')['state']==='blocked','failed generated inputs still block');
} finally { Db::rollback(); }
echo "NOT_RUN paid provider generation; fixtures rolled back\n";
