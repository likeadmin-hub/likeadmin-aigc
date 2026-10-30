<?php
namespace Tests\Feature;

use app\common\service\app\aigc_micro_video\MicroVideoWorkbenchRecords as Records;
use app\common\service\app\aigc_micro_video\MicroVideoWorkbenchJobs as Jobs;
use app\common\service\app\aigc_micro_video\MicroVideoWorkbench as Workbench;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

/** Transactional fixtures only. No runtime/model generation or user project mutation. */
final class MicroVideoGeneratedReferenceRecoveryTest extends TestCase
{
    protected function setUp(): void
    {
        (new \think\App(dirname(__DIR__,2).'/'))->initialize();$db=config('database.connections.mysql');
        if($db['hostname']!=='127.0.0.1'||$db['database']!=='z_cn')self::markTestSkipped('Local transactional acceptance only');
        Db::startTrans();
    }
    protected function tearDown(): void {if(Db::getConfig())Db::rollback();}
    private function fixture(): array
    {
        $project=Records::save(1,1,['kind'=>'project','title'=>'Generated reference recovery fixture','body'=>['images'=>[]]]);
        $script=['text'=>'保留已付费生成的正文','continuity'=>'同一地点','segments'=>[['sort'=>1,'title'=>'交接','duration'=>15,'prompt'=>"0-3秒：人物拿起商品。\n3-15秒：配角接过并回应。",'image_indices'=>[0],'start_state'=>'商品在桌上','end_state'=>'配角拿稳商品','continuity'=>'同一地点']]];
        $request=['snapshot'=>['target_duration'=>15,'images'=>[]]];
        $id=Jobs::table()->insertGetId(['tenant_id'=>1,'user_id'=>1,'project_id'=>$project['id'],'project_revision'=>1,'action'=>'rewrite','idempotency_key'=>'reference-recovery-'.bin2hex(random_bytes(8)),'input_hash'=>str_repeat('a',64),'status'=>'success','request_json'=>json_encode($request),'receipt_json'=>json_encode(['content'=>json_encode(['script'=>$script])]),'result_json'=>json_encode(['script'=>$script]),'create_time'=>time(),'update_time'=>time()]);
        return [$project,$id,$script];
    }
    public function testExistingSuccessfulRewriteCanBeAdoptedWithoutPaidRegeneration(): void
    {
        [$project,$id]=$this->fixture();$before=Db::name('ai_consumption_log')->count();$job=Jobs::owned(1,1,$id);
        $adopted=Jobs::adopt(1,1,['project_id'=>$project['id'],'project_revision'=>1,'job_id'=>$id]);
        self::assertSame([],$adopted['body']['script']['segments'][0]['image_indices']);
        self::assertSame('保留已付费生成的正文',$adopted['body']['script']['text']);
        self::assertSame($job['receipt_json'],Jobs::owned(1,1,$id)['receipt_json']);
        self::assertSame($before,Db::name('ai_consumption_log')->count());
        $this->expectException(\InvalidArgumentException::class);Workbench::executionReferences($adopted['body']);
    }
    public function testLegacyGeneratedProjectReadIsLazyAndManualScriptEditsRemainUntouched(): void
    {
        [$project,$id,$script]=$this->fixture();$body=['images'=>[],'script'=>$script,'last_script_job_id'=>$id,'stage'=>'script_ready','scenes'=>[['scene_id'=>'s1','sort'=>1,'confirmed'=>true,'status'=>'ready','images'=>[],'role_ids'=>[]]]];
        Records::table()->where('id',$project['id'])->update(['body_json'=>json_encode($body)]);
        $stored=Records::owned(1,1,'project',$project['id']);$read=Records::dto($stored);
        self::assertSame([],$read['body']['script']['segments'][0]['image_indices']);
        self::assertSame($body['scenes'],$read['body']['scenes']);
        self::assertSame($stored['body_json'],Records::owned(1,1,'project',$project['id'])['body_json']);
        $body['script']['text']='用户手工修改正文';Records::table()->where('id',$project['id'])->update(['body_json'=>json_encode($body)]);
        self::assertSame([0],Records::dto(Records::owned(1,1,'project',$project['id']))['body']['script']['segments'][0]['image_indices']);
        $body['script']=$script;$body['script']['segments'][0]['image_indices']=[9];Records::table()->where('id',$project['id'])->update(['body_json'=>json_encode($body)]);
        self::assertSame([9],Records::dto(Records::owned(1,1,'project',$project['id']))['body']['script']['segments'][0]['image_indices']);
        $body['script']=$script;$body['last_script_job_id']=$id;Jobs::table()->where('id',$id)->update(['user_id'=>2]);
        Records::table()->where('id',$project['id'])->update(['body_json'=>json_encode($body)]);
        self::assertSame([0],Records::dto(Records::owned(1,1,'project',$project['id']))['body']['script']['segments'][0]['image_indices']);
    }

