<?php
namespace Tests\Feature;

use app\common\model\ai\{AiAppTask,AiConsumptionLog,AiTaskResultAsset};
use app\common\service\ai\{AiTaskBusinessResultService,AiTaskResultAssetService};
use app\common\service\point\PointService;
use app\common\service\power\{MarketVideoRuntimeService as Videos,MarketGenerationRuntime as Runtime,MarketGenerationMedia as Media};
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use think\facade\Db;

class MarketMeasuredVideoCompletionTest extends TestCase
{
    /** @dataProvider quantities */
    public function testOnlyVerifiedOutputSecondsCanCompleteAnActualDurationReservation(array $changes,array $videos,float $expected): void
    {
        $snapshot=array_replace(['requires_actual_duration'=>true,'usage_unit'=>'output_second','quantity'=>99,
            'billing_meter'=>['basis'=>'output']],$changes);
        self::assertSame($expected,(new ReflectionMethod(Videos::class,'measuredOutputSeconds'))->invoke(null,$snapshot,$videos));
    }
    public static function quantities(): array
    {
        $video=['video_uri'=>'uploads/verified.mp4','storage_scope'=>'tenant','storage_engine'=>'local','duration'=>1.04];
        return [
            [[],[$video],1.04], [[],[$video,$video],1.04],
            [[],[$video,array_replace($video,['video_uri'=>'uploads/second.mp4','duration'=>2.0])],3.04],
            [[],[],0.0], [[],[array_diff_key($video,['duration'=>true])],0.0],
            [[],[array_replace($video,['duration'=>0])],0.0], [[],[array_replace($video,['duration'=>INF])],0.0],
            [[],[array_replace($video,['duration'=>-1])],0.0], [[],[array_replace($video,['storage_engine'=>''])],0.0],
            [[],[$video,array_diff_key($video,['duration'=>true])],0.0],
            [['requires_actual_duration'=>false],[$video],0.0],
            [['usage_unit'=>'token'],[$video],0.0],
            [['billing_meter'=>['basis'=>'call']],[$video],0.0],
            [['billing_meter'=>['basis'=>'input']],[$video],0.0],
            [['billing_meter'=>null,'usage_unit'=>'per_call'],[$video],0.0],
            [['billing_meter'=>['basis'=>'output_plus_video']],[$video],1.04],
            [['billing_meter'=>['basis'=>'output_plus_max_video']],[$video],1.04],
        ];
    }

    public function testLocalProbeMeasuresTheFileWithoutRoundingItToTheRequestedSeconds(): void
    {
        (new \think\App())->initialize();
        $path=$this->fixturePath();
        $facts=Media::localFacts($path);
        self::assertSame('video',$facts['kind']);self::assertEqualsWithDelta(1.04,$facts['duration'],0.000001);
        self::assertSame(32,$facts['width']);self::assertSame(32,$facts['height']);
    }

