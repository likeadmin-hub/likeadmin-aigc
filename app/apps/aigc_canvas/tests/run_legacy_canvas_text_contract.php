<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
require $root . '/vendor/autoload.php';

use app\common\service\app\aigc_canvas\AigcCanvasService;

$failures = [];
$repair = new ReflectionMethod(AigcCanvasService::class, 'repairLegacyProjectText');
$repair->setAccessible(true);

$original = "\u{4E00}\u{53EA}\u{6BDB}\u{8338}\u{8338}\u{7684}\u{7EAF}\u{767D}\u{6CE2}\u{65AF}\u{732B}\u{FF0C}\u{7279}\u{5199}\u{6784}\u{56FE}.";
$legacy = mb_convert_encoding($original, 'UTF-8', 'GB18030');
$repaired = $repair->invoke(null, $legacy);
if (!str_starts_with($repaired, mb_substr($original, 0, -2, 'UTF-8'))
    || preg_match('/[\x{20AC}\x{E000}-\x{F8FF}]/u', $repaired)) {
    $failures[] = 'GB18030-decoded UTF-8 prompt was not repaired';
}

$normal = "\u{4E00}\u{53EA}\u{767D}\u{732B}\u{FF0C}\u{67D4}\u{548C}\u{81EA}\u{7136}\u{5149}";
if ($repair->invoke(null, $normal) !== $normal) {
    $failures[] = 'normal Chinese prompt was unexpectedly reinterpreted';
}

$payload = ['prompt' => $legacy, 'title' => $normal, 'nested' => ['content' => $legacy]];
$repairedPayload = $repair->invoke(null, $payload);
if (!str_starts_with((string)($repairedPayload['prompt'] ?? ''), mb_substr($original, 0, -2, 'UTF-8'))
    || !str_starts_with((string)($repairedPayload['nested']['content'] ?? ''), mb_substr($original, 0, -2, 'UTF-8'))) {
    $failures[] = 'nested canvas payload strings were not repaired';
}
if (($repairedPayload['title'] ?? '') !== $normal) {
    $failures[] = 'normal nested text was unexpectedly changed';
}

echo json_encode(['passed' => $failures === [], 'failures' => $failures], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
exit($failures === [] ? 0 : 1);
