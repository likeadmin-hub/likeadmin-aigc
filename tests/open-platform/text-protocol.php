<?php
require dirname(__DIR__, 2).'/vendor/autoload.php';
use app\common\service\open_platform\OpenTextProtocol as P;
function ok($condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function wire($body, bool $sse = false, int $status = 200): array {
    return ['status'=>$status, 'headers'=>['Content-Type'=>$sse ? 'text/event-stream; charset=utf-8' : 'application/json'], 'body'=>is_array($body) ? json_encode($body) : $body];
}
function event(array $data, string $name = ''): string { return ($name !== '' ? 'event: '.$name."\n" : '').'data: '.json_encode($data)."\n\n"; }
$chat = ['id'=>'chat1','object'=>'chat.completion','choices'=>[['message'=>['role'=>'assistant','content'=>'OK'],'finish_reason'=>'stop']], 'usage'=>['prompt_tokens'=>10,'completion_tokens'=>4,'total_tokens'=>14,'prompt_tokens_details'=>['cached_tokens'=>3]]];
foreach (['chat/completions'=>'openai_chat','completions'=>'openai_completions','responses'=>'openai_responses','messages'=>'anthropic_messages'] as $path=>$protocol) ok(P::protocol('/api/v1/'.$path) === $protocol, $path.' protocol');
ok(P::protocol('/api/v1/gemini') === '' && P::protocol('/chat/completions') === '', 'Only real published paths map');
$result = P::observe(wire($chat), 'openai_chat');
ok($result['complete'] && $result['started'] && !$result['failed'], 'Chat JSON terminal');
ok($result['data']['usage']['input_tokens'] === 10 && $result['data']['usage']['prompt_tokens_details']['cached_tokens'] === 3, 'Preserve prompt total and cached detail independently');
$chunk = ['id'=>'chat1','object'=>'chat.completion.chunk','choices'=>[['index'=>0,'delta'=>['content'=>'OK'],'finish_reason'=>'stop']]];
$stream = event($chunk).event(['object'=>'chat.completion.chunk','choices'=>[],'usage'=>$chat['usage']])."data: [DONE]\n\n";
$input = wire($stream, true); $before = serialize($input); $result = P::observe($input, 'openai_chat');
ok(serialize($input) === $before && $input['body'] === $stream, 'Wire bytes untouched');
ok($result['complete'] && $result['data']['usage']['total_tokens'] === 14, 'Chat SSE terminal with usage after finish');
ok(!P::observe(wire(event($chunk), true), 'openai_chat')['complete'], 'Finish reason alone may precede another choice or usage');
ok(!P::observe(wire("data: [DONE]\n\n", true), 'openai_chat')['complete'], 'DONE alone is not acceptance');
$multi = $chat; $multi['choices'][] = ['message'=>['content'=>'partial'],'finish_reason'=>null];
ok(!P::observe(wire($multi), 'openai_chat')['complete'], 'All JSON choices must terminate');
$completion = ['object'=>'text_completion','choices'=>[['text'=>'OK','finish_reason'=>'length']], 'usage'=>['prompt_tokens'=>3,'completion_tokens'=>2]];
ok(P::observe(wire($completion), 'openai_completions')['data']['usage']['total_tokens'] === 5, 'Legacy completions retain native counts and derive total');
$start = ['type'=>'message_start','message'=>['id'=>'msg1','type'=>'message','role'=>'assistant','content'=>[], 'usage'=>['input_tokens'=>10,'output_tokens'=>1,'cache_creation_input_tokens'=>2,'cache_read_input_tokens'=>3,'server_tool_use'=>['web_search_requests'=>1]]]];
$claude = event($start,'message_start').event(['type'=>'message_delta','delta'=>['stop_reason'=>'end_turn'],'usage'=>['output_tokens'=>4,'server_tool_use'=>['web_fetch_requests'=>2]]],'message_delta').event(['type'=>'message_delta','usage'=>['output_tokens'=>7]],'message_delta').event(['type'=>'message_stop'],'message_stop');
foreach (["\n", "\r\n", "\r"] as $ending) {
    $result = P::observe(wire(str_replace("\n", $ending, $claude), true), 'anthropic_messages');
    ok($result['complete'] && $result['data']['usage']['output_tokens'] === 7, 'Claude cumulative usage replaces prior count');
    ok($result['data']['usage']['total_tokens'] === 22 && count($result['data']['usage']['server_tool_use']) === 2, 'Claude cache counts and nested partial usage preserved');
}
$missingCache = $start['message']; unset($missingCache['usage']['cache_creation_input_tokens'], $missingCache['usage']['cache_read_input_tokens']); $missingCache['stop_reason'] = 'end_turn';
$result = P::observe(wire($missingCache), 'anthropic_messages');
ok($result['complete'] && !isset($result['data']['usage']['total_tokens']) && !isset($result['data']['usage']['cache_read_input_tokens']), 'Missing cache usage is not fabricated');
$response = ['id'=>'resp1','object'=>'response','status'=>'completed','output'=>[], 'usage'=>['input_tokens'=>8,'output_tokens'=>3,'input_tokens_details'=>['cached_tokens'=>2]]];
foreach (['completed','incomplete','failed'] as $status) {
    $response['status'] = $status;
    foreach ([wire($response), wire(event(['type'=>'response.'.$status, 'response'=>$response],'response.'.$status),true)] as $w) {
        $result = P::observe($w, 'openai_responses');
        ok($result['complete'] && $result['failed'] === ($status === 'failed') && $result['data']['usage']['total_tokens'] === 11, 'Responses native terminal '.$status);
    }
}
$response['status'] = 'in_progress';
ok(!P::observe(wire($response), 'openai_responses')['complete'], 'In-progress JSON is not terminal');
$partialResponse = event(['type'=>'response.created','response'=>['object'=>'response','id'=>'r','status'=>'in_progress','usage'=>['input_tokens'=>8,'input_tokens_details'=>['cached_tokens'=>2]]]]).event(['type'=>'response.completed','response'=>['object'=>'response','id'=>'r','status'=>'completed','usage'=>['output_tokens'=>3]]]);
$result = P::observe(wire($partialResponse,true),'openai_responses');
ok($result['complete'] && $result['data']['usage']['total_tokens'] === 11 && $result['data']['usage']['input_tokens_details']['cached_tokens'] === 2, 'Response partial nested usage merge');
$error = ['type'=>'error','error'=>['type'=>'overloaded_error','message'=>'overloaded']];
$result = P::observe(wire(event($start).event($error),true),'anthropic_messages');
ok($result['failed'] && $result['started'], 'Error after Claude start cannot prove safe refund');
$result = P::observe(wire(event($chunk).event(['error'=>['message'=>'broken']]),true),'openai_chat');
ok($result['failed'] && $result['started'], 'Error after OpenAI tokens cannot prove safe refund');
$result = P::observe(wire($error,false,529),'anthropic_messages');
ok($result['failed'] && !$result['started'], 'Initial native rejection has no generation evidence');
$multiline = ": keepalive\r\nid: 12\r\nevent: message_start\r\ndata: {\"type\":\"message_start\",\r\ndata: \"message\":{\"type\":\"message\",\"usage\":{\"input_tokens\":5}}}\r\n\r\n".event(['type'=>'message_stop']);
ok(P::observe(wire($multiline,true),'anthropic_messages')['complete'], 'SSE comments CRLF and multiline data');
foreach ([event($chunk)."data: [DONE]\n", event($chunk)."data: {\n\ndata: [DONE]\n\n", event($chunk)."data: [DONE]\n\ndata: {", 'nonsense'] as $broken) ok(!P::observe(wire($broken,true),'openai_chat')['complete'], 'Malformed or truncated SSE remains pending');
foreach (['{}','[]','null','{"object":','{"usage":{"total_tokens":0}}'] as $unknown) ok(!P::observe(wire($unknown),'openai_chat')['complete'], 'Unknown JSON remains pending');
$result = P::observe(wire(['object'=>'text_completion','choices'=>[['text'=>'OK','finish_reason'=>'stop']]]),'openai_completions');
ok($result['complete'] && !isset($result['data']['usage']), 'Terminal output does not fabricate usage');
$invalidUsage = $chat; $invalidUsage['usage'] = ['prompt_tokens'=>-1,'completion_tokens'=>'4'];
$result = P::observe(wire($invalidUsage),'openai_chat');
ok(!isset($result['data']['usage']['input_tokens'], $result['data']['usage']['output_tokens'], $result['data']['usage']['total_tokens']), 'Invalid counts cannot generate canonical counts');
$conflict = event(['type'=>'message_stop'],'message_start');
ok(!P::observe(wire(event($start).$conflict,true),'anthropic_messages')['complete'], 'Conflicting SSE event and JSON type rejected');
$failedResponse = ['object'=>'response','id'=>'r','status'=>'failed','output'=>[['type'=>'message']], 'error'=>['code'=>'server_error'], 'usage'=>null];
$result = P::observe(wire($failedResponse), 'openai_responses');
ok($result['failed'] && $result['started'] && $result['complete'], 'Native failed response envelope does not become pre-generation rejection');
$nullUsage = event(['type'=>'response.created','response'=>['object'=>'response','id'=>'r','status'=>'in_progress','usage'=>['input_tokens'=>8]]]).event(['type'=>'response.in_progress','response'=>['object'=>'response','id'=>'r','status'=>'in_progress','usage'=>null]]).event(['type'=>'response.completed','response'=>['object'=>'response','id'=>'r','status'=>'completed','usage'=>['output_tokens'=>3]]]);
$result = P::observe(wire($nullUsage,true),'openai_responses');
ok($result['data']['usage']['total_tokens'] === 11, 'Null usage in an intermediate response cannot erase known counts');
// Published documentation examples remain native, extensible and separate from execution proof.
foreach (['openai_chat','openai_completions','openai_responses','anthropic_messages'] as $protocol) {
    $definition = \app\common\service\open_platform\OpenMarketProtocolSchemas::definition($protocol);
    \app\common\service\open_platform\OpenSchema::validateRequest($definition['request_example'], $definition['request_schema'], true);
    ok($definition['request_schema']['additionalProperties'] === true, 'Native request extension fields stay allowed');
    $responses = $definition['submit_responses']['200']['content'];
    foreach ($responses as $mime => $content) foreach ($content['examples'] as $example) {
        \app\common\service\open_platform\OpenSchema::validate($example['value'], $content['schema'], '$', 0, null, true);
        $body = is_string($example['value']) ? $example['value'] : json_encode($example['value']);
        $observed = P::observe(['status'=>200,'headers'=>['content-type'=>$mime],'body'=>$body], $protocol);
        ok($observed['complete'] && !$observed['failed'], $protocol.' documentation has native completion evidence');
        ok(!str_contains($body, 'points_cost') && !str_contains($body, 'frozen_points') && !str_contains($body, 'actual_points'), 'Native text examples contain no supplier prices');
        if ($protocol === 'openai_completions' && $mime === 'text/event-stream') ok(!isset($observed['data']['usage']), 'Legacy stream example does not invent unsupported usage frames');
    }
    ok($definition['request_example']['stream'] === ($protocol !== 'openai_completions'), 'Useful streaming default with legacy exception');
}
$chatDefinition = \app\common\service\open_platform\OpenMarketProtocolSchemas::definition('openai_chat');
ok($chatDefinition['request_example']['stream_options']->include_usage === true, 'Chat streaming example requests actual usage');
foreach ([['role'=>'assistant','tool_calls'=>[(object)['id'=>'call1','type'=>'function','function'=>(object)['name'=>'weather','arguments'=>'{}']]]], ['role'=>'assistant','content'=>null,'tool_calls'=>[]]] as $message) {
    \app\common\service\open_platform\OpenSchema::validateRequest(['model'=>'MODEL','messages'=>[(object)$message],'max_completion_tokens'=>32,'stream_options'=>(object)['include_usage'=>true],'vendor_extension'=>(object)['enabled'=>true]], $chatDefinition['request_schema'], true);
}
$rejected = false;
try { \app\common\service\open_platform\OpenSchema::validateRequest(['model'=>'MODEL','messages'=>[(object)['content'=>'Hello']]], $chatDefinition['request_schema'], true); } catch (\app\common\service\open_platform\OpenException $e) { $rejected = true; }
ok($rejected, 'Chat message role remains required');
$result = P::observe(wire($stream.event(['error'=>['message'=>'late upstream error']]), true), 'openai_chat');
ok($result['failed'] && $result['started'], 'Late upstream error cannot be silently converted into successful completion');
$result = P::observe(wire($stream."data: {broken}\n\n", true), 'openai_chat');
ok(!$result['complete'], 'Malformed trailing frame invalidates terminal certainty');
// Billing observes only native nonnegative integer counts; raw response bytes remain opaque.
foreach (['0', 1.2, -1, true, null, [], PHP_INT_MAX * 2.0] as $invalid) {
    $bad = $chat; $bad['usage']['total_tokens'] = $invalid;
    $raw = wire($bad); $rawBefore = serialize($raw);
    $result = P::observe($raw, 'openai_chat');
    ok(!array_key_exists('total_tokens', $result['data']['usage']), 'Invalid supplied total stays unknown rather than being coerced or replaced');
    ok(serialize($raw) === $rawBefore, 'Sanitizing billing counts cannot alter wire bytes');
    $bad['usage'] = ['prompt_tokens'=>$invalid,'completion_tokens'=>$invalid,'input_tokens'=>$invalid,'output_tokens'=>$invalid,'cache_read_input_tokens'=>$invalid,'cache_creation_input_tokens'=>$invalid,'prompt_tokens_details'=>['cached_tokens'=>$invalid],'completion_tokens_details'=>['reasoning_tokens'=>$invalid],'cache_creation'=>['ephemeral_5m_input_tokens'=>$invalid],'cache_details'=>['cached_tokens'=>$invalid]];
    $usage = P::observe(wire($bad), 'openai_chat')['data']['usage'];
    foreach (['prompt_tokens','completion_tokens','input_tokens','output_tokens','cache_read_input_tokens','cache_creation_input_tokens','total_tokens'] as $field) ok(!array_key_exists($field, $usage), 'Invalid native count removed: '.$field);
    ok(!isset($usage['prompt_tokens_details']['cached_tokens'], $usage['completion_tokens_details']['reasoning_tokens'], $usage['cache_creation']['ephemeral_5m_input_tokens'], $usage['cache_details']['cached_tokens']), 'Invalid cached/reasoning counters removed recursively');
}
$bad = $chat; $bad['usage']['total_tokens'] = 1;
ok(!isset(P::observe(wire($bad), 'openai_chat')['data']['usage']['total_tokens']), 'Total below known input/output is contradictory');
$bad['usage'] = ['prompt_tokens'=>PHP_INT_MAX,'completion_tokens'=>1,'total_tokens'=>PHP_INT_MAX];
ok(!isset(P::observe(wire($bad), 'openai_chat')['data']['usage']['total_tokens']), 'Overflowing component sum invalidates supplied total');
unset($bad['usage']['total_tokens']);
ok(!isset(P::observe(wire($bad), 'openai_chat')['data']['usage']['total_tokens']), 'Overflowing sum cannot produce canonical total');
$bad['usage'] = ['prompt_tokens'=>10,'completion_tokens'=>2,'prompt_tokens_details'=>['cached_tokens'=>11],'completion_tokens_details'=>['reasoning_tokens'=>3]];
$usage = P::observe(wire($bad),'openai_chat')['data']['usage'];
ok(!isset($usage['prompt_tokens_details']['cached_tokens'], $usage['completion_tokens_details']['reasoning_tokens']), 'OpenAI subset counters cannot exceed their totals');
$bad['usage'] = ['prompt_tokens'=>10,'completion_tokens'=>2,'input_tokens'=>1];
$usage = P::observe(wire($bad),'openai_chat')['data']['usage'];
ok(!isset($usage['input_tokens']) && $usage['prompt_tokens'] === 10, 'Conflicting canonical alias stays unknown while native count remains');
$bad = $missingCache; $bad['usage'] = ['input_tokens'=>3,'output_tokens'=>2,'cache_read_input_tokens'=>10,'cache_creation_input_tokens'=>1];
$usage = P::observe(wire($bad),'anthropic_messages')['data']['usage'];
ok($usage['input_tokens'] === 3 && $usage['cache_read_input_tokens'] === 10 && $usage['total_tokens'] === 16, 'Claude cached inputs are additive, not subsets of uncached input');
$bad['usage']['cache_read_input_tokens'] = '10';
$usage = P::observe(wire($bad),'anthropic_messages')['data']['usage'];
ok(!isset($usage['cache_read_input_tokens'], $usage['total_tokens']) && $usage['input_tokens'] === 3, 'Malformed Claude cache prevents total derivation');
$bad = $chat; $bad['usage']['input_tokens_details'] = 1.5;
ok(!isset(P::observe(wire($bad),'openai_chat')['data']['usage']['input_tokens_details']), 'Malformed details container is not a billing number');
$bad['usage'] = '0';
ok(!isset(P::observe(wire($bad),'openai_chat')['data']['usage']), 'Scalar usage container is not a billing number');
$bad = $chat; $bad['usage'] = ['prompt_tokens'=>0,'completion_tokens'=>0,'total_tokens'=>0,'prompt_tokens_details'=>['cached_tokens'=>0]];
$usage = P::observe(wire($bad),'openai_chat')['data']['usage'];
ok($usage['total_tokens'] === 0 && $usage['prompt_tokens_details']['cached_tokens'] === 0, 'Explicit valid native integer zero is retained');
$bad = $chat; $bad['usage'] = ['input_tokens'=>10,'output_tokens'=>2,'total_tokens'=>1];
ok(!isset(P::observe(wire($bad),'openai_chat')['data']['usage']['total_tokens']), 'Canonical counts also establish a contradictory total lower bound');
$badResponse = ['object'=>'response','id'=>'resp_bad','status'=>'completed','output'=>[], 'usage'=>['input_tokens'=>10,'output_tokens'=>2,'total_tokens'=>'12','input_tokens_details'=>['cached_tokens'=>-1],'output_tokens_details'=>['reasoning_tokens'=>'1']]];
$usage = P::observe(wire(event(['type'=>'response.completed','response'=>$badResponse]),true),'openai_responses')['data']['usage'];
ok(!isset($usage['total_tokens'], $usage['input_tokens_details']['cached_tokens'], $usage['output_tokens_details']['reasoning_tokens']), 'Responses SSE receives identical billing count validation');
$invalidFinal = event($start).event(['type'=>'message_delta','usage'=>['output_tokens'=>'4']]).event(['type'=>'message_stop']);
$usage = P::observe(wire($invalidFinal,true),'anthropic_messages')['data']['usage'];
ok(!isset($usage['output_tokens'], $usage['total_tokens']), 'Malformed final cumulative count cannot fall back to a stale earlier count');
echo "PASS native text JSON/SSE protocol observation, terminal evidence, partial usage, caching, malformed streams and wire immutability\n";
