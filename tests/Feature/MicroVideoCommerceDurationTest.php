<?php
namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use think\facade\Db;
use app\common\service\app\aigc_micro_video\MicroVideoCommerceTemplates as Catalog;
use app\common\service\app\aigc_micro_video\MicroVideoWorkbenchRecords as Records;
use app\common\service\app\aigc_micro_video\MicroVideoWorkbenchJobs as Jobs;
use app\common\service\app\aigc_micro_video\MicroVideoStore as Store;
use app\common\service\app\aigc_micro_video\MicroVideoSettings as Settings;
use app\common\service\app\aigc_micro_video\MicroVideoService as Service;

/** Local transaction only. Import prepares a draft; no generation confirmation or worker runs. */
final class MicroVideoCommerceDurationTest extends TestCase
{
    private bool $transaction=false;
    private array $config=[];
    private ?string $file=null;

    protected function setUp(): void
    {
        (new \think\App(dirname(__DIR__,2).'/'))->initialize();$db=config('database.connections.mysql');
        if($db['hostname']!=='127.0.0.1'||$db['database']!=='z_cn')self::markTestSkipped('Local transactional acceptance only');
        Db::startTrans();$this->transaction=true;$this->config=Settings::get(1);
        // Make the existing tenant policy explicit in the disposable test transaction.
        $config=$this->config;$config['target_duration_options']=[15,30,60,90,120];
        Store::table('config')->where('tenant_id',1)->update(['config_json'=>Store::encode($config)]);
    }

    protected function tearDown(): void
    {if($this->transaction)Db::rollback();if($this->file&&is_file($this->file))unlink($this->file);}

    private function project(bool $trusted=true): array
    {
        $template=array_values(array_filter(Catalog::catalog()['templates'],static fn($t)=>$t['default_duration']===10))[0];
        $template['seed_key']='commerce_v1_'.$template['code'];
        $saved=$trusted?Records::saveTrustedTemplate(1,['kind'=>'template','title'=>$template['title'],'visibility'=>'tenant','body'=>$template]):Records::save(1,1,['kind'=>'template','title'=>'Ordinary template','body'=>['script'=>$template['script']]]);
        return Records::save(1,1,['kind'=>'project','title'=>'Ten second policy fixture','body'=>['template'=>['id'=>$saved['id'],'revision'=>1],'model_id'=>'market_video_model:182','quality'=>'768P','ratio'=>'9:16','target_duration'=>10,'script'=>['text'=>$template['script'],'segments'=>$template['segments']]]]);
    }

    private function body(array $project): array
    {return Store::json(Records::owned(1,1,'project',$project['id'])['body_json']);}

    private function params(array $body): array
    {return ['model_id'=>$body['model_id'],'quality'=>$body['quality'],'ratio'=>$body['ratio'],'target_duration'=>10,'mode'=>'prompt','prompt'=>$body['script']['text'],'direction_id'=>0,'idempotency_key'=>'duration_test_'.bin2hex(random_bytes(8))];}

