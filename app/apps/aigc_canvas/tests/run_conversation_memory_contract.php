<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
require $root . '/vendor/autoload.php';

use app\common\service\app\aigc_canvas\AigcCanvasAgentRuntimeService;
use app\common\service\app\aigc_canvas\agent\memory\ProjectMemoryService;
use app\common\service\app\aigc_canvas\agent\runtime\AgentLoopService;

$failures = [];
$reference = [
    'type' => 'image',
    'url' => 'https://cdn.example.com/reference.png',
    'name' => 'reference.png',
];

$runtimeMethod = new ReflectionMethod(AigcCanvasAgentRuntimeService::class, 'inheritThreadReferences');
$runtimeMethod->setAccessible(true);
$runtimeContext = $runtimeMethod->invoke(null, [], ['uploaded_references' => [$reference]]);
if (($runtimeContext['uploaded_references'][0]['url'] ?? '') !== $reference['url']) {
    $failures[] = 'task decision context did not inherit same-thread reference assets';
}

$loopMethod = new ReflectionMethod(AgentLoopService::class, 'inheritConversationReferences');
$loopMethod->setAccessible(true);
$loopContext = $loopMethod->invoke(null, [], ['uploaded_references' => [$reference]]);
if (($loopContext['uploaded_references'][0]['url'] ?? '') !== $reference['url']) {
    $failures[] = 'agent loop did not inherit same-thread reference assets';
}

$toolInputMethod = new ReflectionMethod(AgentLoopService::class, 'toolInput');
$toolInputMethod->setAccessible(true);
$canvasAssetInput = $toolInputMethod->invoke(null, 'asset_analyze', [], 'request-1', 'analyze selected asset', [
    'selected_elements' => [$reference],
], 68);
if (($canvasAssetInput['reference_scope'] ?? '') !== 'canvas') {
    $failures[] = 'selected canvas assets are not marked for project persistence';
}
$conversationAssetInput = $toolInputMethod->invoke(null, 'asset_analyze', [], 'request-2', 'analyze uploaded reference', [
    'uploaded_references' => [$reference],
    'selected_elements' => [$reference],
], 68);
if (($conversationAssetInput['reference_scope'] ?? '') !== 'conversation') {
    $failures[] = 'conversation uploads take precedence over selected canvas assets';
}

$runtimeSource = file_get_contents($root . '/app/common/service/app/aigc_canvas/AigcCanvasAgentRuntimeService.php') ?: '';
$loopSource = file_get_contents($root . '/app/common/service/app/aigc_canvas/agent/runtime/AgentLoopService.php') ?: '';
$projectMemorySource = file_get_contents($root . '/app/common/service/app/aigc_canvas/agent/memory/ProjectMemoryService.php') ?: '';
$assetExecutorSource = file_get_contents($root . '/app/common/service/app/aigc_canvas/agent/tools/CanvasAgentToolExecutor.php') ?: '';
if (str_contains($runtimeSource, "\$meta['context_isolated'] = true;")
    || !str_contains($runtimeSource, "\$params['project_memory_enabled'] = true;")) {
    $failures[] = 'new threads do not retain project memory by default';
}
if (!str_contains($loopSource, "'project_memory_enabled'")) {
    $failures[] = 'agent loop does not use the project memory availability flag';
}
if (!str_contains($loopSource, "empty(\$state['new_conversation'])")
    || !str_contains($projectMemorySource, "\$includeConversationGoals")) {
    $failures[] = 'new conversations do not exclude prior conversation goals from project memory';
}

$scopeMethod = new ReflectionMethod(ProjectMemoryService::class, 'isPersistentReferenceAsset');
$scopeMethod->setAccessible(true);
if ($scopeMethod->invoke(null, 'reference_asset', ['source_type' => 'visual_understanding'])) {
    $failures[] = 'legacy visual references without a canvas scope remain visible to new conversations';
}
if ($scopeMethod->invoke(null, 'reference_asset', ['scope' => 'conversation'])) {
    $failures[] = 'conversation-uploaded references can leak into project memory';
}
if (!$scopeMethod->invoke(null, 'reference_asset', ['scope' => 'canvas'])) {
    $failures[] = 'selected canvas assets are not retained as project memory';
}
if (!$scopeMethod->invoke(null, 'brand_fact', [])) {
    $failures[] = 'non-asset project memories should remain available';
}
if (!str_contains($assetExecutorSource, "reference_scope'] ?? '') !== 'canvas'")
    || !str_contains($projectMemorySource, 'isPersistentReferenceAsset')) {
    $failures[] = 'asset source scope is not enforced across the memory boundary';
}

echo json_encode([
    'passed' => $failures === [],
    'failures' => $failures,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;

exit($failures === [] ? 0 : 1);
