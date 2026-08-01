<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
require $root . '/vendor/autoload.php';

use app\common\service\app\aigc_canvas\agent\enrichment\CopyPlanGenerator;
use app\common\service\app\aigc_canvas\agent\delivery\CanvasDeliveryPlannerService;
use app\common\service\app\aigc_canvas\agent\planning\BatchDeliveryPlanner;
use app\common\service\app\aigc_canvas\agent\planning\EcommerceDetailSectionPlanner;
use app\common\service\app\aigc_canvas\agent\prompt\PromptPolicyValidator;

$failures = [];

$withoutEvidence = CopyPlanGenerator::generate([
    ['visible_facts' => ['blue control button']],
]);
if (($withoutEvidence['claims'] ?? []) !== []) {
    $failures[] = 'visible fact without an evidence record was approved';
}

$evidenceId = 'fact_blue_button';
$withEvidence = CopyPlanGenerator::generate([[
    'visible_facts' => ['blue control button'],
    'evidence_catalog' => [[
        'fact_id' => $evidenceId,
        'observation' => 'blue control button',
        'confidence' => 0.95,
        'allowed_usage' => ['identity', 'interaction_copy'],
    ]],
]]);
$claim = $withEvidence['claims'][0] ?? [];
if (($claim['status'] ?? '') !== 'approved_claim'
    || ($claim['evidence_ids'] ?? []) !== [$evidenceId]
    || (float)($claim['confidence'] ?? 0) !== 0.95) {
    $failures[] = 'evidence-backed claim is incomplete';
}

$validated = PromptPolicyValidator::validate([
    'evidence' => [['fact_id' => $evidenceId, 'observation' => 'blue control button']],
    'claims' => [
        ['status' => 'approved_claim', 'text' => 'unsupported', 'source' => 'visible', 'evidence_ids' => []],
        ['status' => 'approved_claim', 'text' => 'supported', 'source' => 'visible', 'evidence_ids' => [$evidenceId]],
    ],
]);
if (count((array)($validated['claims'] ?? [])) !== 1 || ($validated['claims'][0]['text'] ?? '') !== 'supported') {
    $failures[] = 'policy validator accepted an unsupported approved claim';
}

$sections = EcommerceDetailSectionPlanner::normalizeSections([[
    'section_key' => 'hero',
    'title' => 'Hero',
    'purpose' => 'Establish product identity.',
    'narrative' => 'Show the visible blue button.',
    'image_prompt' => 'This legacy provider prompt must not survive.',
    'ratio' => '3:4',
]]);
if (isset($sections[0]['image_prompt']) || ($sections[0]['narrative'] ?? '') !== 'Show the visible blue button.') {
    $failures[] = 'detail plan still exposes a provider prompt';
}

$batchPlan = BatchDeliveryPlanner::plan('ad_creative', 'Create launch visuals.', ['quantity' => 2], ['generate_image']);
foreach ((array)($batchPlan['detail_sections'] ?? []) as $section) {
    if (isset($section['image_prompt']) || trim((string)($section['purpose'] ?? '')) === '') {
        $failures[] = 'contract batch planner emitted a provider prompt';
        break;
    }
}
$dramaBatchPlan = BatchDeliveryPlanner::plan('short_drama_preproduction', 'Create a visual storyboard.', [], ['generate_image']);
if (count((array)($dramaBatchPlan['detail_sections'] ?? [])) !== 4) {
    $failures[] = 'short drama visual delivery does not create four storyboard frames';
}

$compound = CanvasDeliveryPlannerService::plan(0, 0, '海报和详情图');
$groupKeys = array_values(array_filter(array_map(
    static fn(array $group): string => (string)($group['group_key'] ?? ''),
    array_filter((array)($compound['groups'] ?? []), 'is_array')
)));
if (!in_array('ecommerce_detail', $groupKeys, true) || !in_array('poster_campaign', $groupKeys, true)) {
    $failures[] = 'compound detail and poster request was not split into delivery groups';
}
foreach ((array)($compound['items'] ?? []) as $item) {
    if (isset($item['prompt']) || trim((string)($item['creative_intent'] ?? '')) === '') {
        $failures[] = 'compound delivery item still contains a final prompt';
        break;
    }
}

echo json_encode([
    'passed' => $failures === [],
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failures === [] ? 0 : 1);
