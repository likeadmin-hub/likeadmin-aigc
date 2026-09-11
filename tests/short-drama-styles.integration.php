<?php
// Run with the application's configured MySQL database. All fixtures roll back.
require dirname(__DIR__) . '/vendor/autoload.php';
(new \think\App())->initialize();

use app\common\service\app\aigc_short_drama\AigcShortDramaService as Styles;
use think\facade\Db;

$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$options = new ReflectionMethod(Styles::class, 'styleOptions');
$options->setAccessible(true);
$list = static fn(int $tenantId): array => $options->invoke(null, $tenantId);
$names = static fn(array $rows): array => array_column($rows, 'name');
$tenantId = random_int(1500000000, 1900000000);
$check(Db::name('aigc_short_drama_style')->whereIn('tenant_id', [$tenantId, $tenantId + 1])->count() === 0, 'Fixture tenant IDs must be unused');
$marker = 'style-delete-test-' . bin2hex(random_bytes(6));

Db::startTrans();
try {
    $public = Styles::saveAdminStyle(0, ['name' => $marker, 'sort' => 999999]);
    $inherited = array_values(array_filter($list($tenantId), static fn(array $row): bool => $row['name'] === $marker));
    $check(count($inherited) === 1, 'New tenant receives public defaults');
    $id = (int)$inherited[0]['id'];
    $check($id !== (int)$public['id'], 'Tenant default has its own record');
    $beforeRename = $list($tenantId);
    $renamed = Styles::saveAdminStyle($tenantId, ['id' => $id, 'name' => $marker . '-renamed', 'sort' => 999999]);
    $afterRename = $list($tenantId);
    $check((int)$renamed['id'] === $id, 'Renaming retains the existing ID');
    $check(count($afterRename) === count($beforeRename), 'Renaming does not add a frontend option');
    $check(!in_array($marker, $names($afterRename), true), 'Old default name is replaced, not merged from public');
    $check(in_array($marker . '-renamed', $names($afterRename), true), 'New name appears in the picker');
    $check(Styles::adminStyleLists($tenantId, ['keyword' => $marker . '-renamed'])['count'] === 1, 'Admin has exactly one renamed row');
    Styles::saveAdminStyle($tenantId, ['id' => $id, 'name' => $marker, 'sort' => 999999]);
    Styles::setAdminStyleStatus($tenantId, $id, 0);
    $check(!in_array($marker, $names($list($tenantId)), true), 'Disabled default must not fall back to public');
    Styles::setAdminStyleStatus($tenantId, $id, 1);
    Styles::deleteAdminStyle($tenantId, $id);
    $check(!in_array($marker, $names($list($tenantId)), true), 'Deleted default must not fall back to public');
    $check(in_array($marker, $names($list(0)), true), 'Tenant deletion leaves public source intact');

    $custom = Styles::saveAdminStyle($tenantId, ['name' => $marker . '-custom', 'sort' => 999999]);
    $check(in_array($custom['name'], $names($list($tenantId)), true), 'Custom style is selectable');
    $check(in_array($marker, $names($list($tenantId + 1)), true), 'Another tenant retains its defaults');
    try {
        Styles::deleteAdminStyle($tenantId + 1, (int)$custom['id']);
        throw new RuntimeException('Cross-tenant deletion was accepted');
    } catch (Exception $error) {
        $check($error->getMessage() === '画风不存在', 'Cross-tenant deletion is rejected');
    }
    $check(in_array($custom['name'], $names($list($tenantId)), true), 'Rejected deletion leaves owner style intact');
    Styles::deleteAdminStyle($tenantId, (int)$custom['id']);
    $check(!in_array($custom['name'], $names($list($tenantId)), true), 'Deleted custom style is hidden');

    foreach (Db::name('aigc_short_drama_style')->where(['tenant_id' => $tenantId, 'delete_time' => 0])->column('id') as $remainingId) {
        Styles::deleteAdminStyle($tenantId, (int)$remainingId);
    }
    $check($list($tenantId) === [], 'Deleting all styles leaves an empty picker');
    $check(Styles::adminStyleLists($tenantId)['count'] === 0, 'Admin refresh does not reseed deleted styles');
    $check($list($tenantId) === [], 'Repeated frontend refresh remains empty');
    Styles::deleteAdminStyle(0, (int)$public['id']);
    $check(!in_array($marker, $names($list(0)), true), 'Public default can also be deleted');
    echo "PASS: rename preserves ID/count, default/custom deletion, disabling, empty library, repeated reads, tenant isolation\n";
} finally {
    Db::rollback();
}
