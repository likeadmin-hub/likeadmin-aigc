<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
require $root . '/vendor/autoload.php';

use app\common\service\app\aigc_canvas\agent\prompt\PromptSpecCompiler;
use app\common\service\app\aigc_canvas\agent\prompt\PromptEnrichmentService;
use app\common\service\app\aigc_canvas\agent\enrichment\VisualPromptCompiler;
use app\common\service\power\CanvasImagePromptSubmissionGuard;

$failures = [];

$direct = PromptSpecCompiler::compileDirect('一只可爱的猫咪，写实摄影风格，自然光照明，背景虚化，色调温暖柔和。', [
    'ratio' => '16:9',
]);
$directPrompt = (string)$direct['compiled_prompt'];
foreach (['Create one standalone', 'Section purpose', 'Narrative', 'User intent', 'Provider negative prompt', '幼犬'] as $unexpected) {
    if (str_contains($directPrompt, $unexpected)) {
        $failures[] = 'direct prompt retains forbidden text: ' . $unexpected;
    }
}
if (substr_count($directPrompt, '16:9') !== 1) {
    $failures[] = 'direct prompt renders ratio more than once';
}
if (substr_count($directPrompt, '一只可爱的猫咪') !== 1) {
    $failures[] = 'direct prompt duplicates the user request';
}

$legacy = PromptSpecCompiler::compileDirect("Create one standalone generate_image visual.\nSection purpose: 一只可爱的猫咪，写实摄影风格。\nNarrative: 一只可爱的猫咪，写实摄影风格。\nVisible evidence to preserve: 一只棕白相间的幼犬。\nUse ratio 16:9.\nDo not invent price.");
$legacyPrompt = (string)$legacy['compiled_prompt'];
foreach (['Create one standalone', 'Section purpose', 'Narrative', 'Visible evidence', '幼犬'] as $unexpected) {
    if (str_contains($legacyPrompt, $unexpected)) {
        $failures[] = 'legacy structured prompt retains unsafe text: ' . $unexpected;
    }
}
if (substr_count($legacyPrompt, '一只可爱的猫咪') !== 1 || substr_count($legacyPrompt, '16:9') !== 1) {
    $failures[] = 'legacy structured prompt was not normalized';
}

$creativeContext = [
    'product_identity' => ['黑色头戴式耳机'],
    'evidence_catalog' => [
        ['fact_id' => 'fact_cat', 'observation' => '参考图中是一只棕白相间的猫咪', 'source' => 'visible'],
        ['fact_id' => 'fact_dog', 'observation' => '参考图中是一只幼犬', 'source' => 'visible'],
    ],
    'claim_policy' => [
        'claims' => [
            [
                'claim_id' => 'claim_cat',
                'text' => '棕白配色主体',
                'status' => 'approved_claim',
                'source' => 'visible',
                'evidence_ids' => ['fact_cat'],
            ],
            [
                'claim_id' => 'claim_need_battery',
                'text' => '续航时长',
                'status' => 'needs_verification',
                'source' => 'missing_evidence',
                'evidence_ids' => [],
            ],
        ],
    ],
];
$planned = PromptSpecCompiler::compilePlanned($creativeContext, [
    'purpose' => '淘宝商品主图',
    'user_request' => '将商品置于纯白背景，商业产品摄影。',
    'ratio' => '1:1',
    'evidence_ids' => ['fact_cat'],
    'visual_direction' => ['clean_composition'],
], 'ecommerce_main_image');
$plannedPrompt = (string)$planned['compiled_prompt'];
if (!str_contains($plannedPrompt, '猫咪') || str_contains($plannedPrompt, '幼犬')) {
    $failures[] = 'planned prompt does not strictly use selected evidence';
}
if (str_contains($plannedPrompt, 'clean_composition')) {
    $failures[] = 'Chinese prompt retains an internal English visual label';
}
if ((string)($planned['creative_spec_json']['unverified_parameters'][0]['text'] ?? '') !== '续航时长') {
    $failures[] = 'unverified claim is not retained for review outside the prompt';
}

