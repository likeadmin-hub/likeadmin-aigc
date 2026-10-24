<?php
/** No database/provider calls: exercise real schema parsing with upstream object properties. */
require getenv('VIDEO_RUNTIME_SOURCE') ?: dirname(__DIR__) . '/app/common/service/power/MarketVideoRuntimeService.php';

use app\common\service\power\MarketVideoRuntimeService;

set_error_handler(static function ($severity, $message, $file, $line): void {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$method = new ReflectionMethod(MarketVideoRuntimeService::class, 'schemaVideoCapabilities');
$method->setAccessible(true);
$failures = 0;
foreach (['description', 'label', 'title'] as $property) {
    try {
        // JSON Schema's properties may themselves be named description/label/title.
        // Kling element schemas expose exactly this shape for their subject description.
        $schema = [
            'prompt' => ['type' => 'string', 'description' => '视频提示词'],
            'elements' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                $property => ['type' => 'string', 'description' => '主体的文本描述'],
                'image_url' => ['type' => 'string', 'description' => '参考图片'],
            ]]],
        ];
        $result = $method->invoke(null, ['params_schema' => $schema]);
        if ($result['asset_types'] !== ['image']
            || !in_array('text_to_video', $result['generation_modes'], true)
            || !in_array('image_reference', $result['generation_modes'], true)) {
            throw new RuntimeException('Nested schema lost prompt or image capabilities');
        }
        echo 'PASS structured ' . $property . ' property retains nested capabilities' . PHP_EOL;
    } catch (Throwable $e) {
        $failures++;
        fwrite(STDERR, 'FAIL ' . $property . ': ' . $e->getMessage() . PHP_EOL);
    }
}
try {
    $result = $method->invoke(null, ['params_schema' => [
        'prompt' => ['type' => 'string', 'title' => '提示词'],
        'start_image' => ['type' => 'string', 'label' => '首帧'],
        'end_image' => ['type' => 'string', 'description' => '尾帧'],
    ]]);
    if ($result['asset_types'] !== ['image'] || !in_array('start_end', $result['generation_modes'], true)
        || !in_array('text_to_video', $result['generation_modes'], true)) {
        throw new RuntimeException('Scalar description/label/title capability signals changed');
    }
    echo 'PASS legacy scalar labels retain prompt and first/last-frame modes' . PHP_EOL;
} catch (Throwable $e) {
    $failures++;
    fwrite(STDERR, 'FAIL scalar labels: ' . $e->getMessage() . PHP_EOL);
}
restore_error_handler();
exit($failures ? 1 : 0);
