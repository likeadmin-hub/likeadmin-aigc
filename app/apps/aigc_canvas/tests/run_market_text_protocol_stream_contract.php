<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
require $root . '/vendor/autoload.php';

use app\common\service\power\MarketTextModelRuntimeService;

$failures = [];
$source = file_get_contents($root . '/app/common/service/power/MarketTextModelRuntimeService.php');

if (!is_string($source) || !str_contains($source, '$payload[\'stream\'] = true;')) {
    $failures[] = 'market text requests must force stream=true';
}
if (!is_string($source) || !str_contains($source, 'Accept: text/event-stream')) {
    $failures[] = 'market text requests must accept SSE';
}
if (is_string($source) && str_contains($source, 'return self::requestJson($url, $payload, $headers, $sslVerify, $requestTimeout);')) {
    $failures[] = 'market text requests must not use the buffered JSON path';
}

$protocol = new ReflectionMethod(MarketTextModelRuntimeService::class, 'protocol');
$protocol->setAccessible(true);
if ($protocol->invoke(null, 'openai_chat', ['openai_chat'], 'claude-sonnet-4') !== 'anthropic_messages') {
    $failures[] = 'Claude must override stale OpenAI protocol metadata';
}
if ($protocol->invoke(null, 'openai_responses', ['openai_responses'], 'gpt-5') !== 'openai_responses') {
    $failures[] = 'Responses metadata must select the Responses protocol';
}
$protocolForModel = new ReflectionMethod(MarketTextModelRuntimeService::class, 'protocolForModel');
$protocolForModel->setAccessible(true);
if ($protocolForModel->invoke(null, ['protocol' => 'openai_chat', 'channel_code' => 'anthropic', 'model_code' => 'sonnet']) !== 'anthropic_messages') {
    $failures[] = 'Anthropic channels must not use Chat Completions';
}

$payload = new ReflectionMethod(MarketTextModelRuntimeService::class, 'requestPayload');
$payload->setAccessible(true);
$model = ['model_code' => 'claude-sonnet-4'];
$messages = [['role' => 'user', 'content' => 'hello']];
$claude = $payload->invoke(null, 'anthropic_messages', $model, $messages, 'Be concise.', 1024, [
    'temperature' => 0.3,
    'top_p' => 0.8,
    'presence_penalty' => 1,
    'response_format' => ['type' => 'json_object'],
    'tools' => [['type' => 'function', 'function' => ['name' => 'lookup', 'description' => 'Lookup data', 'parameters' => ['type' => 'object']]]],
    'tool_choice' => 'auto',
]);
if (($claude['messages'] ?? null) !== $messages || ($claude['system'] ?? '') !== 'Be concise.' || (int)($claude['max_tokens'] ?? 0) !== 1024) {
    $failures[] = 'Claude must use Messages fields';
}
if (isset($claude['input'], $claude['response_format'], $claude['presence_penalty'], $claude['top_p'])) {
    $failures[] = 'Claude payload contains incompatible OpenAI fields';
}
if (($claude['tools'][0]['input_schema']['type'] ?? '') !== 'object' || ($claude['tool_choice']['type'] ?? '') !== 'auto') {
    $failures[] = 'Claude tool schema was not translated';
}

$responses = $payload->invoke(null, 'openai_responses', ['model_code' => 'gpt-5'], $messages, 'system', 2048, []);
if (($responses['input'] ?? null) !== $messages || ($responses['instructions'] ?? '') !== 'system' || isset($responses['messages'])) {
    $failures[] = 'Responses payload fields are incorrect';
}
$chat = $payload->invoke(null, 'openai_chat', ['model_code' => 'kimi-k3'], $messages, 'system', 2048, []);
if (($chat['messages'][0]['role'] ?? '') !== 'system' || ($chat['messages'][1]['content'] ?? '') !== 'hello') {
    $failures[] = 'Chat Completions payload fields are incorrect';
}

$parse = new ReflectionMethod(MarketTextModelRuntimeService::class, 'parseProviderPayload');
$parse->setAccessible(true);
$event = $parse->invoke(null, ['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => 'streamed']], 'content_block_delta', true);
if (($event['type'] ?? '') !== 'delta' || ($event['content'] ?? '') !== 'streamed') {
    $failures[] = 'Claude SSE text delta was not parsed';
}

echo json_encode(['passed' => $failures === [], 'failures' => $failures], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
exit($failures === [] ? 0 : 1);
