<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use app\common\service\app\aigc_short_drama\canvas_agent\GenerationIntentService as Intent;
use app\common\service\app\aigc_short_drama\canvas_agent\GraphService;
use app\common\service\FileService;
use app\common\service\power\MarketGenerationMedia as Media;
use think\facade\Db;

Db::startTrans();
try {
    $tenant=91001;
    $user=92001;
    $file=['storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>''];
    $uri='uploads/fixture/reference-duration.mp4';
    $url=FileService::getFileUrlByStorage($uri,$file['storage_scope'],$file['storage_engine'],$file['storage_domain']);
    $ref=$file+['uri'=>$uri,'url'=>$url,'type'=>'video','asset_id'=>1,'duration'=>999];
    Db::name('ai_task_result_asset')->insert($file+[
        'tenant_id'=>$tenant,'user_id'=>$user,'asset_type'=>'video','transfer_status'=>'stored','local_uri'=>$uri,
        'storage_meta'=>json_encode(['duration'=>4.064,'file_size'=>1000]),'consumption_id'=>0,'app_task_id'=>0,
    ]);
    $measure=static fn(): float => Media::seconds(['video_url'=>$url],false,static fn()=>7.0);
    agentCheck(Media::withStoredVideoReferences($tenant,$user,[$ref],$measure)===4.064,'full video uses stored measurement instead of browser duration');
    foreach ([[$tenant+1,$user,$ref],[$tenant,$user+1,$ref],[$tenant,$user,array_replace($ref,['asset_id'=>0])],
        [$tenant,$user,array_replace($ref,['storage_scope'=>'platform'])],[$tenant,$user,array_replace($ref,['url'=>$url.'?other'])]] as [$t,$u,$r]) {
        agentCheck(Media::withStoredVideoReferences($t,$u,[$r],$measure)===7.0,'unmatched owner, reference or location retains the normal probe');
    }
    try {
        Media::withStoredVideoReferences($tenant,$user,[$ref],static function(){throw new RuntimeException('stop');});
    } catch (RuntimeException $error) {
        if ($error->getMessage()!=='stop') throw $error;
    }
    agentCheck($measure()===7.0,'measured duration does not leak after completion or exception');
    foreach (['current','legacy','legacy-edited'] as $case) {
        $canvas=Canvas::create($tenant,$user,['title'=>'Video preview dependency'])['id'];
        $saved=Canvas::save($tenant,$user,[
            'id'=>$canvas,'expected_revision'=>0,
            'nodes'=>[
                ['id'=>'1','type'=>'video','title'=>'Source','metadata'=>['prompt'=>'original']],
                ['id'=>'2','type'=>'video','title'=>'Target','metadata'=>[]],
            ],
            'edges'=>[['from'=>'1','to'=>'2','kind'=>'reference']],
        ]);
        $intent=Intent::reserve($tenant,$user,$canvas,'preview','1','video',['prompt'=>'original']);
        if ($case!=='current') {
            // Persist the signature exactly as the earlier version encoded this fixture.
            $snapshot=json_decode($intent['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
            unset($snapshot['target_signature_version']);
            $snapshot['target_signature']=hash('sha256',json_encode([
                'metadata'=>['content_revision'=>$snapshot['content_revision'],'prompt'=>'original'],
                'title'=>'Source','type'=>'video',
            ],JSON_THROW_ON_ERROR));
            Db::name(Intent::TABLE)->where('id',$intent['id'])->update(['snapshot_json'=>json_encode($snapshot)]);
        }
        $claim=Intent::claim($tenant,$user,(int)$intent['id']);
        agentCheck($claim!==null,$case.' snapshot can be claimed');
        $document=Canvas::current($tenant,$user,$canvas);
        $nodes=$document['nodes'];
        $uri='uploads/fixture/preview.mp4';
        $nodes[0]['metadata']=array_replace($nodes[0]['metadata'],[
            'taskTrace'=>['id'=>'task-preview'],'mediaPreviewRatioActive'=>true,
            'videoWidth'=>418,'videoHeight'=>978,'mediaWidth'=>418,'mediaHeight'=>978,
            'uri'=>$uri,'video_url'=>$uri,
        ]);
        if ($case==='legacy-edited') $nodes[0]['metadata']['duration']=8;
        $saved=Canvas::save($tenant,$user,['id'=>$canvas,'expected_revision'=>$document['graph_revision'],'nodes'=>$nodes,'edges'=>$saved['edges']]);
        $dependency=static fn(array $doc): array => Canvas::agentAutoDependencyState($doc['nodes'],$doc['edges'],'2');
        agentCheck($dependency($saved)['state']==='waiting',$case.' preview alone cannot make a dependency ready');
        $result=['results'=>[['url'=>$uri,'uri'=>$uri,'asset_id'=>123,'poster_url'=>'uploads/fixture/poster.jpg']]];
        Intent::accepted($tenant,$user,(int)$claim['id'],$claim['claim_token'],(int)$claim['fencing_version'],'preview-task',$result,true);
        $read=Canvas::current($tenant,$user,$canvas);
        $stored=json_decode(Db::name(GraphService::TABLE)->where('id',$canvas)->value('nodes_json'),true);
        $state=$dependency(['nodes'=>$stored,'edges'=>$read['edges']]);
        if ($case==='legacy-edited') {
            agentCheck($state['state']==='waiting' && empty($stored[0]['metadata']['asset_id']),'legacy recovery preserves a real generation parameter edit');
        } else {
            agentCheck($state['state']==='ready' && $state['references'][0]['asset_id']===123,$case.' connected downstream node can use the completed asset');
            agentCheck($read['graph_revision']===$saved['graph_revision']+1 && $read['graph_revision']===Canvas::current($tenant,$user,$canvas)['graph_revision'],$case.' reopening returns the repaired revision exactly once');
        }
    }
} finally {
    Db::rollback();
}
