<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
require $root . '/vendor/autoload.php';

use app\common\service\app\aigc_canvas\AigcCanvasAgentRuntimeService;

$failures = [];
$method = new ReflectionMethod(AigcCanvasAgentRuntimeService::class, 'toolInput');
$method->setAccessible(true);
$input = $method->invoke(null, 'generate_image', 'Generate a storyboard grid.', [
    'selected_elements' => [[
        'id' => 'selected-image',
        'type' => 'image',
        'url' => 'https://cdn.example.com/selected.png',
    ]],
    'selection' => ['elements' => [[
        'id' => 'selected-video',
        'type' => 'video',
        'url' => 'https://cdn.example.com/selected.mp4',
    ], [
        'id' => 'selected-text',
        'type' => 'text',
        'content' => 'Do not submit text nodes as assets.',
    ]]],
    'uploaded_references' => [[
        'id' => 'uploaded-image',
        'type' => 'image',
        'url' => 'https://cdn.example.com/uploaded.png',
    ]],
], [], 0, 0);

$imageUrls = (array)($input['reference_images'] ?? []);
if ($imageUrls !== ['https://cdn.example.com/uploaded.png', 'https://cdn.example.com/selected.png']) {
    $failures[] = 'uploaded and selected image references were not merged into reference_images';
}

$assets = (array)($input['reference_assets'] ?? []);
$assetKeys = array_map(
    static fn(array $asset): string => (string)($asset['type'] ?? '') . '|' . (string)($asset['url'] ?? ''),
    $assets
);
sort($assetKeys);
if ($assetKeys !== [
    'image|https://cdn.example.com/selected.png',
    'image|https://cdn.example.com/uploaded.png',
    'video|https://cdn.example.com/selected.mp4',
]) {
    $failures[] = 'selected media references were not normalized or text nodes were included';
}

echo json_encode([
    'passed' => $failures === [],
    'failures' => $failures,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;

exit($failures === [] ? 0 : 1);
