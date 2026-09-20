<?php
declare(strict_types=1);
require dirname(__DIR__, 4) . '/vendor/autoload.php';

use app\common\service\app\aigc_short_drama\ShortDramaCanvasService;

$apply = new ReflectionMethod(ShortDramaCanvasService::class, 'applyComposerSkill');
$apply->setAccessible(true);
$payload = ['prompt' => '创作场景', 'content' => '创作场景', 'channel' => 'image-a', 'ratio' => '16:9'];
$skill = ['id' => 7, 'version' => 2, 'definition' => ['stages' => ['media_generation' => '使用暖色调', 'prompt_writing' => '保留人物特征'], 'required_slots' => [['key' => 'brand', 'label' => '品牌']]], 'model_policy' => ['image_models' => ['image-a']]];
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$result = $apply->invoke(null, 'image', $payload, ['skill_inputs' => ['brand' => '测试品牌']], $skill);
$assert(str_contains($result['prompt'], '测试品牌') && str_contains($result['prompt'], '使用暖色调'), 'Skill instructions or required input missing');
$assert($result['content'] === $result['prompt'] && $result['skill_snapshot']['version'] === 2, 'Snapshot and provider content must agree');
$assert($result['channel'] === 'image-a' && $result['ratio'] === '16:9', 'Skill must not replace generation parameters');
foreach ([['params' => [], 'payload' => $payload], ['params' => ['skill_inputs' => ['brand' => '测试']], 'payload' => array_replace($payload, ['channel' => 'forbidden'])]] as $case) {
    $rejected = false;
    try { $apply->invoke(null, 'image', $case['payload'], $case['params'], $skill); } catch (Exception $e) { $rejected = true; }
    $assert($rejected, 'Missing slots and forbidden models must be rejected before provider submission');
}
echo "canvas composer Skill validation passed\n";
