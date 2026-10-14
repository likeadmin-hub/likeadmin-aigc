<?php
// Exercise the real curl SSE buffer with fake wire chunks; no database/network/payment.
namespace app\common\service\power {
    final class AnthropicWireFixture { public static array $events = []; }
    function curl_init() { return new \stdClass(); }
    function curl_setopt_array($handle, array $options) { $handle->options = $options; return true; }
    function curl_exec($handle) {
        $wire = '';
        foreach (AnthropicWireFixture::$events as $event) $wire .= 'event: '.($event['type'] ?? 'message')."\ndata: ".json_encode($event, JSON_UNESCAPED_UNICODE)."\n\n";
        // Byte boundaries deliberately split JSON escapes, UTF-8 characters and SSE delimiters.
        foreach (str_split($wire, 7) as $chunk) ($handle->options[CURLOPT_WRITEFUNCTION])($handle, $chunk);
        return true;
    }
    function curl_errno($handle) { return 0; }
    function curl_error($handle) { return ''; }
    function curl_getinfo($handle, $option) { return 200; }
    function curl_close($handle) {}
}
namespace {
    require dirname(__DIR__, 2).'/vendor/autoload.php';
    use app\common\service\power\{MarketTextModelRuntimeService as R, AnthropicMessagesAdapter as A, AnthropicWireFixture as F};
    use app\common\service\app\aigc_llm\agent\MarketNeuronProvider;
    use NeuronAI\Agent\Agent;
    use NeuronAI\Chat\Messages\UserMessage;
    use NeuronAI\Tools\{Tool, ToolProperty, PropertyType};
    function check($ok, string $message): void { if (!$ok) throw new \RuntimeException($message); }
    function invoke(string $method, ...$args) { return (new \ReflectionMethod(R::class, $method))->invoke(null, ...$args); }
    function wire(array $events, ?array &$visible = null): array {
        F::$events = $events; $visible = [];
        return invoke('curl', 'https://fixture.invalid', 'fixture-only', [], static function($type, $data) use (&$visible) { if ($type === 'delta') $visible[] = $data['delta']; }, true, 10);
    }
    function start(): array { return ['type'=>'message_start','message'=>['id'=>'msg_fixture','usage'=>['input_tokens'=>123,'output_tokens'=>0]]]; }
    function end(string $reason): array { return [['type'=>'message_delta','delta'=>['stop_reason'=>$reason],'usage'=>['output_tokens'=>17]],['type'=>'message_stop']]; }
    function block(int $index, string $id, string $name, array $fragments): array {
        $events = [['type'=>'content_block_start','index'=>$index,'content_block'=>['type'=>'tool_use','id'=>$id,'name'=>$name,'input'=>(object)[]]]];
        foreach ($fragments as $part) $events[] = ['type'=>'content_block_delta','index'=>$index,'delta'=>['type'=>'input_json_delta','partial_json'=>$part]];
        $events[] = ['type'=>'content_block_stop','index'=>$index];
        return $events;
    }
    $events = array_merge([start(),
        ['type'=>'content_block_start','index'=>0,'content_block'=>['type'=>'text','text'=>'先查询。']],
        ['type'=>'content_block_delta','index'=>0,'delta'=>['type'=>'text_delta','text'=>'再回答。']],
        ['type'=>'content_block_delta','index'=>1,'delta'=>['type'=>'thinking_delta','thinking'=>'秘密推理']],
        ['type'=>'content_block_delta','index'=>1,'delta'=>['type'=>'signature_delta','signature'=>'secret']],
    ], block(2,'tool_a','lookup',['{"name":"','服装','"}']), block(4,'tool_b','lookup',['{"name":"模特"}']), end('tool_use'));
    $result = wire($events, $visible);
    check($visible === ['先查询。','再回答。'] && $result['content'] === '先查询。再回答。', 'Native blocks leaked or text reordered');
    check(count($result['tool_calls']) === 2 && $result['tool_calls'][0]['function']['arguments'] === '{"name":"服装"}' && $result['tool_calls'][1]['id'] === 'tool_b', 'Sparse block indices or fragmented arguments corrupted');
    check(invoke('normalizeUsage',$result['usage'])['total_tokens'] === 140 && $result['provider_request_id'] === 'msg_fixture', 'Native receipt/usage lost');
    $empty = wire(array_merge([start()],block(3,'empty','list',[]),end('tool_use')));
    check($empty['tool_calls'][0]['function']['arguments'] === '{}','Empty tool input not an object');
    foreach ([array_slice($events,0,-1),array_merge([start()],block(1,'broken','lookup',['{"name":']),end('tool_use')),array_merge([start()],block(1,'c','lookup',['{}']),end('max_tokens'))] as $invalid) {
        try { wire($invalid); throw new \LogicException('Unsafe stream accepted'); }
        catch (\Exception $e) { check(!$e instanceof \LogicException, $e->getMessage()); }
    }
    $message = invoke('parseProviderPayload',['type'=>'message','id'=>'m','stop_reason'=>'tool_use','content'=>[
        ['type'=>'thinking','thinking'=>'private','signature'=>'secret'],['type'=>'text','text'=>'可见'],
        ['type'=>'tool_use','id'=>'c','name'=>'lookup','input'=>['name'=>'服装']],
    ]]);
    check($message['content'] === '可见' && count($message['tool_calls']) === 1,'Buffered native message flattened');
    $history = A::messages([
        ['role'=>'user','content'=>[['type'=>'text','text'=>'看图'],['type'=>'image_url','image_url'=>['url'=>'https://fixture.invalid/a.png']],['type'=>'image_url','image_url'=>['url'=>'data:image/png;base64,YQ==']]]],
        ['role'=>'assistant','content'=>$result['content'],'tool_calls'=>$result['tool_calls']],
        ['role'=>'tool','tool_call_id'=>'tool_a','content'=>'衣服'],['role'=>'tool','tool_call_id'=>'tool_b','content'=>'模特'],['role'=>'user','content'=>'继续'],
    ]);
    check(count($history) === 3 && count($history[2]['content']) === 3 && $history[2]['content'][1]['tool_use_id'] === 'tool_b' && $history[2]['content'][2]['type'] === 'text','Parallel results not grouped before user text');
    check($history[0]['content'][1]['source']['type'] === 'url' && $history[0]['content'][2]['source']['media_type'] === 'image/png','Vision conversion lost');
    $payload = invoke('requestPayload','anthropic_messages',['model_code'=>'fixture'],[['role'=>'user','content'=>'test']],'system',128,['tools'=>[['type'=>'function','function'=>['name'=>'lookup','parameters'=>['type'=>'object']]]],'tool_choice'=>'required','stream_options'=>['include_usage'=>true],'response_format'=>['type'=>'json_object']]);
    check($payload['tools'][0]['input_schema']['type'] === 'object' && $payload['tool_choice']['type'] === 'any' && !isset($payload['stream_options'],$payload['response_format']), 'Schema/choice protocol mapping failed');
    $requests = []; $toolExecutions = [];
    $provider = new MarketNeuronProvider('selected-claude',function(array $request) use (&$requests,$events): array {
        $requests[] = $request;
        if (count($requests) === 1) return wire($events);
        $native = A::messages($request['messages']);
        check($request['model_selection'] === 'selected-claude' && $request['_disable_model_fallback'], 'Selected model changed');
        check($native[2]['content'][0]['tool_use_id'] === 'tool_a' && $native[2]['content'][1]['tool_use_id'] === 'tool_b', 'Neuron results did not round trip');
        return wire(array_merge([start(),['type'=>'content_block_delta','index'=>0,'delta'=>['type'=>'text_delta','text'=>'已找到服装和模特。']]],end('end_turn')));
    });
    $tool = new class($toolExecutions) extends Tool {
        protected string $name = 'lookup';
        protected ?string $description = 'Find a named item';
        private array $executions;
        public function __construct(array &$executions) { $this->executions = &$executions; }
        protected function properties(): array { return [new ToolProperty('name',PropertyType::STRING,'Name',true)]; }
        public function __invoke(string $name): string { $this->executions[] = $name; return 'found:'.$name; }
    };
    $agent = (new Agent())->setThreadId('anthropic-contract')->setAiProvider($provider)->setTools([$tool]);
    $reply = $agent->chat(new UserMessage('Find clothing and model'));
    check($reply->getMessage()->getContent() === '已找到服装和模特。' && $toolExecutions === ['服装','模特'] && count($requests) === 2, 'Native Neuron tool loop failed');
    // Interleaved Chat tool deltas must remain compatible after fixing sparse indices.
    $calls = invoke('mergeToolCalls',[],[['index'=>1,'id'=>'b','function'=>['name'=>'lookup','arguments'=>'{"name":']],['index'=>0,'id'=>'a','function'=>['name'=>'lookup','arguments'=>'{}']]]);
    $calls = invoke('mergeToolCalls',$calls,[['index'=>1,'function'=>['arguments'=>'"b"}']]]);
    check($calls[1]['function']['arguments'] === '{"name":"b"}' && $calls[0]['id'] === 'a','Chat parallel calls regressed');
    echo "Anthropic protocol passed: native Neuron round trip, parallel tools, SSE byte splits, thinking isolation, completion guard, usage, images and Chat regression\n";
}
