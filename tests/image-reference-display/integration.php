<?php
/** No application database, provider calls, or cloud writes are used. */
require dirname(__DIR__, 2).'/vendor/autoload.php';
(new think\App())->initialize();
set_exception_handler(static function(Throwable $e): void { fwrite(STDERR, 'FAIL '.$e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL); exit(1); });
use think\facade\{Db, Config};
use app\common\service\app\aigc_image\{AigcImageService, AigcImageReferenceDisplayService, ImageAppTaskSync};
use app\common\service\FileService;
if (getenv('IMAGE_REFERENCE_DISPOSABLE_TEST') !== '1') throw new RuntimeException('Disposable runner required');
$config = config('database');
$config['connections']['mysql'] = array_replace($config['connections']['mysql'], ['hostname'=>'127.0.0.1','hostport'=>3306,'database'=>'image_reference_fixture','username'=>'root','password'=>'','trigger_sql'=>true]);
Config::set($config, 'database'); Db::connect(null, true);
$pdo = new PDO('mysql:host=127.0.0.1;dbname=image_reference_fixture;charset=utf8mb4', 'root', '');
if (!preg_match('/^[a-f0-9-]{36}$/iD', (string)getenv('IMAGE_REFERENCE_DB_UUID')) || $pdo->query('SELECT @@server_uuid')->fetchColumn() !== getenv('IMAGE_REFERENCE_DB_UUID')) throw new RuntimeException('Private MySQL identity differs');
$schema = file_get_contents(dirname(__DIR__, 2).'/public/install/db/like.sql');
foreach (['aigc_image_task','aigc_image_result','tenant_file','user','config','tenant_config','tenant','ai_consumption_log'] as $name) {
    if (!preg_match('~^CREATE TABLE(?: IF NOT EXISTS)? `la_'.preg_quote($name,'~').'`\s*\(.*?^\)\s*ENGINE\s*=\s*InnoDB\b[^;]*;~msi', $schema, $m)) throw new RuntimeException('Missing fixture DDL '.$name);
    $pdo->exec($m[0]);
}
preg_match('~^ALTER TABLE `la_aigc_image_task`\s+ADD COLUMN `channel`.*?;~ms', $schema, $m); $pdo->exec($m[0]);
$pdo->exec('ALTER TABLE la_aigc_image_task ADD COLUMN app_task_id int NOT NULL DEFAULT 0, ADD COLUMN provider_params_json text');
foreach ([9101,9102] as $tenant) Db::name('tenant')->insert(['id'=>$tenant,'sn'=>'reference_'.$tenant,'name'=>'fixture','tactics'=>0,'create_time'=>time()]);
request()->tenantId = 9101;
request()->withServer(['HTTP_HOST'=>'history.example.test','SERVER_PORT'=>80]);
Db::name('config')->insert(['type'=>'storage','name'=>'default','value'=>'qiniu']);
Db::name('config')->insert(['type'=>'storage','name'=>'qiniu','value'=>json_encode(['domain'=>'https://new-default.example.test'])]);
$checks = 0; $failures = [];
function check(bool $ok, string $why): void {global $checks,$failures; $checks++; if (!$ok) $failures[] = $why;}
function task(int $tenant, int $user, array $refs): int {
    return Db::name('aigc_image_task')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'status'=>'running','model'=>'fixture-model','reference_images'=>json_encode($refs),'provider_params_json'=>'{}']);
}
function upload(int $tenant, int $user, string $uri, string $domain, array $extra = []): void {
    Db::name('tenant_file')->insert(array_replace(['tenant_id'=>$tenant,'source_id'=>$user,'source'=>1,'uri'=>$uri,'storage_scope'=>'tenant','storage_engine'=>'aliyun','storage_domain'=>$domain,'delete_time'=>0],$extra));
}
$refs = ['uploads/images/owned.png','/uploads/images/custom.png','uploads/aigc_image/generated.png','https://legacy.example.test/photo.png','/uploads/legacy.png','uploads/images/owned.png'];
upload(9101, 9201, $refs[0], 'https://old-cloud.example.test');
upload(9101, 9201, ltrim($refs[1],'/'), 'https://custom.example.test/media');
// Newer records with the same key must never override the task owner's metadata.
upload(9102, 9201, $refs[0], 'https://other-tenant.example.test');
upload(9101, 9202, $refs[0], 'https://other-user.example.test');
upload(9101, 9201, $refs[0], 'https://deleted.example.test', ['delete_time'=>time()]);
upload(9101, 9201, $refs[0], 'https://admin.example.test', ['source'=>0]);
$source = task(9101, 9201, []);
Db::name('aigc_image_result')->insert(['tenant_id'=>9101,'user_id'=>9201,'task_id'=>$source,'image_uri'=>$refs[2],'storage_scope'=>'tenant','storage_engine'=>'qcloud','storage_domain'=>'https://generated.example.test']);
Db::name('aigc_image_result')->insert(['tenant_id'=>9101,'user_id'=>9202,'task_id'=>$source,'image_uri'=>$refs[2],'storage_scope'=>'tenant','storage_engine'=>'qcloud','storage_domain'=>'https://wrong-generated.example.test']);
file_put_contents(public_path().'uploads/legacy.png', 'fixture');
$id = task(9101, 9201, $refs);
$other = task(9101, 9202, ['uploads/images/owned.png']);
$expected = ['https://old-cloud.example.test/uploads/images/owned.png','https://custom.example.test/media/uploads/images/custom.png','https://generated.example.test/uploads/aigc_image/generated.png','https://legacy.example.test/photo.png',request()->domain().'/uploads/legacy.png','https://old-cloud.example.test/uploads/images/owned.png'];
$detail = ImageAppTaskSync::localReads(fn()=>AigcImageService::taskDetail(9101, $id, 9201));
check(($detail['reference_image_urls']??[]) === $expected, 'detail must resolve owned storage metadata and legacy images in original order');
check($detail['reference_images'] === $refs, 'detail must preserve raw submission references');
// Select running rows without polling providers (task receipt is intentionally empty).
$page = ImageAppTaskSync::localReads(fn()=>AigcImageService::taskLists(9101, 9201, ['status'=>'running','page_no'=>1]));
$row = array_column($page['lists'], null, 'id')[$id];
check(($row['reference_image_urls']??[]) === $expected, 'paginated list must expose display URLs');
check($row['reference_images'] === $refs, 'list must preserve raw submission references');
$rows = ImageAppTaskSync::localReads(fn()=>AigcImageService::taskLists(9101, 0, ['status'=>'running']));
$byId = array_column($rows, null, 'id');
check(($byId[$id]['reference_image_urls']??[]) === $expected, 'admin list must resolve each row as its actual owner');
check(($byId[$other]['reference_image_urls']??[]) === ['https://other-user.example.test/uploads/images/owned.png'], 'admin list must not share metadata between users');
foreach ([[9102,9201],[9101,9202]] as [$tenant,$user]) {
    try {ImageAppTaskSync::localReads(fn()=>AigcImageService::taskDetail($tenant,$id,$user)); check(false,'foreign owner detail accepted');}
    catch (Exception $e) {check($e->getMessage()==='任务不存在','foreign task detail denied');}
}
$unsafe = task(9101, 9201, ['uploads/missing.png','../uploads/legacy.png','data:image/png;base64,AAAA','javascript:alert(1)','blob:temporary']);
$unsafeDetail = ImageAppTaskSync::localReads(fn()=>AigcImageService::taskDetail(9101,$unsafe,9201));
check(($unsafeDetail['reference_image_urls']??[]) === array_fill(0,5,''), 'unknown and unsafe paths must not acquire guessed storage URLs');
$slots = ['uploads/missing.png',$refs[0],$refs[0],'https://unrelated.example.test/uploads/images/owned.png'];
$slotTask = task(9101,9201,$slots);
$slotView = ImageAppTaskSync::localReads(fn()=>AigcImageService::taskDetail(9101,$slotTask,9201));
check(($slotView['reference_image_urls']??[]) === ['', $expected[0], $expected[0], $slots[3]], 'unresolved slots and duplicates must preserve index alignment, unrelated hosts remain unchanged');
upload(9102,9201,'uploads/tenant-only.png','https://foreign-private.example.test');
upload(9101,9202,'uploads/user-only.png','https://foreign-private.example.test');
upload(9101,9201,'uploads/deleted-only.png','https://deleted.example.test',['delete_time'=>time()]);
$foreignRefs = ['uploads/tenant-only.png','uploads/user-only.png','uploads/deleted-only.png'];
$foreignTask = task(9101,9201,$foreignRefs);
$foreignView = ImageAppTaskSync::localReads(fn()=>AigcImageService::taskDetail(9101,$foreignTask,9201));
check(($foreignView['reference_image_urls']??[])===['','',''],'foreign and deleted-only storage metadata must never produce URLs');
$sourceQueries = [];
Db::listen(static function($sql) use (&$sourceQueries): void {
    if (preg_match('~^SELECT .*FROM `la_tenant_file`|^SELECT .*FROM `la_aigc_image_result` `r`~i', $sql)) $sourceQueries[] = $sql;
});
$batch = array_fill(0,30,['tenant_id'=>9101,'user_id'=>9201,'reference_images'=>$refs]);
$batch[] = ['tenant_id'=>9101,'user_id'=>9202,'reference_images'=>[$refs[0]]];
$batchResult = AigcImageReferenceDisplayService::appendRows($batch);
check(count($sourceQueries) === 2, 'reference metadata must use two batched SELECTs for many tasks and owners; got '.count($sourceQueries));
check($batchResult[29]['reference_image_urls']===$expected && $batchResult[30]['reference_image_urls']===['https://other-user.example.test/uploads/images/owned.png'], 'batched owner metadata must stay isolated');
// Private URLs are refreshed using the task tenant even on platform reads with no tenant request context.
Db::name('tenant_config')->insert(['tenant_id'=>9101,'type'=>'storage','name'=>'qiniu','value'=>json_encode(['private_access'=>true,'access_key'=>'fixture-key','secret_key'=>'fixture-secret','domain'=>'https://private.example.test'])]);
upload(9101,9201,'uploads/private.png','https://private.example.test',['storage_engine'=>'qiniu']);
$privateId = task(9101,9201,['uploads/private.png','https://private.example.test/uploads/private.png?e=1&token=expired']);
unset(request()->tenantId);
$private = ImageAppTaskSync::localReads(fn()=>AigcImageService::taskDetail(9101,$privateId,9201));
foreach ($private['reference_image_urls'] as $url) {
    parse_str((string)parse_url($url, PHP_URL_QUERY),$query);
    check(str_starts_with($url,'https://private.example.test/uploads/private.png?') && (int)($query['e']??0)>time() && str_starts_with($query['token']??'','fixture-key:'), 'owned private references must receive a fresh signature from explicit tenant config');
}
request()->tenantId = 9101;
$implicit = FileService::getFileUrlByStorage('uploads/private.png','tenant','qiniu','https://private.example.test');
$explicit = FileService::getFileUrlByStorage('uploads/private.png','tenant','qiniu','https://private.example.test',9101);
parse_str((string)parse_url($implicit, PHP_URL_QUERY),$implicitQuery);
parse_str((string)parse_url($explicit, PHP_URL_QUERY),$explicitQuery);
check(strtok($implicit,'?')===strtok($explicit,'?') && str_starts_with($implicitQuery['token']??'','fixture-key:') && str_starts_with($explicitQuery['token']??'','fixture-key:'),'existing callers must retain request-tenant private signing');
check(FileService::getFileUrlByStorage('uploads/legacy.png','platform','local')===request()->domain().'/uploads/legacy.png','existing local storage path behavior retained');
check(FileService::getFileUrlByStorage('','','','')==='','empty file remains empty');
check(json_decode(Db::name('aigc_image_task')->where('id',$id)->value('reference_images'),true)===$refs, 'read must not rewrite persisted references');
if ($failures) throw new RuntimeException(implode('; ', $failures));
echo "PASS image reference display: $checks checks (private MySQL identity verified)\n";
