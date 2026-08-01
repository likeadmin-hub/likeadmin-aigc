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
$dramaWithBrief = $skillClass::compileForAgent(
    $byKey['short_drama_preproduction'],
    'Ancient imperial chef wakes in a modern restaurant group, starts again as an apprentice, clashes with a traditional father and a ruthless investor, then wins a state-banquet competition to prove his value.',
    [],
    true
);
if (in_array('story_brief', $dramaWithBrief['missing_slots'], true)) {
    $failures[] = 'long-form story brief was not recognized as slot evidence';
}
if (in_array('generate_image', $dramaWithBrief['allowed_tools'], true)
    || !empty($dramaWithBrief['visual_delivery_policy']['matched'])) {
    $failures[] = 'ordinary short drama planning incorrectly activates visual delivery';
}
$visualDrama = $skillClass::compileForAgent(
    $byKey['short_drama_preproduction'],
    'Ancient imperial chef revives a failing modern restaurant. Generate a 2D anime visual storyboard with four frames.',
    [],
    true
);
if (empty($visualDrama['visual_delivery_policy']['matched'])
    || !in_array('generate_image', $visualDrama['allowed_tools'], true)
    || empty($visualDrama['output_policy']['batch_mode'])
    || (int)($visualDrama['default_slots']['quantity'] ?? 0) !== 4) {
    $failures[] = 'short_drama_preproduction does not activate its visual delivery contract';
}
if (in_array('create_short_drama_plan', $dramaWithBrief['output_policy']['terminal_tools'] ?? [], true)) {
    $failures[] = 'short_drama_preproduction still terminates through a cross-app task';
}
$bindingMethod = new ReflectionMethod('app\\common\\service\\app\\aigc_canvas\\agent\\runtime\\AgentTaskDecisionService', 'bindingMode');
$bindingMethod->setAccessible(true);
if ($bindingMethod->invoke(null, true, true, (array)$dramaWithBrief['binding_policy']) !== 'advisory') {
    $failures[] = 'selected skill blocks a safe planning turn before execution';
}
if ($bindingMethod->invoke(null, true, true, ['execution_mode' => 'strict']) !== 'contract') {
    $failures[] = 'explicit strict policy no longer blocks execution';
}
foreach ($byKey as $key => $skill) {
    $binding = (array)($skill['agent_policy_json']['binding_policy'] ?? []);
    if (($binding['default_mode'] ?? '') !== 'advisory' || ($binding['execution_mode'] ?? '') !== 'strict_on_execute') {
        $failures[] = "builtin Skill does not default to advisory planning: {$key}";
    }

    $tools = (array)($skill['tool_policy_json']['allowed_tools'] ?? []);
    $mediaTools = array_values(array_intersect($tools, ['generate_image', 'generate_video', 'generate_music']));
    $output = (array)($skill['output_policy_json'] ?? []);
    if ($mediaTools !== [] && !in_array((string)($output['format'] ?? ''), ['media', 'media_and_canvas', 'json_canvas'], true)) {
        $failures[] = "media Skill has no media delivery contract: {$key}";
    }
    if ($mediaTools !== [] && empty($output['write_to_canvas'])) {
        $failures[] = "media Skill does not write its result to the canvas: {$key}";
    }

    $hardSlots = [];
    foreach ((array)($skill['required_slots_json'] ?? []) as $slot) {
        $slot = is_string($slot) ? ['key' => $slot] : (array)$slot;
        $slotKey = (string)($slot['key'] ?? '');
        $level = (string)($slot['required_level'] ?? 'soft');
        if ($slotKey !== '' && $level === 'hard') {
            $hardSlots[] = $slotKey;
        }
    }
    if (count($hardSlots) > 1 && !in_array($key, ['image_edit'], true)) {
        $failures[] = "Skill asks for too many hard slots before creating: {$key}";
    }
}

foreach (['ecommerce_image', 'music_generation'] as $key) {
    if (!in_array((string)($byKey[$key]['output_policy_json']['format'] ?? ''), ['media', 'media_and_canvas'], true)) {
        $failures[] = "legacy media Skill was not normalized: {$key}";
    }
}
foreach ([
    ['ecommerce_selling_point', 'selling_point'],
    ['ecommerce_mockup', 'scene'],
    ['logo_design', 'industry'],
    ['logo_design', 'brand_traits'],
    ['video_generation', 'motion'],
    ['youtube_thumbnail', 'headline'],
] as [$key, $slotKey]) {
    $slots = array_values(array_filter((array)($byKey[$key]['required_slots_json'] ?? []), static function ($slot) use ($slotKey): bool {
        return (string)((is_array($slot) ? $slot : [])['key'] ?? '') === $slotKey;
    }));
    if (($slots[0]['required_level'] ?? '') !== 'soft' && ($slots[0]['required_level'] ?? '') !== 'inferable') {
        $failures[] = "optional creative input remains hard: {$key}.{$slotKey}";
    }
}

$payloadMethod = new ReflectionMethod($skillClass, 'payload');
$payloadMethod->setAccessible(true);
$customMedia = $payloadMethod->invoke(null, [
    'name' => 'Custom product visual',
    'skill_key' => 'custom_product_visual',
    'skill_type' => 'agent_prompt',
    'required_slots_json' => [['key' => 'selling_point', 'type' => 'text']],
    'tool_policy_json' => ['allowed_tools' => ['generate_image']],
], true);
if (($customMedia['required_slots_json'][0]['required_level'] ?? '') !== 'soft'
    || !in_array('ask_user', (array)($customMedia['tool_policy_json']['allowed_tools'] ?? []), true)
    || ($customMedia['output_policy_json']['format'] ?? '') !== 'media') {
    $failures[] = 'tenant media Skill is not normalized for natural-language creation';
}
try {
    $payloadMethod->invoke(null, [
        'name' => 'Broken visual Skill',
        'skill_key' => 'broken_visual',
        'skill_type' => 'agent_prompt',
        'output_policy_json' => ['format' => 'media'],
        'tool_policy_json' => ['allowed_tools' => ['generate_text']],
    ], true);
    $failures[] = 'invalid media Skill configuration was accepted';
} catch (Throwable) {
    // A media output without a media tool must fail before it reaches a user.
}
if (in_array('create_short_drama_plan', $byKey['short_drama_preproduction']['tool_policy_json']['allowed_tools'] ?? [], true)
    || !in_array('canvas_mutation', $byKey['short_drama_preproduction']['tool_policy_json']['allowed_tools'] ?? [], true)) {
    $failures[] = 'short_drama_preproduction is not limited to canvas-native delivery';
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
