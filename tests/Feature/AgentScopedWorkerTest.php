<?php
namespace Tests\Feature;
use app\common\service\app\aigc_llm\agent\{AgentStore as Store, AgentExecutionService as Service, AgentComposerSettings};
use PHPUnit\Framework\TestCase;
use think\facade\Db;
final class AgentScopedWorkerTest extends TestCase
{
    public function testClaimTouchesOnlyTheRequestedTenantAndRun():void
    {
        if(getenv('AGENT_MODEL_DB_TESTS')!=='local-existing')self::markTestSkipped('Rollback claim only; no provider');
        (new \think\App())->initialize();Db::startTrans();
        try {
            $create=static fn()=>Service::submit(1,3,['content'=>'工作者范围测试，不发送供应商','request_id'=>'scope-'.bin2hex(random_bytes(12)),
                'preferences'=>['mode'=>'ask','models'=>['text'=>AgentComposerSettings::textModel(1)]]]);
            $first=$create();$second=$create();
            $claim=new \ReflectionMethod(Service::class,'claimNextRun');$claim->setAccessible(true);
            $row=$claim->invoke(null,1,(int)$second['id']);
            self::assertSame((int)$second['id'],(int)$row['id']);
            self::assertSame('queued',Store::run(1,3,(int)$first['id'])['status']);
            self::assertNull($claim->invoke(null,2,(int)$first['id']));
            self::assertSame('queued',Store::run(1,3,(int)$first['id'])['status']);
        } finally {Db::rollback();}
    }
}
