<?php
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
use think\facade\Db;
use think\facade\Config;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationStore as Store;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationService as Service;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationWorker as Worker;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationProviderInterface;
use app\common\service\app\aigc_short_drama\canvas_agent\FeatureGate;

/** Local configured catalog, transactional fixture, no external model calls. */
class CanvasDeepAgentRoutingTest extends TestCase
{
    public function testGlobalDefaultMigratesAnOldThreadAndKeepsQueuedSnapshotsFrozen(): void {
        if(getenv('SHORT_DRAMA_AGENT_LOCAL_ROUTING_TESTS')!=='1') $this->markTestSkipped('Configured local catalog and SDK required');
        (new \think\App())->initialize();
        $tenant=(int)(getenv('SHORT_DRAMA_TEST_TENANT')?:1);$user=1900000011;
        $original=Config::get('short_drama_agent');
        Config::set(array_replace($original,['engine'=>'deepagents_v1']),'short_drama_agent');
        Db::startTrans();
        try {
            $row=Db::name('aigc_short_drama_config')->where('tenant_id',$tenant)->find();
            self::assertNotEmpty($row,'A configured local catalog is required');
            $config=json_decode($row['config_json'],true);unset($config['canvas_agent']['dialogue_engine']);
            $config['canvas_agent']['enabled']=true;
            Db::name('aigc_short_drama_config')->where('tenant_id',$tenant)->update(['config_json'=>json_encode($config)]);
            self::assertSame('deepagents_v1',FeatureGate::dialogueEngine($tenant));
            $canvas=Canvas::create($tenant,$user,['title'=>'Temporary routing acceptance'])['id'];
            $thread=Store::create($tenant,$user,$canvas,'runtime-routing')['id'];
            Db::name(Store::PREFIX.'thread')->where('id',$thread)->update(['settings_json'=>json_encode(['dialogue_engine'=>'legacy','retained_marker'=>'old-history'])]);
            $request=['request_key'=>'hello','content'=>'你好','selected_node_ids'=>[],'base_revision'=>0];
            $ack=Service::send($tenant,$user,$canvas,$thread,$request);
            $snapshot=Db::name(Store::PREFIX.'run')->where('id',$ack['run_id'])->value('context_snapshot');
            self::assertSame('deepagents_v1',json_decode($snapshot,true)['intent_routing']['dialogue_engine']);
            self::assertSame($ack,Service::send($tenant,$user,$canvas,$thread,$request));
            $config['canvas_agent']['dialogue_engine']='legacy';
            Db::name('aigc_short_drama_config')->where('tenant_id',$tenant)->update(['config_json'=>json_encode($config)]);
            self::assertSame('legacy',FeatureGate::dialogueEngine($tenant));
            self::assertSame($snapshot,Db::name(Store::PREFIX.'run')->where('id',$ack['run_id'])->value('context_snapshot'));
            $provider=new class implements ConversationProviderInterface {
                public int $calls=0;
                public function preflight(int $tenant,int $user,array $request):void{}
                public function generate(int $tenant,int $user,array $request):array {
                    $this->calls++;$value=['content'=>json_encode(CanvasAgentDecisionTest::reply()),'safety_checked'=>true];
                    ($request['result_validator'])($value);return $value;
                }
            };
            self::assertSame('success',Worker::process($tenant,$user,$ack['run_id'],$provider));
            self::assertSame(1,$provider->calls);
            $saved=json_decode(Db::name(Store::PREFIX.'thread')->where('id',$thread)->value('settings_json'),true);
            self::assertSame('deepagents_v1',$saved['dialogue_engine']);
            self::assertSame('old-history',$saved['retained_marker']);
            self::assertEmpty($saved['workflow_state']??[],'Greeting cannot create production');
            unset($config['canvas_agent']['dialogue_engine']);
            Db::name('aigc_short_drama_config')->where('tenant_id',$tenant)->update(['config_json'=>json_encode($config)]);
            $request['request_key']='runtime-unavailable';
            $failed=Service::send($tenant,$user,$canvas,$thread,$request);
            Config::set(['engine'=>'deepagents_v1','python'=>'/nonexistent/python'],'short_drama_agent');
            self::assertSame('failed',Worker::process($tenant,$user,$failed['run_id'],$provider));
            self::assertSame(1,$provider->calls,'No paid transport on failed startup');
            self::assertSame('AGENT_SERVICE_UNAVAILABLE',Db::name(Store::PREFIX.'run')->where('id',$failed['run_id'])->value('error_code'));
            self::assertSame(0,Db::name(Store::PREFIX.'message')->where(['run_id'=>$failed['run_id'],'role'=>'assistant'])->count());
        } finally {Db::rollback();Config::set($original,'short_drama_agent');}
    }
}
