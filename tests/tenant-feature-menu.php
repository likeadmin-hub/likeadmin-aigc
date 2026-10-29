<?php
require dirname(__DIR__).'/app/common/service/auth/TenantFeatureMenuService.php';
use app\common\service\auth\TenantFeatureMenuService as Menus;
function check($condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$rows = [
 ['id'=>3,'pid'=>2,'paths'=>'detail','component'=>'custom/tenant/detail'],
 ['id'=>2,'pid'=>1,'paths'=>'orders','component'=>'brand/order'],
 ['id'=>1,'pid'=>0,'paths'=>'brand','source_menu_key'=>'core_tenant_brand'],
 ['id'=>5,'pid'=>4,'paths'=>'settings','app_code'=>'open_platform'],
 ['id'=>4,'pid'=>0,'paths'=>'open-platform','app_code'=>'open_platform'],
 ['id'=>6,'pid'=>0,'paths'=>'workbench'],
];
$original=$rows;
check(array_column(Menus::filter($rows,['oem'=>false,'api'=>false]),'id') === [6], 'Denied features must remove entire subtrees independent of row order');
check(array_column(Menus::filter($rows,['oem'=>true,'api'=>false]),'id') === [3,2,1,6], 'OEM grant must preserve original role-filtered rows and ordering');
check(array_column(Menus::filter($rows,['oem'=>false,'api'=>true]),'id') === [5,4,6], 'API grant must remain independent of OEM authorization');
check(Menus::filter($rows,['oem'=>true,'api'=>true]) === $rows && $original === $rows, 'Grant filtering must not mutate or expand original role permissions');
check(Menus::filter([$rows[1]],['oem'=>false,'api'=>true]) === [], 'Direct OEM child role rows must not escape the grant gate');
check(Menus::filter([$rows[3]],['oem'=>true,'api'=>false]) === [], 'Direct API child role rows must not escape the grant gate');
check(Menus::filter($rows,[]) === [$rows[5]], 'Unknown grants must not expose restricted features');
echo "PASS tenant entitlement menu projection: independent grants, whole subtrees and role permissions preserved\n";
