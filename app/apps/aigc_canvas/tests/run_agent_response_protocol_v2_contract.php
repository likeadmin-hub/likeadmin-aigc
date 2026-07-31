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
$assert(count((array)($clarify['blocks'] ?? [])) === 1, 'clarify must not expose runtime slot lists');
$assert(!str_contains((string)json_encode($clarify['blocks'] ?? [], JSON_UNESCAPED_UNICODE), 'visual_subject'), 'clarify leaked its internal slot key');

$productReferenceClarify = AgentResponseProtocol::fromResult([
    'next_action' => 'clarify',
    'reply' => 'Please provide a product reference.',
    'task_decision' => ['missing_hard_slots' => ['product_reference']],
]);
$assert(!str_contains((string)json_encode($productReferenceClarify['blocks'] ?? [], JSON_UNESCAPED_UNICODE), 'product_reference'), 'product reference slot leaked its internal field key');

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
$assert(count((array)($runtimeClarifyResponse['blocks'] ?? [])) === 1, 'runtime clarify exposed a slot list');

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

$internalTrace = AgentResponseProtocol::fromResult([
    'next_action' => 'chat',
    'reply' => "分析：根据 selected_skill_contract 与 task_decision，allowed_tools 允许 generate_image。\nproject_memory: ...",
    'task_decision' => ['intent' => 'text_generation'],
]);
$internalTraceText = (string)($internalTrace['reply'] ?? '') . json_encode($internalTrace['blocks'] ?? []);
$assert(!str_contains($internalTraceText, 'selected_skill_contract'), 'internal skill contract leaked into the response');
$assert(!str_contains($internalTraceText, 'allowed_tools'), 'internal tool permission leaked into the response');
$assert(!str_contains($internalTraceText, 'project_memory'), 'internal memory trace leaked into the response');
$assert(str_contains((string)($internalTrace['reply'] ?? ''), '没有得到可用的文本结果'), 'internal text trace did not use the safe text fallback');

$routingTrace = AgentResponseProtocol::fromResult([
    'next_action' => 'chat',
    'reply' => "selected_skill_contract: general_image\nallowed_tools: [generate_image]\nproject_memory: script request",
    'task_decision' => ['intent' => 'text_generation'],
]);
$routingTraceText = (string)($routingTrace['reply'] ?? '') . json_encode($routingTrace['blocks'] ?? []);
$assert(!str_contains($routingTraceText, 'general_image'), 'internal routing decision leaked into the response');
$assert(!str_contains($routingTraceText, 'generate_image'), 'internal tool name leaked into the response');

$singleFieldJsonTrace = AgentResponseProtocol::fromResult([
    'next_action' => 'chat',
    'reply' => '{"selected_skill_contract":"general_image"}',
    'task_decision' => ['intent' => 'text_generation'],
]);
$singleFieldJsonTraceText = (string)($singleFieldJsonTrace['reply'] ?? '') . json_encode($singleFieldJsonTrace['blocks'] ?? []);
$assert(!str_contains($singleFieldJsonTraceText, 'selected_skill_contract'), 'single-field internal JSON leaked into the response');

$streamDrain = new ReflectionMethod(\app\common\service\app\aigc_canvas\agent\runtime\AgentLoopService::class, 'drainSafeAssistantStream');
$streamDrain->setAccessible(true);
$visiblePending = 'A visible response is ready.';
$visibleBlocked = false;
$visibleDelta = $streamDrain->invokeArgs(null, [&$visiblePending, &$visibleBlocked]);
$assert($visibleDelta === 'A visible response is ready.' && !$visibleBlocked && $visiblePending === '', 'safe prose was not released as a stream segment');
$jsonPending = '{"summary":"A visible response is ready."}';
$jsonBlocked = false;
$jsonDelta = $streamDrain->invokeArgs(null, [&$jsonPending, &$jsonBlocked]);
$assert($jsonDelta === '' && $jsonBlocked && $jsonPending === '', 'structured model output leaked into a stream segment');
$tracePending = 'task_decision: generate_image.';
$traceBlocked = false;
$traceDelta = $streamDrain->invokeArgs(null, [&$tracePending, &$traceBlocked]);
$assert($traceDelta === '' && $traceBlocked && $tracePending === '', 'internal stream trace leaked into a stream segment');

$restoredTrace = AigcCanvasAgentRuntimeService::formatMessage([
    'id' => 0,
    'role' => 'assistant',
    'content' => "分析：selected_skill_contract: general_image\nallowed_tools: [generate_image]\nproject_memory: script request",
    'content_json' => [],
]);
$assert(($restoredTrace['content'] ?? '') === '', 'historical internal trace was returned to the chat UI');

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

$workflowPresentation = AgentResponseProtocol::fromResult([
    'next_action' => 'chat',
    'reply' => 'Actual strategy result',
    'delivery_item' => [
        'id' => 73, 'objective' => 'Brand strategy', 'status' => 'completed',
        'meta' => ['workflow_stage' => 'strategy'],
        'result' => ['research' => ['basis' => 'known_information', 'summary' => 'Actual strategy result']],
    ],
]);
$workflowTypes = array_column((array)$workflowPresentation['blocks'], 'type');
$assert(in_array('task_status', $workflowTypes, true), 'delivery item status is not projected to presentation');
$assert(in_array('research_summary', $workflowTypes, true), 'known-information strategy summary is not projected');
$assert(!in_array('source_list', $workflowTypes, true), 'known-information strategy fabricated a source list');

