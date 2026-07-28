<?php

declare(strict_types=1);

use app\common\service\app\aigc_canvas\agent\runtime\AgentLoopService;

$root = dirname(__DIR__, 4);
require $root . '/vendor/autoload.php';

$method = new ReflectionMethod(AgentLoopService::class, 'explicitDelegationTasks');
$method->setAccessible(true);
$request = '请把任务分配给 3 位协作助手并行完成：品牌定位、主视觉文案和短视频脚本。不要生成媒体，不写入画布，直接开始。';
$tasks = $method->invoke(null, $request);
$ordinary = $method->invoke(null, '请写一段产品文案。');
$overrideMethod = new ReflectionMethod(AgentLoopService::class, 'isExplicitTextOnlyDelegation');
$overrideMethod->setAccessible(true);
$textOnlyOverride = $overrideMethod->invoke(null, $request, $tasks);
$mediaOnlyRequest = '请把任务分配给 3 位协作助手并行完成：品牌定位、主视觉文案和短视频脚本。不要生成媒体。';
$mediaOnlyTasks = $method->invoke(null, $mediaOnlyRequest);
$mediaOnlyOverride = $overrideMethod->invoke(null, $mediaOnlyRequest, $mediaOnlyTasks);
$codes = array_column($tasks, 'agent_code');
$failures = [];

if ($codes !== ['planner', 'copy', 'visual']) {
    $failures[] = 'explicit collaborator request is not mapped to durable delegate roles';
}
if ($ordinary !== []) {
    $failures[] = 'ordinary single-part request should not be delegated';
}
if (!$textOnlyOverride) {
    $failures[] = 'explicit text-only collaboration request must override a media skill contract';
}
if ($mediaOnlyOverride) {
    $failures[] = 'delegation override must require both media and canvas-write prohibitions';
}

echo json_encode([
    'passed' => $failures === [],
    'checked' => ['explicit_parallel_delegation' => true, 'ordinary_request_guard' => true, 'text_only_override_guard' => true],
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failures === [] ? 0 : 1);
