<?php
require __DIR__.'/lifecycle.php';
use app\common\service\app\AppDisplayConfigService as Display;
use app\common\service\app\aigc_copywriting\CopywritingBootstrap;
use think\facade\Db;

$base=dirname(__DIR__,2).'/app/apps/aigc_copywriting/';
$menus=json_decode(file_get_contents($base.'menus/tenant.json'),true)[0]['children'];
$permissions=json_decode(file_get_contents($base.'permissions/tenant.json'),true);
$apis=json_decode(file_get_contents($base.'api_schema.json'),true)['apis'];
foreach(['detail'=>'GET','setup'=>'POST'] as $action=>$method) {
    $path='app.aigc_copywriting.config/'.$action;
    check(in_array($path,array_column($menus,'perms'),true),'config menu permission exists');
    check(in_array($path,array_column($permissions,'api_path'),true),'config permission declaration exists');
    $api=array_values(array_filter($apis,fn($item)=>$item['api_path']===$path&&$item['scene']==='tenant_admin'));
    check(count($api)===1 && $api[0]['api_method']===$method && $api[0]['need_login']===1 && $api[0]['need_role_permission']===1,'config API requires tenant role authorization');
}
Db::startTrans();
try {
    $other=Display::detail($tenant+100000,'aigc_copywriting');
    $sibling=Display::detail($tenant,'aigc_model_wear');
    $data=Display::save($tenant,'aigc_copywriting',[
        'title'=>'测试文案配置','description'=>'介绍内容','cover_uri'=>'resource/image/copywriting/copy_rewrite.png',
        'virtual_use_count'=>'2.3万','sort'=>876,'status'=>0,'is_recommend'=>1
    ]);
    check($data['title']==='测试文案配置' && $data['sort']===876 && $data['status']===0 && $data['is_recommend']===1,'display settings round trip');
    check(str_ends_with($data['cover_url'],'/resource/image/copywriting/copy_rewrite.png'),'cover resolves to delivery URL');
    $public=Display::skillPreview($tenant,'aigc_copywriting');
    check($public['title']===$data['title'] && !isset($public['extra']) && !isset($public['tenant_id']),'public display is whitelisted');
    check(Display::map($tenant,['aigc_copywriting'])['aigc_copywriting']['status']===0,'tool directory consumes same display visibility');
    check(Display::detail($tenant+100000,'aigc_copywriting')===$other,'other tenant untouched');
    check(Display::detail($tenant,'aigc_model_wear')===$sibling,'sibling app untouched');
    Display::save($tenant,'aigc_copywriting',['cover_uri'=>'']);
    check(Display::detail($tenant,'aigc_copywriting')['cover_url']==='','cover clearing is persisted');
    CopywritingBootstrap::ensureTenant($tenant);
    $settings=Display::detail($tenant,'aigc_copywriting');
    CopywritingBootstrap::ensureTenant($tenant);
    check(Display::detail($tenant,'aigc_copywriting')===$settings,'runtime and tenant bootstrap preserve custom display settings');
    echo "copywriting display configuration PASS (rolled back; no model calls)\n";
} finally {Db::rollback();}
