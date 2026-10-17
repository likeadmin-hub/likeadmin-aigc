<?php
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
use think\facade\Db;
use app\common\service\app\aigc_micro_video\MicroVideoFrames as F;
use app\common\service\app\aigc_micro_video\MicroVideoService as V;
use app\common\service\app\aigc_micro_video\MicroVideoStore as S;
use app\common\service\app\aigc_micro_video\MicroVideoSettings as C;
use app\common\service\app\aigc_micro_video\MicroVideoFrameCatalog as Catalog;

/** Transactional local admission tests. No paid provider call or persistent balance change. */
final class MicroVideoFramesWorkflowTest extends TestCase
{
    private int $id;private array $model;
    protected function setUp(): void
    {
        (new \think\App(dirname(__DIR__,2).'/'))->initialize();$db=config('database.connections.mysql');if($db['hostname']!=='127.0.0.1'||$db['database']!=='z_cn')self::markTestSkipped('Local transactional tests only');
        $template=S::table('task')->where(['tenant_id'=>1,'user_id'=>1])->order('id')->find();$models=Catalog::models(1);if(!$template||!$models)self::markTestSkipped('Local owned task and reserved image model required');$this->model=$models[0];
        Db::startTrans();unset($template['id']);$params=S::json($template['params_json']);$params['ratio']=$this->model['specs'][0]['ratio'];$snapshot=S::json($template['config_snapshot']);$snapshot['frame_feature_version']=1;$snapshot['shot_timeline_version']=0;$template['model_snapshot']=S::encode(\app\common\service\app\aigc_micro_video\MicroVideoCatalog::model(1,$params['model_id']));$template=array_replace($template,['idempotency_key'=>'frames_test_'.bin2hex(random_bytes(10)),'params_json'=>S::encode($params),'config_snapshot'=>S::encode($snapshot),'status'=>'awaiting_confirmation','generation_mode'=>'reference','frame_status'=>'off','frame_model_id'=>'','frames_revision'=>0,'plan_revision'=>1,'quote_token'=>'','quote_json'=>'{}','confirmed_quote'=>'{}','submit_key'=>'','resume_key'=>'','lease_token'=>'','lease_until'=>0,'delete_time'=>0]);$this->id=S::table('task')->insertGetId($template);
        foreach([1,2] as $sort)S::table('segment')->insert(['tenant_id'=>1,'user_id'=>1,'task_id'=>$this->id,'sort'=>$sort,'title'=>'镜头'.$sort,'duration'=>6,'prompt'=>'0-3秒：人物展示蓝瓶。3-6秒：蓝瓶特写。','image_indices'=>'[0]','start_state'=>'持瓶站定','end_state'=>'瓶身正面展示','continuity'=>'保持身份光线','status'=>'pending']);
        $c=C::get(1);$c['frames_enabled']=1;$c['frame_model_ids']=[$this->model['id']];$c['default_frame_model_id']=$this->model['id'];$c['default_frame_quality']=$this->model['specs'][0]['quality'];S::table('config')->where('tenant_id',1)->update(['config_json'=>S::encode($c)]);
    }
    protected function tearDown(): void{Db::rollback();}
    private function configured(): array{return F::configure(1,1,['id'=>$this->id,'plan_revision'=>1,'generation_mode'=>'start_end','frame_model_id'=>$this->model['id'],'frame_quality'=>$this->model['specs'][0]['quality']]);}
    private function reject(callable $f): void{try{$f();}catch(\InvalidArgumentException|\RuntimeException $e){self::assertNotSame('',$e->getMessage());return;}self::fail('Admission should reject');}
    public function testConfigurationCreatesNPlusOneSharedBoundaryRowsWithoutBilling(): void
    {
        $before=Db::name('ai_consumption_log')->count();$d=$this->configured();self::assertCount(3,$d['frames']);self::assertSame('idle',$d['frame_status']);self::assertSame($d['segments'][0]['last_frame_id'],$d['segments'][1]['first_frame_id']);self::assertSame($before,Db::name('ai_consumption_log')->count());$same=$this->configured();self::assertSame(array_column($d['frames'],'id'),array_column($same['frames'],'id'));
    }
    public function testQuoteIsBoundToRevisionAndReplayCreatesOnlyOneAdmission(): void
    {
        $d=$this->configured();$q=F::estimate(1,1,['id'=>$this->id,'plan_revision'=>1]);self::assertSame(3,$q['frame_count']);$before=Db::name('ai_consumption_log')->count();$p=['id'=>$this->id,'plan_revision'=>1,'frames_revision'=>$d['frames_revision'],'quote_token'=>$q['quote_token'],'idempotency_key'=>'frame_generate_fixture'];$a=F::generate(1,1,$p);$b=F::generate(1,1,$p);self::assertSame('generating',$a['frame_status']);self::assertSame($a['frames_revision'],$b['frames_revision']);self::assertSame(1,S::table('frame_batch')->where('task_id',$this->id)->count());self::assertSame($before,Db::name('ai_consumption_log')->count());$p['frames_revision']++;$this->reject(fn()=>F::generate(1,1,$p));$this->reject(fn()=>F::save(1,1,['id'=>$this->id,'plan_revision'=>1,'frames_revision'=>$a['frames_revision'],'frame_id'=>$a['frames'][0]['id'],'prompt'=>'改动','reference_indices'=>[0]]));
    }
    public function testStaticEditKeepsEarlierMediaAndInvalidatesDependentTailAndQuote(): void
    {
        $d=$this->configured();S::table('frame')->where('task_id',$this->id)->update(['status'=>'success','confirmed'=>1,'image_uri'=>'uploads/kept-audit.png']);$before=F::rows($this->id);$q=F::estimate(1,1,['id'=>$this->id,'plan_revision'=>1,'frame_ids'=>[$before[1]['id']]]);$changed=F::save(1,1,['id'=>$this->id,'plan_revision'=>1,'frames_revision'=>$d['frames_revision'],'frame_id'=>$before[1]['id'],'prompt'=>'静态人物举起蓝瓶，保持身份。','reference_indices'=>[0]]);$after=F::rows($this->id);self::assertSame($before[0],$after[0]);self::assertNotSame($before[1]['id'],$after[1]['id']);self::assertSame('stale',$after[1]['status']);self::assertSame('stale',$after[2]['status']);self::assertSame('uploads/kept-audit.png',S::table('frame')->where('id',$before[1]['id'])->value('image_uri'));$this->reject(fn()=>F::generate(1,1,['id'=>$this->id,'plan_revision'=>1,'frames_revision'=>$changed['frames_revision'],'quote_token'=>$q['quote_token'],'frame_ids'=>[$after[1]['id']],'idempotency_key'=>'stale_frame_quote']));
    }
    public function testFrameOwnershipReadinessAndOldRevisionsAreRejected(): void
    {
        $d=$this->configured();$this->reject(fn()=>F::confirm(1,1,['id'=>$this->id,'plan_revision'=>1,'frames_revision'=>$d['frames_revision']]));$this->reject(fn()=>F::estimate(1,2,['id'=>$this->id,'plan_revision'=>1]));$this->reject(fn()=>F::save(1,1,['id'=>$this->id,'plan_revision'=>1,'frames_revision'=>0,'frame_id'=>$d['frames'][0]['id'],'prompt'=>'静止画面','reference_indices'=>[0]]));$this->reject(fn()=>F::estimate(1,1,['id'=>$this->id,'plan_revision'=>1,'frame_ids'=>[987654321]]));$this->reject(fn()=>V::quoteTask(S::owned(1,1,$this->id)));
    }
    public function testOrdinaryPlanSavePreservesStableSegmentIdsAndInvalidatesOnlyChangedTail(): void
    {
        $d=$this->configured();$before=S::segments($this->id);$segments=array_map(fn($s)=>S::segmentData($s),$before);$segments[1]['end_state']='瓶身微微倾斜';$saved=V::savePlan(1,1,['id'=>$this->id,'plan_revision'=>1,'segments'=>$segments]);self::assertSame(array_column($before,'id'),array_column(S::segments($this->id),'id'));self::assertSame($d['frames'][0]['id'],$saved['frames'][0]['id']);self::assertNotSame($d['frames'][1]['id'],$saved['frames'][1]['id']);self::assertSame(2,$saved['plan_revision']);
    }
    public function testLegacyTaskCannotOptInAndKeepsReferenceMode(): void
    {
        $task=S::owned(1,1,$this->id);$c=S::json($task['config_snapshot']);unset($c['frame_feature_version']);S::update($this->id,['config_snapshot'=>S::encode($c)]);$this->reject(fn()=>$this->configured());self::assertSame('reference',S::owned(1,1,$this->id)['generation_mode']);
    }
}
