<?php

$root = dirname(__DIR__, 4);
$path = $root . '/app/common/service/power/MarketVideoRuntimeService.php';
$source = file_get_contents($path);

require $root . '/vendor/autoload.php';

if (!is_string($source) || !str_contains($source, "'Description' => mb_substr")) {
    fwrite(STDERR, "Seedance material-group description is missing\n");
    exit(1);
}
if (!str_contains($source, 'private static function seedanceResponseIdentifier')) {
    fwrite(STDERR, "Seedance response identifier is missing\n");
    exit(1);
}
if (!str_contains($source, "'asset_group', 'failed'")) {
    fwrite(STDERR, "Seedance asset-group diagnostics are missing\n");
    exit(1);
}
if (!str_contains($source, 'private static function seedanceContent')) {
    fwrite(STDERR, "Seedance asset-ID request mapping is missing\n");
    exit(1);
}

$method = new ReflectionMethod(app\common\service\power\MarketVideoRuntimeService::class, 'seedanceResponseIdentifier');
$method->setAccessible(true);

$groupKeys = ['group_id', 'groupid', 'id'];
$assetKeys = ['asset_id', 'assetid', 'id'];
$cases = [
    ['payload' => ['data' => ['data' => ['GroupId' => 'group_nested']]], 'keys' => $groupKeys, 'expected' => 'group_nested'],
    ['payload' => ['data' => '{"Result":{"GroupId":"group_json"}}'], 'keys' => $groupKeys, 'expected' => 'group_json'],
    ['payload' => ['data' => 'group_scalar'], 'keys' => $groupKeys, 'expected' => 'group_scalar'],
    ['payload' => ['result' => '{"AssetId":"asset_json"}'], 'keys' => $assetKeys, 'expected' => 'asset_json'],
    ['payload' => ['data' => 'success'], 'keys' => $groupKeys, 'expected' => ''],
];

foreach ($cases as $case) {
    $actual = (string)$method->invoke(null, $case['payload'], $case['keys']);
    if ($actual !== $case['expected']) {
        fwrite(STDERR, "Seedance response identifier contract failed\n");
        exit(1);
    }
}

$payloadMethod = new ReflectionMethod(app\common\service\power\MarketVideoRuntimeService::class, 'appPayload');
$payloadMethod->setAccessible(true);
$payload = $payloadMethod->invoke(null, [
    'app_code' => 'seedance',
    'locked_params' => [],
], [
    'prompt' => 'test',
    'resolution' => '720p',
    'duration' => 4,
    'ratio' => '9:16',
    'reference_assets' => [
        ['type'=>'image','url'=>'asset://image_1','role'=>'reference_image'],
        ['type'=>'video','url'=>'asset://video_1','role'=>'reference_video'],
        ['type'=>'audio','url'=>'asset://audio_1','role'=>'reference_audio'],
    ],
], 'test-key');

if (($payload['content'][1]['image_url']['url'] ?? '') !== 'asset://image_1'
    || ($payload['content'][2]['video_url']['url'] ?? '') !== 'asset://video_1'
    || ($payload['content'][3]['audio_url']['url'] ?? '') !== 'asset://audio_1') {
    fwrite(STDERR, "Seedance create payload does not use locked asset IDs\n");
    exit(1);
}
if (str_contains(json_encode($payload), 'raw-image.png')) {
    fwrite(STDERR, "Seedance create payload still contains a source URL\n");
    exit(1);
}

$modeMethod = new ReflectionMethod(app\common\service\power\MarketVideoRuntimeService::class, 'skuSupportsInputMode');
$modeMethod->setAccessible(true);
$seedanceProduct = ['upstream_app_code' => 'seedance', 'resource_type' => 'app_api'];
if (!$modeMethod->invoke(null, 'text_to_video', 'video_edit', $seedanceProduct, ['_pricing_variant' => 'with_video'])) {
    fwrite(STDERR, "Seedance with_video SKU does not accept video reference input\n");
    exit(1);
}
if ($modeMethod->invoke(null, 'text_to_video', 'video_edit', $seedanceProduct, ['_pricing_variant' => 'without_video'])) {
    fwrite(STDERR, "Seedance without_video SKU accepts video reference input\n");
    exit(1);
}

echo "market video Seedance asset contract passed\n";
