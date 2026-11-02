<?php
namespace Tests\Feature;
require_once __DIR__.'/CanvasAgentDecisionTest.php';
use PHPUnit\Framework\TestCase;
use think\facade\Db;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationStore as Store;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationWorkflow as Workflow;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationWorker as Worker;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationAgentDecision as Decision;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationProviderInterface;

/** End-to-end control replay with isolated fixtures and no external model calls. */
class CanvasAgentDecisionPersistenceTest extends TestCase
{
    public static function engines(): array { return [['decision_v1'],['neuron_v1'],['deepagents_v1']]; }
    /** @dataProvider engines */
    public function testMultiTurnWorkerCommitRefreshPauseMemoryAndIdempotency(string $engine): void {
        if(getenv('SHORT_DRAMA_DB_TESTS')!=='1') $this->markTestSkipped('Transactional MySQL test');
        (new \think\App())->initialize();$tenant=random_int(1500000000,1900000000);$user=$tenant;
        Db::startTrans();
        try {
            Db::name('aigc_short_drama_config')->insert(['tenant_id'=>$tenant,'config_json'=>json_encode(['canvas_agent'=>['enabled'=>true,'dialogue_engine'=>$engine,'workflow'=>['enabled'=>true]]]),'status'=>1]);
            $canvas=Canvas::create($tenant,$user,['title'=>'Offline agent decision replay'])['id'];
            $thread=Store::create($tenant,$user,$canvas,'decision-test')['id'];
            $settings=json_decode(Db::name(Store::PREFIX.'thread')->where('id',$thread)->value('settings_json'),true);
            self::assertSame($engine==='deepagents_v1'?'neuron_v1':$engine,$settings['dialogue_engine']);
            // Existing threads migrate at acceptance without losing settings.
            $settings['dialogue_engine']='legacy';$settings['retained_marker']='old-thread';
            Db::name(Store::PREFIX.'thread')->where('id',$thread)->update(['settings_json'=>json_encode($settings)]);
            $steps=[
                ['你好','respond',[],[]],
                ['主角叫林小凡','respond',[],[['key'=>'hero','value'=>'林小凡','evidence'=>'主角叫林小凡']]],
                ['我想生成短剧','start_project',CanvasAgentDecisionTest::intake(),[]],
                ['程序员大战孙悟空','update_requirements',CanvasAgentDecisionTest::intake(['story_direction'=>'程序员大战孙悟空'],true),[]],
                ['你觉得竖屏好吗','respond',[],[]],
                ['一集60秒，先别生成','update_requirements',CanvasAgentDecisionTest::intake(['episode_count'=>'1集','episode_duration'=>'60秒'],true,'pause'),[]],
                ['先聊聊，主角叫什么','respond',[],[]],
                ['恢复制作','resume',[],[]],
            ];
            $anchor=0;$calls=0;
            foreach($steps as $index=>[$text,$action,$args,$remember]) {
                $currentRow=Db::name(Store::PREFIX.'thread')->where('id',$thread)->find();
                $current=json_decode($currentRow['settings_json'],true);$state=$current['workflow_state']??[];
                $routing=['version'=>3,'dialogue_engine'=>$engine,'skill_candidates'=>[],
                    'workflow_candidate'=>$state?:CanvasAgentDecisionTest::state()];
                if($state) {
                    $candidate=$state;$candidate['stage_state']['status']='collecting';$candidate['state_revision']++;
                    $routing+=['kind'=>'active_workflow','base_workflow_revision'=>$state['state_revision'],'workflow_paused'=>false];
                    $routing['workflow_candidate']=$candidate;
                }
                $request=['request_key'=>'replay-'.$index,'content'=>$text,'selected_node_ids'=>[],'base_revision'=>0];
                $resolved=['settings'=>['generation_mode'=>'manual'],'skill'=>[],'intent_routing'=>$routing];
                $ack=Store::enqueue($tenant,$user,$canvas,$thread,$request,$resolved);
                $value=CanvasAgentDecisionTest::reply($action,$args,$remember);
                $provider=new class($value) implements ConversationProviderInterface {
                    public function __construct(private array $value){}
                    public function preflight(int $tenant,int $user,array $request):void{}
                    public function generate(int $tenant,int $user,array $request):array {
                        if(!str_contains($request['system_prompt'],'统一对话决策')) throw new \RuntimeException('Missing runtime policy');
                        $result=['content'=>json_encode($this->value,JSON_UNESCAPED_UNICODE),'tool_calls'=>[],'safety_checked'=>true];
                        ($request['result_validator'])($result);
                        ($request['on_event'])('delta',['delta'=>$result['content']]);
                        return $result;
                    }
                };
                self::assertSame('success',Worker::process($tenant,$user,$ack['run_id'],$provider));$calls++;
                self::assertSame($ack,Store::enqueue($tenant,$user,$canvas,$thread,$request,$resolved));
                self::assertSame('not_claimed',Worker::process($tenant,$user,$ack['run_id'],$provider));
                $saved=json_decode(Db::name(Store::PREFIX.'thread')->where('id',$thread)->value('settings_json'),true);
                self::assertSame($engine,$saved['dialogue_engine']);self::assertSame('old-thread',$saved['retained_marker']);
                if($index>=1) self::assertSame('林小凡',$saved['agent_memory']['hero']['value']);
                $view=Workflow::read($tenant,$user,$canvas,$thread);
                if($index===2) self::assertNull($view['card']);
                if($index===3) $anchor=$ack['run_id'];
                if($index>=3) self::assertSame($anchor,$view['workflow']['intake_anchor_run_id']);
                if($index===4 || $index===6) self::assertSame($state,$saved['workflow_state']);
                if($index===5) {self::assertTrue($saved['workflow_state']['execution_paused']);self::assertSame('60秒',$saved['workflow_state']['slot_values']['episode_duration']);}
                if($index===7) self::assertFalse($saved['workflow_state']['execution_paused']);
                if($index>=2) {
                    $frozen=json_decode(Db::name(Store::PREFIX.'run')->where('id',$ack['run_id'])->value('context_snapshot'),true);
                    self::assertSame('林小凡',$frozen['agent_memory']['hero']['value']);
                }
                self::assertSame(1,Db::name(Store::PREFIX.'event')->where(['run_id'=>$ack['run_id'],'kind'=>'agent.decision'])->count());
                $event=json_decode(Db::name(Store::PREFIX.'event')->where(['run_id'=>$ack['run_id'],'kind'=>'agent.decision'])->value('payload_json'),true);
                self::assertSame($engine,$event['engine']);
            }
            self::assertSame(8,$calls);self::assertSame(16,Db::name(Store::PREFIX.'message')->where('thread_id',$thread)->count());
            self::assertSame(0,Db::name('aigc_short_drama_script_task')->where('tenant_id',$tenant)->count());
            self::assertSame('[]',Db::name(\app\common\service\app\aigc_short_drama\canvas_agent\GraphService::TABLE)->where('id',$canvas)->value('nodes_json'));
        } finally {Db::rollback();}
    }
}
