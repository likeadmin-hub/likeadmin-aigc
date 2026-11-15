<?php
/** Local transaction fixtures; upstream HTTP and storage are synthetic, actual wallets roll back. */
namespace app\common\service\update {
    final class UpdateSourceClient {
        public static function getSource(): array {return ['base_url'=>'https://offline.fixture.invalid','api_key'=>'offline-test'];}
        public static function sslVerify(array $source): bool {return true;}
    }
}
namespace app\common\service\app\aigc_video {
    final class AigcVideoAssetService {
        public static array $facts=[];
        public static function persistGeneratedVideo(string $url,int $tenant,int $user): array {
            return self::$facts+['uri'=>'uploads/offline-lipsync.mp4','width'=>0,'height'=>0,'stored'=>true,'storage_engine'=>'local','storage_domain'=>''];
        }
    }
}
namespace app\common\service\power {
    final class PicLipsyncOfflineHttp {
        public static array $body=[];
        public static int $errno=0;
        public static int $calls=0;
    }
    function curl_init(){return new \stdClass();}
    function curl_setopt_array($handle,$options){
        if(!str_ends_with($options[CURLOPT_URL],'/pic_lipsync/query'))throw new \RuntimeException('No generation submissions allowed');
        return true;
    }
    function curl_setopt($handle,$key,$value){return true;}
    function curl_exec($handle){PicLipsyncOfflineHttp::$calls++;return json_encode(PicLipsyncOfflineHttp::$body);}
    function curl_errno($handle){return PicLipsyncOfflineHttp::$errno;}
    function curl_error($handle){return PicLipsyncOfflineHttp::$errno?'Offline simulated network timeout':'';}
    function curl_getinfo($handle,$key){return 200;}
    function curl_close($handle){}
}
namespace {
    require dirname(__DIR__).'/vendor/autoload.php';
    (new \think\App())->initialize();
    set_exception_handler(static function(\Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
    use think\facade\Db;
    use app\common\service\power\MarketPicLipsyncAppRuntimeService as Runtime;
    use app\common\service\power\PicLipsyncOfflineHttp as Http;
    use app\common\service\app\aigc_video\AigcVideoAssetService as Storage;
    use app\common\service\point\PointService;
    use app\common\service\app\aigc_pic_lipsync\AigcPicLipsyncService as AppService;
    $db=config('database.connections.mysql');
    if(PHP_SAPI!=='cli'||$db['hostname']!=='127.0.0.1'||!in_array($db['database'],['z_cn','x_cn'],true))throw new \RuntimeException('Local rollback only');
    $checks=0;$check=static function($ok,string $why)use(&$checks){if(!$ok)throw new \RuntimeException($why);$checks++;};
    $tenant=1800000000+random_int(1,10000000);$user=1900000000+random_int(1,10000000);
    $wallet=static fn()=>[(float)Db::name('tenant')->where('id',$tenant)->value('point_balance'),(float)Db::name('user')->where('id',$user)->value('user_money')];
    $find=static fn(int $id)=>Db::name('ai_consumption_log')->where('id',$id)->find();
    Db::startTrans();
    try {
        Db::name('tenant')->insert(['id'=>$tenant,'sn'=>'pl-offline','name'=>'Offline settlement fixture','point_balance'=>1000,'disable'=>0,'create_time'=>time(),'update_time'=>time()]);
        Db::name('user')->insert(['id'=>$user,'tenant_id'=>$tenant,'sn'=>$user,'account'=>'pl-'.$user,'nickname'=>'Offline fixture','user_money'=>1000,'create_time'=>time(),'update_time'=>time()]);
        request()->tenantId=$tenant;request()->userId=$user;
        request()->withServer(['HTTP_HOST'=>'customer.example.test','HTTPS'=>'on','SERVER_PORT'=>443]);request()->setHost('customer.example.test');
        $make=static function(string $mode='text',float $quantity=15,int $age=0)use($tenant,$user){
            $key='offline-pic-'.bin2hex(random_bytes(8));$summary=['mode'=>$mode,'reference_sample_seconds'=>1.25];
            $task=Db::name('ai_app_task')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'task_no'=>$key,'app_code'=>'aigc_pic_lipsync','action_code'=>'generate','status'=>'running','request_summary'=>json_encode($summary),'result_summary'=>'{}','idempotency_key'=>$key,'create_time'=>time()-$age,'update_time'=>time()]);
            $id=Db::name('ai_consumption_log')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'app_task_id'=>$task,'consume_no'=>$key,'app_code'=>'aigc_pic_lipsync','action_code'=>'generate','resource_type'=>'app_api','provider'=>'power_market','upstream_task_id'=>'offline_'.$key,'quantity'=>$quantity,
                'price_snapshot'=>json_encode(['platform_price'=>2,'tenant_price'=>3,'usage_unit_size'=>1]),'request_summary'=>json_encode($summary),'usage_snapshot'=>'{}','response_summary'=>'{}','run_status'=>'running','billing_status'=>'reserved','reserved_tenant_cost'=>$quantity*2,'reserved_user_price'=>$quantity*3,'create_time'=>time()-$age,'update_time'=>time()]);
            PointService::reserveBusinessAmountsInCurrentTransaction($tenant,$user,$quantity*2,$quantity*3,$key.'-reserve','Offline fixture',['app_code'=>'aigc_pic_lipsync','app_task_id'=>$task,'consumption_id'=>$id]);
            $business=Db::name('aigc_pic_lipsync_task')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'app_task_id'=>$task,'consumption_id'=>$id,'drive_mode'=>$mode,'status'=>'running','request_snapshot'=>'{}','create_time'=>time(),'update_time'=>time()]);
            Db::name('ai_app_task')->where('id',$task)->update(['business_table'=>'aigc_pic_lipsync_task','business_id'=>$business]);
            return $id;
        };
        $completed=static fn($seconds)=>['code'=>1,'result'=>['status'=>'completed','data'=>['output_url'=>'https://offline.fixture.invalid/result.mp4','duration'=>$seconds,'resolution'=>'720x1280']]];
        Http::$body=$completed(10.125);Storage::$facts=[];
        $before=$wallet();$id=$make();$reply=Runtime::refresh($id);$bill=$find($id);
        $check($reply['status']==='success','Text task with upstream duration must finish immediately: '.json_encode(['reply'=>$reply,'billing_status'=>$bill['billing_status'],'error_code'=>$bill['error_code'],'error_message'=>$bill['error_message']],JSON_UNESCAPED_UNICODE));
        $check((float)$bill['quantity']===10.125&&$bill['billing_status']==='settled','Fractional upstream duration settles');
        $usage=json_decode($bill['usage_snapshot'],true);
        $check($usage['settlement_basis']==='upstream_generated_video','Upstream duration must not be mislabeled as local measurement');
        $check($wallet()===[round($before[0]-20.25,2),round($before[1]-round(30.375,2),2)],'Actual tenant/user charges use immutable snapshot and refund estimate difference');
        $check($reply['videos'][0]['width']===720&&$reply['videos'][0]['height']===1280,'Resolution metadata fills missing dimensions');
        $after=$wallet();$calls=Http::$calls;Runtime::refresh($id);
        $check($wallet()===$after&&Http::$calls===$calls,'Repeated successful polling cannot charge or query again');
        Runtime::fail($id,'late failure');$check($wallet()===$after&&$find($id)['billing_status']==='settled','Late failure cannot refund settled output');

        $business=(int)Db::name('aigc_pic_lipsync_task')->where('consumption_id',$id)->value('id');
        $detail=AppService::taskDetail($tenant,$business,$user);
        $check($detail['status']==='success'&&$detail['video_url']==='https://customer.example.test/uploads/offline-lipsync.mp4','Frontend task detail must expose the settled local video');
        $check((float)$detail['input_seconds']===10.125&&$detail['results'][0]['width']===720,'Frontend history receives actual usage and dimensions');
        AppService::taskDetail($tenant,$business,$user);
        $check(Db::name('aigc_pic_lipsync_result')->where('task_id',$business)->count()===1,'Repeated business sync cannot duplicate video results');

        Storage::$facts=['duration'=>9.5,'width'=>1080,'height'=>1920];$id=$make();$reply=Runtime::refresh($id);$bill=$find($id);
        $check((float)$bill['quantity']===9.5&&$reply['videos'][0]['width']===1080,'Local probe takes priority over upstream metadata');
        $check(json_decode($bill['usage_snapshot'],true)['settlement_basis']==='measured_generated_video','Local duration keeps its own provenance');

        Storage::$facts=[];Http::$body=$completed(10.125);Http::$body['result']['data']['usage']=['input_audio_seconds'=>8.25];
        $id=$make();Runtime::refresh($id);$check((float)$find($id)['quantity']===8.25,'Explicit upstream driving-audio usage remains first priority');
        Http::$body=$completed(25);$id=$make('audio',6.5);Runtime::refresh($id);
        $check((float)$find($id)['quantity']===6.5,'Audio mode must not bill output duration or reference metadata');

        foreach([0,-1,'NaN','Infinity',null] as $invalid){
            Http::$body=$completed($invalid);$before=$wallet();$id=$make();$reply=Runtime::refresh($id);$bill=$find($id);
            $check($reply['status']==='failed'&&$bill['error_code']==='settlement_usage_missing','Missing/invalid usage must fail immediately with its real code');
            $check(str_contains($reply['error'],'成片时长')&&$reply['error_code']==='settlement_usage_missing','Real reason reaches the frontend');
            $check($wallet()===$before&&$bill['billing_status']==='refunded','Invalid usage refunds the reservation once');
            $business=(int)Db::name('aigc_pic_lipsync_task')->where('consumption_id',$id)->value('id');
            $detail=AppService::taskDetail($tenant,$business,$user);
            $check($detail['status']==='failed'&&str_contains($detail['error'],'成片时长'),'Frontend task detail must retain the actual settlement reason');
            $after=$wallet();$calls=Http::$calls;Runtime::refresh($id);Runtime::fail($id,'duplicate');
            $check($wallet()===$after&&Http::$calls===$calls,'Repeated failure cannot refund or query twice');
        }
        Http::$body=$completed(400);$before=$wallet();$id=$make('text',1);$reply=Runtime::refresh($id);
        $check($reply['status']==='failed'&&$reply['error_code']==='settlement_failed'&&str_contains($reply['error'],'不足'),'Billing rejection fails immediately with its real reason');
        $check($wallet()===$before&&$find($id)['billing_status']==='refunded','Failed extra settlement rolls back charges and refunds only the original reserve');
        Http::$body=$completed(10.125);Http::$errno=28;$id=$make();$reserved=$wallet();$reply=Runtime::refresh($id);
        $check($reply['status']==='running'&&$find($id)['billing_status']==='reserved'&&$wallet()===$reserved,'Transient query errors keep the reservation for retry');
        Http::$errno=0;Runtime::refresh($id);$check($find($id)['billing_status']==='settled','Transient error can recover and settle once');
        $id=$make('text',15,7300);Http::$body=$completed(0);$reply=Runtime::refresh($id);
        $check($reply['error_code']==='settlement_usage_missing'&&$find($id)['error_code']!=='timeout','Completed output with missing usage is never mislabeled timeout');
        echo "PASS $checks picture-lipsync settlement, precise billing, failure reason, retry and idempotency checks; rolled back\n";
    } finally {Db::rollback();}
    $check(Db::name('tenant')->where('id',$tenant)->count()===0&&Db::name('user')->where('id',$user)->count()===0,'Synthetic accounts must roll back');
}