    public function testRetainedSettledReceiptReconcilesLocallyAndDoesNotChargeAgain(): void
    {
        [$project,$id,$script]=$this->fixture();$script['segments'][0]['image_indices']=[99];
        $receipt=json_encode(['content'=>json_encode(['script'=>$script])]);
        $consumption=Db::name('ai_consumption_log')->order('id')->find();self::assertNotEmpty($consumption);unset($consumption['id']);
        $consumption=array_replace($consumption,['tenant_id'=>1,'user_id'=>1,'app_code'=>'aigc_micro_video','consume_no'=>'reference-recovery-'.bin2hex(random_bytes(8)),'run_status'=>'success','billing_status'=>'settled','actual_user_price'=>2.5]);
        $consumptionId=Db::name('ai_consumption_log')->insertGetId($consumption);
        Jobs::table()->where('id',$id)->update(['status'=>'failed','consumption_id'=>$consumptionId,'receipt_json'=>$receipt,'result_json'=>'{}','error'=>'分段引用图片不存在']);
        $before=Db::name('ai_consumption_log')->count();$balance=Db::name('user')->where('id',1)->value('user_money');
        try{
            $recovered=Jobs::reconcile(1,1,$id);$again=Jobs::reconcile(1,1,$id);
            self::assertSame('success',$recovered['status']);self::assertSame([],$recovered['result']['script']['segments'][0]['image_indices']);
            self::assertSame($recovered['result'],$again['result']);self::assertSame(2.5,$again['user_charge_points']);
            self::assertSame($receipt,Jobs::owned(1,1,$id)['receipt_json']);self::assertSame($before,Db::name('ai_consumption_log')->count());
            self::assertSame($balance,Db::name('user')->where('id',1)->value('user_money'));
        }finally{@unlink(runtime_path().'micro_workbench_job_'.$id.'.lock');}
    }

    public function testActualRewriteRequestHasAnImageFreeSchemaEvenWithASelectedVideoModel(): void
    {
        $config=\app\common\service\app\aigc_micro_video\MicroVideoSettings::detail(1);$models=$config['models']??[];
        $writers=\app\common\service\power\MarketTextModelRuntimeService::textOptions(1);
        if(!$models||!$writers)self::markTestSkipped('Requires existing local model catalogs; no model is called');
        $model=$models[0];$spec=$model['specs'][0];
        $project=Records::save(1,1,['kind'=>'project','title'=>'Image-free writing contract fixture','body'=>['images'=>[],'model_id'=>$model['id'],'quality'=>$spec['quality'],'ratio'=>$spec['ratio'],'target_duration'=>15]]);
        $before=Db::name('ai_consumption_log')->count();
        $input=Jobs::input(1,1,['project_id'=>$project['id'],'project_revision'=>1,'action'=>'rewrite','selection'=>['model_id'=>$writers[0]['id']]]);
        $request=$input['request'];$content=json_decode($request['content'],true);
        self::assertSame(0,$request['image_reference_contract']['image_count']);self::assertSame([],$content['image_reference_contract']['allowed_image_indices']);
        self::assertSame([],$content['planning_contract']['output_schema']['segments'][0]['image_indices']);
        self::assertSame([],$request['snapshot']['images']);self::assertSame($before,Db::name('ai_consumption_log')->count());
    }
}