$visualDirection = VisualPromptCompiler::compile([], '生成商品主图');
if (isset($visualDirection['prompt']) || isset($visualDirection['negative_prompt']) || empty($visualDirection['visual_system'])) {
    $failures[] = 'visual understanding output is still shaped as a provider prompt';
}

$guarded = [
    'submission_prepared' => true,
    'prompt' => $plannedPrompt,
    'compiled_prompt' => $plannedPrompt,
    'prompt_spec_json' => $planned['prompt_spec_json'],
    'prompt_hash' => $planned['prompt_hash'],
    'compiler_version' => $planned['compiler_version'],
    'preflight' => ['status' => 'passed'],
];
try {
    CanvasImagePromptSubmissionGuard::assertPrepared('aigc_canvas', $guarded);
} catch (Throwable $e) {
    $failures[] = 'prepared request was rejected by the market guard';
}
try {
    CanvasImagePromptSubmissionGuard::assertPrepared('aigc_canvas', ['prompt' => $plannedPrompt]);
    $failures[] = 'market guard accepts request without compiler snapshot';
} catch (Throwable) {
}

try {
    PromptSpecCompiler::compileDirect('纯白背景的户外森林产品摄影', ['ratio' => '1:1']);
    $failures[] = 'visual conflict was not rejected';
} catch (InvalidArgumentException) {
}

$edit = PromptSpecCompiler::compileEdit('将背景改为浅灰色摄影棚，不改变主体。', ['黑色头戴式耳机'], ['ratio' => '1:1']);
if (($edit['prompt_spec_json']['mode'] ?? '') !== 'edit'
    || !str_contains((string)$edit['compiled_prompt'], '黑色头戴式耳机')
    || !str_contains((string)$edit['compiled_prompt'], '浅灰色摄影棚')) {
    $failures[] = 'edit prompt does not preserve identity and edit direction';
}

$snapshot = PromptSpecCompiler::reuseSnapshot([
    'prompt' => $plannedPrompt,
    'creative_spec_json' => $planned['creative_spec_json'],
    'prompt_spec_json' => $planned['prompt_spec_json'],
    'compiler_version' => $planned['compiler_version'],
    'prompt_hash' => $planned['prompt_hash'],
    'evidence_ids' => $planned['evidence_ids'],
    'claim_ids' => $planned['claim_ids'],
]);
if ($snapshot['compiled_prompt'] !== $plannedPrompt || $snapshot['prompt_hash'] !== $planned['prompt_hash']) {
    $failures[] = 'retry snapshot is not reproducible';
}

if ((array)($direct['prompt_spec_json']['constraints'] ?? []) !== []) {
    $failures[] = 'generic direct prompt incorrectly contains the product-claim guard';
}
if ((array)($planned['prompt_spec_json']['constraints'] ?? []) === []) {
    $failures[] = 'product prompt is missing the product-claim guard';
}
$enriched = PromptEnrichmentService::normalizeResponse('{"description":"A complete visual brief","visual_direction":["soft light","medium shot"]}');
if (($enriched['description'] ?? '') !== 'A complete visual brief' || count((array)($enriched['visual_direction'] ?? [])) !== 2) {
    $failures[] = 'direct prompt enrichment response cannot be normalized';
}
$portable = PromptSpecCompiler::compileDirect('一只小博美🐶', ['ratio' => '9:16']);
if (str_contains((string)$portable['compiled_prompt'], '🐶')) {
    $failures[] = 'direct prompt retains a non-BMP provider-incompatible symbol';
}
$malformed = PromptSpecCompiler::compileDirect('一只小博美' . chr(0xE7) . '，写实摄影', ['ratio' => '9:16']);
if (!mb_check_encoding((string)$malformed['compiled_prompt'], 'UTF-8')) {
    $failures[] = 'direct prompt retains malformed UTF-8 bytes';
}

echo json_encode([
    'passed' => $failures === [],
    'failures' => $failures,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;

exit($failures === [] ? 0 : 1);
