<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
require $root . '/vendor/autoload.php';

use app\common\service\power\MarketTextModelRuntimeService;

$runtime = (string)file_get_contents($root . '/app/common/service/power/MarketTextModelRuntimeService.php');
$agent = (string)file_get_contents($root . '/app/common/service/app/aigc_short_drama/canvas_agent/MarketTextConversationProvider.php');
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(str_contains($runtime, 'public static function resolveRoutedModel'), 'preflight has no server-owned routing entry point');
$assert(str_contains($runtime, 'self::routeModel($tenantId, $selection, $requiresVision)'), 'routing entry point does not apply vision compatibility');
$assert(str_contains($runtime, 'self::fallbackModel(') && str_contains($runtime, 'self::isExplicitModelUnavailable($e->getMessage())'), 'fallback is not restricted to explicit upstream model rejection');
$assert(str_contains($agent, 'MarketTextModelRuntimeService::resolveRoutedModel($tenant,$selection,$images!==[])'), 'Agent preflight bypasses server-owned model routing');
$assert(!str_contains($agent, 'MarketTextModelRuntimeService::resolveModel($tenant,$selection,$images!==[])'), 'Agent preflight retains a browser-visible hard model gate');

$classifier = new ReflectionMethod(MarketTextModelRuntimeService::class, 'isExplicitModelUnavailable');
$classifier->setAccessible(true);
$assert($classifier->invoke(null, 'model_not_found') === true, 'model-not-found must permit a tenant-model fallback');
$assert($classifier->invoke(null, 'provider timeout') === false, 'timeout must not trigger a second paid model request');

echo json_encode([
    'passed' => $failures === [],
    'failures' => $failures,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;

exit($failures === [] ? 0 : 1);
