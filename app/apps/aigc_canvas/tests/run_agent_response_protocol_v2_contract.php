<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
require $root . '/vendor/autoload.php';

use app\common\service\app\aigc_canvas\agent\runtime\AgentResponseProtocol;
use app\common\service\app\aigc_canvas\AigcCanvasAgentRuntimeService;

$failures = [];

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$required = ['schema_version', 'kind', 'title', 'summary', 'blocks', 'actions', 'reply', 'content', 'quick_actions'];
$assertV2 = static function (array $response, string $kind) use ($assert, $required): void {
    foreach ($required as $key) $assert(array_key_exists($key, $response), $kind . ' is missing ' . $key);
    $assert(($response['schema_version'] ?? 0) === 2, $kind . ' schema version is not 2');
    $assert(is_array($response['blocks'] ?? null), $kind . ' blocks are not an array');
    $assert(is_array($response['actions'] ?? null), $kind . ' actions are not an array');
    $assert(array_key_exists('message', (array)($response['content'] ?? [])), $kind . ' legacy content.message is missing');
};

$onboarding = AgentResponseProtocol::onboarding([
    'agent_name' => '画布助手',
    'welcome_text' => '我可以帮助你完成图片和文案创作。',
    'capabilities' => ['生成图片', '整理文案'],
    'quick_prompts' => ['创建海报'],
]);
$assertV2($onboarding, 'onboarding');
$assert(($onboarding['kind'] ?? '') === 'onboarding', 'onboarding kind is incorrect');
$assert(($onboarding['blocks'][1]['type'] ?? '') === 'bullets', 'onboarding has no capability bullets');

$clarify = AgentResponseProtocol::fromResult([
    'next_action' => 'clarify',
    'reply' => '请补充图片的主体和比例。',
    'task_decision' => ['missing_hard_slots' => ['visual_subject', 'ratio']],
]);
$assertV2($clarify, 'clarify');
$assert(($clarify['kind'] ?? '') === 'clarify', 'clarify kind is incorrect');
$assert(($clarify['blocks'][1]['type'] ?? '') === 'fields', 'clarify has no fields block');
$assert(($clarify['blocks'][1]['items'][0]['label'] ?? '') === '主题/内容', 'clarify field label is not user-facing');

$implicitClarify = AgentResponseProtocol::fromResult([
    'next_action' => 'chat',
    'reply' => '你想生成什么主体或场景？',
    'task_decision' => ['missing_hard_slots' => ['visual_subject']],
]);
$assertV2($implicitClarify, 'implicit clarify');
$assert(($implicitClarify['kind'] ?? '') === 'clarify', 'missing required slots must render as clarify');
$assert(($implicitClarify['title'] ?? '') === '请补充创作信息', 'implicit clarify title is incorrect');

$clarificationMethod = new ReflectionMethod(AigcCanvasAgentRuntimeService::class, 'clarificationResult');
$clarificationMethod->setAccessible(true);
$runtimeClarify = $clarificationMethod->invoke(null, [
    'clarify_question' => '你想生成什么主体或场景？',
    'missing_slots' => ['subject'],
    'slot_state' => ['subject' => ['status' => 'missing']],
]);
$runtimeClarifyResponse = AgentResponseProtocol::fromResult($runtimeClarify);
$assert(($runtimeClarifyResponse['kind'] ?? '') === 'clarify', 'runtime clarify kind is incorrect');
$assert(($runtimeClarifyResponse['blocks'][1]['type'] ?? '') === 'fields', 'runtime clarify does not preserve fields');
$assert(($runtimeClarifyResponse['blocks'][1]['items'][0]['label'] ?? '') === '主题/内容', 'runtime clarify field label is incorrect');

$plan = AgentResponseProtocol::fromResult([
    'next_action' => 'confirm_plan',
    'reply' => '已整理为三步方案。',
    'batch_id' => 42,
    'planned_sections' => [['section_key' => 'hero', 'title' => '首屏视觉']],
]);
$assertV2($plan, 'plan');
$assert(($plan['kind'] ?? '') === 'plan', 'plan kind is incorrect');
$assert(($plan['blocks'][1]['type'] ?? '') === 'steps', 'plan has no steps block');
$assert(($plan['actions'][0]['type'] ?? '') === 'confirm_plan', 'plan confirmation action is missing');

$execution = AgentResponseProtocol::fromResult([
    'next_action' => 'subagents_pending',
    'reply' => '正在分配协作任务。',
    'subtasks' => [['role' => 'planner', 'status' => 'running']],
]);
$assertV2($execution, 'execution');
$assert(($execution['kind'] ?? '') === 'execution', 'execution kind is incorrect');
$assert(($execution['blocks'][1]['type'] ?? '') === 'task_progress', 'execution has no progress block');

$final = AgentResponseProtocol::fromResult(['next_action' => 'chat', 'reply' => '方案已完成。']);
$assertV2($final, 'final');
$assert(($final['kind'] ?? '') === 'final', 'final kind is incorrect');

$outOfScope = AgentResponseProtocol::fromResult(['next_action' => 'out_of_scope', 'reply' => '当前没有启用该能力。']);
$assertV2($outOfScope, 'out_of_scope');
$assert(($outOfScope['kind'] ?? '') === 'out_of_scope', 'out_of_scope kind is incorrect');
$assert(($outOfScope['blocks'][0]['type'] ?? '') === 'notice', 'out_of_scope has no notice');

$error = AgentResponseProtocol::fromResult(['next_action' => 'error', 'error' => '服务暂时不可用。']);
$assertV2($error, 'error');
$assert(($error['kind'] ?? '') === 'error', 'error kind is incorrect');
$assert(($error['blocks'][0]['type'] ?? '') === 'notice', 'error has no notice');
$assert(($error['actions'][0]['type'] ?? '') === 'retry', 'error retry action is missing');

foreach ([$onboarding, $clarify, $plan, $execution, $final, $outOfScope, $error] as $response) {
    foreach ((array)$response['blocks'] as $block) {
        $assert(in_array((string)($block['type'] ?? ''), ['paragraph', 'bullets', 'fields', 'steps', 'task_progress', 'notice'], true), 'unknown block type was emitted');
    }
}

echo json_encode(['passed' => $failures === [], 'failures' => $failures], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 1);
