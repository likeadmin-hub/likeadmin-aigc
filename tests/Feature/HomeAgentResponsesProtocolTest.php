<?php
// Native wire fixtures through the actual curl SSE decoder and Neuron loop; no paid calls.
namespace app\common\service\power {
    final class ResponsesWireFixture { public static array $events = []; }
    function curl_init() { return new \stdClass(); }
    function curl_setopt_array($handle, array $options) { $handle->options = $options; return true; }
    function curl_exec($handle) {
        $wire = '';
        foreach (ResponsesWireFixture::$events as $event) $wire .= 'data: '.json_encode($event, JSON_UNESCAPED_UNICODE)."\n\n";
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
    use app\common\service\power\{MarketTextModelRuntimeService as R, ResponsesMessagesAdapter as A, ResponsesWireFixture as F, MarketTextModelProviderException};
    use app\common\service\app\aigc_llm\agent\MarketNeuronProvider;
    use NeuronAI\Agent\Agent;
    use NeuronAI\Chat\Messages\UserMessage;
    use NeuronAI\Tools\{Tool, ToolProperty, PropertyType};
    function check($ok, string $message): void { if (!$ok) throw new \RuntimeException($message); }
    function invoke(string $name, ...$args) { return (new \ReflectionMethod(R::class,$name))->invoke(null,...$args); }
    function wire(array $events, ?array &$visible = null): array {
        F::$events=$events; $visible=[];
        return invoke('curl','https://fixture.invalid','fixture-only',[],static function($type,$data) use (&$visible) { if($type==='delta')$visible[]=$data['delta']; },true,10);
    }
    function created(): array { return ['type'=>'response.created','response'=>['id'=>'resp_fixture','status'=>'in_progress']]; }
    function complete(array $items): array { return ['type'=>'response.completed','response'=>['id'=>'resp_fixture','status'=>'completed','output'=>$items,'usage'=>['input_tokens'=>123,'output_tokens'=>17,'total_tokens'=>140]]]; }
    function message(string $text): array { return ['type'=>'message','id'=>'msg_fixture','role'=>'assistant','status'=>'completed','content'=>[['type'=>'output_text','text'=>$text,'annotations'=>[]]]]; }
    $reasoning=['type'=>'reasoning','id'=>'rs_fixture','summary'=>[],'encrypted_content'=>'opaque-fixture'];
    $callA=['type'=>'function_call','id'=>'fc_a','call_id'=>'call_a','name'=>'lookup','arguments'=>'{"name":"服装"}','status'=>'completed'];
    $callB=['type'=>'function_call','id'=>'fc_b','call_id'=>'call_b','name'=>'lookup','arguments'=>'{"name":"模特"}','status'=>'completed'];
    $items=[$reasoning,message('先查询。'),$callA,$callB];
    $events=[created(),['type'=>'response.reasoning_summary_text.delta','delta'=>'秘密推理'],
        ['type'=>'response.output_item.added','output_index'=>0,'item'=>['type'=>'reasoning','id'=>'rs_fixture','encrypted_content'=>'partial-opaque']],
        ['type'=>'response.output_item.done','output_index'=>0,'item'=>$reasoning],
        ['type'=>'response.output_text.delta','output_index'=>1,'content_index'=>0,'delta'=>'先查'],
        ['type'=>'response.output_text.delta','output_index'=>1,'content_index'=>0,'delta'=>'询。'],
        ['type'=>'response.output_text.done','output_index'=>1,'content_index'=>0,'text'=>'先查询。'],
        ['type'=>'response.output_item.added','output_index'=>2,'item'=>array_replace($callA,['arguments'=>'','status'=>'in_progress'])],
        ['type'=>'response.function_call_arguments.delta','output_index'=>2,'delta'=>'{"name":'],
        ['type'=>'response.function_call_arguments.delta','output_index'=>2,'delta'=>'"服装"}'],
        ['type'=>'response.function_call_arguments.done','output_index'=>2,'arguments'=>$callA['arguments']],
        ['type'=>'response.output_item.done','output_index'=>2,'item'=>$callA],
        complete($items)];
    $result=wire($events,$visible);
    check($visible===['先查','询。']&&$result['content']==='先查询。','Snapshot repeated text or reasoning leaked');
    check(count($result['tool_calls'])===2&&$result['tool_calls'][0]['id']==='call_a'&&$result['tool_calls'][0]['function']['arguments']===$callA['arguments'],'Native calls duplicated or wrong call ID');
    check($result['_responses_output']===$items&&$result['usage']['total_tokens']===140&&$result['provider_request_id']==='resp_fixture','Continuation/receipt/usage lost');
    foreach([
        array_slice($events,0,-1),
        [created(),complete([array_replace($callA,['arguments'=>'{"name":'])])],
        [created(),complete([$callA,$callA])],
        [created(),complete([array_diff_key($reasoning,['encrypted_content'=>true]),$callA])],
        [created(),['type'=>'response.incomplete','response'=>['id'=>'resp_fixture','status'=>'incomplete','usage'=>['input_tokens'=>9,'output_tokens'=>7]]]],
    ] as $broken) {
        try { wire($broken); throw new \LogicException('Unsafe tool response accepted'); }
        catch(MarketTextModelProviderException $e) { check($e->partialResult!==null&&$e->partialResult['tool_calls']===[]&&!$e->allowsModelFallback,'Incomplete native response lost review evidence'); }
    }
    $mapped=A::input([['role'=>'user','content'=>[['type'=>'text','text'=>'看图'],['type'=>'image_url','image_url'=>['url'=>'data:image/png;base64,YQ==']]]],['role'=>'assistant','content'=>'说明','tool_calls'=>[$result['tool_calls'][0]]],['role'=>'tool','tool_call_id'=>'call_a','content'=>'done']]);
    check($mapped[0]['content'][0]['type']==='input_text'&&$mapped[0]['content'][1]['type']==='input_image'&&$mapped[3]['type']==='function_call_output','Input mapping wrong');
    $params=A::params(['tools'=>[['type'=>'function','function'=>['name'=>'lookup','parameters'=>['type'=>'object']]]],'tool_choice'=>['type'=>'function','function'=>['name'=>'lookup']],'response_format'=>['type'=>'json_schema','json_schema'=>['name'=>'reply','schema'=>['type'=>'object']]],'enable_thinking'=>false,'stream_options'=>['include_usage'=>true]]);
    check($params['tools'][0]['name']==='lookup'&&$params['tools'][0]['strict']===false&&$params['tool_choice']['name']==='lookup'&&$params['text']['format']['name']==='reply'&&!isset($params['response_format'])&&!isset($params['enable_thinking']),'Request options not native');
    $requests=[]; $executions=[];
    $provider=new MarketNeuronProvider('selected-responses',function(array $request)use(&$requests,$events,$items):array {
        $requests[]=$request;
        if(count($requests)===1)return wire($events);
        $normalized=invoke('normalizeMessages','test',[],$request['messages'],true);
        $native=A::input($normalized);
        check(array_slice($native,1,4)===$items&&$native[5]['type']==='function_call_output'&&$native[5]['call_id']==='call_a'&&$native[6]['call_id']==='call_b','Neuron lost ordered encrypted continuation or call outputs');
        check($request['model_selection']==='selected-responses'&&$request['_disable_model_fallback'],'Model changed');
        return wire([created(),complete([message('已找到。')])]);
    });
    $tool=new class($executions) extends Tool {
        protected string $name='lookup'; protected ?string $description='Find item'; private array $executions;
        public function __construct(array &$executions){$this->executions=&$executions;}
        protected function properties():array{return [new ToolProperty('name',PropertyType::STRING,'Name',true)];}
        public function __invoke(string $name):string{$this->executions[]=$name;return 'found:'.$name;}
    };
    $agent=(new Agent())->setThreadId('responses-contract')->setAiProvider($provider)->setTools([$tool]);
    $reply=$agent->chat(new UserMessage('Find clothing and model'));
    check($reply->getMessage()->getContent()==='已找到。'&&$executions===['服装','模特']&&count($requests)===2,'Real Neuron Responses round trip failed');
    $fresh=new MarketNeuronProvider('selected-responses',function(array $request):array {check(!str_contains(json_encode($request),'opaque-fixture'),'Another provider instance inherited context');return ['content'=>'你好'];});
    (new Agent())->setThreadId('fresh-responses-contract')->setAiProvider($fresh)->chat(new UserMessage('你好'));
    $legacy=invoke('normalizeMessages','test',[],[['role'=>'assistant','content'=>'visible','tool_calls'=>[['id'=>'a']],'_responses_output'=>$items]],false);
    check(!str_contains(json_encode($legacy),'opaque-fixture'),'Native continuation leaked into legacy normalization');
    echo "Responses protocol passed: actual Neuron loop, parallel calls, native history, encrypted continuation isolation, fragmented SSE, no snapshot duplicates, incomplete review, images and usage\n";
}
