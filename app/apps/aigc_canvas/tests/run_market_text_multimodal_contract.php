<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
require $root . '/vendor/autoload.php';

use app\common\service\power\MarketTextModelRuntimeService;

$failures = [];
$normalizeMessages = new ReflectionMethod(MarketTextModelRuntimeService::class, 'normalizeMessages');
$normalizeMessages->setAccessible(true);
$contentLength = new ReflectionMethod(MarketTextModelRuntimeService::class, 'messageContentLength');
$contentLength->setAccessible(true);

$prompt = 'Analyze this reference image.';
$messages = $normalizeMessages->invoke(null, $prompt, ['https://cdn.example.com/reference.png'], []);
$messageContent = $messages[0]['content'] ?? null;
if (!is_array($messageContent)) {
    $failures[] = 'reference images must produce structured multimodal message content';
} elseif ($contentLength->invoke(null, $messageContent) !== mb_strlen($prompt, 'UTF-8')) {
    $failures[] = 'multimodal message length must include text only and ignore image URLs';
}

echo json_encode([
    'passed' => $failures === [],
    'failures' => $failures,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;

exit($failures === [] ? 0 : 1);
