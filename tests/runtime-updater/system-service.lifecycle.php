<?php
declare(strict_types=1);
/** Exercise real new-tenant provisioning and signup only against disposable MySQL. */
if (getenv('SYSTEM_SERVICE_DISPOSABLE') !== '1') throw new RuntimeException('Disposable fixture required');
require dirname(__DIR__,2) . '/vendor/autoload.php';
(new think\App())->initialize();
use think\facade\Db;
use app\common\enum\AdminTerminalEnum;
use app\platformapi\logic\tenant\TenantLogic;
use app\platformapi\logic\tenant\TenantAdminLogic;
use app\platformapi\logic\tenant\TenantSystemMenuLogic;
use app\platformapi\service\TenantCreatService;
use app\common\service\app\DefaultAppService;
use app\common\service\billing\PackageProvisionService;
use app\common\service\membership\MembershipService;
use app\api\logic\LoginLogic;
use app\common\model\user\User;
function lifecycleCheck(bool $value, string $label): void { if (!$value) throw new RuntimeException($label); }
lifecycleCheck(str_starts_with((string)config('database.connections.mysql.database'),'fixture_'), 'Wrong database');
foreach ([false,true] as $split) {
    request()->source=AdminTerminalEnum::PLATFORM;
    unset(request()->tenantId);
    $sn = $split ? 'acceptsplit' : 'acceptshared';
    $params = ['host_name'=>$sn,'tactics'=>$split?1:0,'domain_alias'=>$sn.'.fixture','name'=>'Acceptance fixture','avatar'=>'','tel'=>'','account'=>'fixtureadmin','password'=>'disposable-only'];
    $tenant = TenantLogic::add($params);
    $tenantId = (int)$tenant['id'];
    lifecycleCheck((int)\app\common\service\tenant\TenantDomainAliasService::findTenantByDomain($sn.'.fixture')['id']===$tenantId, 'Alias resolves another tenant');
    if ($split) {
        lifecycleCheck((int)$tenant['tactics']===1, 'Split table selection was not persisted');
        $creator = new TenantCreatService();
        lifecycleCheck($creator->createTenantTable($sn), 'Split tenant table creation failed');
        $roleId = (int)Db::name('tenant_system_role_'.$sn)->insertGetId(['tenant_id'=>$tenantId,'name'=>'fixture role','create_time'=>time()]);
        lifecycleCheck($creator->initializationTenantData($tenantId,$sn,$params), 'Split tenant bootstrap failed');
    } else {
        \app\tenantapi\logic\article\ArticleLogic::initialization($tenantId);
        $admin = TenantAdminLogic::initialization($tenantId,$sn,$params);
        \app\common\model\dept\TenantDept::initialization($tenantId,(int)$admin['id']);
        TenantSystemMenuLogic::initialization($tenantId);
        \app\platformapi\logic\setting\pay\PayConfigLogic::initialization($tenantId);
        \app\platformapi\logic\setting\pay\PayWayLogic::initialization($tenantId);
        \app\tenantapi\logic\notice\NoticeLogic::initialization($tenantId);
        \app\tenantapi\logic\decorate\DecorateDataLogic::initialization($tenantId);
    }
    DefaultAppService::syncTenantDefaults($tenantId,$sn,$split);
    PackageProvisionService::syncTenant($tenantId,$sn,$split);
    TenantLogic::syncCustomStorageMenu($tenantId,false);
    $menuTable = $split ? 'tenant_system_menu_' . $sn : 'tenant_system_menu';
    foreach (['ai_consumption/detail','decorate.template/export','decorate.template/import','decorate.data/sources'] as $permission)
        lifecycleCheck(Db::name($menuTable)->where(['tenant_id'=>$tenantId,'perms'=>$permission])->count() === 1, 'Missing tenant permission: ' . $permission);
    if ($split) {
        $prefix = (string)config('database.connections.mysql.prefix');
        $orphans = Db::query('SELECT c.id FROM `'.$prefix.$menuTable.'` c LEFT JOIN `'.$prefix.$menuTable.'` p ON p.id=c.pid AND p.tenant_id=c.tenant_id WHERE c.tenant_id=? AND c.pid>0 AND p.id IS NULL',[$tenantId]);
        lifecycleCheck($orphans===[], 'Fresh split menus have orphan parents');
        $grants = Db::name('tenant_system_role_menu_'.$sn)->where('role_id',$roleId)->column('menu_id');
        lifecycleCheck(count($grants)===2, 'Split membership permission grants did not reach the split role');
        foreach ($grants as $menuId) lifecycleCheck(Db::name($menuTable)->where(['id'=>$menuId,'tenant_id'=>$tenantId])->count()===1, 'Split role references another menu table');
        $fileId=(int)Db::name('tenant_file_'.$sn)->insertGetId(['tenant_id'=>$tenantId,'name'=>'fixture material','uri'=>'uploads/fixture.png','storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>'fixture.invalid']);
        lifecycleCheck(Db::name('tenant_file_'.$sn)->where('id',$fileId)->value('storage_scope')==='tenant', 'Split material storage metadata was lost');
    }
    lifecycleCheck(Db::name('membership_plan')->where(['tenant_id'=>$tenantId,'name'=>'免费会员'])->count() === 1, 'Free membership not provisioned');
    request()->source=AdminTerminalEnum::USER;
    request()->tenantId=$tenantId;
    $account = 'release_acceptance_user';
    lifecycleCheck(LoginLogic::register(['account'=>$account,'password'=>'disposable-only','channel'=>4]), 'Real signup failed: ' . LoginLogic::getError());
    $user = User::where('account',$account)->findOrEmpty();
    lifecycleCheck(!$user->isEmpty() && (int)$user['tenant_id']===$tenantId, 'Signup identity/tenant mismatch');
    $membership = MembershipService::status($tenantId,(int)$user['id']);
    lifecycleCheck($membership['is_member']===1 && $membership['membership_plan']==='免费会员', 'Signup missing free entitlement');
    MembershipService::grantDefaultFreeMembership($tenantId,(int)$user['id']);
    lifecycleCheck(Db::name('user_membership')->where(['tenant_id'=>$tenantId,'user_id'=>(int)$user['id']])->count()===1, 'Repeated entitlement grant duplicated membership');
    lifecycleCheck(!LoginLogic::register(['account'=>$account,'password'=>'disposable-only','channel'=>4]), 'Duplicate signup accepted');
    lifecycleCheck(User::where('account',$account)->count()===1, 'Duplicate signup changed identity rows');
    echo 'PASS real ' . ($split ? 'split' : 'shared') . " tenant bootstrap + signup: admin/menu/payment/decoration, alias/account isolation, free entitlement, menu graph, role links, material metadata and idempotent grant\n";
}
