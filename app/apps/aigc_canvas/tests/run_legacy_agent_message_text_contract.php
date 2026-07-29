<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
require $root . '/vendor/autoload.php';

use app\common\service\app\aigc_canvas\AigcCanvasAgentRuntimeService;

$failures = [];
$original = "\u{4E00}\u{53EA}\u{6BDB}\u{8338}\u{8338}\u{7684}\u{767D}\u{732B}.";
$legacy = mb_convert_encoding($original, 'UTF-8', 'GB18030');
$expectedPrefix = mb_substr($original, 0, -2, 'UTF-8');

$thread = AigcCanvasAgentRuntimeService::formatThread([
    'id' => 1,
    'title' => $legacy,
    'summary' => $legacy,
    'meta_json' => ['label' => $legacy],
]);
foreach (['title', 'summary'] as $field) {
    if (!str_starts_with((string)($thread[$field] ?? ''), $expectedPrefix)) {
        $failures[] = 'thread ' . $field . ' was not repaired';
    }
}
if (!str_starts_with((string)($thread['meta']['label'] ?? ''), $expectedPrefix)) {
    $failures[] = 'thread metadata was not repaired';
}

$message = AigcCanvasAgentRuntimeService::formatMessage([
    'id' => 0,
    'content' => $legacy,
    'content_json' => ['response' => ['reply' => $legacy]],
    'error' => $legacy,
]);
foreach ([(string)($message['content'] ?? ''), (string)($message['error'] ?? ''), (string)($message['content_json']['response']['reply'] ?? '')] as $value) {
    if (!str_starts_with($value, $expectedPrefix)) {
        $failures[] = 'agent message payload was not repaired';
        break;
    }
}

$runtimeSource = file_get_contents($root . '/app/common/service/app/aigc_canvas/AigcCanvasAgentRuntimeService.php') ?: '';
if (!str_contains($runtimeSource, "'output' => AigcCanvasService::repairLegacyCanvasText(")
    || !str_contains($runtimeSource, "'error' => (string)AigcCanvasService::repairLegacyCanvasText")) {
    $failures[] = 'recovery run output is not covered by text repair';
}

echo json_encode(['passed' => $failures === [], 'failures' => $failures], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
exit($failures === [] ? 0 : 1);
