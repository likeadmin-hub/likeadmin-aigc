<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
require $root . '/vendor/autoload.php';

use app\common\service\app\aigc_canvas\agent\runtime\AgentLoopService;

$failures = [];
$method = new ReflectionMethod(AgentLoopService::class, 'preflightEnrichmentDecision');
$method->setAccessible(true);
$references = [
    'selected_elements' => [[
        'id' => 'reference-image',
        'type' => 'image',
        'url' => 'https://cdn.example.com/reference.png',
    ]],
];

$ordinary = $method->invoke(null, '当前画布有多少个节点？', $references, []);
if (($ordinary['run'] ?? null) !== false || ($ordinary['reason'] ?? '') !== 'deferred_on_demand') {
    $failures[] = 'ordinary canvas questions must defer reference visual analysis';
}

$analysis = $method->invoke(null, '请分析这张参考图中的主体和构图', $references, []);
if (($analysis['run'] ?? null) !== true || ($analysis['reason'] ?? '') !== 'explicit_analysis_request') {
    $failures[] = 'explicit reference analysis must retain preflight visual understanding';
}

$policy = $method->invoke(null, '生成一张海报', $references, [
    'skill_contract' => ['enrichment_policy' => ['preflight_visual_understanding' => true]],
]);
if (($policy['run'] ?? null) !== true || ($policy['reason'] ?? '') !== 'skill_policy') {
    $failures[] = 'skill policy must be able to require preflight visual understanding';
}

$noReference = $method->invoke(null, '请分析当前画布', [], []);
if (($noReference['run'] ?? null) !== false || ($noReference['reason'] ?? '') !== 'no_reference_asset') {
    $failures[] = 'requests without reference assets must not enter visual enrichment';
}

$toolInput = new ReflectionMethod(AgentLoopService::class, 'toolInput');
$toolInput->setAccessible(true);
$assetAnalyze = $toolInput->invoke(null, 'asset_analyze', [], 'request-1', '请识别参考图内容', $references, 1, []);
if (empty($assetAnalyze['requires_visual_understanding'])
    || ($assetAnalyze['references'][0]['url'] ?? '') !== 'https://cdn.example.com/reference.png') {
    $failures[] = 'on-demand asset_analyze must receive selected references and request visual understanding';
}

echo json_encode([
    'passed' => $failures === [],
    'failures' => $failures,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;

exit($failures === [] ? 0 : 1);
