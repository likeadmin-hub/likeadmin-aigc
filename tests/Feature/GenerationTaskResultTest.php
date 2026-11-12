<?php
namespace Tests\Feature;

use app\common\model\ai\{AiAppTask,AiConsumptionLog,AiTaskResultAsset};
use app\common\service\ai\GenerationTaskResultService as Results;
use app\common\service\power\MarketGenerationRuntime as Runtime;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

class GenerationTaskResultTest extends TestCase
{
    private function fixture(callable $check): void
    {
        if(getenv('AIGC_TRANSFER_CHECKPOINT_DB_TESTS')!=='1')self::markTestSkipped('Explicit local rollback fixture only');
        (new \think\App())->initialize();self::assertContains(Db::connect()->getConfig('hostname'),['127.0.0.1','localhost']);self::assertContains(Db::connect()->getConfig('database'),['x_cn','z_cn']);
        Db::startTrans();
        try {
            $owner=['tenant_id'=>199999957,'user_id'=>199999957];$key='unify-'.bin2hex(random_bytes(8));
            $task=AiAppTask::create($owner+['task_no'=>$key,'app_code'=>'aigc_canvas','action_code'=>'video_generate','business_table'=>'aigc_canvas_run',
                'business_id'=>199999957,'status'=>'success','progress'=>100,'idempotency_key'=>$key,'create_time'=>time(),'update_time'=>time(),'finish_time'=>time()]);
            $c=AiConsumptionLog::create($owner+['consume_no'=>$key,'app_task_id'=>$task['id'],'app_code'=>'aigc_canvas','protocol'=>'video_generate',
                'provider'=>'power_market','resource_type'=>'model','run_status'=>'success','billing_status'=>'settled','price_snapshot'=>['resource_type'=>'model','model_type'=>'video','product_id'=>2147483000,'frozen'=>['audio'=>false,'quality'=>'high']],
                'request_summary'=>['credentials'=>'never-return-this'],'response_summary'=>['videos'=>[['video_uri'=>'uploads/unify-owned.mp4']]],'create_time'=>time(),'update_time'=>time(),'finish_time'=>time()]);
            $asset=AiTaskResultAsset::create($owner+['consumption_id'=>$c['id'],'app_task_id'=>$task['id'],'asset_type'=>'video','external_url'=>'https://example.invalid/provider-expired.mp4',
                'local_uri'=>'uploads/unify-owned.mp4','storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>'','transfer_status'=>'stored',
                'storage_meta'=>['width'=>32,'height'=>32,'duration'=>1,'mime_type'=>'video/mp4','file_size'=>100],'create_time'=>time(),'update_time'=>time()]);
            $check($task,$c,$asset,$owner);
        } finally {Db::rollback();}
    }

    public function testExistingManagedImageRegistrationPreservesItsMeasuredFacts(): void
    {
        $this->fixture(function($task,$c,$asset,$owner){
            $asset->delete();$uri='uploads/managed-image.png';
            $c->save(['protocol'=>'image_generate','response_summary'=>['images'=>[['image_uri'=>$uri,'stored'=>true,'storage_scope'=>'platform','storage_engine'=>'local','storage_domain'=>'','width'=>32,'height'=>32,'file_size'=>120,'mime_type'=>'image/png','provider_private'=>'must-not-copy']]]]);
            $assets=\app\common\service\ai\AiTaskResultAssetService::recordConsumptionAssets((int)$c['id']);
            self::assertCount(1,$assets);self::assertSame(32,$assets[0]['storage_meta']['width']);self::assertArrayNotHasKey('provider_private',$assets[0]['storage_meta']);
            $view=Results::read((int)$task['id'],$owner['tenant_id'],$owner['user_id']);
            self::assertSame('success',$view['status']);self::assertSame(120,$view['results'][0]['file_size']);self::assertSame('image/png',$view['results'][0]['mime_type']);
            \app\common\service\ai\AiTaskResultAssetService::recordConsumptionAssets((int)$c['id']);
            self::assertSame(1,AiTaskResultAsset::where('consumption_id',$c['id'])->count());
        });
    }

