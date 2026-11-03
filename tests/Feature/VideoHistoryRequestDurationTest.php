<?php
namespace Tests\Feature;

use app\common\service\app\aigc_video\AigcVideoService;
use PHPUnit\Framework\TestCase;

class VideoHistoryRequestDurationTest extends TestCase
{
    private function duration(array $task,?array $log=null): ?int
    {return (new \ReflectionMethod(AigcVideoService::class,'historicalRequestDuration'))->invoke(null,$task,$log);}
    private function task(): array{return ['tenant_id'=>1,'user_id'=>2,'app_task_id'=>3,'consumption_id'=>4,'duration'=>1];}
    private function log(): array{return ['tenant_id'=>1,'user_id'=>2,'app_task_id'=>3,'id'=>4,'request_summary'=>['duration'=>6]];}

    public function testSavedBusinessDurationWinsOverMeasuredDurationAndPreservesZero(): void
    {
        foreach([0,-1,4]as$value){$task=$this->task()+['model_json'=>['duration'=>$value]];$before=$task;
            self::assertSame($value,$this->duration($task,$this->log()));self::assertSame($before,$task);}
    }
    public function testLegacyDurationUsesOnlyItsAssociatedOwnedConsumption(): void
    {
        self::assertSame(6,$this->duration($this->task(),$this->log()));
        foreach(['tenant_id','user_id','app_task_id','id']as$key){$log=$this->log();$log[$key]++;self::assertNull($this->duration($this->task(),$log));}
        $task=$this->task();$task['app_task_id']=0;$log=$this->log();$log['app_task_id']=0;
        self::assertNull($this->duration($task,$log));
    }
    public function testAbsentOrMalformedRecordedDurationsRemainAbsent(): void
    {
        self::assertNull($this->duration($this->task()));
        foreach([null,'',false,1.25,'invalid',[]]as$value){$log=$this->log();$log['request_summary']['duration']=$value;
            self::assertNull($this->duration($this->task(),$log));}
    }
    public function testJsonSnapshotsAndSummaryAreReadWithoutMutatingRows(): void
    {
        $task=$this->task()+['model_json'=>'{}'];$log=$this->log();$log['request_summary']='{"duration":4}';$before=[$task,$log];
        self::assertSame(4,$this->duration($task,$log));self::assertSame($before,[$task,$log]);
    }
}
