<?php
// No external dispatch: this fixture serves only loopback protocol acceptance tests.
if (($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') { http_response_code(403); exit; }
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$case = $input['fixture_case'] ?? 'chat';
header('X-Request-Id: upstream-fixture-id');
header('X-RateLimit-Remaining-Requests: 17');
if (in_array($case, ['reject','unavailable'], true)) {
    http_response_code($case === 'reject' ? 429 : 503);
    header('Content-Type: application/json'); header('Retry-After: 5');
    echo '{"error":{"type":"rate_limit_error","message":"fixture preserved exactly"}}'; exit;
}
if ($case === 'responses') {
    $body = '{ "id":"resp-fixture", "object":"response", "status":"completed", "output":[{"type":"message","content":[{"type":"output_text","text":"OK"}]}], "usage":{"input_tokens":7,"output_tokens":9,"total_tokens":16}, "native_extra":{"untouched":true} }';
    $chunks = ["event: response.created\ndata: {\"type\":\"response.created\",\"response\":{\"object\":\"response\",\"id\":\"resp-fixture\",\"status\":\"in_progress\"}}\n\n", "event: response.completed\ndata: {\"type\":\"response.completed\",\"response\":".$body."}\n\n"];
} elseif ($case === 'claude') {
    $body = '{"id":"msg-fixture","type":"message","role":"assistant","content":[{"type":"thinking","thinking":"native thought","signature":"opaque"},{"type":"text","text":"OK"}],"stop_reason":"end_turn","usage":{"input_tokens":7,"output_tokens":9,"cache_read_input_tokens":0,"cache_creation_input_tokens":0}}';
    $chunks = ["event: message_start\ndata: {\"type\":\"message_start\",\"message\":{\"id\":\"msg-fixture\",\"type\":\"message\",\"usage\":{\"input_tokens\":7,\"output_tokens\":1,\"cache_read_input_tokens\":0,\"cache_creation_input_tokens\":0}}}\n\n", "event: ping\ndata: {\"type\":\"ping\"}\n\nevent: message_delta\ndata: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"end_turn\"},\"usage\":{\"output_tokens\":9}}\n\nevent: message_stop\ndata: {\"type\":\"message_stop\"}\n\n"];
} elseif ($case === 'legacy') {
    $body = '{"object":"text_completion","choices":[{"text":"OK","index":0,"finish_reason":"stop"}],"usage":{"prompt_tokens":7,"completion_tokens":9,"total_tokens":16}}';
    $chunks = ["data: ".$body."\n\n", "data: [DONE]\n\n"];
} else {
    $body = '{ "id":"chat-fixture", "object":"chat.completion", "choices":[{"message":{"role":"assistant","content":null,"tool_calls":[{"id":"call-fixture","type":"function","function":{"name":"f","arguments":"{}"}}]},"finish_reason":"tool_calls"}], "usage":{"prompt_tokens":7,"completion_tokens":9,"total_tokens":16}, "opaque":{} }';
    $chunks = ["data: {\"id\":\"chat-fixture\",\"object\":\"chat.completion.chunk\",\"choices\":[{\"index\":0,\"delta\":{\"content\":\"OK\"},\"finish_reason\":null}]}\r\n\r\n", "data: {\"object\":\"chat.completion.chunk\",\"choices\":[],\"usage\":{\"prompt_tokens\":7,\"completion_tokens\":9,\"total_tokens\":16}}\r\n\r\ndata: [DONE]\r\n\r\n"];
}
if ($case === 'missing_usage') { $body = json_decode($body, true); unset($body['usage']); $body = json_encode($body); $chunks[1] = "data: [DONE]\n\n"; }
if ($case === 'stream_error') $chunks[1] = "event: error\ndata: {\"error\":{\"type\":\"overloaded_error\",\"message\":\"native error\"}}\n\n";
if ($case === 'truncated' || $case === 'disconnect') $chunks = [$chunks[0]];
$stream = !empty($input['stream']) || $case === 'unexpected_sse';
if ($case === 'json_on_stream') $stream = false;
header('Content-Type: '.($stream ? 'text/event-stream; charset=utf-8' : 'application/json'));
if ($case === 'disconnect') header('Content-Length: 99999');
if ($stream) { foreach ($chunks as $chunk) { echo $chunk; flush(); usleep(20000); } }
else echo $body;
