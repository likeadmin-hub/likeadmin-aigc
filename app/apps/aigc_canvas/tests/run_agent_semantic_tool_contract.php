<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
require $root . '/vendor/autoload.php';

use app\common\service\app\aigc_canvas\agent\runtime\AgentTaskDecisionService;
use app\common\service\app\aigc_canvas\agent\runtime\AgentToolInvocationPolicy;

$failures = [];

$normal = AgentTaskDecisionService::decide(0, '画一张小博美犬的图片', [], [], []);
if (($normal['router_source'] ?? '') !== 'primary_model'
    || ($normal['runtime_allowed_tools'] ?? null) !== []
    || ($normal['binding_mode'] ?? '') !== 'none') {
    $failures[] = 'normal turn is still pre-routed or tool-restricted';
}

$single = AgentToolInvocationPolicy::evaluate('generate_image', ['quantity' => 1], [], [], false);
if (empty($single['allowed']) || !empty($single['requires_confirmation'])) {
    $failures[] = 'single media proposal incorrectly requires confirmation';
}

$batch = AgentToolInvocationPolicy::evaluate('generate_image', ['quantity' => '3'], [], [], false);
if (empty($batch['allowed']) || empty($batch['requires_confirmation'])
    || ($batch['reason'] ?? '') !== 'batch_generation'
    || (int)($batch['normalized_input']['quantity'] ?? 0) !== 3) {
    $failures[] = 'batch media proposal is not gated from its actual arguments';
}

$delete = AgentToolInvocationPolicy::evaluate('canvas_mutation', ['operation' => 'delete'], [], [], false);
if (empty($delete['requires_confirmation']) || ($delete['reason'] ?? '') !== 'destructive_canvas_mutation') {
    $failures[] = 'destructive canvas proposal is not gated';
}

$resumed = AgentTaskDecisionService::decide(0, '确认', [], [], [
    'execution_confirmation' => true,
    'agent_decision_context' => [
        'original_request' => '生成三张小博美犬图片',
        'tool_proposal' => [
            'tool_code' => 'generate_image',
            'arguments' => ['quantity' => 3, 'prompt' => '小博美犬'],
        ],
    ],
]);
if (($resumed['decision_mode'] ?? '') !== 'execute'
    || ($resumed['confirmed_tool_proposal']['tool_code'] ?? '') !== 'generate_image'
    || (int)($resumed['confirmed_tool_proposal']['arguments']['quantity'] ?? 0) !== 3) {
    $failures[] = 'confirmation does not reuse the original tool proposal';
}

$loop = file_get_contents($root . '/app/common/service/app/aigc_canvas/agent/runtime/AgentLoopService.php') ?: '';
if (!str_contains($loop, 'AgentToolInvocationPolicy::evaluate')
    || !str_contains($loop, 'toolConfirmationResult')
    || !str_contains($loop, 'executeApprovedToolProposal')) {
    $failures[] = 'tool proposal policy is not wired into the Agent loop';
}
if (str_contains($loop, '$revisionPlan = self::fallbackMediaRevisionDelivery')
    || str_contains($loop, '$immediateMedia =')) {
    $failures[] = 'legacy direct media execution branch remains active';
}
if (str_contains($loop, 'create_short_drama_plan')) {
    $failures[] = 'canvas agent still exposes a cross-app short-drama tool';
}
$toolExecutor = file_get_contents($root . '/app/common/service/app/aigc_canvas/agent/tools/CanvasAgentToolExecutor.php') ?: '';
$skillCatalog = file_get_contents($root . '/app/common/service/app/aigc_canvas/AigcCanvasSkillService.php') ?: '';
$toolRegistry = file_get_contents($root . '/app/common/service/app/aigc_canvas/agent/tools/CanvasAgentToolRegistryService.php') ?: '';
if (str_contains($toolExecutor, 'create_short_drama_plan')
    || str_contains($toolExecutor, 'aigc_short_drama')
    || str_contains($skillCatalog, "['ask_user', 'generate_text', 'create_short_drama_plan']")
    || str_contains($skillCatalog, "'terminal_tools' => ['create_short_drama_plan']")
    || str_contains($toolRegistry, "'create_short_drama_plan'")
    || is_file($root . '/app/common/service/app/aigc_canvas/agent/drama/CanvasShortDramaWorkflowService.php')) {
    $failures[] = 'canvas agent still contains the retired short-drama bridge';
}

echo json_encode([
    'passed' => $failures === [],
    'failures' => $failures,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;

exit($failures === [] ? 0 : 1);
