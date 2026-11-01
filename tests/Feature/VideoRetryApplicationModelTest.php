<?php
namespace Tests\Feature;

use app\common\service\app\aigc_video\AigcVideoService as Service;
use app\common\service\power\{MarketCreationParameters as Controls,MarketGenerationApplicationProtocol as Protocol,MarketVideoRuntimeService as Videos};
use app\common\model\app\aigc_video\AigcVideoTask;
use app\common\model\power\{PowerMarketProduct,PowerMarketSku};
use app\common\model\ai\{AiAppTask,AiConsumptionLog};
use PHPUnit\Framework\TestCase;
use think\facade\Db;

class VideoRetryApplicationModelTest extends TestCase
{
    private static function entry(): array
    {
        $fixture=json_decode(file_get_contents(dirname(__DIR__).'/fixtures/market-application-source-contracts-20261101.json'),true,512,JSON_THROW_ON_ERROR);
        foreach($fixture['entries']as$entry)if($entry['product']['upstream_app_code']==='seedance')return $entry;
        throw new \LogicException('Seedance capture missing');
    }
    private static function task(bool $video=false): array
    {
        $entry=self::entry();$sku=$entry['skus'][$video?0:1];
        return ['user_id'=>1,'prompt'=>'Stored retry prompt','negative_prompt'=>'','style'=>'general',
            'reference_images'=>[],'reference_assets'=>$video?[['type'=>'video','role'=>'reference_video','url'=>'https://fixtures.invalid/input.mp4']]:[],
            'channel'=>'market_video_app:seedance:140','market_product_id'=>140,'market_sku_id'=>$sku['id'],
            'quality'=>'480p','ratio'=>'16:9','duration'=>5,'quantity'=>1,
            'model_json'=>['model_id'=>'market_video_app:seedance:140','resource_type'=>'app_api'],
            'pricing_snapshot'=>['resource_type'=>'app_api','app_code'=>'seedance','api_code'=>'create']];
    }
    private static function request(array $task): array
    {
        return (new \ReflectionMethod(Service::class,'retryRequest'))->invoke(null,$task);
    }
    private static function option(array $sku): array
    {
        $entry=self::entry();return ['value'=>'market_video_app:seedance:140','resource_type'=>'app_api','app_code'=>'seedance','api_code'=>'create',
            'params_schema'=>$entry['metadata']['params_schema'],'default_params'=>$entry['metadata']['default_params'],
            'skus'=>[['market_sku_id'=>$sku['id'],'locked_params'=>$sku['locked_params']]]];
    }
    public static function explicitModels(): array
    {
        $cases=[];foreach([false,true]as$video)foreach(['advanced','provider','both']as$location)$cases[($video?'video':'text').'/'.$location]=[$video,$location];return $cases;
    }
    /** @dataProvider explicitModels */
    public function testExplicitFastChoiceSurvivesBusinessRetryThroughCurrentControlsAndNewSnapshot(bool $video,string $location): void
    {
        $task=self::task($video);$value=$video?'seedance-2-fast-video-2-video':'seedance-2-fast-text-2-video';
        if($location!=='provider')$task['model_json']['advanced_params']=['model'=>$value];
        if($location!=='advanced')$task['model_json']['provider_params']=['model'=>$value,'api_key'=>'never replay this'];
        $before=$task;$request=self::request($task);self::assertSame(['model'=>$value],$request['advanced_params']);self::assertArrayNotHasKey('provider_params',$request);
        $entry=self::entry();$sku=$entry['skus'][$video?0:1];$request=Controls::applyOptions($request,[self::option($sku)],'video');
        self::assertSame($value,$request['provider_params']['model']);
        $snapshot=Protocol::bindNewSnapshot(['resource_type'=>'app_api','app_code'=>'seedance','api_code'=>'create','sku_id'=>$sku['id'],
            'locked_params'=>$sku['locked_params'],'market_metadata'=>$entry['metadata']]);
        self::assertSame('seedance_create_20261101',$snapshot['application_contract']['profile']);
        self::assertSame($value,Videos::prepareRequest($snapshot,$request)->nativeInput['model']);self::assertSame($before,$task);
    }
    public function testOldMissingChoiceDoesNotAcquireANewDefaultAndOtherApplicationsRemainScoped(): void
    {
        $task=self::task();$before=$task;self::assertArrayNotHasKey('advanced_params',self::request($task));self::assertSame($before,$task);
        $task['model_json']['model_id']='market_video_app:wan:133';$task['channel']='market_video_app:wan:133';
        $task['pricing_snapshot']=['resource_type'=>'app_api','app_code'=>'wan','api_code'=>'create'];
        $task['model_json']['advanced_params']=['model'=>'wan2.7'];self::assertArrayNotHasKey('advanced_params',self::request($task));
    }
    public static function malformedChoices(): array
    {
        return [[null],[false],[42],[[]],[''],['conflicting'],['bad_container']];
    }
    /** @dataProvider malformedChoices */
    public function testMalformedOrConflictingStoredChoicesAreRejectedBeforeAnyNewRequest(mixed $value): void
    {
        $task=self::task();$task['model_json']['advanced_params']=['model'=>$value];
        if($value==='conflicting')$task['model_json']['provider_params']=['model'=>'seedance-2-fast-text-2-video'];
        if($value==='bad_container')$task['model_json']['advanced_params']='unparsed old object';
        $this->expectException(\Exception::class);self::request($task);
    }
    public static function noLongerAllowed(): array
    {
        return [['unknown'],['sku_lock'],['video_variant'],['wrong_api'],['removed_option']];
    }
    /** @dataProvider noLongerAllowed */
    public function testCurrentDeclarationAndSkuMayRejectButNeverSilentlyReplaceTheOldChoice(string $case): void
    {
        $task=self::task();$task['model_json']['provider_params']=['model'=>'seedance-2-fast-text-2-video'];$entry=self::entry();$sku=$entry['skus'][1];
        if($case==='unknown')$task['model_json']['provider_params']['model']='retired-model';
        if($case==='sku_lock')$sku['locked_params']['model']='seedance-2-text-2-video';
        if($case==='video_variant')$sku['locked_params']['_pricing_variant']='with_video';
        $option=self::option($sku);if($case==='wrong_api')$option['api_code']='query';
        $request=self::request($task);$this->expectException(\InvalidArgumentException::class);
        Controls::applyOptions($request,$case==='removed_option'?[]:[$option],'video');
    }