    /** @dataProvider states */
    public function testTaskViewSeparatesExecutionBillingAndDelivery(string $execution,string $billing,string $storage,string $status,string $stage): void
    {
        $this->fixture(function($t,$c,$a,$owner)use($execution,$billing,$storage,$status,$stage){
            $t->save(['status'=>in_array($execution,['success','failed','canceled'],true)?$execution:'running']);$c->save(['run_status'=>$execution,'billing_status'=>$billing]);
            $a->save(['transfer_status'=>$storage]);
            $before=[$t->refresh()->toArray(),$c->refresh()->toArray(),$a->refresh()->toArray()];$jobs=(int)Db::name('ai_task_job')->where('consumption_id',$c['id'])->count();
            $r=Results::read((int)$t['id'],$owner['tenant_id'],$owner['user_id']);
            self::assertSame(1,$r['schema_version']);self::assertSame('app_task:'.$t['id'],$r['task_ref']);self::assertSame($status,$r['status']);self::assertSame($stage,$r['stage']);
            self::assertGreaterThan(time()-60,$r['created_at']);self::assertGreaterThan(time()-60,$r['finished_at']);
            self::assertSame($storage==='stored' && $execution==='success' && $billing==='settled'?1:0,count($r['results']));
            if($r['results']){self::assertSame((int)$a['id'],$r['results'][0]['result_asset_id']);self::assertSame('uploads/unify-owned.mp4',$r['results'][0]['uri']);self::assertStringNotContainsString('example.invalid',$r['results'][0]['url']);}
            self::assertArrayNotHasKey('price_snapshot',$r);self::assertStringNotContainsString('never-return-this',json_encode($r));
            self::assertSame($before,[$t->refresh()->toArray(),$c->refresh()->toArray(),$a->refresh()->toArray()]);self::assertSame($jobs,(int)Db::name('ai_task_job')->where('consumption_id',$c['id'])->count());
        });
    }
    public static function states(): array{return [
        ['success','settled','stored','success','ready'],['success','reserved','stored','running','settling'],
        ['success','settled','external','running','transferring'],['success','settled','retrying','running','transferring'],
        ['running','reserved','external','running','generating'],['pending_confirmation','reserved','external','running','confirmation'],
        ['failed','refunded','external','failed','failed'],['canceled','refunded','external','canceled','canceled']];}

    /** @dataProvider foreign */
    public function testCanonicalReadAndRuntimeRejectForeignOwners(int $tenantDelta,int $userDelta): void
    {
        $this->fixture(function($t,$c,$a,$owner)use($tenantDelta,$userDelta){foreach(['result','runtime']as$path){
            try{if($path==='result')Results::read((int)$t['id'],$owner['tenant_id']+$tenantDelta,$owner['user_id']+$userDelta);
                else Runtime::consumption((int)$c['id'],$owner['tenant_id']+$tenantDelta,$owner['user_id']+$userDelta);
                self::fail('Cross-owner task accepted');}catch(\InvalidArgumentException $e){self::assertStringContainsString('无权访问',$e->getMessage());}
        }});
    }
    public static function foreign(): array{return [[1,0],[0,1],[1,1]];}

    public function testFrozenRuntimeAndCanonicalReferenceDoNotDependOnLiveCatalogueOrLegacyIds(): void
    {
        $this->fixture(function($t,$c,$a,$owner){
            self::assertSame(\app\common\service\power\MarketVideoModelRuntimeService::class,Runtime::consumption((int)$c['id'],...array_values($owner))->adapter);
            $r=Results::appendConsumption(['task_id'=>999,'status'=>'success'],(int)$c['id'],...array_values($owner));
            self::assertSame(999,$r['task_id']);self::assertSame('app_task:'.$t['id'],$r['generation_task']['task_ref']);
            $a->save(['app_task_id'=>0]);self::assertCount(1,Results::read((int)$t['id'],...array_values($owner))['results']);
            $a->save(['user_id'=>$owner['user_id']+1]);self::assertSame([],Results::read((int)$t['id'],...array_values($owner))['results']);
            self::assertSame('transferring',Results::read((int)$t['id'],...array_values($owner))['stage']);
        });
    }

