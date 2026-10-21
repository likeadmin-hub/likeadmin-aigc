<?php
// Local-only, rollback fixture test. It never publishes a saved tenant template.
$root = dirname(__DIR__);
require $root . '/runtime-loader.php';
(new AigcRuntimeApp($root))->initialize();
use app\common\service\decorate\DecorateTemplateService as Templates;
use app\common\service\decorate\DecorateDataSourceService as Sources;
use app\common\service\decorate\DecorateWorkSourceService as Works;
use app\common\model\user\User;
use think\facade\Db;
$config = config('database.connections.mysql');
if (($config['hostname'] ?? '') !== '127.0.0.1' || ($config['database'] ?? '') !== 'z_cn') throw new RuntimeException('Local development database required');
$checks = 0;
function check($condition, $message) { global $checks; if (!$condition) throw new RuntimeException($message); ++$checks; }
$validate = new ReflectionMethod(Templates::class, 'validatePagePayload');$validate->setAccessible(true);
foreach ([-481, -1] as $value) {
    $invalid = ['name' => 'spacer', 'content' => ['height' => 20], 'styles' => [$value === -481 ? 'margin_top' : 'padding_top' => $value]];
    try { $validate->invoke(null, json_encode([$invalid]), '[]'); throw new LogicException('invalid numeric style accepted'); }
    catch (RuntimeException $e) { ++$checks; }
}
$before = hash('sha256', json_encode(Templates::detail(1, 46, 'mobile')));
$activeTemplateIds = Db::name('decorate_template')->where('tenant_id', 1)->where('is_active', 1)->column('id');
Db::startTrans();
try {
    $template = Templates::add(1, ['name' => 'neutral decoration rollback fixture']);
    $detail = Templates::detail(1, (int)$template['id'], 'mobile');
    check((int)$detail['template']['is_active'] === 0, 'new template is draft');
    check(count($detail['settings']['mobile_tabbar']['list']) === 4, 'four default bottom tabs');
    Db::name('decorate_template')->where('tenant_id', 1)->update(['is_active' => 0]);
    Db::name('decorate_template')->where('id', $template['id'])->update([
        'is_active' => 1,
        'published_settings' => json_encode(['mobile_style' => ['themeColor1' => '#80f0c4', 'themeColor2' => '#48d5e8', 'custom_field' => 'retained']]),
    ]);
    $theme = Templates::activeMobileStyle(1);
    check($theme['buttonColor'] === 'white' && $theme['topTextColor'] === 'white', 'partial theme lacks required text colors');
    check($theme['themeColor1'] === '#80f0c4' && $theme['navigationBarColor'] === '#80f0c4' && $theme['custom_field'] === 'retained', 'theme defaults changed configured colors/extensions');
    Db::name('decorate_template')->where('id', $template['id'])->update(['is_active' => 0]);
    Db::name('decorate_template')->whereIn('id', $activeTemplateIds)->update(['is_active' => 1]);
    check(count(array_filter($detail['pages'], fn($page) => str_starts_with($page['page_code'], 'logic_ai_'))) === 0, 'new template contains a tenant example');
    Templates::ensureDefaultTemplate(1, true);
    check(hash('sha256', json_encode(Templates::detail(1, 46, 'mobile'))) === $before, 'existing tenant template changed');
    // Reproduce the new-tenant SQL seed path: old legacy rows already exist.
    $fixtureTenant = 214700001;
    check(Db::name('decorate_template')->where('tenant_id', $fixtureTenant)->count() === 0, 'fixture id already used');
    Db::name('decorate_page')->insert(['tenant_id' => $fixtureTenant, 'template_id' => 0, 'type' => 1, 'name' => 'initial SQL seed', 'data' => '[]', 'meta' => '[]', 'create_time' => time(), 'update_time' => time()]);
    Templates::ensureDefaultTemplate($fixtureTenant, true);
    $fresh = Templates::detail($fixtureTenant, 0, 'mobile');
    check(!str_contains(json_encode($fresh), 'logic-ai') && !str_contains(json_encode($fresh), 'logic_ai_'), 'new tenant automatically received example template');
    check(count(array_filter($fresh['pages'], fn($page) => in_array($page['page_code'], ['home','user','service'], true))) === 3, 'neutral system roots incomplete');
    $workUser = 214700002;
    $taskId = Db::name('aigc_image_task')->insertGetId(['tenant_id'=>1,'user_id'=>$workUser,'status'=>'success','create_time'=>time(),'update_time'=>time(),'delete_time'=>0]);
    $baseResult = ['tenant_id'=>1,'user_id'=>$workUser,'task_id'=>$taskId,'image_uri'=>'/resource/image/common/menu_generator.png','storage_scope'=>'platform','storage_engine'=>'local','create_time'=>time(),'delete_time'=>0];
    Db::name('aigc_image_result')->insert($baseResult);
    Db::name('aigc_image_result')->insert(array_replace($baseResult, ['delete_time'=>time()]));
    Db::name('aigc_image_result')->insert(array_replace($baseResult, ['tenant_id'=>2]));
    Db::name('aigc_image_result')->insert(array_replace($baseResult, ['user_id'=>$workUser+1]));
    $visibleWorks = Works::lists(1, $workUser, 12);
    check(count($visibleWorks) === 1, 'completed work ownership/delete filtering failed');
    check($visibleWorks[0]['category'] === 'aigc_image' && $visibleWorks[0]['image'] !== '', 'finished image category/media missing');
    Db::name('aigc_image_task')->where('id',$taskId)->update(['status'=>'running']);
    check(Works::lists(1, $workUser, 12) === [], 'running task shown as completed work');
    Db::name('aigc_image_task')->where('id',$taskId)->update(['status'=>'success','delete_time'=>time()]);
    check(Works::lists(1, $workUser, 12) === [], 'deleted task shown as work');
    check(Works::lists(1, 0) === [] && Works::lists(0, 1) === [], 'guest/platform private works leaked');
    check(Sources::resolve('user_profile', [], 1, ['user_id' => 0]) === [], 'guest profile leaked');
    $userId = (int)User::where('tenant_id', 1)->value('id');
    if ($userId > 0) {
        check(Sources::resolve('user_profile', [], $fixtureTenant, ['user_id' => $userId]) === [], 'cross-tenant profile leaked');
        $profile = Sources::resolve('user_profile', [], 1, ['user_id' => $userId]);
        check(isset($profile[0]['quota']), 'real profile missing');
        $works = Works::lists(1, $userId, 12);
        foreach ($works as $work) {
            [$code, $id] = explode(':', $work['id']);
            check(Db::name($code . '_result')->where(['id' => (int)$id, 'tenant_id' => 1, 'user_id' => $userId, 'delete_time' => 0])->count() === 1, 'work owner mismatch');
            check($work['media_url'] !== '' && $work['link']['path'] !== '', 'work media/link missing');
        }
    }
} finally { Db::rollback(); }
check(hash('sha256', json_encode(Templates::detail(1,46,'mobile'))) === $before, 'fixture rollback changed draft');
echo "PASS neutral decoration defaults/private source: $checks checks; database fixtures rolled back\n";
