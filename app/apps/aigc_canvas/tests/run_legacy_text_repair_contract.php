<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
require $root . '/vendor/autoload.php';

use app\common\service\app\aigc_canvas\AigcCanvasService;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$formatProject = new ReflectionMethod(AigcCanvasService::class, 'formatProject');
$formatProject->setAccessible(true);

$project = $formatProject->invoke(null, [
    'id' => 0,
    'tenant_id' => 0,
    'user_id' => 0,
    'name' => "\u{74A7}\u{52EA}\u{9A87}\u{7459}\u{55DB}\u{E576}",
    'thumbnail' => '',
    'nodes_json' => [[
        'id' => 'node-1',
        'type' => 'image',
        'title' => '璧勪骇鍥剧墖',
        'metadata' => ['save_state' => '宸茶嚜鍔ㄤ繚瀛?'],
    ]],
    'edges_json' => [[
        'id' => 'edge-1',
        'label' => '鍓湰',
    ]],
    'viewport_json' => [
        'x' => 120,
        'y' => -40,
        'k' => 1,
    ],
    'create_time' => 1,
    'update_time' => 1,
], true);

$assert(($project['name'] ?? '') === '资产视频', 'project name is not repaired');
$assert(($project['nodes'][0]['title'] ?? '') === '资产图片', 'node title is not repaired');
$assert(($project['nodes'][0]['metadata']['save_state'] ?? '') === '已自动保存', 'nested node metadata is not repaired');
$assert(($project['edges'][0]['label'] ?? '') === '副本', 'edge label is not repaired');
$assert(($project['viewport']['x'] ?? null) === 120.0, 'viewport x changed during text repair');
$assert(($project['viewport']['y'] ?? null) === -40.0, 'viewport y changed during text repair');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Legacy canvas text repair contract passed" . PHP_EOL;
