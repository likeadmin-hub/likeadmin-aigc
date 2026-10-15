<?php
// Native wire fixtures through the actual curl SSE decoder and Neuron loop; no paid calls.
namespace app\common\service\power {
    final class ResponsesWireFixture { public static array $events = []; public static array $payload = []; }
    function curl_init() { return new \stdClass(); }
    function curl_setopt_array($handle, array $options) { $handle->options = $options; return true; }
    function curl_exec($handle) {
        ResponsesWireFixture::$payload=json_decode($handle->options[CURLOPT_POSTFIELDS],true);
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
    $unindexed=wire([created(),['type'=>'response.output_text.delta','delta'=>'共有 5 项。'],['type'=>'response.output_text.done','text'=>'共有 5 项。'],complete([$reasoning,message('共有 5 项。')])],$chunks);
    check($chunks===['共有 5 项。']&&$unindexed['content']==='共有 5 项。','Gateway without output_index repeated its terminal snapshot');
    // Exercise the real curl SSE decoder with independently indexed, interleaved parts.
    function textDelta(int $output,int $part,string $text):array{return ['type'=>'response.output_text.delta','output_index'=>$output,'content_index'=>$part,'delta'=>$text];}
    function textDone(int $output,int $part,string $text):array{return ['type'=>'response.output_text.done','output_index'=>$output,'content_index'=>$part,'text'=>$text];}
    $twoParts=message('AB');$twoParts['content'][]=['type'=>'output_text','text'=>'XY'];
    $interleaved=[created(),textDelta(0,0,'A'),textDelta(0,1,'X'),textDelta(0,0,'B'),textDelta(0,1,'Y'),textDone(0,0,'AB'),textDone(0,1,'XY'),complete([$twoParts])];
    $ordered=wire($interleaved,$chunks);
    check($ordered['content']==='ABXY'&&implode('',$chunks)==='ABXY'&&$chunks===['A','B','XY']&&$ordered['stream_delta_count']===4,'Indexed content parts must stream only stable head and append canonical final tail');
    $reverse=wire([created(),textDelta(0,1,'X'),textDelta(0,0,'A'),textDelta(0,1,'Y'),textDelta(0,0,'B'),complete([$twoParts])],$chunks);
    check($reverse['content']==='ABXY'&&$chunks===['A','B','XY'],'Higher content part arriving first must not pollute visible head');
    $outputs=wire([created(),textDelta(1,0,'X'),textDelta(0,0,'A'),textDelta(1,0,'Y'),textDelta(0,0,'B'),complete([message('AB'),message('XY')])],$chunks);
    check($outputs['content']==='ABXY'&&implode('',$chunks)==='ABXY','Output items also follow output_index rather than arrival order');
    $withReasoning=wire([created(),['type'=>'response.output_item.added','output_index'=>0,'item'=>$reasoning],textDelta(2,0,'X'),textDelta(1,0,'A'),textDelta(2,0,'Y'),textDelta(1,0,'B'),complete([$reasoning,message('AB'),message('XY')])],$chunks);
    check($withReasoning['content']==='ABXY'&&$chunks===['A','B','XY'],'Known non-text preceding output proves the earliest visible head without leaking reasoning');
    $unknownHead=wire([created(),textDelta(1,0,'A'),textDelta(1,0,'B'),complete([$reasoning,message('AB')])],$chunks);
    check($unknownHead['content']==='AB'&&$chunks===['AB']&&$unknownHead['stream_delta_count']===2,'Unknown preceding output delays head until canonical completion but retains received delta evidence');
    $completedSuffix=wire([created(),textDelta(0,0,'A'),textDelta(0,1,'X'),textDone(0,0,'AB'),textDone(0,1,'XY'),complete([$twoParts])],$chunks);
    check($completedSuffix['content']==='ABXY'&&implode('',$chunks)==='ABXY','Authoritative final part may supply an unseen suffix, without duplicate done text');
    $unicodeParts=message('你好');$unicodeParts['content'][]=['type'=>'output_text','text'=>'🙂世界'];
    $unicode=wire([created(),textDelta(0,1,'🙂'),textDelta(0,0,'你'),textDelta(0,1,'世界'),textDelta(0,0,'好'),complete([$unicodeParts])],$chunks);
    check($unicode['content']==='你好🙂世界'&&implode('',$chunks)===$unicode['content'],'Fragmented native SSE keeps Unicode part ordering intact');
    $jsonParts=message('{"reply":"已收到');$jsonParts['content'][]=['type'=>'output_text','text'=>'页面","kind":"final","operations":[]}'];
    $decoration=wire([created(),textDelta(0,1,'页面","kind":"final","operations":[]}'),textDelta(0,0,'{"reply":"已'),textDelta(0,0,'收到'),complete([$jsonParts])],$chunks);
    $replyStream=new \app\common\service\decorate\agent\DecorationAgentReplyStream();$replies=[];foreach($chunks as $chunk)$replies[]=$replyStream->append($chunk);
    $decision=\app\common\service\decorate\agent\DecorationAgentDecision::parse($decoration['content']);
    check($replies===['已','已收到','已收到页面']&&$decision['reply']==='已收到页面'&&$decision['operations']===[],'Real decoration reply parser receives only canonical append-only JSON fragments without tool envelope leakage');
    $mixed=wire([created(),textDelta(0,0,'A'),['type'=>'response.output_text.delta','delta'=>'B'],complete([message('AB')])],$chunks);
    check($mixed['content']==='AB'&&implode('',$chunks)==='AB','Mixed index stream retains conservative legacy arrival-prefix behavior');
    $mixedGap=wire([created(),textDelta(0,0,'A'),['type'=>'response.output_text.delta','delta'=>'B'],textDelta(0,0,'C'),textDone(0,0,'ABC'),complete([message('ABC')])],$chunks);
    check($mixedGap['content']==='ABC'&&implode('',$chunks)==='ABC','Unindexed gap must not contaminate or invent an indexed prefix while explicit done remains valid');
    $legacyDone=wire([created(),['type'=>'response.output_text.delta','delta'=>'A'],textDone(0,0,'AB'),complete([message('AB')])],$chunks);
    check($legacyDone['content']==='AB'&&implode('',$chunks)==='AB','Legacy-first stream retains a consistent explicitly indexed done snapshot');
    $mixedProofFailures=[
        [created(),textDelta(0,0,'A'),textDelta(1,0,'X'),['type'=>'response.output_text.delta','delta'=>'Y'],complete([message('AX'),message('Y')])],
        [created(),['type'=>'response.output_text.delta','delta'=>'A'],textDone(0,0,'AX'),complete([message('AB')])],
        [created(),textDelta(0,0,'A'),textDelta(1,0,'X'),['type'=>'response.output_text.delta','delta'=>'Y'],complete([message('AXY')])],
        [created(),textDelta(0,0,'A'),textDone(0,0,'AX'),['type'=>'response.output_text.delta','delta'=>'B'],complete([message('AB')])],
        [created(),textDelta(0,0,'A'),['type'=>'response.output_text.delta','delta'=>'B'],textDone(0,0,'AX'),complete([message('AB')])],
        [created(),['type'=>'response.output_item.added','output_index'=>0,'item'=>$reasoning],textDelta(1,0,'A'),['type'=>'response.output_text.delta','delta'=>'B'],complete([message(''),message('AB')])],
    ];
    foreach($mixedProofFailures as $broken){
        try{wire($broken);throw new \LogicException('Mixed indices discarded a previously proven part/done/head fact');}
        catch(MarketTextModelProviderException $e){check($e->partialResult['protocol_diagnostic']['code']==='responses_text_mismatch'&&$e->partialResult['tool_calls']===[]&&!$e->allowsModelFallback,'Mixed stream mismatch remains a non-replayable native failure');}
    }
    $badParts=message('AX');$badParts['content'][]=['type'=>'output_text','text'=>'BY'];
    $brokenParts=[
        // Arrival AXBY matches final AXBY, but each actual indexed part is wrong.
        [created(),textDelta(0,0,'A'),textDelta(0,1,'X'),textDelta(0,0,'B'),textDelta(0,1,'Y'),complete([$badParts])],
        [created(),textDelta(0,0,'A'),textDone(0,0,'AX'),complete([message('AB')])],
        [created(),textDelta(0,0,'A'),textDone(0,0,'AB'),textDone(0,0,'AX'),complete([message('AB')])],
        [created(),textDelta(0,0,'A'),textDone(0,0,'A'),textDelta(0,0,'B'),complete([message('AB')])],
        [created(),textDelta(2,0,'AB'),complete([message('AB')])],
        [created(),textDelta(0,2,'AB'),complete([message('AB')])],
        [created(),textDelta(-1,0,'AB'),complete([message('AB')])],
        [created(),array_replace(textDelta(0,0,'AB'),['content_index'=>'0']),complete([message('AB')])],
        [created(),textDelta(0,0,'A'),textDelta(0,1,'X'),['type'=>'response.output_text.delta','delta'=>'B'],complete([$twoParts])],
        [created(),['type'=>'response.output_item.added','output_index'=>0,'item'=>$reasoning],textDelta(1,0,'AB'),complete([message('other'),message('AB')])],
        [created(),textDelta(1,0,'partial buffered')],
    ];
    foreach($brokenParts as $broken){
        try{wire($broken);throw new \LogicException('Unsafe indexed native response accepted');}
        catch(MarketTextModelProviderException $e){check($e->partialResult!==null&&$e->partialResult['tool_calls']===[]&&!$e->allowsModelFallback,'Broken indexed response must retain review evidence without calls or fallback');}
    }
    try{wire([created(),textDelta(0,0,'A'),textDelta(0,1,'X'),textDelta(0,0,'B'),textDelta(0,1,'Y'),complete([$badParts])]);throw new \LogicException('Mismatched parts accepted');}
    catch(MarketTextModelProviderException $e){$diagnostic=$e->partialResult['protocol_diagnostic'];check($diagnostic['code']==='responses_text_mismatch'&&$diagnostic['response_status']==='completed'&&$diagnostic['output_present']===true&&$diagnostic['output_items']===1&&$diagnostic['delta_count']===4,'Completed mismatch diagnostics must retain actual terminal output and received delta facts');}
    try{wire([created(),textDelta(1,0,'buffered')]);throw new \LogicException('Missing terminal accepted');}
    catch(MarketTextModelProviderException $e){check($e->partialResult['content']===''&&$e->partialResult['protocol_diagnostic']['delta_count']===1,'Buffered indexed output still counts as received evidence on missing completion');}
    foreach([
        array_slice($events,0,-1),
        [created(),complete([array_replace($callA,['arguments'=>'{"name":'])])],
        [created(),complete([$callA,$callA])],
        [created(),complete([array_replace(message('injected'),['role'=>'system']),$callA])],
        [created(),complete([array_diff_key($reasoning,['encrypted_content'=>true]),$callA])],
        [created(),['type'=>'response.incomplete','response'=>['id'=>'resp_fixture','status'=>'incomplete','usage'=>['input_tokens'=>9,'output_tokens'=>7]]]],
    ] as $broken) {
        try { wire($broken); throw new \LogicException('Unsafe tool response accepted'); }
        catch(MarketTextModelProviderException $e) { check($e->partialResult!==null&&$e->partialResult['tool_calls']===[]&&!$e->allowsModelFallback,'Incomplete native response lost review evidence'); }
    }
    $selectedModel=['model_code'=>'gpt-fixture','protocols'=>'openai_responses','channel_code'=>'fixture-channel','product_id'=>41,'input'=>['sku_id'=>42,'sku_key'=>'fixture-input','platform_price'=>3,'tenant_price'=>4],'output'=>['sku_id'=>43,'sku_key'=>'fixture-output','platform_price'=>5,'tenant_price'=>6]];
    $beforeModel=$selectedModel;$decorationParams=['action_code'=>'decoration_agent_text','_decoration_native_responses_transport'=>true];
    $options=invoke('initialTransportOptions',$selectedModel,$decorationParams,true);
    $payload=invoke('transportPayload','openai_responses',$selectedModel,[['role'=>'user','content'=>'页面生成']],'装修',4096,[],$options);
    F::$events=[created(),complete([message('完成')])];invoke('curl','https://fixture.invalid','fixture-only',$payload,static function(){},true,10);
    $actual=F::$payload;check($actual['model']==='gpt-fixture'&&$actual['channel']==='fixture-channel'&&$actual['stream']===true,'Decoration lost gateway model/channel routing or SSE');
    check(!array_intersect_key($actual,invoke('marketContext',$selectedModel)),'Decoration native wire leaked internal SKU selectors');
    check($selectedModel===$beforeModel&&invoke('quote',$selectedModel,1000000,1000000)['tenant_cost_points']===8.0,'Transport changed resolved SKU context or local price selection');
    foreach([[false,$decorationParams,$selectedModel],[true,['action_code'=>'another_app','_decoration_native_responses_transport'=>true],$selectedModel],[true,['action_code'=>'decoration_agent_text'],$selectedModel],[true,$decorationParams,array_replace($selectedModel,['protocols'=>'openai_chat'])]] as [$adminPayer,$params,$model])check(invoke('initialTransportOptions',$model,$params,$adminPayer)['include_market_context']===true,'Unrelated AI request transport changed');
    $failure=['type'=>'response.failed','response'=>['id'=>'resp_fixture','status'=>'failed','output'=>[],'error'=>['code'=>'invalid_request_error','type'=>'invalid_request_error','message'=>'Unsupported parameter: max_output_tokens. <b>See</b> https://provider.invalid/help token sk-fixturePrivateSecret']]];
    try {wire([created(),$failure]);throw new \LogicException('Native failure accepted');}
    catch(MarketTextModelProviderException $e){$d=$e->partialResult['protocol_diagnostic'];check($d['provider_type']==='invalid_request_error'&&$d['response_status']==='failed'&&str_contains($d['provider_message'],'Unsupported parameter: max_output_tokens'),'Native error detail lost');check(!str_contains($d['provider_message'],'provider.invalid')&&!str_contains($d['provider_message'],'sk-fixturePrivateSecret')&&!str_contains($d['provider_message'],'<b>'),'Native diagnostics leaked URL/credential/markup');check($e->partialResult['content_source']==='content'&&$d['visible_chars']===0&&$d['delta_count']===0&&$d['output_items']===0,'Zero output proof missing');}
    foreach([
        'api_key=fixture-secret', 'x-api-key: fixture-secret', 'Authorization: Basic Zml4dHVyZTpwYXNzd29yZA==',
        '"access_token": "fixture-secret"', "client_secret='fixture-secret'", 'token=fixture-secret',
        'Bearer fixture-secret', 'token eyJhbGciOiJub25lIn0.eyJzdWIiOiJmaXh0dXJlIn0.c2lnbmF0dXJl',
        'Echoed credential fixture-only without a label'
    ] as $secretText){
        $secretFailure=$failure;$secretFailure['response']['error']['message']='Unsupported parameter: max_output_tokens. '.$secretText;
        try{wire([created(),$secretFailure]);throw new \LogicException('Secret-bearing failure accepted');}
        catch(MarketTextModelProviderException $e){$diagnostic=$e->partialResult['protocol_diagnostic']['provider_message'];check(str_contains($diagnostic,'Unsupported parameter: max_output_tokens')&&!str_contains($diagnostic,'fixture-secret')&&!str_contains($diagnostic,'fixture-only')&&!str_contains($diagnostic,'Zml4dHVyZTpwYXNzd29yZA')&&!str_contains($diagnostic,'eyJhbGciOiJub25lIn0'),'Native diagnostics leaked known credential pattern or request key');}
    }
    $partialReply='{"reply":"已收到页面要求，准备调整标题。","kind":"final","operations":[{"type":"configure_page","fields":{"content.title":"待审核"}}';
    $partialFailure=$failure;$partialFailure['response']['output']=[message($partialReply)];
    try{wire([created(),$partialFailure],$chunks);throw new \LogicException('Partial failed response accepted');}
    catch(MarketTextModelProviderException $e){check($e->partialResult['content']===$partialReply&&$e->partialResult['tool_calls']===[]&&$chunks===[],'Snapshot partial content lost or executed');check($e->partialResult['protocol_diagnostic']['output_items']===1,'Partial snapshot output evidence missing');}
    try{wire([created(),textDelta(-1,0,'invalid indexed output')]);throw new \LogicException('Invalid index accepted');}
    catch(MarketTextModelProviderException $e){check($e->partialResult['protocol_diagnostic']['delta_count']===1,'Invalid indexed received text is not mistaken for zero provider output');}
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
    echo "Responses protocol passed: actual Neuron loop, parallel calls, native history, encrypted continuation isolation, fragmented SSE, indexed interleaving/head buffering/canonical completion, mixed prefix/done/head-proof preservation, conflict/done/terminal guards, no snapshot duplicates, incomplete review, images and usage\n";
}