    public function testBindingIsOwnedIdempotentAndCannotRetargetAnAcceptedTask(): void
    {
        $this->fixture(function($t,$c,$a,$owner){
            $runtime=Runtime::consumption((int)$c['id'],...array_values($owner));
            $runtime->bind((int)$t['id'],199999957,...array_values($owner));
            $before=AiAppTask::find($t['id'])->toArray();
            try{$runtime->bind((int)$t['id'],199999958,...array_values($owner));self::fail('Retarget accepted task');}
            catch(\InvalidArgumentException $e){self::assertStringContainsString('不匹配',$e->getMessage());}
            self::assertSame($before,AiAppTask::find($t['id'])->toArray());
            $c->save(['user_id'=>$owner['user_id']+1]);
            try{Runtime::consumption((int)$c['id'],$owner['tenant_id'],$owner['user_id']+1);self::fail('Cross-owner application link');}
            catch(\InvalidArgumentException $e){self::assertStringContainsString('无权访问',$e->getMessage());}
        });
    }

    public function testPendingViewNeverInventsAProviderOrLegacyBusinessIdentity(): void
    {
        $pending=Results::pending(42);self::assertSame('app_task:42',$pending['task_ref']);self::assertTrue($pending['polling_required']);
        self::assertSame('running',$pending['status']);self::assertSame([], $pending['results']);
        $r=Results::carry(['task_id'=>123,'results'=>['legacy']],['generation_task'=>$pending]);self::assertSame(123,$r['task_id']);self::assertSame(['legacy'],$r['results']);
        self::assertSame(['task_id'=>123],Results::carry(['task_id'=>123],['generation_task'=>['schema_version'=>1,'app_task_id'=>42,'task_ref'=>'42']]));
    }

    public function testCompoundApplicationKeepsParentStatePriceAndBusinessResults(): void
    {
        $this->fixture(function($t,$c,$a,$owner){
            $other=$t->toArray();unset($other['id']);foreach(['create_time','update_time','finish_time']as$key)$other[$key]=time();$other['task_no']='second-'.bin2hex(random_bytes(6));$other['idempotency_key']=$other['task_no'];$second=AiAppTask::create($other);
            $receipt=$c->toArray();unset($receipt['id']);foreach(['create_time','update_time','finish_time']as$key)$receipt[$key]=time();$receipt['consume_no']=$other['task_no'];$receipt['app_task_id']=$second['id'];AiConsumptionLog::create($receipt);
            $children=[];foreach([$t['id'],$second['id']]as$id)$children[]=Db::name('aigc_image_task')->insertGetId($owner+['app_task_id'=>$id,'prompt'=>'Owned composite fixture','provider'=>'power_market','status'=>'success','delete_time'=>0,'create_time'=>time(),'update_time'=>time()]);
            $foreign=Db::name('aigc_image_task')->insertGetId(['tenant_id'=>$owner['tenant_id'],'user_id'=>$owner['user_id']+1,'app_task_id'=>$second['id'],'prompt'=>'Not this user','status'=>'success','delete_time'=>0,'create_time'=>time(),'update_time'=>time()]);
            $parent=['task_id'=>987,'status'=>'running','estimate'=>['user_charge_points'=>123],'results'=>[['module'=>'owned-business-result']]];
            $view=Results::appendRelated($parent,'image',array_merge($children,[$foreign]),...array_values($owner));
            self::assertSame('running',$view['status']);self::assertSame($parent['estimate'],$view['estimate']);self::assertSame($parent['results'],$view['results']);self::assertCount(2,$view['generation_tasks']);
            self::assertSame(['app_task:'.$t['id'],'app_task:'.$second['id']],$view['generation_task_refs']);self::assertArrayNotHasKey('generation_task',$view);
            self::assertSame(987,Results::carry($parent,$view)['task_id']);self::assertCount(2,Results::carry($parent,$view)['generation_tasks']);
            self::assertSame(['app_task:'.$t['id']],array_column(Results::readMany([$t['id'],2147483000],...array_values($owner)),'task_ref'));
            $list=Results::appendRelatedList([$parent+$owner+['image_task_ids'=>array_reverse($children)]],'image');
            self::assertSame(['app_task:'.$second['id'],'app_task:'.$t['id']],$list[0]['generation_task_refs']);
            self::assertSame($parent['estimate'],$list[0]['estimate']);self::assertSame('running',$list[0]['status']);
            $native=Results::appendNativeList([$owner+['app_task_id'=>$t['id'],'status'=>'success'],['tenant_id'=>$owner['tenant_id'],'user_id'=>$owner['user_id']+1,'app_task_id'=>$t['id']]]);
            self::assertSame('app_task:'.$t['id'],$native[0]['generation_task']['task_ref']);self::assertArrayNotHasKey('generation_task',$native[1]);
        });
    }

