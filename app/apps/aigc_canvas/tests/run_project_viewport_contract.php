<?php

declare(strict_types=1);

use app\common\service\app\aigc_canvas\AigcCanvasService;

$root = dirname(__DIR__, 4);
require $root . '/vendor/autoload.php';

$method = new ReflectionMethod(AigcCanvasService::class, 'normalizeViewport');
$method->setAccessible(true);

$legacy = $method->invoke(null, ['x' => 12, 'y' => 34, 'k' => 1.16]);
$canonical = $method->invoke(null, ['x' => 12, 'y' => 34, 'zoom' => 0.95]);
$failures = [];

if (($legacy['zoom'] ?? null) !== 1.16) {
    $failures[] = 'viewport k is not accepted for backward-compatible canvas saves';
}
if (($canonical['zoom'] ?? null) !== 0.95) {
    $failures[] = 'viewport zoom is not preserved for the canonical API payload';
}

echo json_encode([
    'passed' => $failures === [],
    'checked' => ['legacy_k' => true, 'canonical_zoom' => true],
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failures === [] ? 0 : 1);
