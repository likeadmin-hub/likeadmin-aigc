<?php
// Local-only, rollback fixture test. It never publishes a saved tenant template.
$root = dirname(__DIR__);
require $root . '/runtime-loader.php';
(new AigcRuntimeApp($root))->initialize();
use app\common\service\decorate\DecorateTemplateService as Templates;
use app\common\service\decorate\DecorateDataSourceService as Sources;
use app\common\service\decorate\DecorateWorkSourceService as Works;
use app\common\service\decorate\MobilePresetService;
use app\common\model\user\User;
use think\facade\Db;
$config = config('database.connections.mysql');
if (($config['hostname'] ?? '') !== '127.0.0.1' || ($config['database'] ?? '') !== 'z_cn') throw new RuntimeException('Local development database required');
$checks = 0;
function check($condition, $message) { global $checks; if (!$condition) throw new RuntimeException($message); ++$checks; }
$preset = MobilePresetService::logicAi();
check(array_column($preset['pages'], 'code') === ['home', 'logic_ai_inspiration', 'logic_ai_works', 'user'], 'four independent page roots');
$validate = new ReflectionMethod(Templates::class, 'validatePagePayload');$validate->setAccessible(true);
foreach ($preset['pages'] as $page) {
    $validate->invoke(null, json_encode($page['data']), json_encode($page['meta']));
    ++$checks;
}
foreach ([-481, -1] as $value) {
    $invalid = ['name' => 'spacer', 'content' => ['height' => 20], 'styles' => [$value === -481 ? 'margin_top' : 'padding_top' => $value]];
    try { $validate->invoke(null, json_encode([$invalid]), '[]'); throw new LogicException('invalid numeric style accepted'); }
    catch (RuntimeException $e) { ++$checks; }
}
$walk = function($value) use (&$walk, $root) {
    if (is_array($value)) { foreach($value as $v) $walk($v); return; }
    if (is_string($value) && str_starts_with($value, '/resource/')) check(is_file($root . '/public' . $value), 'shipped asset missing: ' . $value);
};$walk($preset);
$before = hash('sha256', json_encode(Templates::detail(1, 46, 'mobile')));
Db::startTrans();
try {
    $template = Templates::add(1, ['name' => 'logic-ai rollback fixture']);
    $detail = Templates::detail(1, (int)$template['id'], 'mobile');
    check((int)$detail['template']['is_active'] === 0, 'new template is draft');
    check(count($detail['settings']['mobile_tabbar']['list']) === 4, 'four default bottom tabs');
    foreach($preset['pages'] as $definition) {
        $found = array_values(array_filter($detail['pages'], fn($p) => $p['page_code'] === $definition['code']));
        check(count($found) === 1, 'default root missing/duplicated');
        check(json_decode($found[0]['draft_data'], true) === $definition['data'], 'editable preset payload changed');
    }
    Templates::ensureDefaultTemplate(1, true);
    check(hash('sha256', json_encode(Templates::detail(1, 46, 'mobile'))) === $before, 'existing tenant template changed');
    // Reproduce the new-tenant SQL seed path: old legacy rows already exist.
    $fixtureTenant = 214700001;
    check(Db::name('decorate_template')->where('tenant_id', $fixtureTenant)->count() === 0, 'fixture id already used');
    Db::name('decorate_page')->insert(['tenant_id' => $fixtureTenant, 'template_id' => 0, 'type' => 1, 'name' => 'initial SQL seed', 'data' => '[]', 'meta' => '[]']);
    Templates::ensureDefaultTemplate($fixtureTenant, true);
    $fresh = Templates::detail($fixtureTenant, 0, 'mobile');
    check($fresh['settings']['mobile_tabbar']['style']['icon_atlas'] === '/resource/image/decoration/logic-ai/tab-icons.png', 'SQL seed blocked new-tenant preset');
    check(count(array_filter($fresh['pages'], fn($p) => in_array($p['page_code'], ['home','user','logic_ai_inspiration','logic_ai_works']))) === 4, 'fresh tenant roots incomplete');
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
echo "PASS logic-ai mobile preset/private source: $checks checks; database fixtures rolled back\n";