    public function testRealBusinessRetryOfUnboundedTokenSkuCannotCreateTaskConsumptionOrReservation(): void
    {
        if(getenv('AIGC_VIDEO_PREPARATION_DB_TESTS')!=='1')self::markTestSkipped('Explicit local rollback fixture only');
        (new \think\App())->initialize();self::assertContains(Db::connect()->getConfig('hostname'),['127.0.0.1','localhost']);self::assertContains(Db::connect()->getConfig('database'),['x_cn','z_cn']);
        $entry=self::entry();$tag='retry-model-'.bin2hex(random_bytes(8));Db::startTrans();
        try{
            $product=PowerMarketProduct::create(['product_code'=>$tag,'name'=>'Owned retry source','resource_type'=>'app_api','model_type'=>'video',
                'source_code'=>'owned-fixture','upstream_resource_key'=>$tag,'upstream_model_code'=>'','upstream_channel_code'=>'','upstream_app_code'=>'seedance','upstream_api_code'=>'create',
                'source_payload'=>['market_metadata'=>$entry['metadata']],'status'=>1,'create_time'=>time(),'update_time'=>time()]);
            $sku=PowerMarketSku::create(['product_id'=>$product['id'],'sku_key'=>$tag,'title'=>'Owned unbounded Token SKU','locked_params'=>$entry['skus'][1]['locked_params'],
                'usage_unit'=>'token','usage_unit_size'=>1000000,'upstream_price'=>1,'sale_points'=>1,'sale_status'=>1,'status'=>1,'source_payload'=>[],'create_time'=>time(),'update_time'=>time()]);
            $data=self::task();$data['tenant_id']=1;$data['channel']='market_video_app:seedance:'.$product['id'];$data['market_product_id']=$product['id'];$data['market_sku_id']=$sku['id'];
            $data['model_json']['model_id']=$data['channel'];$data['model_json']['advanced_params']=['model'=>'seedance-2-fast-text-2-video'];
            $data+=['provider'=>'power_market','model'=>$data['channel'],'provider_task_id'=>'','status'=>'failed','error'=>'Owned fixture','app_task_id'=>0,'consumption_id'=>0,
                'tenant_cost_points'=>0,'user_charge_points'=>0,'create_time'=>time(),'update_time'=>time(),'finish_time'=>time(),'delete_time'=>0];
            if(!AigcVideoTask::hasDurationColumn())unset($data['duration']);
            $task=AigcVideoTask::create($data);$before=$task->toArray();$counts=[AigcVideoTask::count(),AiAppTask::count(),AiConsumptionLog::count(),Db::name('user_account_log')->count(),Db::name('tenant_point_log')->count()];
            $balances=[(float)Db::name('tenant')->where('id',1)->value('point_balance'),(float)Db::name('user')->where('id',1)->value('user_money')];
            try{Service::retryTask(1,(int)$task['id']);self::fail('A business retry must not invent the Token upper bound');}
            catch(\Exception$error){self::assertStringContainsString('Token 费用上限',$error->getMessage());}
            self::assertSame($counts,[AigcVideoTask::count(),AiAppTask::count(),AiConsumptionLog::count(),Db::name('user_account_log')->count(),Db::name('tenant_point_log')->count()]);
            self::assertSame($balances,[(float)Db::name('tenant')->where('id',1)->value('point_balance'),(float)Db::name('user')->where('id',1)->value('user_money')]);
            self::assertSame($before,AigcVideoTask::find($task['id'])->toArray());
        }finally{Db::rollback();}
        self::assertSame(0,PowerMarketProduct::where('product_code',$tag)->count());
    }
}