    private function image(): array
    {
        $uri='uploads/micro-commerce-duration-'.bin2hex(random_bytes(8)).'.png';$this->file=dirname(__DIR__,2).'/public/'.$uri;
        file_put_contents($this->file,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/a9sAAAAASUVORK5CYII='));
        Db::name('tenant_file')->insert(['tenant_id'=>1,'source_id'=>1,'source'=>1,'type'=>10,'cid'=>0,'uri'=>$uri,'name'=>'Fixture','storage_scope'=>'platform','storage_engine'=>'local','storage_domain'=>'','create_time'=>time(),'delete_time'=>0]);
        return ['uri'=>$uri];
    }

    public function testTrustedTenSecondTemplateCanPrepareARealRewriteRequestWithoutChangingTenantPolicy(): void
    {
        $project=$this->project();$before=Db::name('ai_consumption_log')->count();$config=Settings::get(1);
        $body=$this->body($project);$prepared=Service::form(1,1,$this->params($body),false,Service::workbenchTargetDurations($body));
        self::assertSame(10,$prepared['params']['target_duration']);self::assertSame($config['target_duration_options'],$prepared['config']['target_duration_options']);
        $text=\app\common\service\power\MarketTextModelRuntimeService::textOptions(1);self::assertNotEmpty($text);
        $input=Jobs::input(1,1,['project_id'=>$project['id'],'project_revision'=>1,'action'=>'rewrite','selection'=>['model_id'=>$text[0]['id']]]);
        self::assertSame(10,$input['request']['planning_contract']['target_duration']);self::assertSame([10],$input['request']['planning_contract']['segment_durations']);
        self::assertSame($config,Settings::get(1));self::assertNotContains(10,Settings::get(1)['target_duration_options']);self::assertSame($before,Db::name('ai_consumption_log')->count());
    }

    public function testTrustedTenSecondTemplateImportsAnUnsubmittedDraftUsingRealModelSpecifications(): void
    {
        $project=$this->project();$body=$this->body($project);$params=$this->params($body);$params['images']=[$this->image()];$params['continuity']=$body['template_snapshot']['body']['reference_rules'];
        $segments=$body['script']['segments'];$segments[0]['image_indices']=[0];$before=Db::name('ai_consumption_log')->count();$config=Settings::get(1);
        $task=Service::importWorkbenchPlan(1,1,$params,$segments,['project_id'=>$project['id'],'project_revision'=>1,'body'=>$body]);
        self::assertSame('awaiting_confirmation',$task['status']);self::assertSame(10,$task['params']['target_duration']);self::assertSame(10,$task['segments'][0]['duration']);
        self::assertSame(0,Store::table('attempt')->where('task_id',$task['id'])->count());self::assertSame($before,Db::name('ai_consumption_log')->count());self::assertSame($config,Settings::get(1));
    }

    public function testHttpArgumentsCannotOpenTenSecondsForLegacyFormOrAnOrdinaryTemplate(): void
    {
        $project=$this->project(false);$body=$this->body($project);$params=$this->params($body)+['allowedTargetDurations'=>[10],'allowed_target_durations'=>[10],'protected_prompt'=>true,'internal_prompt'=>'forged'];
        foreach([fn()=>Service::form(1,1,$params,false),fn()=>Service::importWorkbenchPlan(1,1,$params,[],['body'=>$body])] as $operation){
            try{$operation();self::fail('Legacy duration policy bypassed');}catch(\InvalidArgumentException $error){self::assertSame('请选择开放的目标时长',$error->getMessage());}
        }
        $this->expectException(\InvalidArgumentException::class);$this->expectExceptionMessage('请选择开放的目标时长');
        $text=\app\common\service\power\MarketTextModelRuntimeService::textOptions(1);
        Jobs::input(1,1,['project_id'=>$project['id'],'project_revision'=>1,'action'=>'rewrite','selection'=>['model_id'=>$text[0]['id']]]);
    }

    public function testPublicSnapshotCannotGrantTheInternalDurationAllowance(): void
    {
        $project=$this->project();$body=Records::publicBody($this->body($project));
        $this->expectException(\InvalidArgumentException::class);$this->expectExceptionMessage('请选择开放的目标时长');
        Service::importWorkbenchPlan(1,1,$this->params($body),[],['body'=>$body]);
    }

    public function testTrustedDurationAllowanceDoesNotMakeUnsupportedModelSegmentsCallable(): void
    {
        $project=$this->project();$body=$this->body($project);$params=$this->params($body);$params['model_id']='market_video_model:156';$params['quality']='1080P';$params['ratio']='16:9';$params['images']=[$this->image()];$params['continuity']=$body['template_snapshot']['body']['reference_rules'];
        $model=\app\common\service\app\aigc_micro_video\MicroVideoCatalog::model(1,$params['model_id']);$params['quality']=$model['specs'][0]['quality'];
        self::assertNotContains(10,array_column($model['specs'],'duration'));
        $segments=$body['script']['segments'];$segments[0]['image_indices']=[0];
        $this->expectException(\InvalidArgumentException::class);$this->expectExceptionMessage('分段时长不受当前模型支持');
        Service::importWorkbenchPlan(1,1,$params,$segments,['project_id'=>$project['id'],'project_revision'=>1,'body'=>$body]);
    }

    public function testOnlyProtectedCommerceTenSecondServerSnapshotsReceiveTheAllowance(): void
    {
        $project=$this->project();$body=$this->body($project);self::assertSame([10],Service::workbenchTargetDurations($body));
        foreach(['protected_prompt'=>false,'internal_prompt'=>'','workflow_type'=>'story','default_duration'=>15,'seed_key'=>'starter_v1_product'] as $field=>$value){
            $invalid=$body;$invalid['template_snapshot']['body'][$field]=$value;self::assertSame([],Service::workbenchTargetDurations($invalid));
        }
    }
}
