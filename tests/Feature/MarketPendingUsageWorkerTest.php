<?php
namespace Tests\Feature;

use app\common\service\ai\{AiMarketTaskRuntimeService as Runtime,AiTaskJobService as Jobs};
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class MarketPendingUsageWorkerTest extends TestCase
{
    /** @dataProvider states */
    public function testResultQueriesWaitForUsageWithoutReopeningOtherTerminalTasks(array $changes,bool $waiting,bool $finished): void
    {
        $consumption = array_replace([
            'provider'=>'power_market','protocol'=>'video_generate',
            'run_status'=>'success','billing_status'=>'reserved','price_snapshot'=>[],
        ], $changes);
        self::assertSame($waiting, Runtime::awaitsUsageSettlement($consumption));
        self::assertSame($finished, (new ReflectionMethod(Jobs::class,'readyForBusinessProcessing'))->invoke(null,$consumption));
    }

    public static function states(): array
    {
        return [
            'video duration pending'=>[[],true,false],
            'video token pending'=>[['billing_status'=>'pending_usage'],true,false],
            'image usage pending'=>[['protocol'=>'image_generate','billing_status'=>'pending_usage'],true,false],
            'image reserved'=>[['protocol'=>'image_generate'],true,false],
            'music usage pending'=>[['protocol'=>'application_api','billing_status'=>'pending_usage','price_snapshot'=>['app_code'=>'music_generate']],true,false],
            'tool usage pending'=>[['protocol'=>'application_api'],true,false],
            'legacy image snapshot'=>[['protocol'=>'','price_snapshot'=>['resource_type'=>'model','model_type'=>'image']],true,false],
            'legacy video JSON snapshot'=>[['protocol'=>'','price_snapshot'=>'{"resource_type":"model","model_type":"video"}'],true,false],
            'settled'=>[['billing_status'=>'settled'],false,true],
            'free'=>[['billing_status'=>'none'],false,true],
            'refunded'=>[['billing_status'=>'refunded'],false,true],
            'failed refunded'=>[['run_status'=>'failed','billing_status'=>'refunded'],false,true],
            'failed reservation'=>[['run_status'=>'failed'],false,true],
            'canceled'=>[['run_status'=>'canceled'],false,true],
            'cancelled'=>[['run_status'=>'cancelled'],false,true],
            'running'=>[['run_status'=>'running'],false,false],
            'submitting'=>[['run_status'=>'submitted'],false,false],
            'billing review'=>[['billing_status'=>'settlement_failed'],false,true],
            'text usage review'=>[['protocol'=>'openai_responses','billing_status'=>'pending_usage','price_snapshot'=>['resource_type'=>'model','model_type'=>'text']],false,true],
            'agent transcription'=>[['provider'=>'agent_transcribe','protocol'=>'application_api','billing_status'=>'pending_usage'],false,true],
            'non market'=>[['provider'=>'custom'],false,true],
            'unknown adapter'=>[['protocol'=>'unknown'],false,true],
        ];
    }
}