$sourcedPresentation = AgentResponseProtocol::fromResult([
    'next_action' => 'chat',
    'reply' => 'Actual research result',
    'delivery_item' => [
        'id' => 74, 'objective' => 'Research', 'status' => 'completed',
        'meta' => ['workflow_stage' => 'research'],
        'result' => ['research' => ['basis' => 'tool_results', 'summary' => 'Actual research result', 'sources' => [
            ['title' => 'Primary source', 'url' => 'https://example.test/source'],
        ]]],
    ],
]);
$sourcedTypes = array_column((array)$sourcedPresentation['blocks'], 'type');
$assert(in_array('source_list', $sourcedTypes, true), 'actual research source was not projected');

$scriptPresentation = AgentResponseProtocol::fromResult([
    'next_action' => 'chat',
    'response_kind' => 'execution_status',
    'reply' => "**Theme:** Rooftop duel\n**Duration:** 5 seconds\n\n| Shot | Duration | Visual | Sound |\n| --- | --- | --- | --- |\n| 1 | 0-2s | Two rivals face off<br>in the rain | Thunder |\n| 2 | 2-5s | The final strike | Impact |\n\n### Direction\n1. Start with a still frame\n2. Cut on the strike",
    'workspace_actions' => [['action_type' => 'insert_text']],
]);
$scriptTypes = array_column((array)$scriptPresentation['blocks'], 'type');
$assert(in_array('key_value', $scriptTypes, true), 'document fields were not structured');
$assert(in_array('comparison_table', $scriptTypes, true), 'markdown table was not structured');
$assert(in_array('heading', $scriptTypes, true), 'markdown heading was not structured');
$assert(in_array('numbered_list', $scriptTypes, true), 'ordered document list was not structured');
$scriptTable = array_values(array_filter((array)$scriptPresentation['blocks'], static fn(array $block): bool => ($block['type'] ?? '') === 'comparison_table'));
$assert(($scriptTable[0]['rows'][0][2] ?? '') === "Two rivals face off\nin the rain", 'table cell line break split a storyboard row');

$markdownPresentation = AgentResponseProtocol::fromResult([
    'next_action' => 'chat',
    'reply' => "Overview\n*\n1. **Writing and planning**\n- **Draft** marketing copy\n- Build an outline",
]);
$markdownBlocks = (array)($markdownPresentation['blocks'] ?? []);
$markdownJson = (string)json_encode($markdownBlocks, JSON_UNESCAPED_UNICODE);
$assert(in_array('heading', array_column($markdownBlocks, 'type'), true), 'standalone Markdown heading was not projected as a heading');
$assert(!str_contains($markdownJson, '"*"'), 'bare Markdown separator leaked into presentation');
$assert(!str_contains($markdownJson, '**Draft**'), 'Markdown emphasis leaked into list item');

$internalFieldReply = AgentResponseProtocol::fromResult([
    'next_action' => 'chat',
    'reply' => 'provider_task_id: 314159',
]);
$internalFieldText = (string)($internalFieldReply['reply'] ?? '') . json_encode($internalFieldReply['blocks'] ?? []);
$assert(!str_contains($internalFieldText, '314159'), 'single provider task id leaked into the response');

$mediaSubmission = AgentResponseProtocol::fromResult([
    'next_action' => 'generation_submitted',
    'reply' => 'Masterpiece, best quality, ultra-realistic, 8k resolution, photorealistic. This compiled provider prompt must not be visible in chat.',
    'original_user_request' => 'Create a product poster with a clean editorial composition.',
    'tool_calls' => [['tool_code' => 'generate_image', 'status' => 'running', 'output' => ['task_id' => 123]]],
]);
$mediaSubmissionText = (string)($mediaSubmission['reply'] ?? '') . json_encode($mediaSubmission['blocks'] ?? []);
$assert(!str_contains($mediaSubmissionText, 'Masterpiece'), 'compiled media prompt leaked into the response');
$assert(!str_contains($mediaSubmissionText, '123'), 'media task id leaked into the response');
$assert(!str_contains($mediaSubmissionText, "\u{521b}\u{4f5c}\u{65b9}\u{5411}"), 'media submission retained a fixed creative-direction field');
$assert(!str_contains($mediaSubmissionText, "\u{5904}\u{7406}\u{8bf4}\u{660e}"), 'media submission retained a fixed process field');
$assert(($mediaSubmission['blocks'][0]['type'] ?? '') === 'paragraph', 'media submission did not use a document paragraph');

foreach ([$onboarding, $clarify, $plan, $execution, $final, $outOfScope, $error, $evidence] as $response) {
    foreach ((array)$response['blocks'] as $block) {
        $assert(in_array((string)($block['type'] ?? ''), ['paragraph', 'bullets', 'steps', 'heading', 'numbered_list', 'key_value', 'comparison_table'], true), 'unknown or card block type was emitted');
    }
}

echo json_encode(['passed' => $failures === [], 'failures' => $failures], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 1);
