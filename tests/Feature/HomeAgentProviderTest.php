<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
use app\common\service\app\aigc_llm\agent\MarketNeuronProvider;
use app\common\service\power\MarketTextModelRuntimeService;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\{Tool, ToolProperty, PropertyType};

final class HomeAgentProviderTest 
{
    private static function same($expected, $actual): void
    {
        if ($expected !== $actual) throw new RuntimeException('Expected '.var_export($expected,true).' got '.var_export($actual,true));
    }
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
        $agent=(new Agent())->setThreadId('home-agent-contract')->setAiProvider($provider)->setTools([$tool]);
        $state=$agent->chat(new UserMessage('Find dress'));
        self::same('找到 dress',$state->getMessage()->getContent());
        self::same(2,count($requests));
        self::same('chosen-model',$requests[1]['model_selection']);
        self::same(true,$requests[1]['_disable_model_fallback']);
        $messages=$requests[1]['messages'];
        self::same('tool',$messages[2]['role']);
        self::same('call_1',$messages[2]['tool_call_id']);
        self::same('found:dress',$messages[2]['content']);
    }

    public function testMarketPreservesToolMessagesOnlyForAgentTransport(): void
    {
        $method=new ReflectionMethod(MarketTextModelRuntimeService::class,'normalizeMessages');
        $method->setAccessible(true);
        $rows=[['role'=>'user','content'=>'draw'],['role'=>'assistant','content'=>'','tool_calls'=>[['id'=>'c']]],['role'=>'tool','tool_call_id'=>'c','content'=>'done']];
        self::same([$rows[0]],$method->invoke(null,'draw',[],$rows));
        self::same($rows,$method->invoke(null,'draw',[],$rows,true));
    }
}

$test=new HomeAgentProviderTest();
$test->testNeuronExecutesAToolAndSendsItsResultBack();
$test->testMarketPreservesToolMessagesOnlyForAgentTransport();
echo "Home Agent provider contracts passed\n";
