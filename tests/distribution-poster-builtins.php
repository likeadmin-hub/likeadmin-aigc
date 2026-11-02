<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
use app\common\service\distribution\BuiltinPosterService as Builtin;
use app\common\service\distribution\PosterLayout;

function check(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); echo 'PASS '.$message.PHP_EOL; }
foreach (array_keys(Builtin::CATALOG) as $key) {
    $layout=Builtin::layout($key,1); $layers=array_column($layout['layers'],null,'id');
    check($layers['code']['y']===.815 && $layers['avatar']['y']===.845 && $layers['nickname']['y']===.855 && $layers['attribution']['y']===.93 && $layers['attribution']['text']==='邀你一起，开启 AI 创作','footer positions and invitation copy match '.$key);
    if (in_array($key,['playground','odyssey'],true)) check(count($layers)===4,'complete marketing bitmap has footer only '.$key);
    else {
        check($layers['eyebrow']['text']==='AI 一站式创作平台' && $layers['cta']['text']==='扫码，把灵感变成作品' && $layers['cta']['y']===.755,'editable marketing layers are present '.$key);
        check($layers['title']['text']===($key==='nocturne'?'让想象，成为作品。':"给每个灵感\n一个漂亮的开始"),'marketing headline is exact '.$key);
    }
}
$assetRoot=sys_get_temp_dir().'/poster-builtin-fixtures-'.bin2hex(random_bytes(6));
$assetDir=$assetRoot.'/public/resource/distribution-posters'; mkdir($assetDir,0700,true);
foreach (array_keys(Builtin::CATALOG) as $key) file_put_contents($assetDir.'/'.$key.'.png','fixture');
register_shutdown_function(function()use($assetRoot,$assetDir){foreach(glob($assetDir.'/*.png') as $file)unlink($file);rmdir($assetDir);rmdir(dirname($assetDir));rmdir(dirname($assetDir,2));rmdir($assetRoot);});
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE custom_tenant (id INTEGER PRIMARY KEY, tactics INTEGER, sn TEXT)');
$db->exec("INSERT INTO custom_tenant VALUES (1,0,'one'),(2,1,'two'),(3,0,'three')");
$receipt = '(id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,cid INTEGER,source_id INTEGER,source INTEGER,type INTEGER,name TEXT,uri TEXT,storage_scope TEXT,storage_engine TEXT,storage_domain TEXT,create_time INTEGER,update_time INTEGER,delete_time INTEGER)';
$db->exec('CREATE TABLE custom_tenant_file '.$receipt);
$db->exec('CREATE TABLE custom_tenant_file_two '.$receipt);
$db->exec('CREATE TABLE custom_distribution_poster_template (id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,builtin_key TEXT DEFAULT NULL,name TEXT,sort INTEGER,status INTEGER,revision INTEGER,published_version INTEGER,draft_json TEXT,create_time INTEGER,update_time INTEGER,delete_time INTEGER DEFAULT 0,UNIQUE(tenant_id,builtin_key))');
$db->exec('CREATE TABLE custom_distribution_poster_version (id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,template_id INTEGER,revision INTEGER,name TEXT,layout_json TEXT,admin_id INTEGER,create_time INTEGER,UNIQUE(template_id,revision))');
Builtin::ensure(1,$db,'custom_',$assetRoot);
$rows=$db->query('SELECT * FROM custom_distribution_poster_template WHERE tenant_id=1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
check(count($rows)===4,'first access installs four templates');
foreach ($rows as $row) {
    $layout=json_decode($row['draft_json'],true,512,JSON_THROW_ON_ERROR); PosterLayout::validate($layout);
    check((int)$row['status']===1 && (int)$row['published_version']>0,'builtin '.$row['builtin_key'].' is published with valid geometry');
    $version=$db->query('SELECT layout_json FROM custom_distribution_poster_version WHERE id='.(int)$row['published_version'])->fetchColumn();
    check($version===$row['draft_json'],'published layout matches initial draft');
}
$files=$db->query('SELECT * FROM custom_tenant_file')->fetchAll(PDO::FETCH_ASSOC);
check(count($files)===4 && $files[0]['storage_scope']==='platform' && $files[0]['storage_engine']==='local' && $files[0]['storage_domain']==='' && (int)$files[0]['source']===0 && (int)$files[0]['type']===10,'tenant receives local platform image receipts');
$db->exec("UPDATE custom_distribution_poster_template SET status=0 WHERE builtin_key='nocturne'");
$db->exec("UPDATE custom_distribution_poster_template SET name='管理员修改',draft_json='{}',revision=8 WHERE builtin_key='atelier'");
$db->exec("UPDATE custom_distribution_poster_template SET delete_time=123,status=0 WHERE builtin_key='playground'");
$db->exec("INSERT INTO custom_distribution_poster_template (tenant_id,name,builtin_key) VALUES (1,'自定义一',NULL),(1,'自定义二',NULL)");
$before=$db->query('SELECT * FROM custom_distribution_poster_template ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
Builtin::ensure(1,$db,'custom_',$assetRoot);
check($before===$db->query('SELECT * FROM custom_distribution_poster_template ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),'repeat seed preserves custom edits disabled and deleted templates');
check((int)$db->query('SELECT COUNT(*) FROM custom_distribution_poster_version')->fetchColumn()===4 && (int)$db->query('SELECT COUNT(*) FROM custom_tenant_file')->fetchColumn()===4,'repeat seed creates no new versions or receipts');
Builtin::seedAll($db,'custom_',$assetRoot);
check((int)$db->query('SELECT COUNT(*) FROM custom_distribution_poster_template WHERE tenant_id=2')->fetchColumn()===4 && (int)$db->query('SELECT COUNT(*) FROM custom_distribution_poster_template WHERE tenant_id=3')->fetchColumn()===4,'upgrade seed covers all tenants');
check((int)$db->query('SELECT COUNT(*) FROM custom_tenant_file_two WHERE tenant_id=2')->fetchColumn()===4 && (int)$db->query('SELECT COUNT(*) FROM custom_tenant_file WHERE tenant_id=2')->fetchColumn()===0,'split tenant receipt uses its real file table');
$db->exec("INSERT INTO custom_tenant VALUES (4,0,'four')");
$db->exec("CREATE TRIGGER fail_poster_version BEFORE INSERT ON custom_distribution_poster_version WHEN NEW.tenant_id=4 BEGIN SELECT RAISE(FAIL,'fixture failure'); END");
try { Builtin::ensure(4,$db,'custom_',$assetRoot); throw new LogicException('Expected rollback'); } catch (PDOException $e) {}
check((int)$db->query('SELECT COUNT(*) FROM custom_distribution_poster_template WHERE tenant_id=4')->fetchColumn()===0 && (int)$db->query('SELECT COUNT(*) FROM custom_tenant_file WHERE tenant_id=4')->fetchColumn()===0,'failed publication rolls back templates and receipts');
$db->exec("INSERT INTO custom_tenant VALUES (5,0,'five')");
unlink($assetDir.'/atelier.png');
$partial=Builtin::ensure(5,$db,'custom_',$assetRoot);
check($partial['inserted']===3 && $partial['missing_assets']===['atelier'],'missing single image is reported and other templates still seed');
check((int)$db->query("SELECT COUNT(*) FROM custom_distribution_poster_template WHERE tenant_id=5 AND builtin_key='atelier'")->fetchColumn()===0,'missing image leaves no permanent builtin key');
file_put_contents($assetDir.'/atelier.png','fixture');
$complete=Builtin::ensure(5,$db,'custom_',$assetRoot);
check($complete['inserted']===1 && $complete['missing_assets']===[],'later asset delivery fills only its missing template');
$db->exec("INSERT INTO custom_tenant VALUES (6,0,'p3-private-signature'),(7,1,'split-blue'),(8,1,'bad`serial')");
$db->exec('CREATE TABLE `custom_tenant_file_split-blue` '.$receipt);
check(Builtin::ensure(6,$db,'custom_',$assetRoot)['inserted']===4,'shared tenant with hyphen serial uses shared file table');
check(Builtin::ensure(7,$db,'custom_',$assetRoot)['inserted']===4 && (int)$db->query('SELECT COUNT(*) FROM `custom_tenant_file_split-blue` WHERE tenant_id=7')->fetchColumn()===4,'split tenant with hyphen serial uses quoted file table');
foreach ([[8,'custom_'],[6,'bad-prefix_']] as [$tenant,$prefix]) {
    try { Builtin::ensure($tenant,$db,$prefix,$assetRoot); throw new LogicException('Unsafe identifier accepted'); }
    catch (RuntimeException $e) { check(str_contains($e->getMessage(),'无效'),'unsafe split serial or database prefix rejected'); }
}
