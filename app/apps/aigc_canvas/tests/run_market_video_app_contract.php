<?php

$root = dirname(__DIR__, 4);
$path = $root . '/app/common/service/power/MarketVideoRuntimeService.php';
$source = file_get_contents($path);

require $root . '/vendor/autoload.php';

if (!is_string($source) || !str_contains($source, "'happy_horse' => ['submit']")) {
    fwrite(STDERR, "Happy Horse submit contract is missing\n");
    exit(1);
}
$legacyHappyCreate = "'happy_horse' && \$api === 'create'";
if (str_contains($source, $legacyHappyCreate)) {
    fwrite(STDERR, "Unsupported Happy Horse create endpoint is selectable\n");
    exit(1);
}

$method = new ReflectionMethod(app\common\service\power\MarketVideoRuntimeService::class, 'appPayload');
$method->setAccessible(true);
$payload = $method->invoke(null, [
    'app_code' => 'wan',
    'locked_params' => ['model' => 'wan2.7-videoedit'],
], [
    'prompt' => 'test',
    'resolution' => '720p',
    'duration' => 4,
    'ratio' => '9:16',
    'reference_assets' => [['type' => 'image', 'url' => 'https://example.com/reference.png']],
], 'test-key');

if (($payload['model'] ?? '') !== 'wan2.7-videoedit') {
    fwrite(STDERR, "Wan SKU model lock was not preserved\n");
    exit(1);
}

$modelPayload = new ReflectionMethod(app\common\service\power\MarketVideoRuntimeService::class, 'modelPayload');
$modelPayload->setAccessible(true);
$h3Snapshot = [
    'model_code' => 'h3-video',
    'channel_code' => 'h3',
    'locked_params' => ['resolution' => '2K'],
    'params_schema' => ['ratio' => ['type' => 'string'], 'content' => ['type' => 'array']],
    'requires_concrete_text_to_video_ratio' => true,
    'default_text_to_video_ratio' => '16:9',
];
$textPayload = $modelPayload->invoke(null, $h3Snapshot, [
    'prompt' => 'test',
    'ratio' => 'adaptive',
], 'test-key');
if (($textPayload['ratio'] ?? '') !== '16:9'
    || ($textPayload['content'] ?? []) !== [['type' => 'text', 'text' => 'test']]) {
    fwrite(STDERR, "H3 text-to-video payload did not replace adaptive ratio\n");
    exit(1);
}

$referencePayload = $modelPayload->invoke(null, $h3Snapshot, [
    'prompt' => 'test',
    'ratio' => 'adaptive',
    'reference_assets' => [
        ['type' => 'image', 'url' => 'https://example.com/first.png', 'role' => 'first_frame_image'],
        ['type' => 'image', 'url' => 'https://example.com/last.png', 'role' => 'last_frame_image'],
        ['type' => 'image', 'url' => 'https://example.com/reference.png', 'role' => 'reference_image'],
        ['type' => 'video', 'url' => 'https://example.com/reference.mp4', 'role' => 'reference_video'],
        ['type' => 'audio', 'url' => 'https://example.com/reference.mp3', 'role' => 'reference_audio'],
    ],
], 'test-key');
if (($referencePayload['ratio'] ?? '') !== 'adaptive') {
    fwrite(STDERR, "H3 reference-media payload unexpectedly changed its ratio\n");
    exit(1);
}
if (isset($referencePayload['image_urls']) || isset($referencePayload['video_urls']) || isset($referencePayload['audio_urls'])) {
    fwrite(STDERR, "H3 payload still contains generic reference fields\n");
    exit(1);
}
$expectedH3Content = [
    ['type' => 'text', 'text' => 'test'],
    ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/first.png'], 'role' => 'first_frame'],
    ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/last.png'], 'role' => 'last_frame'],
    ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/reference.png'], 'role' => 'reference_image'],
    ['type' => 'video_url', 'video_url' => ['url' => 'https://example.com/reference.mp4'], 'role' => 'reference_video'],
    ['type' => 'audio_url', 'audio_url' => ['url' => 'https://example.com/reference.mp3'], 'role' => 'reference_audio'],
];
if (($referencePayload['content'] ?? []) !== $expectedH3Content) {
    fwrite(STDERR, "H3 reference-media content contract is invalid\n");
    exit(1);
}

$requiresConcreteRatio = new ReflectionMethod(app\common\service\power\MarketVideoRuntimeService::class, 'requiresConcreteTextToVideoRatio');
$requiresConcreteRatio->setAccessible(true);
if (!$requiresConcreteRatio->invoke(null, ['upstream_model_code' => 'h3-video'], [])) {
    fwrite(STDERR, "H3 concrete-ratio capability was not detected\n");
    exit(1);
}

echo "market video app contract passed\n";
