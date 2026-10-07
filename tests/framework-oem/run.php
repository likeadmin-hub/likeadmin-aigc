<?php
require dirname(__DIR__, 2).'/app/common/service/OemBrandService.php';
use app\common\service\OemBrandService as B;
$n=0;
function check($ok,$name){global $n; if(!$ok)throw new RuntimeException($name);$n++;}
$root=sys_get_temp_dir().'/oem-brand-test-'.bin2hex(random_bytes(5));mkdir($root.'/oem',0755,true);
check(B::read($root)===[], 'ordinary installation unchanged');
$b=['schema_version'=>1,'name'=>"测试'品牌",'slug'=>'test-ai','logo'=>'oem-assets/v1/logo.png','favicon'=>'oem-assets/v1/favicon.png','description'=>'测试平台'];
file_put_contents($root.'/oem/brand.json',json_encode($b));
check(B::read($root)['name']===$b['name'],'reads embedded brand');
check(B::defaultValue('platform','name',$root)===$b['name'],'platform default');
check(B::defaultValue('website','pc_logo',$root)===$b['logo'],'local asset');
check(B::defaultValue('website','unknown',$root)===null,'unknown field untouched');
try {B::validate(array_merge($b,['logo'=>'../secret']));throw new RuntimeException('unsafe path accepted');}catch(InvalidArgumentException $e){$n++;}
try {B::validate(array_merge($b,['schema_version'=>2]));throw new RuntimeException('future schema accepted');}catch(InvalidArgumentException $e){$n++;}
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE la_config (id INTEGER PRIMARY KEY,type TEXT,name TEXT,value TEXT,create_time INTEGER,update_time INTEGER)');
$db->exec('CREATE TABLE la_tenant_config (id INTEGER PRIMARY KEY,tenant_id INTEGER,type TEXT,name TEXT,value TEXT,create_time INTEGER,update_time INTEGER)');
B::initialize($db,'la_',null,$root);
check($db->query("SELECT value FROM la_config WHERE type='platform' AND name='name'")->fetchColumn()===$b['name'],'parameterized new install');
B::initialize($db,'la_',7,$root);
check($db->query("SELECT value FROM la_tenant_config WHERE tenant_id=7 AND type='tenant' AND name='name'")->fetchColumn()===$b['name'],'new tenant');
$db->exec("UPDATE la_config SET value='客户修改' WHERE type='platform' AND name='name'");
B::initialize($db,'la_',null,$root,false);
check($db->query("SELECT value FROM la_config WHERE type='platform' AND name='name'")->fetchColumn()==='客户修改','preserves saved customization');
unlink($root.'/oem/brand.json');rmdir($root.'/oem');rmdir($root);
echo "PASS $n brand assertions\n";
