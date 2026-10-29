<?php
namespace Tests\Feature;
use app\common\service\app\aigc_llm\agent\{AgentComposerSettings as Settings, AgentStore, AgentExecutionService};
use PHPUnit\Framework\TestCase;
use think\facade\Db;
final class AgentExplicitTextModelTest extends TestCase
{
    protected function setUp():void
    {
        if(getenv('AGENT_MODEL_DB_TESTS')!=='local-existing')self::markTestSkipped('Local rollback only');
        (new \think\App())->initialize();
        $db=config('database.connections.mysql');self::assertSame('127.0.0.1',$db['hostname']);
        Db::startTrans();
    }
    protected function tearDown():void {if(getenv('AGENT_MODEL_DB_TESTS')==='local-existing')Db::rollback();}
    public function testAnExplicitModelRemainsSelectedAndIsFrozenOnTheRun():void
    {
        $codes=array_column(Settings::textOptions(1)['models'],'code');
        $default=Settings::textModel(1);$selected=current(array_diff($codes,[$default]));self::assertNotFalse($selected);
        self::assertSame($selected,Settings::textModel(1,false,$selected));
        $run=AgentExecutionService::submit(1,3,['content'=>'模型选择离线验收，不启动生成','request_id'=>'model-check-'.bin2hex(random_bytes(12)),
            'preferences'=>['mode'=>'ask','models'=>['text'=>$selected]]]);
        $stored=AgentStore::json(AgentStore::run(1,3,(int)$run['id'])['preferences']);
        self::assertSame($selected,$stored['models']['text']);
    }
    public function testAnUnavailableExplicitModelDoesNotFallBackSilently():void
    { $this->expectException(\InvalidArgumentException::class);Settings::textModel(1,false,'not-a-model'); }
}
