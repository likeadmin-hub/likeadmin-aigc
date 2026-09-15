<?php
// Database integration checks. Fixtures always roll back and invoke no provider.
require dirname(__DIR__) . '/vendor/autoload.php';
(new \think\App())->initialize();

use app\common\service\app\aigc_short_drama\ShortDramaSkillService as Skills;
use app\common\service\app\aigc_short_drama\ShortDramaSkillRuntime as Runtime;
use think\facade\Db;

$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    $checks++;
};
$reject = static function (callable $call, string $message) use ($check): void {
    try { $call(); } catch (Exception $e) { $check(true, $message); return; }
    $check(false, $message);
};
$tenant = random_int(1500000000, 1900000000);
Db::startTrans();
try {
    $check(Skills::featured($tenant)['lists'] === [], 'New tenant catalogue is empty');
    // Material library records use nullable SoftDelete timestamps. A selected
    // image/video cover must therefore be accepted when delete_time is NULL.
    $coverId = (int)Db::name('tenant_file')->insertGetId([
        'tenant_id' => $tenant, 'cid' => 0, 'type' => 20, 'name' => 'skill-cover.mp4', 'uri' => 'uploads/test/skill-cover.mp4',
        'storage_scope' => 'tenant', 'storage_engine' => 'local', 'storage_domain' => '', 'source' => 0,
        'create_time' => time(), 'update_time' => time(), 'delete_time' => null,
    ]);
    $payloadMethod = new ReflectionMethod(Skills::class, 'payload');
    $payloadMethod->setAccessible(true);
    $coverPayload = $payloadMethod->invoke(null, $tenant, [
        'name' => 'Cover validation', 'skill_key' => 'cover_validation', 'invocation_rule' => '封面验证',
        'cover_asset_id' => $coverId, 'cover_type' => 'video', 'definition' => [],
    ], true);
    $check((int)$coverPayload['cover_asset_id'] === $coverId && $coverPayload['cover_type'] === 'video', 'Tenant material cover accepts nullable delete time');
    $input = ['name' => 'Original story', 'skill_key' => 'original_story', 'invocation_rule' => '悬疑短片', 'status' => 1,
        'definition' => ['stages' => array_fill_keys(array_keys(Runtime::STAGES), '原创创作规则'), 'keywords' => ['悬疑']]];
    $draft = Skills::create($tenant, 1, $input);
    $id = $draft['id'];
    $check(Skills::featured($tenant)['lists'] === [], 'Draft is not public');
    $reject(static fn() => Skills::resolveForTask($tenant, ['skill_id' => $id]), 'Draft cannot execute');
    $reject(static fn() => Skills::detail($tenant + 1, $id), 'Cross-tenant read rejected');
    $reject(static fn() => Skills::update($tenant + 1, 1, $input + ['id' => $id]), 'Cross-tenant edit rejected');
    Skills::release($tenant, 1, ['id' => $id, 'version' => 1]);
    $frozen = Skills::resolveForTask($tenant, ['skill_id' => $id, 'skill_version' => 1, 'skill_source' => 'manual']);
    $bytes = Db::name('aigc_short_drama_skill_version')->where(['tenant_id' => $tenant, 'skill_id' => $id, 'version' => 1])->value('snapshot_json');
    Skills::setDefault($tenant, 7, $id, true);
    $edited = Skills::update($tenant, 1, array_replace($input, ['id' => $id, 'version' => 1, 'name' => 'Edited story']));
    $check($edited['version'] === 2, 'Edit allocates a new version');
    $check(Skills::detail($tenant, $id, true)['name'] === 'Original story', 'Published detail ignores draft edits');
    $check(Skills::mine($tenant, 7)['defaults'][0]['name'] === 'Original story', 'Defaults ignore draft edits');
    $reject(static fn() => Skills::update($tenant, 1, $input + ['id' => $id, 'version' => 1]), 'Concurrent stale edit rejected');
    Skills::release($tenant, 1, ['id' => $id, 'version' => 2]);
    $check(Skills::detail($tenant, $id, true)['name'] === 'Edited story', 'New release visible');
    $check($frozen['name'] === 'Original story' && $frozen['version'] === 1, 'Existing snapshot unchanged');
    $check($bytes === Db::name('aigc_short_drama_skill_version')->where(['tenant_id' => $tenant, 'skill_id' => $id, 'version' => 1])->value('snapshot_json'), 'Historical bytes are immutable');
    $reject(static fn() => Skills::resolveForTask($tenant, ['skill_id' => $id, 'skill_version' => 1]), 'Stale selection requires reconfirmation');
    Skills::rollback($tenant, 1, ['id' => $id, 'version' => 1]);
    $check(Skills::detail($tenant, $id, true)['version'] === 2, 'Rollback draft does not change publication');
    Skills::recordUsage($tenant, 7, 5, 'skill_test_task', $frozen);
    Skills::recordUsage($tenant, 7, 5, 'skill_test_task', $frozen);
    $check(count(Skills::history($tenant, 7)['lists']) === 1, 'Usage writes are idempotent');
    $check(Skills::history($tenant, 8)['lists'] === [], 'Usage is user-scoped');
    $check(count(Skills::recommend($tenant, ['prompt' => '写一个悬疑故事'])['lists']) === 1, 'Rule recommendation matches');
    Skills::release($tenant, 1, ['id' => $id, 'release_status' => 'paused']);
    $reject(static fn() => Skills::resolveForTask($tenant, ['skill_id' => $id]), 'Paused skill cannot execute');
    Skills::setDefault($tenant, 7, $id, false);
    $check(Skills::mine($tenant, 7)['defaults'] === [], 'Paused default can be removed');
    Skills::delete($tenant, $id);
    $check(count(Skills::history($tenant, 7)['lists']) === 1, 'Deletion preserves history');
    $check(count(Runtime::STAGES) === 5 && Runtime::instruction($frozen, 'video_edit') === '', 'No editing stage');
    $confirmSnapshot = $frozen + [];
    $confirmSnapshot['execution_policy'] = ['require_confirmation' => ['script', 'assets', 'storyboard']];
    $confirmTaskId = 'skill_confirm_' . $tenant;
    Db::name('aigc_short_drama_script_task')->insert(['tenant_id' => $tenant, 'user_id' => 7, 'project_id' => 5, 'task_id' => $confirmTaskId, 'status' => 'success', 'skill_id' => $id,
        'skill_snapshot_json' => json_encode($confirmSnapshot), 'request_json' => '{}']);
    $reject(static fn() => Runtime::assertConfirmed($tenant, 7, 5, $confirmTaskId), 'Media is gated before confirmations');
    $reject(static fn() => Runtime::confirm($tenant, 7, $confirmTaskId, 'storyboard'), 'Confirmation order is enforced');
    $reject(static fn() => Runtime::confirm($tenant, 8, $confirmTaskId, 'script'), 'Other user cannot confirm');
    $reject(static fn() => Runtime::confirm($tenant + 1, 7, $confirmTaskId, 'script'), 'Other tenant cannot confirm');
    foreach (['script', 'assets', 'storyboard'] as $node) Runtime::confirm($tenant, 7, $confirmTaskId, $node);
    Runtime::assertConfirmed($tenant, 7, 5, $confirmTaskId);
    $check(true, 'Media allowed after all confirmations');
    $before = Runtime::confirm($tenant, 7, $confirmTaskId, 'script');
    $check($before === Runtime::confirm($tenant, 7, $confirmTaskId, 'script'), 'Confirmation is idempotent');
    $policy = ['model_policy' => ['allowed_models' => ['allowed'], 'allowed_ratios' => ['9:16'], 'max_duration' => 10, 'allow_audio' => false]];
    $reject(static fn() => Runtime::validateMedia($policy, 'shot_video', ['model_code' => 'denied']), 'Model policy is enforced');
    $reject(static fn() => Runtime::validateMedia($policy, 'shot_video', ['model_code' => 'allowed', 'ratio' => '16:9']), 'Ratio policy is enforced');
    $reject(static fn() => Runtime::validateMedia($policy, 'shot_video', ['model_code' => 'allowed', 'ratio' => '9:16', 'duration' => 20]), 'Duration policy is enforced');
    $reject(static fn() => Runtime::validateMedia($policy, 'bgm_audio', ['model_code' => 'allowed', 'ratio' => '9:16']), 'Audio policy is enforced');
    Runtime::validateMedia([], 'shot_video', []);
    Runtime::validateMedia($policy, 'export_video', []);
    $check(true, 'No-skill and existing export behavior is preserved');
    $lookup = new ReflectionMethod(\app\common\service\app\aigc_short_drama\AigcShortDramaService::class, 'skillSnapshotForGeneration');
    $lookup->setAccessible(true);
    $check($lookup->invoke(null, $tenant, ['_skill_snapshot' => $frozen]) === [], 'Client-supplied snapshot is ignored');
    $check($lookup->invoke(null, $tenant, ['project_id' => 5, '_skill_origin_task_id' => $confirmTaskId])['version'] === 1, 'Downstream task inherits exact stored version');
    $check($lookup->invoke(null, $tenant + 1, ['project_id' => 5, '_skill_origin_task_id' => $confirmTaskId]) === [], 'Cross-tenant snapshot lookup denied');
    echo "PASS: {$checks} Skill lifecycle/isolation/snapshot checks\n";
} finally { Db::rollback(); }
