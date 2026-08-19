<?php

$root = dirname(__DIR__, 4);
$path = $root . '/app/common/service/power/MarketVideoRuntimeService.php';
$source = file_get_contents($path);
$taskRuntimeSource = file_get_contents($root . '/app/common/service/ai/AiMarketTaskRuntimeService.php');

require $root . '/vendor/autoload.php';

if (!is_string($source) || !str_contains($source, 'public static function isSupportedAppProduct(array $product): bool')) {
    fwrite(STDERR, "Market video runtime no longer exposes the shared support gate\n");
    exit(1);
}
if (str_contains($source, "'wan' => ['create'], 'seedance' => ['create'], 'happy_horse' => ['submit']")) {
    fwrite(STDERR, "Legacy video app allowlist is still present\n");
    exit(1);
}
if (!str_contains($source, '$generationModes = self::generationModes($product, $metadata);')
    || !str_contains($source, '$assetTypes = self::supportedAssetTypes($product, $metadata);')) {
    fwrite(STDERR, "Market video runtime no longer derives app video support from capabilities\n");
    exit(1);
}
if (!str_contains($source, "upstream_api_code' => 'query'")) {
    fwrite(STDERR, "Market video runtime no longer verifies query contract\n");
    exit(1);
}
if (!is_string($taskRuntimeSource) || !str_contains($taskRuntimeSource, 'MarketVideoRuntimeService::isSupportedAppProduct($product)')) {
    fwrite(STDERR, "Market task runtime no longer reuses the shared market support gate\n");
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

$genericPayload = $method->invoke(null, [
    'app_code' => 'grok_video_xaiq',
    'model_code' => 'grok-video',
    'locked_params' => [],
], [
    'prompt' => 'test',
    'resolution' => '720p',
    'duration' => 6,
], 'test-key');
if (($genericPayload['model'] ?? '') !== 'grok-video') {
    fwrite(STDERR, "Generic video app payload no longer preserves upstream model code\n");
    exit(1);
}

$grokPayload = $method->invoke(null, [
    'app_code' => 'grok_video',
    'model_code' => 'grok-video',
    'locked_params' => ['model' => 'grok-imagine-video-1.5-fast'],
], [
    'prompt' => 'test',
    'resolution' => '720p',
    'duration' => 10,
    'ratio' => '16:9',
    'reference_assets' => [['type' => 'image', 'url' => 'https://example.com/reference.png']],
], 'test-key');
if (($grokPayload['aspect_ratio'] ?? '') !== '16:9' || isset($grokPayload['size'])) {
    fwrite(STDERR, "Grok video app payload does not use the Grok Video aspect_ratio contract\n");
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

$referenceAudioRequiresVisual = new ReflectionMethod(app\common\service\power\MarketVideoRuntimeService::class, 'referenceAudioRequiresVisual');
$referenceAudioRequiresVisual->setAccessible(true);
if (!$referenceAudioRequiresVisual->invoke(null, ['upstream_model_code' => 'h3-video'], [
    'developer_doc_content' => '参考音频不能单独使用，必须至少同时有一个参考图片或参考视频。',
])) {
    fwrite(STDERR, "H3 audio-reference visual dependency was not detected\n");
    exit(1);
}

$frameAndReferenceMutuallyExclusive = new ReflectionMethod(app\common\service\power\MarketVideoRuntimeService::class, 'frameAndReferenceMutuallyExclusive');
$frameAndReferenceMutuallyExclusive->setAccessible(true);
if (!$frameAndReferenceMutuallyExclusive->invoke(null, ['upstream_model_code' => 'h3-video'], [
    'developer_doc_content' => '首尾帧模式与多模态参考模式互斥，不能同时出现 first_frame 或 last_frame。',
])) {
    fwrite(STDERR, "H3 frame/reference exclusivity was not detected\n");
    exit(1);
}

$assertAssets = new ReflectionMethod(app\common\service\power\MarketVideoRuntimeService::class, 'assertAssets');
$assertAssets->setAccessible(true);
$h3Market = [
    'product' => [
        'resource_type' => app\common\service\power\PowerMarketService::TYPE_MODEL,
        'upstream_model_code' => 'h3-video',
        'source_payload' => [
            'market_metadata' => [
                'developer_doc_content' => '参考音频不能单独使用，必须至少同时有一个参考图片或参考视频。首尾帧模式与多模态参考模式互斥，不能同时出现 first_frame 或 last_frame。',
                'supported_asset_types' => ['image', 'video', 'audio'],
                'max_reference_images' => 9,
                'max_reference_videos' => 3,
                'max_reference_audios' => 3,
                'generation_modes' => ['audio_reference', 'video_edit', 'start_end'],
            ],
        ],
    ],
];
try {
    $assertAssets->invoke(null, $h3Market, [
        'generation_method' => 'audio_reference',
        'reference_assets' => [
            ['type' => 'audio', 'url' => 'https://example.com/reference.mp3'],
        ],
    ]);
    fwrite(STDERR, "H3 audio-reference unexpectedly allowed audio-only input\n");
    exit(1);
} catch (Throwable $e) {
    if (!str_contains($e->getMessage(), 'reference image or video')) {
        throw $e;
    }
}
$assertAssets->invoke(null, $h3Market, [
    'generation_method' => 'audio_reference',
    'reference_assets' => [
        ['type' => 'image', 'url' => 'https://example.com/reference.png'],
        ['type' => 'audio', 'url' => 'https://example.com/reference.mp3'],
    ],
]);
$assertAssets->invoke(null, [
    'product' => [
        'resource_type' => app\common\service\power\PowerMarketService::TYPE_MODEL,
        'upstream_model_code' => 'omni-video',
        'source_payload' => [
            'market_metadata' => [
                'supported_asset_types' => ['image', 'video', 'audio'],
                'max_reference_images' => 9,
                'max_reference_videos' => 3,
                'max_reference_audios' => 3,
                'generation_modes' => ['omni_reference'],
            ],
        ],
    ],
], [
    'generation_method' => 'video_edit',
    'reference_assets' => [
        ['type' => 'video', 'url' => 'https://example.com/reference.mp4'],
    ],
]);
$assertAssets->invoke(null, [
    'product' => [
        'resource_type' => app\common\service\power\PowerMarketService::TYPE_MODEL,
        'upstream_model_code' => 'video-reference-only',
        'source_payload' => [
            'market_metadata' => [
                'supported_asset_types' => ['video'],
                'max_reference_videos' => 1,
                'generation_modes' => ['video_edit'],
            ],
        ],
    ],
], [
    'generation_method' => 'omni_reference',
    'reference_assets' => [
        ['type' => 'video', 'url' => 'https://example.com/reference.mp4'],
    ],
]);
try {
    $assertAssets->invoke(null, $h3Market, [
        'generation_method' => 'video_edit',
        'reference_assets' => [
            ['type' => 'image', 'url' => 'https://example.com/first.png', 'role' => 'first_frame_image'],
            ['type' => 'video', 'url' => 'https://example.com/reference.mp4'],
        ],
    ]);
    fwrite(STDERR, "H3 frame/reference mix was unexpectedly allowed\n");
    exit(1);
} catch (Throwable $e) {
    if (!str_contains($e->getMessage(), 'first/last frame mode')) {
        throw $e;
    }
}

$durationOptions = new ReflectionMethod(app\common\service\power\MarketVideoRuntimeService::class, 'durationOptions');
$durationOptions->setAccessible(true);
$ratioOptions = new ReflectionMethod(app\common\service\power\MarketVideoRuntimeService::class, 'ratioOptions');
$ratioOptions->setAccessible(true);
$happyHorseDurations = $durationOptions->invoke(null, [
    'params_schema' => [
        'duration' => [
            'type' => 'number',
            'options' => '3～15 的整数',
            'description' => '目标生成时长（秒），整数。'
        ]
    ]
]);
if ($happyHorseDurations !== range(3, 15)) {
    fwrite(STDERR, "Chinese duration range was not expanded from market schema\n");
    exit(1);
}

$seedanceDurations = $durationOptions->invoke(null, [
    'params_schema' => [
        'properties' => [
            'duration' => [
                'type' => 'integer',
                'default' => '5',
                'description' => 'Duration in seconds. Valid values: 4~15, or -1 for auto.',
            ],
        ],
    ],
]);
if ($seedanceDurations !== range(4, 15)) {
    fwrite(STDERR, "Seedance duration range was not expanded from JSON Schema properties\n");
    exit(1);
}

$grokVideoSchema = [
    'params_schema' => [
        'duration' => [
            'type' => 'integer',
            'default' => '6',
            'options' => '6 / 10 / 15 / 20 / 25 / 30',
            'description' => '视频时长（秒）。',
        ],
        'aspect_ratio' => [
            'type' => 'string',
            'options' => '2:3 / 3:2 / 1:1 / 9:16 / 16:9',
            'description' => '视频宽高比。建议与参考图比例一致。',
        ],
    ],
];
if ($durationOptions->invoke(null, $grokVideoSchema) !== [6, 10, 15, 20, 25, 30]) {
    fwrite(STDERR, "Grok Video duration options were not parsed from model schema\n");
    exit(1);
}
if ($ratioOptions->invoke(null, $grokVideoSchema) !== ['2:3', '3:2', '1:1', '9:16', '16:9']) {
    fwrite(STDERR, "Grok Video ratio options were not parsed from model schema\n");
    exit(1);
}

echo "market video app contract passed\n";
