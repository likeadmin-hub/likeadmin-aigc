<?php
/** Read-only parity check for requests produced by pc/scripts/audit-canvas-video-materials.mjs.
 * Usage (on local develop): php tests/canvas-native-material-audit.php < requests.json
 * No database, provider requests, storage writes or point deductions.
 */
require dirname(__DIR__) . '/vendor/autoload.php';
use app\common\service\app\aigc_canvas\AigcCanvasService as Canvas;
use app\common\service\power\MarketGenerationMaterials as Materials;
$cases = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$normalize = new ReflectionMethod(Canvas::class, 'normalizeVideoParams');
$prepare = new ReflectionMethod(Canvas::class, 'prepareMentionParams');
$errors = [];
$passed = 0;
foreach ($cases as $case) {
    try {
        $mode = $case['mode'];
        $metadata = ['params_schema'=>$mode['parameter_schema'], 'material_capabilities'=>['modes'=>[$mode]]];
        $request = $normalize->invoke(null, $prepare->invoke(null, $case['request']), 1, 0, false);
        $request['reference_assets'] = \app\common\service\app\aigc_video\AigcVideoReferenceAssetService::normalize($request, 100);
        $payload = Materials::serialize($metadata, $request);
        Materials::validate($metadata, $request, $payload);
        $baseline = array_replace($case['request'], ['reference_assets'=>$case['assets']]);
        if ($payload !== Materials::serialize($metadata, $baseline)) {
            throw new RuntimeException('Canvas normalization changed the native material payload');
        }
        $passed++;
    } catch (Throwable $e) {
        $errors[] = ['model'=>$case['name'], 'mode'=>$case['mode']['method'], 'scenario'=>$case['scenario'], 'error'=>$e->getMessage()];
    }
}
echo json_encode(compact('passed', 'errors'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
exit($errors === [] ? 0 : 1);
