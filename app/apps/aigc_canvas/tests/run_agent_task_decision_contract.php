<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
require $root . '/vendor/autoload.php';

use app\common\service\app\aigc_canvas\agent\runtime\AgentTaskDecisionService;

$failures = [];
$class = new ReflectionClass(AgentTaskDecisionService::class);

$none = $class->getMethod('noneDecision');
$none->setAccessible(true);
$answer = $none->invoke(null, true, [], 0, 'Explain the difference between a logo and a poster.', []);
if (($answer['decision_mode'] ?? '') !== 'answer' || ($answer['runtime_allowed_tools'] ?? null) !== []) {
    $failures[] = 'ordinary advice is not a tool-free answer decision';
}

$decorate = $class->getMethod('decorate');
$decorate->setAccessible(true);
$modes = [
    'clarify' => ['action_mode' => 'clarify', 'next_action' => 'clarify', 'execution_mode' => 'clarify'],
    'plan' => ['action_mode' => 'plan', 'next_action' => 'reply', 'execution_mode' => 'plan'],
    'execute' => ['action_mode' => 'execute', 'next_action' => 'reply', 'execution_mode' => 'execute'],
    'confirm' => [
        'action_mode' => 'confirm',
        'next_action' => 'confirm_execution',
        'execution_mode' => 'plan',
        'requires_confirmation' => true,
        'skill_contract' => ['execution_confirmed' => false],
    ],
];
foreach ($modes as $expected => $seed) {
    $decision = $decorate->invoke(null, array_merge([
        'intent' => 'chat',
        'delivery_item_id' => 0,
        'requires_confirmation' => false,
        'skill_contract' => [],
    ], $seed), 'test request', [], []);
    if (($decision['decision_mode'] ?? '') !== $expected) {
        $failures[] = "decision mode {$expected} is not normalized";
    }
}

$requiresConfirmation = $class->getMethod('requiresConfirmation');
$requiresConfirmation->setAccessible(true);
$upgradeReasons = $class->getMethod('upgradeReasons');
$upgradeReasons->setAccessible(true);
$chineseBatchUpgrades = $upgradeReasons->invoke(null, '生成三张可爱的小博美犬图片，分别是正面、侧面和奔跑姿势。', [], [
    'binding_policy' => ['upgrade_to_contract_on' => ['batch']],
    'capability_tools' => ['costly' => ['generate_image']],
]);
if (!in_array('batch', $chineseBatchUpgrades, true)) {
    $failures[] = 'Chinese multi-image request does not enter the confirmation decision';
}
if ($requiresConfirmation->invoke(null, 'generation', ['paid_generation'], ['capability_tools' => ['costly' => ['generate_image']]], 'Create a dog image', [])) {
    $failures[] = 'concrete media generation incorrectly requires confirmation';
}
if (!$requiresConfirmation->invoke(null, 'generation', ['batch'], ['capability_tools' => ['costly' => ['generate_image']]], 'Create three dog images', [])) {
    $failures[] = 'batch media generation does not require confirmation';
}
if (!$requiresConfirmation->invoke(null, 'canvas_edit', ['canvas_write', 'destructive_canvas_write'], [], 'Delete the selected node', [])) {
    $failures[] = 'destructive canvas mutation does not require confirmation';
}
if ($requiresConfirmation->invoke(null, 'text_generation', [], ['allowed_tools' => ['generate_text']], 'Draft a title', [])) {
    $failures[] = 'text generation incorrectly requires confirmation';
}

$actionMode = $class->getMethod('actionMode');
$actionMode->setAccessible(true);
if ($actionMode->invoke(null, 'generation', 'costly', ['prompt_or_brief'], true, false) !== 'clarify') {
    $failures[] = 'missing required input does not take precedence over confirmation';
}

$runtimeSource = file_get_contents($root . '/app/common/service/app/aigc_canvas/AigcCanvasAgentRuntimeService.php') ?: '';
if (str_contains($runtimeSource, "['legacy_router', 'legacy_skill']")) {
    $failures[] = 'request parameters can still bypass the sole Agent Loop execution path';
}

echo json_encode([
    'passed' => $failures === [],
    'failures' => $failures,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;

exit($failures === [] ? 0 : 1);
