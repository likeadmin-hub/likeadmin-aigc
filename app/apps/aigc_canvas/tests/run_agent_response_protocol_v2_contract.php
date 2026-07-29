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
    'welcome_text' => "你好，告诉我你想创作什么。\n- 生成图片\n- 整理文案",
    'capabilities' => ['生成图片', '整理文案'],
    'quick_prompts' => ['创建海报', '整理画布', '制作视频', '撰写文案'],
]);
$assertV2($onboarding, 'onboarding');
$assert(($onboarding['kind'] ?? '') === 'onboarding', 'onboarding kind is incorrect');
$assert(($onboarding['title'] ?? '') === '', 'onboarding must begin with a direct reply, not a status heading');
$assert(count((array)($onboarding['blocks'] ?? [])) === 1, 'onboarding must not auto-list capabilities');
$assert(($onboarding['blocks'][0]['text'] ?? '') === '你好，告诉我你想创作什么。', 'onboarding must keep only a concise direct welcome');
$assert(count((array)($onboarding['actions'] ?? [])) === 3, 'onboarding must expose at most three example prompts');

$clarify = AgentResponseProtocol::fromResult([
    'next_action' => 'clarify',
    'reply' => '请补充图片的主体和比例。',
    'task_decision' => ['missing_hard_slots' => ['visual_subject', 'ratio']],
]);
$assertV2($clarify, 'clarify');
$assert(($clarify['kind'] ?? '') === 'clarify', 'clarify kind is incorrect');
$assert(($clarify['title'] ?? '') === '先确认这几项', 'clarify title is not action-oriented');
$assert(($clarify['summary'] ?? '') === '', 'clarify must not repeat its next action in a summary');
$assert(($clarify['blocks'][1]['type'] ?? '') === 'bullets', 'clarify must use a compact list');
$assert(($clarify['blocks'][1]['items'][0]['label'] ?? '') === '主题/内容', 'clarify label is not user-facing');

$implicitClarify = AgentResponseProtocol::fromResult([
    'next_action' => 'chat',
    'reply' => '你想生成什么主体或场景？',
    'task_decision' => ['missing_hard_slots' => ['visual_subject']],
]);
$assertV2($implicitClarify, 'implicit clarify');
$assert(($implicitClarify['kind'] ?? '') === 'clarify', 'missing required slots must render as clarify');
$assert(($implicitClarify['title'] ?? '') === '先确认这几项', 'implicit clarify title is incorrect');

$clarificationMethod = new ReflectionMethod(AigcCanvasAgentRuntimeService::class, 'clarificationResult');
$clarificationMethod->setAccessible(true);
$runtimeClarify = $clarificationMethod->invoke(null, [
    'clarify_question' => '你想生成什么主体或场景？',
    'missing_slots' => ['subject'],
    'slot_state' => ['subject' => ['status' => 'missing']],
]);
$runtimeClarifyResponse = AgentResponseProtocol::fromResult($runtimeClarify);
$assert(($runtimeClarifyResponse['kind'] ?? '') === 'clarify', 'runtime clarify kind is incorrect');
$assert(($runtimeClarifyResponse['blocks'][1]['type'] ?? '') === 'bullets', 'runtime clarify does not preserve a compact slot list');
$assert(($runtimeClarifyResponse['blocks'][1]['items'][0]['label'] ?? '') === '主题/内容', 'runtime clarify label is incorrect');

$failureMethod = new ReflectionMethod(AigcCanvasAgentRuntimeService::class, 'failureResult');
$failureMethod->setAccessible(true);
$runtimeFailure = $failureMethod->invoke(null, 'provider diagnostic must not reach the user');
$runtimeFailureResponse = AgentResponseProtocol::fromResult($runtimeFailure);
$assert(($runtimeFailureResponse['kind'] ?? '') === 'error', 'runtime failure kind is incorrect');
$assert(($runtimeFailureResponse['actions'][0]['type'] ?? '') === 'retry', 'runtime failure has no retry action');
$assert(!str_contains((string)($runtimeFailure['reply'] ?? ''), 'provider diagnostic'), 'runtime failure exposed a diagnostic');

