<?php
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
use think\facade\Db;
use app\common\model\app\aigc_digital_human\AigcDigitalHumanTask;
use app\common\service\app\aigc_digital_human\AigcDigitalHumanService;
final class DigitalHumanAcceptedDurationTest extends TestCase
{
    public function testMeasuredTtsDurationDoesNotChangeAcceptedRequestIdentity(): void
    {
        (new \think\App())->initialize();
        self::assertContains(config('database.connections.mysql.database'),['x_cn','z_cn']);
        Db::startTrans();
        try {
            $criteria=['avatar_id'=>2147482010,'voice_id'=>2147482011,'title'=>'Synthetic '.bin2hex(random_bytes(6)),'script_text'=>'色块','prompt'=>'','channel'=>'private','quality'=>'source','ratio'=>'adaptive','duration'=>3,'tts_audio_uri'=>''];
            $task=AigcDigitalHumanTask::create($criteria+['tenant_id'=>1,'user_id'=>2147482012,'status'=>'success','create_time'=>time(),'provider_payload_json'=>['request_duration'=>3,'drive_mode'=>'text']]);
            $task->save(['duration'=>1,'tts_audio_uri'=>'uploads/synthetic-tts.wav']);
            $find=new \ReflectionMethod(AigcDigitalHumanService::class,'findRecentDuplicateTask');$find->setAccessible(true);
            self::assertSame((int)$task['id'],(int)$find->invoke(null,1,2147482012,$criteria)['id']);
            foreach(['duration'=>4,'voice_id'=>2147482013,'script_text'=>'另一段文案'] as $key=>$value)
                self::assertNull($find->invoke(null,1,2147482012,array_replace($criteria,[$key=>$value])));
            $task->save(['provider_payload_json'=>['market_estimate'=>['duration'=>3]]]);
            self::assertSame((int)$task['id'],(int)$find->invoke(null,1,2147482012,$criteria)['id']);
            $task->save(['provider_payload_json'=>[]]);self::assertNull($find->invoke(null,1,2147482012,$criteria));
        } finally {Db::rollback();}
    }
}
