<?php
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
use app\common\service\app\aigc_short_drama\ShortDramaScriptGeneration;
use app\common\service\app\aigc_llm\agent\{MarketNeuronProvider,AgentStore};
use NeuronAI\Chat\Messages\UserMessage;

final class CreationCumulativeLimitsTest extends TestCase
{
    public function testLargeValidScriptDoesNotStopAtCumulativeCallsOrReservedOutput(): void
    {
        $skeleton=['title'=>'长剧','story_outline'=>'保留完整剧情','subjects'=>[['id'=>'p','name'=>'主角']], 'locations'=>[['id'=>'l','name'=>'屋内']], 'scene_beats'=>[]];
        for ($i=0;$i<24;$i++) $skeleton['scene_beats'][]=['scene_ref_id'=>'l','goal'=>'事件'.$i,'entry'=>'进入','exit'=>'离开','shot_count'=>12];
        $calls=0;
        $result=ShortDramaScriptGeneration::generate([],['context_window'=>200000,'max_tokens'=>16384],['system_prompt'=>'','content'=>'完整生成'],
            static function($key,$input) use($skeleton,&$calls):array {
                $calls++;
                if (!str_starts_with($key,'v3_scene_')) $payload=$skeleton;
                else {
                    preg_match('/分段任务=(.+)$/us',$input['content'],$match);
                    $task=json_decode($match[1],true,512,JSON_THROW_ON_ERROR);
                    $payload=['storyboard'=>array_map(static fn($id)=>['shot_id'=>$id,'scene_ref_id'=>'l','subject_ref_ids'=>['p'],'visual_description'=>'主角完成当前动作','dialogue'=>'','voice_role'=>'','speech_type'=>'none','recommended_duration_seconds'=>5],$task['required_shot_ids'])];
                }
                return ['result'=>['content'=>json_encode($payload,JSON_UNESCAPED_UNICODE)]];
            });
        self::assertGreaterThan(48,$calls);
        self::assertCount(288,$result['payload']['storyboard']);
        self::assertCount(288,array_unique(array_column($result['payload']['storyboard'],'shot_id')));
    }
    public function testAgentDoesNotStopAtEightValidTransportCalls(): void
    {
        $calls=0;
        $provider=new MarketNeuronProvider('selected',static function() use(&$calls):array { $calls++;return ['content'=>'回复'.$calls]; });
        for($i=1;$i<=12;$i++) self::assertSame('回复'.$i,$provider->chat(new UserMessage('继续'))->message()->getContent());
        self::assertSame(12,$calls);
    }
    public function testHistoricalBudgetDoesNotCreateExtraConfirmationInAutoMode(): void
    {
        self::assertFalse(AgentStore::requiresConfirmation(['mode'=>'auto','budget'=>1],1000));
        self::assertTrue(AgentStore::requiresConfirmation(['mode'=>'ask','budget'=>1],1000));
    }
    public function testAdvertisedOutputCapacityIsNotClippedToGeneric32768OrRaisedTo256(): void
    {
        self::assertSame(65536,\app\common\service\power\TextModelCapacity::output(['max_output_tokens'=>65536],65536));
        self::assertSame(128,\app\common\service\power\TextModelCapacity::output(['max_output_tokens'=>128],512));
    }
    public function testUnknownInputCapacityDoesNotBecomeAnInvented24000TokenStop(): void
    {
        self::assertSame(0,MarketNeuronProvider::inputBudget([]));
        $messages=[['role'=>'user','content'=>str_repeat('完整剧本不能被截掉。',6000)]];
        self::assertSame($messages,MarketNeuronProvider::fitContext($messages,'规则',[],0));
        self::assertSame(12000,MarketNeuronProvider::inputBudget(['context_window'=>32000,'max_input_tokens'=>12000]));
        $this->expectException(\app\common\service\app\aigc_llm\agent\AgentContextCapacityExceeded::class);
        MarketNeuronProvider::fitContext($messages,'规则',[],12000);
    }
    public function testPlanValidationCanContinueUntilARealToolCallInsteadOfAOneRepairCeiling():void
    {
        $calls=0;
        $provider=new MarketNeuronProvider('selected',static function()use(&$calls):array{
            if(++$calls<4)return ['content'=>'声称已保存的方案'];
            return ['content'=>'','tool_calls'=>[['id'=>'actual-call','function'=>['name'=>'plan_creation','arguments'=>'{}']]]];
        },static fn(string $reply)=>$reply==='声称已保存的方案'?'必须保存真实方案':null);
        $result=$provider->chat(new UserMessage('按原方式继续'));
        self::assertInstanceOf(\NeuronAI\Chat\Messages\ToolCallMessage::class,$result->message());
        self::assertSame(4,$calls);
    }
}