    public function testExistingCompletedVideoSettlesOnceAndStopsRegisteringOrUploadingCopies(): void
    {
        if(getenv('AIGC_TRANSFER_CHECKPOINT_DB_TESTS')!=='1')self::markTestSkipped('Explicit local rollback fixture only');
        (new \think\App())->initialize();
        self::assertContains(Db::connect()->getConfig('hostname'),['127.0.0.1','localhost']);
        self::assertContains(Db::connect()->getConfig('database'),['x_cn','z_cn']);
        $user=(int)Db::name('user')->where('tenant_id',1)->where('user_money','>',100)->order('user_money','desc')->value('id');
        if(!$user)self::markTestSkipped('Funded local fixture unavailable');
        $key='measured-video-'.bin2hex(random_bytes(6));$uri='uploads/'.$key.'.mp4';$path=root_path().'public/'.$uri;
        copy($this->fixturePath(),$path);
        Db::startTrans();
        try{
            Db::name('tenant')->where('id',1)->lock(true)->find();Db::name('user')->where('id',$user)->lock(true)->find();
            $before=$this->balances($user);
            $task=AiAppTask::create(['task_no'=>$key,'tenant_id'=>1,'user_id'=>$user,'app_code'=>'aigc_video','action_code'=>'video_generate',
                'business_table'=>'aigc_video_task','business_id'=>0,'status'=>'success','progress'=>100,'idempotency_key'=>$key,
                'create_time'=>time(),'update_time'=>time(),'finish_time'=>time()]);
            $snapshot=['protocol'=>'video_generate','resource_type'=>'model','model_type'=>'video','product_id'=>2147483000,'quantity'=>1,
                'usage_unit'=>'output_second','usage_unit_size'=>1,'platform_price'=>10,'tenant_price'=>10,'requires_actual_duration'=>true,
                'billing_meter'=>['version'=>1,'basis'=>'output','round_up'=>true,'source'=>'legacy_summary']];
            $video=['video_uri'=>$uri,'width'=>0,'height'=>0,'storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>''];
            $c=AiConsumptionLog::create(['consume_no'=>$key,'app_task_id'=>$task['id'],'tenant_id'=>1,'user_id'=>$user,'app_code'=>'aigc_video',
                'action_code'=>'video_generate','provider'=>'power_market','resource_type'=>'model','protocol'=>'video_generate',
                'upstream_task_id'=>'accepted-offline-fixture','price_snapshot'=>$snapshot,'response_summary'=>['videos'=>[$video]],
                'run_status'=>'success','billing_status'=>'reserved','reserved_tenant_cost'=>10,'reserved_user_price'=>10,
                'create_time'=>time(),'update_time'=>time()]);
            PointService::reserveBusinessAmountsInCurrentTransaction(1,$user,10,10,$key.'-reserve','Offline fixture',
                ['app_code'=>'aigc_video','app_task_id'=>(int)$task['id'],'consumption_id'=>(int)$c['id']]);
            $asset=AiTaskResultAsset::create(['consumption_id'=>$c['id'],'app_task_id'=>$task['id'],'tenant_id'=>1,'user_id'=>$user,'asset_type'=>'video',
                'external_url'=>'https://fixture.invalid/already-generated.mp4','local_uri'=>$uri,'storage_scope'=>'tenant','storage_engine'=>'local',
                'storage_domain'=>'','storage_meta'=>[],'transfer_status'=>'stored','create_time'=>time(),'update_time'=>time()]);
            $fileCount=Db::name('tenant_file')->count();$count=AiTaskResultAsset::where('consumption_id',$c['id'])->count();
            $runtime=Runtime::consumption((int)$c['id'],1,$user);
            foreach([1,2] as $attempt){
                $result=$runtime->refresh((int)$c['id'],1,$user);
                self::assertSame('success',$result['generation_task']['status']);self::assertFalse($result['generation_task']['polling_required']);
                $fresh=AiConsumptionLog::find($c['id']);
                self::assertSame('settled',$fresh['billing_status']);self::assertSame(11.0,(float)$fresh['actual_user_price']);
                self::assertSame(1.04,(float)$fresh['usage_snapshot']['actual_quantity']);
                self::assertSame('managed_output_metadata',$fresh['usage_snapshot']['actual_quantity_source']);
                self::assertSame($before['user']-11,$this->balances($user)['user']);
                self::assertSame($before['tenant']-11,$this->balances($user)['tenant']);
                self::assertSame($count,AiTaskResultAsset::where('consumption_id',$c['id'])->count());
                self::assertSame($fileCount,Db::name('tenant_file')->count());
            }
            self::assertSame(1,(int)Db::name('ai_consumption_event')->where(['consumption_id'=>$c['id'],'event_type'=>'settle'])->count());
            $this->expectException(\Exception::class);
            AiTaskResultAssetService::measureStoredVideo((int)$asset['id'],(int)$c['id'],1,$user+1);
        }finally{Db::rollback();@unlink($path);}
    }

    private function fixturePath(): string
    {
        $path=(string)getenv('AIGC_TRANSFER_MEDIA_DIR').'/measured-1.04.mp4';
        if(!is_file($path))self::markTestSkipped('Generate the isolated 32x32 1.04-second clip first');
        return $path;
    }
    private function balances(int $user): array
    {
        return ['tenant'=>(float)Db::name('tenant')->where('id',1)->value('point_balance'),
            'user'=>(float)Db::name('user')->where('id',$user)->value('user_money')];
    }
}
