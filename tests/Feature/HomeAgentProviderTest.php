<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use app\common\service\app\aigc_llm\agent\MarketNeuronProvider;
use app\common\service\power\MarketTextModelRuntimeService;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\{Tool, ToolProperty, PropertyType};

final class HomeAgentProviderTest extends TestCase
{
    public function testNeuronExecutesAToolAndSendsItsResultBack(): void
    {
        $requests=[];
        $provider=new MarketNeuronProvider('chosen-model',function(array $request)use(&$requests):array{
            $requests[]=$request;
            return count($requests)===1
                ? ['tool_calls'=>[['id'=>'call_1','function'=>['name'=>'lookup','arguments'=>'{"name":"dress"}']]]]
                : ['content'=>'找到 dress'];
        });
        $tool=new class extends Tool {
            protected string $name='lookup';
            protected ?string $description='Find a named item';
            protected function properties(): array {return [new ToolProperty('name',PropertyType::STRING,'Name',true)];}
            public function __invoke(string $name): string {return 'found:'.$name;}
        };
        $agent=(new Agent())->setAiProvider($provider)->setTools([$tool]);
        $state=$agent->chat(new UserMessage('Find dress'));
        self::assertSame('找到 dress',$state->getMessage()->getContent());
        self::assertCount(2,$requests);
        self::assertSame('chosen-model',$requests[1]['model_selection']);
        self::assertTrue($requests[1]['_disable_model_fallback']);
        $messages=$requests[1]['messages'];
        self::assertSame('tool',$messages[2]['role']);
        self::assertSame('call_1',$messages[2]['tool_call_id']);
        self::assertSame('found:dress',$messages[2]['content']);
    }

    public function testMarketPreservesToolMessagesOnlyForAgentTransport(): void
    {
        $method=new ReflectionMethod(MarketTextModelRuntimeService::class,'normalizeMessages');
        $method->setAccessible(true);
        $rows=[['role'=>'user','content'=>'draw'],['role'=>'assistant','content'=>'','tool_calls'=>[['id'=>'c']]],['role'=>'tool','tool_call_id'=>'c','content'=>'done']];
        self::assertSame([$rows[0]],$method->invoke(null,'draw',[],$rows));
        self::assertSame($rows,$method->invoke(null,'draw',[],$rows,true));
    }
}
