<?php
/** Offline routing fixture. No database, provider HTTP, files or point mutations. */
namespace app\common\model\ai {
    final class AiConsumptionLog implements \ArrayAccess {
        public static array $state = [];
        public static function findOrEmpty(int $id): self { return new self(); }
        public function isEmpty(): bool { return false; }
        public function toArray(): array { return self::$state; }
        public function offsetExists(mixed $key): bool { return isset(self::$state[$key]); }
        public function offsetGet(mixed $key): mixed { return self::$state[$key] ?? null; }
        public function offsetSet(mixed $key,mixed $value): void { self::$state[$key]=$value; }
        public function offsetUnset(mixed $key): void { unset(self::$state[$key]); }
    }
}
namespace app\common\service\power {
    final class PendingUsageFixture {
        public static array $calls=[];
        public static bool $receiptReady=false;
        public static function refresh(string $adapter): void {
            self::$calls[]=$adapter;
            if(self::$receiptReady) \app\common\model\ai\AiConsumptionLog::$state['billing_status']='settled';
        }
    }
    final class MarketVideoRuntimeService {
        public static function refresh(int $id): void { PendingUsageFixture::refresh('video'); }
    }
    final class MarketImageModelRuntimeService {
        public static function refresh(int $id): void { PendingUsageFixture::refresh('image'); }
    }
    final class MarketApplicationApiRuntimeService {
        public static function refresh(int $id): void { PendingUsageFixture::refresh('application_api'); }
    }
}
namespace app\common\service\ai {
    final class AiTaskBusinessResultService {
        public static int $syncs=0;
        public static function syncTerminalByConsumptionId(int $id): bool { self::$syncs++;return true; }
        public static function syncByConsumptionId(int $id): void { self::$syncs++; }
    }
    final class AiTaskLifecycleEventService {
        public static function record(...$args): void {}
    }
}
namespace {
    require dirname(__DIR__).'/app/common/service/ai/AiMarketTaskRuntimeService.php';
    require dirname(__DIR__).'/app/common/service/ai/AiTaskJobService.php';
    use app\common\model\ai\AiConsumptionLog as Log;
    use app\common\service\power\PendingUsageFixture as Fixture;
    use app\common\service\ai\{AiMarketTaskRuntimeService as Runtime,AiTaskJobService as Jobs,AiTaskBusinessResultService as Business};
    function check(bool $ok,string $message): void { if(!$ok)throw new \RuntimeException($message); }
    $query=new \ReflectionMethod(Jobs::class,'queryResult');
    foreach([
        ['video_generate','aigc_video','reserved','video',[]],
        ['image_generate','aigc_image','pending_usage','image',[]],
        ['application_api','aigc_music','pending_usage','application_api',[]],
        ['application_api','aigc_pic_lipsync','reserved','application_api',[]],
        ['','aigc_canvas','reserved','video',['resource_type'=>'model','requires_actual_duration'=>true]],
        ['','aigc_short_drama','reserved','video',['resource_type'=>'model','requires_actual_duration'=>true]],
    ] as [$protocol,$app,$billing,$adapter,$snapshot]) {
        Log::$state=['id'=>1,'provider'=>'power_market','protocol'=>$protocol,'app_code'=>$app,
            'run_status'=>'success','billing_status'=>$billing,'upstream_task_id'=>'owned-offline-task','price_snapshot'=>$snapshot,
            'response_summary'=>['videos'=>[['video_uri'=>'owned.mp4']]]];
        Fixture::$calls=[];Fixture::$receiptReady=false;Business::$syncs=0;
        check($query->invoke(null,1)===false,$app.': query job finished before its usage receipt');
        check(Fixture::$calls===[$adapter],$app.': dispatcher skipped the pending adapter');
        check(Business::$syncs===1,$app.': pending usage blocked result registration/transfer');
        check(Log::$state['billing_status']===$billing,$app.': missing receipt released its reservation');
        Fixture::$receiptReady=true;
        Runtime::refresh(1);
        check(Log::$state['billing_status']==='settled',$app.': later receipt could not complete');
        check(Fixture::$calls===[$adapter,$adapter],$app.': recovery used the wrong adapter');
        Runtime::refresh(1);
        check(Fixture::$calls===[$adapter,$adapter],$app.': settled task queried the provider again');
        foreach(['failed','canceled','cancelled'] as $terminal) {
            Log::$state['run_status']=$terminal;Log::$state['billing_status']='refunded';
            Runtime::refresh(1);
            check(Fixture::$calls===[$adapter,$adapter],$app.': refunded terminal task reopened');
        }
        echo 'PASS '.$app.': wait, register result, resume usage, stop after settlement/refund'.PHP_EOL;
    }
}
