<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
require $root . '/vendor/autoload.php';

$skillClass = 'app\\common\\service\\app\\aigc_canvas\\AigcCanvasSkillService';
$evaluationClass = 'app\\common\\service\\app\\aigc_canvas\\AigcCanvasSkillEvaluationService';
$catalogMethod = new ReflectionMethod($skillClass, 'productCatalog');
$catalogMethod->setAccessible(true);
$catalog = $catalogMethod->invoke(null);
$byKey = [];
foreach ($catalog as $skill) {
    $byKey[(string)($skill['skill_key'] ?? '')] = $skill;
}

$caseMethod = new ReflectionMethod($evaluationClass, 'builtinCases');
$caseMethod->setAccessible(true);
$cases = $caseMethod->invoke(null);
$failures = [];

foreach (['ecommerce_main_image', 'ecommerce_selling_point', 'image_to_video', 'logo_design'] as $key) {
    if (!isset($byKey[$key])) {
        $failures[] = "missing P1 Skill: {$key}";
    }
}
foreach (['presentation_design', 'short_drama_preproduction'] as $key) {
    if (!isset($byKey[$key])) {
        $failures[] = "missing P2 Skill: {$key}";
    }
}

foreach ($byKey as $key => $skill) {
    if (($skill['skill_type'] ?? '') === 'workflow_template') {
        $failures[] = "catalog retains workflow_template: {$key}";
    }
    if (str_starts_with($key, 'wf_') || str_starts_with($key, 'workflow_')) {
        $failures[] = "catalog retains workflow template key: {$key}";
    }
}
foreach ($cases as $case) {
    $key = (string)($case['skill_key'] ?? '');
    if (str_starts_with($key, 'wf_') || str_starts_with($key, 'workflow_')) {
        $failures[] = "evaluation retains workflow template case: {$key}";
    }
}

$mainImage = $skillClass::compileForAgent($byKey['ecommerce_main_image'], '给不锈钢保温杯做一张淘宝白底主图', [], true);
if ($mainImage['missing_slots'] !== [] || !in_array('generate_image', $mainImage['allowed_tools'], true)) {
    $failures[] = 'ecommerce_main_image baseline cannot execute';
}

$sellingPoint = $skillClass::compileForAgent($byKey['ecommerce_selling_point'], '给耳机做一张电商图片', [], true);
if (($sellingPoint['slot_state']['selling_point']['status'] ?? '') !== 'missing') {
    $failures[] = 'ecommerce_selling_point does not retain its missing selling point state';
}

$imageToVideo = $skillClass::compileForAgent($byKey['image_to_video'], '让镜头缓慢推进', [
    'selected_elements' => [['id' => 'image-1', 'type' => 'image']],
], true);
if ($imageToVideo['missing_slots'] !== [] || !in_array('generate_video', $imageToVideo['allowed_tools'], true)) {
    $failures[] = 'image_to_video cannot use a selected canvas image';
}

$drama = $skillClass::compileForAgent($byKey['short_drama_preproduction'], '帮我做短剧前期制作', [], true);
if (!in_array('story_brief', $drama['missing_slots'], true)) {
    $failures[] = 'short_drama_preproduction does not clarify a missing story brief';
}
if (!in_array('create_short_drama_plan', $byKey['short_drama_preproduction']['tool_policy_json']['allowed_tools'] ?? [], true)) {
    $failures[] = 'short_drama_preproduction cannot create a durable short-drama task';
}
if (($byKey['short_drama_preproduction']['release_status'] ?? '') !== 'active') {
    $failures[] = 'short_drama_preproduction is not active';
}

$groups = [];
foreach ($cases as $case) {
    foreach ((array)($case['tags'] ?? []) as $tag) {
        $groups[(string)$tag] = true;
    }
}
foreach (['p0', 'p1', 'p2', '正例', '反例', '缺槽位', '选区'] as $tag) {
    if (!isset($groups[$tag])) {
        $failures[] = "evaluation seed lacks coverage tag: {$tag}";
    }
}

echo json_encode([
    'catalog_count' => count($byKey),
    'evaluation_case_count' => count($cases),
    'passed' => $failures === [],
    'failures' => $failures,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;

exit($failures === [] ? 0 : 1);
