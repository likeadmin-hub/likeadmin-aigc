<?php
namespace Tests\Feature;

use app\common\model\ai\{AiAppTask,AiConsumptionLog,AiTaskResultAsset};
use app\common\model\app\aigc_canvas\{AigcCanvasProject,AigcCanvasRun};
use app\common\service\ai\AiTaskBusinessResultService;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

class MarketCanvasManagedProjectionTest extends TestCase
{
    /** @dataProvider boundaries */
    public function testManagedResultRepairsOnlyItsActiveOwnedNode(string $kind,string $boundary): void
    {
        if(getenv('AIGC_TRANSFER_CHECKPOINT_DB_TESTS')!=='1')self::markTestSkipped('Explicit local rollback fixture only');
        (new \think\App())->initialize();
        self::assertContains(Db::connect()->getConfig('hostname'),['127.0.0.1','localhost']);
        self::assertContains(Db::connect()->getConfig('database'),['x_cn','z_cn']);
        Db::startTrans();
        try {
            $owner=['tenant_id'=>199999956,'user_id'=>199999956];$uri='uploads/owned-b56.'.$kind;
            $frozen=['audio'=>false,'quality'=>'fixture','duration'=>1,'old_sku'=>56];
            $node=['id'=>'owned-node','type'=>$kind,'metadata'=>['prompt'=>'Synthetic fixture','status'=>'running','pending'=>true,
                'mediaItems'=>[],'audio'=>false,'quality'=>'fixture','duration'=>1]];
            $project=AigcCanvasProject::create($owner+['name'=>'b56 rollback fixture','nodes_json'=>[],'edges_json'=>[['id'=>'preserved-edge']],
                'viewport_json'=>['x'=>12,'y'=>24,'zoom'=>0.5],'delete_time'=>0,'create_time'=>time()-60,'update_time'=>time()-60]);
            $run=AigcCanvasRun::create($owner+['project_id'=>$project['id'],'node_id'=>'owned-node','run_type'=>$kind==='audio'?'music':$kind,
                'source_app_code'=>'power_market_'.$kind,'status'=>'running','params_json'=>$frozen,'result_json'=>[],'error'=>'',
                'create_time'=>time()-60,'update_time'=>time()-60,'finish_time'=>0,'delete_time'=>0]);
            $task=AiAppTask::create($owner+['task_no'=>'b56-'.bin2hex(random_bytes(8)),'app_code'=>'aigc_canvas','action_code'=>'generate',
                'business_table'=>'aigc_canvas_run','business_id'=>$run['id'],'status'=>'success','create_time'=>time(),'update_time'=>time()]);
            $key=['image'=>'images','video'=>'videos','audio'=>'items'][$kind];
            $c=AiConsumptionLog::create($owner+['consume_no'=>'b56-'.bin2hex(random_bytes(8)),'app_task_id'=>$task['id'],'app_code'=>'aigc_canvas',
                'provider'=>'power_market','run_status'=>in_array($boundary,['failure','terminal_conflict'],true)?'failed':'success','billing_status'=>'settled',
                'error_msg'=>'Owned fixture failure','price_snapshot'=>$frozen,'request_summary'=>$frozen,
                'response_summary'=>[$key=>[[$kind.'_uri'=>$uri,'storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>'','width'=>32,'height'=>32]]],
                'create_time'=>time()-60,'update_time'=>time()-60,'finish_time'=>time()-30]);
            AiTaskResultAsset::create($owner+['consumption_id'=>$c['id'],'app_task_id'=>$task['id'],'asset_type'=>$kind,
                'external_url'=>'https://example.invalid/fixture','local_uri'=>$uri,'storage_scope'=>'tenant','storage_engine'=>'local',
                'transfer_status'=>$boundary==='untransferred'?'external':'stored','storage_meta'=>[],'create_time'=>time(),'update_time'=>time()]);
            $run->save(['source_task_id'=>$c['id']]);$node['metadata']['taskId']=(string)$c['id'];
            if($boundary==='stale_task')$node['metadata']['taskId']='later-task';
            if($boundary==='canceled_node')$node['metadata']['status']='canceled';
            if($boundary==='completed_node')$node['metadata']=array_replace($node['metadata'],['status'=>'success','url'=>'uploads/manual-replacement']);
            if($boundary==='failed_run')$run->save(['status'=>'failed']);
            if($boundary==='terminal_conflict')$run->save(['status'=>'success']);
            if($boundary==='foreign_run')$run->save(['user_id'=>199999957]);
            if($boundary==='foreign_project')$project->save(['user_id'=>199999957]);
            if($boundary==='deleted_project')$project->save(['delete_time'=>time()]);
            if($boundary==='collection')$node['metadata']['mediaItems']=[['id'=>'prior','type'=>$kind,'url'=>'uploads/prior-result','mediaSource'=>'generated']];
            $project->save(['nodes_json'=>$boundary==='deleted_node'?[]:[$node]]);
            $beforeProject=AigcCanvasProject::find($project['id'])->toArray();$beforeRun=AigcCanvasRun::find($run['id'])->toArray();$beforeC=AiConsumptionLog::find($c['id'])->toArray();
            AiTaskBusinessResultService::syncByConsumptionId((int)$c['id']);
            $afterProject=AigcCanvasProject::find($project['id'])->toArray();$afterRun=AigcCanvasRun::find($run['id'])->toArray();
            self::assertSame($beforeC,AiConsumptionLog::find($c['id'])->toArray(),'Consumption and frozen billing must not change');
            self::assertSame($frozen,$afterRun['params_json']);
            self::assertSame($beforeProject['edges_json'],$afterProject['edges_json']);self::assertSame($beforeProject['viewport_json'],$afterProject['viewport_json']);
            if(in_array($boundary,['active','collection','failure'],true)) {
                $m=$afterProject['nodes_json'][0]['metadata'];self::assertSame($boundary==='failure'?'failed':'success',$m['status']);self::assertFalse($m['pending']);
                self::assertSame(false,$m['audio']);self::assertSame('fixture',$m['quality']);self::assertSame(1,$m['duration']);
                if($boundary==='failure')self::assertSame('Owned fixture failure',$m['errorDetails']);
                else {self::assertSame($uri,$m['uri']);self::assertCount($boundary==='collection'?2:1,$m['mediaItems']);self::assertSame($uri,end($m['mediaItems'])['assetUri']);}
            } else self::assertSame($beforeProject,$afterProject,'A late result must not overwrite an unrelated node or project');
            if(in_array($boundary,['foreign_run','untransferred','failed_run','terminal_conflict'],true))self::assertSame($beforeRun,$afterRun);
            else self::assertSame($boundary==='failure'?'failed':'success',$afterRun['status']);
            AiTaskBusinessResultService::syncByConsumptionId((int)$c['id']);
            self::assertSame($afterRun,AigcCanvasRun::find($run['id'])->toArray());self::assertSame($afterProject,AigcCanvasProject::find($project['id'])->toArray());
        } finally {Db::rollback();}
    }

    public static function boundaries(): array
    {
        $cases=[];foreach(['image','video','audio']as$kind)foreach(['active','collection','failure','stale_task','canceled_node','completed_node',
            'failed_run','terminal_conflict','foreign_run','foreign_project','deleted_project','deleted_node','untransferred']as$boundary)$cases[]=[$kind,$boundary];return $cases;
    }
}