    /** @dataProvider handoffs */
    public function testRetiredVideoReceiptsKeepPaidArchiveWithoutWakingAWorker(string $kind,string $state,bool $bound,bool $wake): void
    {
        if(getenv('AIGC_TRANSFER_CHILD_DB_TESTS')!=='1')$this->markTestSkipped('Explicit local database fixture required');
        (new \think\App())->initialize();
        if(!\app\common\service\app\RetiredAppService::historyAvailable($kind))$this->markTestSkipped('No legacy archive in this installation');
        $this->fixture(function($t,$c,$a,$owner)use($kind,$state,$bound,$wake){
            $parent=$this->schemaRow('aigc_micro_video_task',$owner+['idempotency_key'=>'unify-micro-'.bin2hex(random_bytes(6)),
                'status'=>$state,'frame_status'=>'generating','next_run_at'=>time()+600,'delete_time'=>0]);
            $source=$this->schemaRow('aigc_micro_video_'.$kind,$owner+['task_id'=>$parent,'sort'=>1,'boundary_index'=>0,'version'=>1]);
            if($bound)$this->schemaRow('aigc_micro_video_attempt',$owner+['task_id'=>$parent,$kind.'_id'=>$source,
                'kind'=>$kind==='frame'?'frame':'video','attempt_no'=>1,'status'=>'running','consumption_id'=>$c['id'],'app_task_id'=>$t['id']]);
            $t->save(['app_code'=>'aigc_micro_video','business_table'=>'aigc_micro_video_'.$kind,'business_id'=>$source]);$c->save(['app_code'=>'aigc_micro_video']);
            $before=Db::name('aigc_micro_video_task')->where('id',$parent)->find();
            self::assertTrue(\app\common\service\ai\AiTaskBusinessResultService::syncTerminalByConsumptionId((int)$c['id']));
            $after=Db::name('aigc_micro_video_task')->where('id',$parent)->find();self::assertSame($state,$after['status']);
            self::assertSame($before,$after);
            \app\common\service\ai\AiTaskBusinessResultService::syncTerminalByConsumptionId((int)$c['id']);
            self::assertSame($after,Db::name('aigc_micro_video_task')->where('id',$parent)->find());
        });
    }
    public static function handoffs(): array{return [['segment','running',true,true],['segment','waiting_continue',true,false],
        ['segment','success',true,false],['segment','running',false,false],['frame','awaiting_confirmation',true,true],['frame','success',true,false]];}

    private function schemaRow(string $table,array $values): int
    {
        $prefix=(string)Db::connect()->getConfig('prefix');$known=[];
        foreach(Db::query('SHOW COLUMNS FROM `'.$prefix.$table.'`')as$field){$key=$field['Field'];$known[$key]=true;
            if(!array_key_exists($key,$values)&&$field['Null']==='NO'&&$field['Default']===null&&!str_contains($field['Extra'],'auto_increment'))
                $values[$key]=preg_match('/int|decimal|float|double/',$field['Type'])?0:'{}';}
        return (int)Db::name($table)->insertGetId(array_intersect_key($values,$known));
    }