$plan = AgentResponseProtocol::fromResult([
    'next_action' => 'confirm_plan',
    'reply' => '已整理为三步方案。',
    'batch_id' => 42,
    'planned_sections' => [['section_key' => 'hero', 'title' => '首屏视觉']],
]);
$assertV2($plan, 'plan');
$assert(($plan['kind'] ?? '') === 'plan', 'plan kind is incorrect');
$assert(($plan['title'] ?? '') === '建议这样做', 'plan title is not decision-oriented');
$assert(($plan['summary'] ?? '') === '', 'plan must not repeat the confirmation instruction');
$assert(($plan['blocks'][1]['type'] ?? '') === 'steps', 'plan has no steps block');
$assert(($plan['actions'][0]['type'] ?? '') === 'confirm_plan', 'plan confirmation action is missing');
$assert(($plan['actions'][0]['label'] ?? '') === '开始生成', 'plan confirmation action is not user-facing');

$execution = AgentResponseProtocol::fromResult([
    'next_action' => 'subagents_pending',
    'reply' => '正在分配协作任务。',
    'subtasks' => [['role' => 'planner', 'status' => 'running']],
]);
$assertV2($execution, 'execution');
$assert(($execution['kind'] ?? '') === 'execution', 'execution kind is incorrect');
$assert(($execution['title'] ?? '') === '', 'execution must not show a chat status title');
$assert(($execution['summary'] ?? '') === '', 'execution must not show a chat status summary');
$assert(count((array)($execution['blocks'] ?? [])) === 0, 'execution must not render status text or a progress card');

$final = AgentResponseProtocol::fromResult([
    'next_action' => 'chat',
    'title' => '已完成',
    'reply' => "可以按这个方向继续。\n\n- 保留主视觉\n- 调整文案层级",
]);
$assertV2($final, 'final');
$assert(($final['kind'] ?? '') === 'final', 'final kind is incorrect');
$assert(($final['title'] ?? '') === '', 'ordinary final responses must not show a generic completion title');
$assert(($final['blocks'][0]['type'] ?? '') === 'paragraph', 'final does not preserve its opening paragraph');
$assert(($final['blocks'][1]['type'] ?? '') === 'bullets', 'final does not render Markdown list items as a document list');

$outOfScope = AgentResponseProtocol::fromResult(['next_action' => 'out_of_scope', 'reply' => '当前没有启用该能力。']);
$assertV2($outOfScope, 'out_of_scope');
$assert(($outOfScope['kind'] ?? '') === 'out_of_scope', 'out_of_scope kind is incorrect');
$assert(($outOfScope['title'] ?? '') === '', 'out_of_scope must not show a status heading');
$assert(($outOfScope['blocks'][0]['type'] ?? '') === 'paragraph', 'out_of_scope must use document flow instead of a card');

$error = AgentResponseProtocol::fromResult(['next_action' => 'error', 'error' => '服务暂时不可用。']);
$assertV2($error, 'error');
$assert(($error['kind'] ?? '') === 'error', 'error kind is incorrect');
$assert(($error['title'] ?? '') === '', 'error must not show a status heading');
$assert(($error['blocks'][0]['type'] ?? '') === 'paragraph', 'error must use document flow instead of a card');
$assert(($error['actions'][0]['type'] ?? '') === 'retry', 'error retry action is missing');

$evidence = AgentResponseProtocol::fromResult([
    'next_action' => 'chat',
    'reply' => '我会围绕这些信息继续创作。',
    'creative_brief' => ['copy_plan' => ['claims' => ['突出蓝色按钮']]],
], ['visible_facts' => ['蓝色控制按钮']]);
$assertV2($evidence, 'evidence');
$assert(($evidence['kind'] ?? '') === 'final', 'evidence presentation kind is incorrect');
$assert(($evidence['response_kind'] ?? '') === 'evidence_review', 'evidence response kind is incorrect');
$assert(($evidence['title'] ?? '') === '', 'evidence must begin with its direct reply');
$assert(($evidence['blocks'][1]['title'] ?? '') === '已确认', 'evidence facts do not have a concise section label');
$assert(($evidence['blocks'][2]['title'] ?? '') === '可用于创作', 'evidence claims do not have a concise section label');

foreach ([$onboarding, $clarify, $plan, $execution, $final, $outOfScope, $error, $evidence] as $response) {
    foreach ((array)$response['blocks'] as $block) {
        $assert(in_array((string)($block['type'] ?? ''), ['paragraph', 'bullets', 'steps'], true), 'unknown or card block type was emitted');
    }
}

echo json_encode(['passed' => $failures === [], 'failures' => $failures], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 1);
