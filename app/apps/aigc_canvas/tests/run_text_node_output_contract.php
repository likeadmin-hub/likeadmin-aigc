<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
require $root . '/vendor/autoload.php';

use app\common\service\app\aigc_canvas\AigcCanvasService;

$failures = [];
$resolve = new ReflectionMethod(AigcCanvasService::class, 'resolveTextSystemPrompt');
$resolve->setAccessible(true);

$finalOnly = (string)$resolve->invoke(null, ['output_contract' => 'final_only']);
if (!str_contains($finalOnly, '只输出一份可直接使用的最终结果')) {
    $failures[] = 'final-only text contract was not applied';
}

$custom = (string)$resolve->invoke(null, [
    'output_contract' => 'final_only',
    'system_prompt' => '保留产品名称和规格'
]);
if (!str_contains($custom, '保留产品名称和规格') || !str_contains($custom, '只输出一份可直接使用的最终结果')) {
    $failures[] = 'custom text instruction was not composed with the final-only contract';
}

$ordinary = (string)$resolve->invoke(null, ['system_prompt' => '普通文本输出']);
if ($ordinary !== '普通文本输出') {
    $failures[] = 'ordinary text request was unexpectedly changed';
}

echo json_encode(['passed' => $failures === [], 'failures' => $failures], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
exit($failures === [] ? 0 : 1);