    public function testDeliveryViewsAreExcludedFromFrozenProviderReceipts(): void
    {
        $payload=['task_id'=>123,'results'=>[['url'=>'uploads/owned.png']],'audio'=>false,'quality'=>'high','generation_task'=>Results::pending(42),
            'generation_tasks'=>[Results::pending(43)],'generation_task_refs'=>['app_task:43']];
        self::assertSame(['task_id'=>123,'results'=>[['url'=>'uploads/owned.png']],'audio'=>false,'quality'=>'high'],Results::withoutView($payload));
    }
    public function testCanvasHttpFormattersCarryCanonicalViewWithoutChangingLegacyTaskIdentity(): void
    {
        $controller=(new \ReflectionClass(\app\api\controller\app\aigc_canvas\GenerateController::class))->newInstanceWithoutConstructor();
        $view=Results::pending(42);
        foreach(['ImageResult','VideoResult','MusicResult','ImageTask','VideoTask','MusicTask']as$method){
            $format=new \ReflectionMethod($controller,'format'.$method);$format->setAccessible(true);
            $result=$format->invoke($controller,['task_id'=>7,'status'=>'running','generation_task'=>$view,'results'=>[]]);
            self::assertSame($view,$result['generation_task']);self::assertSame(7,$result['task_id']);self::assertSame('7',$result['taskId']);
        }
    }

    public function testCanvasMediaQueriesRejectUnownedTaskInsteadOfReturningAnEmptySuccess(): void
    {
        $this->fixture(function($t,$c,$a,$owner){
            foreach(['imageTaskDetail','videoTaskDetail','musicTaskDetail']as$method){
                try{\app\common\service\app\aigc_canvas\AigcCanvasService::$method($owner['tenant_id'],$owner['user_id']+1,(int)$c['id']);self::fail('Unowned task returned a success response');}
                catch(\Exception $e){self::assertSame('任务不存在或无权访问',$e->getMessage());}
            }
        });
    }

    public function testStageChildrenPreserveParentStatePriceAndOwnerBoundaries(): void
    {
        $this->fixture(function($t,$c,$a,$owner){
            $parent=['id'=>987,'status'=>'awaiting_confirmation','estimate'=>['user_charge_points'=>9],'result_url'=>'/composed'];
            $view=Results::appendConsumptionIds($parent,[(int)$c['id'],(int)$c['id'],0,999999999],$owner['tenant_id'],$owner['user_id']);
            self::assertSame($parent,array_diff_key($view,array_flip(['generation_tasks','generation_task_refs'])));
            self::assertCount(1,$view['generation_tasks']);self::assertSame(['app_task:'.$t['id']],$view['generation_task_refs']);
            self::assertSame([],Results::appendConsumptionIds($parent,[(int)$c['id']],$owner['tenant_id'],$owner['user_id']+1)['generation_tasks']);
        });
    }

    public function testWorkflowListMatchesBothOwnerAndParentWithoutReplacingBusinessResults(): void
    {
        $this->fixture(function($t,$c,$a,$owner){
            $t->save(['business_table'=>'aigc_digital_human_task','business_id'=>987]);
            $row=$owner+['id'=>987,'status'=>'running','results'=>[['video_url'=>'/business-composition']]];
            $rows=Results::appendWorkflowList([$row,array_replace($row,['id'=>988]),array_replace($row,['user_id'=>$owner['user_id']+1])],'aigc_digital_human_task');
            self::assertSame('running',$rows[0]['status']);self::assertSame($row['results'],$rows[0]['results']);
            self::assertSame(['app_task:'.$t['id']],$rows[0]['generation_task_refs']);self::assertSame([],$rows[1]['generation_tasks']);self::assertSame([],$rows[2]['generation_tasks']);
        });
    }

    public function testLegacyWrappersRetainTemporaryDeliveryProjectionOutage(): void
    {
        $response=Results::carry(['task_id'=>7,'status'=>'success'],['generation_tasks_pending'=>true]);
        self::assertTrue($response['generation_tasks_pending']);self::assertSame(7,$response['task_id']);
        self::assertSame(['task_id'=>7,'status'=>'success'],Results::withoutView($response));
    }

    public function testSharedTaskReferenceDoesNotChangeShortDramaGenerationInputFence(): void
    {
        $method=new \ReflectionMethod(\app\common\service\app\aigc_short_drama\canvas_agent\GenerationIntentService::class,'nodeInputSignature');$method->setAccessible(true);
        $node=['id'=>'1','type'=>'video','title'=>'Fixture','metadata'=>['prompt'=>'original','quality'=>'4k']];
        $before=$method->invoke(null,$node);$node['metadata']['generationTaskRef']='app_task:42';
        self::assertSame($before,$method->invoke(null,$node));$node['metadata']['prompt']='new prompt';self::assertNotSame($before,$method->invoke(null,$node));
    }

}
