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

    /** @dataProvider states */
    public function testTaskViewSeparatesExecutionBillingAndDelivery(string $execution,string $billing,string $storage,string $status,string $stage): void
    {
        $this->fixture(function($t,$c,$a,$owner)use($execution,$billing,$storage,$status,$stage){
            $t->save(['status'=>in_array($execution,['success','failed','canceled'],true)?$execution:'running']);$c->save(['run_status'=>$execution,'billing_status'=>$billing]);
            $a->save(['transfer_status'=>$storage]);
            $before=[$t->refresh()->toArray(),$c->refresh()->toArray(),$a->refresh()->toArray()];$jobs=(int)Db::name('ai_task_job')->where('consumption_id',$c['id'])->count();
            $r=Results::read((int)$t['id'],$owner['tenant_id'],$owner['user_id']);
            self::assertSame(1,$r['schema_version']);self::assertSame('app_task:'.$t['id'],$r['task_ref']);self::assertSame($status,$r['status']);self::assertSame($stage,$r['stage']);
            self::assertSame($storage==='stored'?1:0,count($r['results']));
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
            $other=$t->toArray();unset($other['id']);$other['task_no']='second-'.bin2hex(random_bytes(6));$other['idempotency_key']=$other['task_no'];$second=AiAppTask::create($other);
            $receipt=$c->toArray();unset($receipt['id']);$receipt['consume_no']=$other['task_no'];$receipt['app_task_id']=$second['id'];AiConsumptionLog::create($receipt);
            $children=[];foreach([$t['id'],$second['id']]as$id)$children[]=Db::name('aigc_image_task')->insertGetId($owner+['app_task_id'=>$id,'prompt'=>'Owned composite fixture','provider'=>'power_market','status'=>'success','delete_time'=>0,'create_time'=>time(),'update_time'=>time()]);
            $foreign=Db::name('aigc_image_task')->insertGetId(['tenant_id'=>$owner['tenant_id'],'user_id'=>$owner['user_id']+1,'app_task_id'=>$second['id'],'prompt'=>'Not this user','status'=>'success','delete_time'=>0,'create_time'=>time(),'update_time'=>time()]);
            $parent=['task_id'=>987,'status'=>'running','estimate'=>['user_charge_points'=>123],'results'=>[['module'=>'owned-business-result']]];
            $view=Results::appendRelated($parent,'image',array_merge($children,[$foreign]),...array_values($owner));
            self::assertSame('running',$view['status']);self::assertSame($parent['estimate'],$view['estimate']);self::assertSame($parent['results'],$view['results']);self::assertCount(2,$view['generation_tasks']);
            self::assertSame(['app_task:'.$t['id'],'app_task:'.$second['id']],$view['generation_task_refs']);self::assertArrayNotHasKey('generation_task',$view);
            self::assertSame(987,Results::carry($parent,$view)['task_id']);self::assertCount(2,Results::carry($parent,$view)['generation_tasks']);
            self::assertSame(['app_task:'.$t['id']],array_column(Results::readMany([$t['id'],2147483000],...array_values($owner)),'task_ref'));
        });
    }
}
