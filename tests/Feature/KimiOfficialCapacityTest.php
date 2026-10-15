<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use app\common\service\power\TextModelCapacity as C;
use app\common\service\power\MarketTextModelRuntimeService as R;
use app\common\service\app\aigc_llm\agent\MarketNeuronProvider as P;
use app\common\service\app\aigc_llm\agent\AgentContextCapacityExceeded;

function same($expected, $actual, string $message): void {
    if ($expected !== $actual) throw new RuntimeException($message);
}
$model = ['model_code' => 'kimi-k3', 'context_window' => 0, 'max_tokens' => 0];
same(['context_window'=>1048576, 'max_input_tokens'=>0, 'max_output_tokens'=>1048576], C::metadata($model), 'Official fallback');
same(131072, C::output($model, 0), 'Official default must bypass legacy 32K ceiling');
same(917504, P::inputBudget($model), 'Reserve official output from shared context');
same(4096, C::output($model, 4096), 'Explicit application output retained');
same(8192, C::output($model + ['default_params'=>['max_tokens'=>8192]], 0), 'Configured output retained');
same(16384, C::output($model + ['default_params'=>['max_completion_tokens'=>16384]], 0), 'Official output alias');
same(20000, C::output($model + ['default_params'=>['max_output_tokens'=>20000]], 0), 'Responses output alias');
$channel = array_replace($model, ['context_window'=>65536, 'max_input_tokens'=>30000, 'max_output_tokens'=>8192]);
same(8192, C::output($channel, 0), 'Channel output limit wins');
same(30000, P::inputBudget($channel), 'Channel input limit wins');
same(8192, C::output($channel, 131072), 'Explicit output cannot exceed channel limit');
same(128, C::output(array_replace($model, ['max_output_tokens'=>128]), 0), 'Do not raise small channel limits');
same(1048575, C::output($model, 2000000), 'Output must leave room for input');
same(8192, C::metadata($model + ['capabilities'=>['max_output_tokens'=>8192]])['max_output_tokens'], 'Nested channel metadata wins');
same(64000, C::metadata($model + ['context_length'=>64000])['context_window'], 'Channel context alias wins');
foreach (['kimi-k2.6', 'custom-kimi-k3', 'kimi-k3-custom', 'other'] as $code) {
    same(24000, P::inputBudget(['model_code'=>$code]), 'No guessed defaults for other IDs');
    same(8192, C::output(['model_code'=>$code], 0), 'Other model default changed');
    same(32768, C::output(['model_code'=>$code, 'max_tokens'=>65536], 65536), 'Other model ceiling changed');
}
$rows = [['role'=>'user','content'=>'必须保留首条约束。'.str_repeat('word ',30000).'最后更正：不得对外发布。']];
same($rows, P::fitContext($rows, 'system', [], P::inputBudget($model)), 'Long input changed');
$calls = 0;
$provider = new P('kimi-k3', function () use (&$calls) { $calls++; return ['content'=>'unexpected']; }, null, 100);
try {
    $provider->chat(new NeuronAI\Chat\Messages\UserMessage($rows[0]['content']));
    throw new RuntimeException('Expected overflow rejection');
} catch (AgentContextCapacityExceeded $e) {}
same(0, $calls, 'Overflow must never reach paid transport');
$resolve = new ReflectionMethod(R::class, 'resolveMaxTokens'); $resolve->setAccessible(true);
$payload = new ReflectionMethod(R::class, 'requestPayload'); $payload->setAccessible(true);
$params = new ReflectionMethod(R::class, 'generationParams'); $params->setAccessible(true);
$defaults = $params->invoke(null, $model + ['default_params'=>['max_completion_tokens'=>16384, 'max_output_tokens'=>20000]], []);
same(false, isset($defaults['max_completion_tokens']) || isset($defaults['max_output_tokens']), 'Defaults must not override resolved output in payload');
$max = $resolve->invoke(null, $model, []);
foreach (['openai_chat'=>'max_tokens', 'openai_responses'=>'max_output_tokens', 'anthropic_messages'=>'max_tokens'] as $protocol=>$field) {
    $body = $payload->invoke(null, $protocol, $model, [['role'=>'user','content'=>'offline']], 'rules', $max, $defaults);
    same(131072, $body[$field], 'Resolved official output lost in '.$protocol);
}
echo "PASS K3 official defaults, explicit/channel limits, protocol payloads, unchanged other models, full context and zero-call overflow\n";
