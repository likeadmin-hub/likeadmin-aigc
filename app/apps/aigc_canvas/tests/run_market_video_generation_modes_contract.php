<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
require $root . '/vendor/autoload.php';

use app\common\service\power\MarketVideoRuntimeService;

$failures = [];
$modesMethod = new ReflectionMethod(MarketVideoRuntimeService::class, 'generationModes');
$modesMethod->setAccessible(true);

$product = [
    'upstream_app_code' => 'custom_video',
    'upstream_model_code' => 'custom-video-v1',
    'upstream_channel_code' => 'custom_video',
];
$metadata = [
    'input_modes' => ['text_to_video', 'image_reference', 'video_edit'],
    'generation_modes' => ['omni_reference', 'start_end', 'multi_frame'],
    'supported_asset_types' => ['image', 'video', 'audio'],
    'max_reference_images' => 4,
    'max_reference_videos' => 1,
    'max_reference_audios' => 1,
];
$modes = $modesMethod->invoke(null, $product, $metadata);
$expected = [
    'omni_reference',
    'start_end',
    'multi_frame',
];
if ($modes !== $expected) {
    $failures[] = 'explicit video generation modes were expanded beyond the upstream declaration';
}

$imageOnlyModes = $modesMethod->invoke(null, $product, [
    'input_modes' => ['text_to_video', 'image_to_video'],
]);
if ($imageOnlyModes !== ['text_to_video', 'image_to_video']) {
    $failures[] = 'image-to-video capability was not kept distinct from image reference capability';
}

$imageReferenceOnlyModes = $modesMethod->invoke(null, $product, [
    'input_modes' => ['text_to_video', 'image_reference'],
]);
if ($imageReferenceOnlyModes !== ['text_to_video', 'image_reference']) {
    $failures[] = 'explicit image reference input mode was expanded into image-to-video';
}

$libTvStyleModes = $modesMethod->invoke(null, $product, [
    'input_modes' => ['text2video', 'singleImage2video', 'frames2video', 'mixed2video', 'videoEdit2video', 'audio2video'],
]);
if ($libTvStyleModes !== ['text_to_video', 'omni_reference', 'image_to_video', 'start_end', 'video_edit', 'audio_reference']) {
    $failures[] = 'LibTV-style model mode identifiers were not normalized';
}

$objectModes = $modesMethod->invoke(null, $product, [
    'generation_modes' => [
        ['code' => 'text_to_video'],
        ['value' => 'mixed2video'],
        ['mode' => 'frames2video'],
    ],
]);
if ($objectModes !== ['text_to_video', 'omni_reference', 'start_end']) {
    $failures[] = 'object-form generation modes were not normalized';
}

$legacyModes = $modesMethod->invoke(null, $product, [
    'supported_asset_types' => ['image'],
    'max_reference_images' => 2,
]);
if ($legacyModes !== ['text_to_video', 'image_to_video', 'image_reference']) {
    $failures[] = 'market asset-type fallback should not invent start/end frame capability';
}

$source = file_get_contents($root . '/app/common/service/power/MarketVideoRuntimeService.php');
foreach ([
    "\$generationMethod === 'text_to_video'",
    "\$generationMethod === 'image_to_video'",
    "\$generationMethod === 'image_reference'",
    "\$generationMethod === 'video_edit'",
    "\$generationMethod === 'omni_reference'",
    "\$generationMethod === 'audio_reference'",
] as $needle) {
    if (!is_string($source) || !str_contains($source, $needle)) {
        $failures[] = 'market video runtime is missing generation mode validation: ' . $needle;
    }
}

echo json_encode([
    'passed' => $failures === [],
    'failures' => $failures,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;

exit($failures === [] ? 0 : 1);
