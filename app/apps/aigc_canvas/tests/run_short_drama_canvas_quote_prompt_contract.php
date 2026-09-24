<?php
declare(strict_types=1);

require dirname(__DIR__, 4) . '/vendor/autoload.php';

use app\common\service\app\aigc_short_drama\ShortDramaCanvasService;

$method = new ReflectionMethod(ShortDramaCanvasService::class, 'generationPayload');
$method->setAccessible(true);
$params = ['channel' => 'market_image_model:test', 'quality' => '2K', 'ratio' => '16:9', 'prompt' => ''];
$preview = $method->invoke(null, 'image', $params, 1, 1, 20, true);
if (($preview['quality'] ?? '') !== '2K' || ($preview['ratio'] ?? '') !== '16:9') {
    throw new RuntimeException('Empty-prompt price preview lost the selected SKU');
}

try {
    $method->invoke(null, 'image', $params, 1, 1, 20);
    throw new RuntimeException('Generation accepted an empty prompt');
} catch (Exception $error) {
    if ($error->getMessage() !== '请输入提示内容') throw $error;
}

echo "short drama canvas quote prompt contract ok\n";
