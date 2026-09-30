<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use app\common\service\app\aigc_short_drama\AigcShortDramaService as Drama;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use app\common\service\app\aigc_short_drama\canvas_agent\GraphService as Graph;
use think\facade\Db;
Db::startTrans();
try {
    $tenant=91082;$user=92082;
    $canvas=Canvas::create($tenant,$user,['title'=>'uploaded storage regression'])['id'];
    $resolve=new ReflectionMethod(Canvas::class,'resolveOwnedReferenceAssets');$resolve->setAccessible(true);
    foreach (['image','video','audio'] as $index=>$type) {
        $uri='uploads/regression/'.$canvas.'-'.$type;
        $file=Db::name('tenant_file')->insertGetId(['tenant_id'=>$tenant,'uri'=>$uri,'name'=>'fixture','storage_scope'=>'tenant','storage_engine'=>'aliyun','storage_domain'=>'https://owned-storage.example','delete_time'=>null]);
        $asset=Drama::registerAsset($tenant,$user,['canvas_id'=>$canvas,'node_id'=>(string)($index+1),'node_type'=>$type,'uri'=>$uri,'storage_scope'=>'','storage_engine'=>'','storage_domain'=>'']);
        $row=Db::name('aigc_short_drama_asset')->where('id',$asset['id'])->find();
        agentCheck($row['storage_engine']==='aliyun','new '.$type.' registration inherits original upload storage');
        Db::name('aigc_short_drama_asset')->where('id',$asset['id'])->update(['storage_scope'=>'','storage_engine'=>'','storage_domain'=>'']);
        $row=Db::name('aigc_short_drama_asset')->where('id',$asset['id'])->find();
        $resolved=$resolve->invoke(null,$tenant,$user,$canvas,[['type'=>$type,'asset_id'=>$row['id'],'url'=>'http://s.cn/'.$uri]]);
        agentCheck($resolved[0]['url']==='https://owned-storage.example/'.$uri,'legacy '.$type.' submission uses authoritative cloud URL instead of HTTP host');
        $retry=Drama::registerAsset($tenant,$user,['canvas_id'=>$canvas,'node_id'=>(string)($index+1),'node_type'=>$type,'uri'=>$uri]);
        agentCheck($retry['id']===$row['id'] && $retry['url']===$resolved[0]['url'],'idempotent asset read also restores cloud delivery');
        $node=['id'=>(string)($index+1),'type'=>$type,'metadata'=>['source'=>'upload','uri'=>$uri]];
        $document=Db::name(Graph::TABLE)->where('id',$canvas)->find();
        $nodes=Graph::registeredUploadNodes($document,[$node]);
        agentCheck($nodes[0]['metadata']['storage_domain']==='https://owned-storage.example','canvas '.$type.' projection carries storage metadata');
        $foreign=$row;$foreign['tenant_id']=$tenant+1;
        agentCheck(Drama::canvasAssetStorage($foreign)['storage_engine']==='','legacy repair cannot read another tenant upload');
        $explicit=$row;$explicit['storage_scope']='tenant';$explicit['storage_engine']='local';
        agentCheck(Drama::canvasAssetStorage($explicit)===$explicit,'explicit local storage is not replaced by cloud upload');
        Db::name('tenant_file')->where('id',$file)->update(['delete_time'=>time()]);
        try { $resolve->invoke(null,$tenant,$user,$canvas,[['type'=>$type,'asset_id'=>$row['id']]]); throw new RuntimeException('unresolved storage accepted'); }
        catch (Exception $e) { agentCheck($e->getMessage()==='参考素材存储信息不完整，请重新上传该素材','unresolved '.$type.' reference fails before quote/provider submission'); }
    }
} finally { Db::rollback(); }
echo "NOT_RUN paid video generation; fixtures rolled back\n";
