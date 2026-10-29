<?php
/** Local isolated tables only: missing menus, tenant boundaries and repeat runs. */
require dirname(__DIR__) . '/vendor/autoload.php';
(new think\App())->initialize();
set_exception_handler(static function (Throwable $e): void { fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1); });
use think\facade\Db;
use app\common\service\auth\TenantAuthorizationMenus;

if (Db::query('SELECT DATABASE() db')[0]['db'] !== 'z_cn') throw new RuntimeException('Local fixture database required');
$prefix = config('database.connections.mysql.prefix');
$table = 'auth_menu_fixture_' . bin2hex(random_bytes(5));
$split = $table . '_split';
$assert = static function ($condition, $message): void { if (!$condition) throw new RuntimeException($message); };
$sync = new ReflectionMethod(TenantAuthorizationMenus::class, 'syncTable');
$sync->setAccessible(true);
$insert = static function ($table, $tenant, $pid, $type, $path, $component = '', $extra = []): int {
    return (int)Db::name($table)->insertGetId($extra + ['tenant_id'=>$tenant,'pid'=>$pid,'type'=>$type,'name'=>$path,'paths'=>$path,'component'=>$component,'source'=>'core']);
};
try {
    foreach ([$table, $split] as $name) {
        Db::execute("CREATE TABLE `{$prefix}{$name}` LIKE `{$prefix}tenant_system_menu`");
        $setting = $insert($name, 1, 0, 'M', 'setting');
        $permission = $insert($name, 1, $setting, 'M', 'permission');
        $organization = $insert($name, 1, $setting, 'M', 'organization');
        // The same paths in another tenant must never be used as parents.
        $insert($name, 2, 0, 'M', 'permission');
        $otherBefore = Db::name($name)->where('tenant_id', 2)->select()->toArray();
        $sync->invoke(null, 1, $name);
        $rows = Db::name($name)->where('tenant_id', 1)->select()->toArray();
        $assert(count($rows) === 27, 'Expected 5 pages and 19 action records');
        $parents = array_column($rows, 'pid', 'component');
        $assert((int)$parents['permission/admin/index'] === $permission, 'Admin parent mismatch');
        $assert((int)$parents['organization/department/index'] === $organization, 'Department parent mismatch');
        $sync->invoke(null, 1, $name);
        $assert($rows === Db::name($name)->where('tenant_id', 1)->select()->toArray(), 'Repeat sync changed IDs or state');
        $assert($otherBefore === Db::name($name)->where('tenant_id', 2)->select()->toArray(), 'Cross-tenant modification');
        $sync->invoke(null, 3, $name);
        $assert(Db::name($name)->where('tenant_id', 3)->count() === 0, 'Created an unconfigured administration root');

        // Explicit tenant choices must survive; no auto-grants are performed.
        $role = Db::name($name)->where('tenant_id', 1)->where('component', 'permission/role/index')->find();
        Db::name($name)->where('id', $role['id'])->update(['is_disable'=>1,'is_show'=>0]);
        $dept = Db::name($name)->where('tenant_id', 1)->where('component', 'organization/department/index')->find();
        Db::name($name)->where('id', $dept['id'])->update(['source'=>'tenant','name'=>'自定义部门','pid'=>$setting]);
        $before = Db::name($name)->select()->toArray();
        $grants = Db::name('tenant_system_role_menu')->order(['role_id','menu_id'])->select()->toArray();
        $sync->invoke(null, 1, $name);
        $assert($before === Db::name($name)->select()->toArray(), 'Custom or disabled menu changed');
        $assert($grants === Db::name('tenant_system_role_menu')->order(['role_id','menu_id'])->select()->toArray(), 'Role grants changed');
    }
    // Catalog pages and actions are already present in both fresh-install sources.
    $catalog = json_decode(file_get_contents(dirname(__DIR__).'/app/common/service/auth/tenant-authorization-menus.json'), true);
    foreach (['app/platformapi/db/tenantData.sql','public/install/db/like.sql'] as $file) {
        $seed = file_get_contents(dirname(__DIR__).'/'.$file);
        foreach ($catalog as $group) foreach ($group['children'] as $page) {
            $assert(str_contains($seed, "'".$page['component']."'"), 'Fresh install lacks '.$page['component']);
            foreach ($page['children'] ?? [] as $action) $assert(str_contains($seed, "'".$action['perms']."'"), 'Fresh install lacks '.$action['perms']);
        }
    }
    echo "PASS: missing five pages, actions, shared/split tables, tenant isolation, idempotency, custom/disabled settings, unchanged role grants, fresh seeds.\n";
} finally {
    foreach ([$split, $table] as $name) Db::execute("DROP TABLE IF EXISTS `{$prefix}{$name}`");
}
