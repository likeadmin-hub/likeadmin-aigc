<?php
declare(strict_types=1);
$fixtureCase=($argv[1]??'')==='--run'?($argv[3]??''):($argv[1]??'');
$migration=str_starts_with($fixtureCase,'legacy402-');$from=$migration?'1.0.402':'1.0.391';$to=$migration?'1.0.403':'1.0.392';
$GLOBALS['fixture_target_version']=$to;
/** Real signed package + real service + real MySQL, entirely inside a disposable container. */
function verify(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function writeFixture(string $path, string $content): void {
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0755, true);
    file_put_contents($path, $content);
}
function copyFixture(string $from, string $to): void {
    if (is_dir($from)) {
        if (!is_dir($to)) mkdir($to, 0755, true);
        foreach (new FilesystemIterator($from, FilesystemIterator::SKIP_DOTS) as $item) {
            verify(!$item->isLink(), 'Fixture source contains a link');
            copyFixture($item->getPathname(), $to . '/' . $item->getFilename());
        }
    } else { if (!is_dir(dirname($to))) mkdir(dirname($to), 0755, true); verify(copy($from, $to), 'Fixture copy failed'); }
}
function freshFixtureGeneration(string $site,bool $canonical=false):void {
    $expectedVersion=$GLOBALS['fixture_target_version'];
    $official=getenv('FIXTURE_OFFICIAL_ARCHIVE');
    $location=$canonical?'!str_starts_with((new ReflectionClass(\\app\\FixtureVersion::class))->getFileName(),'.var_export($site.'/app/',true).')':'!str_contains((new ReflectionClass(\\app\\FixtureVersion::class))->getFileName(),"/.composer-runtime/generations/")';
    if($official)$location='!str_starts_with((new ReflectionClass(\\app\\common\\service\\update\\SystemPackageUpdateService::class))->getFileName(),'.var_export($site.'/app/',true).')';
    $code='require '.var_export($site.'/runtime-loader.php',true).'; (new AigcRuntimeApp('.var_export($site,true).'))->initialize(); if(\\app\\FixtureVersion::VERSION!=="new" || config("project.version")!=='.var_export($expectedVersion,true).' || '.$location.'){throw new RuntimeException("Fresh entry did not serve selected code/version");} echo "PASS actual fresh site entry serves selected PHP code/version\\n";';
    if($official)$code=str_replace('\\app\\FixtureVersion::VERSION!=="new" || ','',$code);
    $pipes=[];$child=proc_open([PHP_BINARY,'-r',$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);verify(is_resource($child),'Fresh observer failed to start');fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);verify(proc_close($child)===0,'Fresh observer failed: '.$err.$out);echo $out;
}
function fixtureMetadata():array {
    $result=[];foreach(['app','app_version','tenant_app','app_api','app_frontend_entry','app_install','system_menu','tenant_system_menu','system_role_menu','tenant_system_role_menu','app_plan','membership_plan','membership_plan_app','tenant_package_app_plan'] as $table) {
        $indexes=think\facade\Db::query("SHOW INDEX FROM la_".$table." WHERE Key_name='PRIMARY'");usort($indexes,static fn(array $a,array $b):int=>(int)$a['Seq_in_index']<=>(int)$b['Seq_in_index']);
        $result[$table]=think\facade\Db::name($table)->order(array_fill_keys(array_column($indexes,'Column_name'),'asc'))->select()->toArray();
    }return $result;
}
if (($argv[1] ?? '') !== '--run') {
    verify(getenv('SYSTEM_SERVICE_DISPOSABLE') === '1', 'Only disposable database execution is allowed');
    $case = $argv[1] ?? '';
    $plainCase=preg_replace('/^(?:canonical|legacy402)-/','',$case);
    verify(in_array($plainCase, ['success','dependency-failure','finalize-failure','metadata-failure','commit-reply-failure','precommit-exit','confirm-failure'], true), 'Unknown fixture case');
    $site = sys_get_temp_dir() . '/system-service-' . bin2hex(random_bytes(6));
    $baseline=$migration?'/baseline':'/repo';
    foreach (['app','config','extend','route','scripts'] as $dir) copyFixture($baseline.'/' . $dir, $site . '/' . $dir);
    foreach (['composer.json','composer.lock','vendor'] as $path) copyFixture('/production/' . $path, $site . '/' . $path);
    copyFixture($baseline.'/runtime-loader.php', $site . '/runtime-loader.php');
    copyFixture($baseline.'/runtime-retention.php',$site.'/runtime-retention.php');
    if(!$migration)copyFixture('/repo/direct-runtime-loader.php',$site.'/direct-runtime-loader.php');
    writeFixture($site . '/config/project.php', "<?php return ['version'=>'{$from}'];\n");
    writeFixture($site . '/upgrade/version.json', json_encode(['version'=>$from]));
    writeFixture($site . '/config/install.lock', 'fixture installed');
    writeFixture($site . '/.env', "[DATABASE]\nHOSTNAME=mysql\nDATABASE=" . getenv('PHP_DATABASE_DATABASE') . "\nUSERNAME=root\nPASSWORD=\nPREFIX=la_\nHOSTPORT=3306\n[CACHE]\nDRIVER=file\n");
    writeFixture($site . '/public/uploads/business.bin', 'KEEP_UPLOAD');
    writeFixture($site . '/runtime/task-lease.json', '{"lease":"existing-owner","attempts":2}');
    writeFixture($site . '/app/FixtureVersion.php', '<?php namespace app; class FixtureVersion { const VERSION="old"; }');
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --run ' . escapeshellarg($site) . ' ' . escapeshellarg($case);
    passthru($command, $exit);
    exit($exit);
}
$site = $argv[2]; $canonical=str_starts_with($argv[3],'canonical-');$case=preg_replace('/^(?:canonical|legacy402)-/','',$argv[3]);
require $site . '/vendor/autoload.php';
(new think\App($site . '/'))->initialize();
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, (string)$error . "\n"); exit(1); });
use think\facade\Db;
use app\common\service\update\SystemPackageUpdateService;
use app\common\service\update\ComposerRuntimeUpdateService;
use app\common\service\update\UpdateLicenseService;
use app\common\service\update\UpdateSourceClient;
use app\common\service\update\SystemUpdateProtocol;
use app\common\service\license\SignedLicenseProtocol;
verify(root_path() === $site . '/', 'Service root escaped the temporary fixture site');
verify(str_starts_with((string)config('database.connections.mysql.database'), 'fixture_'), 'Database is not disposable');
request()->withServer(array_merge(request()->server(), ['HTTP_HOST'=>'system.fixture', 'SERVER_NAME'=>'system.fixture']));
$key = openssl_pkey_new(['private_key_bits'=>2048]);
$public = openssl_pkey_get_details($key)['key'];
Db::name('update_source')->where('status', 1)->update(['status'=>0]);
Db::name('update_source')->insert(['name'=>'disposable fixture','base_url'=>'http://fixture.invalid','online_base_url'=>'http://fixture.invalid',
    'license_key'=>'fixture','online_license_key'=>'fixture','dev_mode'=>1,'public_key'=>$public,'status'=>1]);
$license = new UpdateLicenseService(); $machine = $license->machineCode();
$payload = (object)['schema_version'=>3,'license_no'=>'LIC-system-service','version'=>1,'product_code'=>UpdateSourceClient::PRODUCT_CODE,
    'domains'=>[$machine['domain']],'machine_fingerprint_hash'=>$machine['machine_fingerprint_hash'],'license_type'=>'commercial',
    'is_perpetual'=>true,'expires_at'=>0,'update_mode'=>'lifetime','issuer'=>(object)['copyright'=>[]]];
openssl_sign(SignedLicenseProtocol::json($payload), $signature, $key, OPENSSL_ALGO_SHA256);
$license->storeCertificate(SignedLicenseProtocol::json((object)['payload'=>$payload,'signature'=>base64_encode($signature),'algorithm'=>'RSA-SHA256']));
// Seed actual business records; the service must neither run these tasks nor touch their money/leases.
function seedBusiness(string $table, array $values): void {
    foreach (Db::query('SHOW COLUMNS FROM `la_' . $table . '`') as $column) {
        if (array_key_exists($column['Field'], $values) || $column['Null']==='YES' || $column['Default']!==null || str_contains($column['Extra'],'auto_increment')) continue;
        $values[$column['Field']] = preg_match('/int|decimal|float|double/', $column['Type']) ? 0 : '';
    }
    Db::name($table)->insert($values);
}
seedBusiness('tenant', ['id'=>901,'sn'=>'fixture901','name'=>'preserve-tenant','point_balance'=>'128.50','create_time'=>time()]);
seedBusiness('user', ['id'=>901,'tenant_id'=>901,'sn'=>901,'account'=>'fixture901','user_money'=>'77.25']);
seedBusiness('user_account_log', ['tenant_id'=>901,'user_id'=>901,'sn'=>'fixture-ledger','change_amount'=>'2.50','left_amount'=>'77.25']);
seedBusiness('recharge_order', ['tenant_id'=>901,'user_id'=>901,'sn'=>'fixture-order','order_amount'=>'3.00','pay_status'=>1]);
seedBusiness('aigc_short_drama_episode_task', ['tenant_id'=>901,'user_id'=>901,'status'=>'running','heartbeat_at'=>123456,'attempt_number'=>2]);
seedBusiness('aigc_short_drama_canvas_poster_job', ['status'=>'running','lease_token'=>'existing-owner','lease_expire_time'=>4294967295,'attempts'=>2,'idempotency_key'=>'fixture-existing-task']);
$businessTables = ['tenant','user','user_account_log','recharge_order','aigc_short_drama_episode_task','aigc_short_drama_canvas_poster_job'];
$business = []; foreach ($businessTables as $table) $business[$table] = Db::name($table)->order('id')->select()->toArray();
$metadata=fixtureMetadata();
$package = $site . '/fixture-package';
foreach (['composer.json','composer.lock','vendor'] as $path) copyFixture('/production/' . $path, $package . '/composer-runtime/' . $path);
writeFixture($package . '/files/app/FixtureVersion.php', '<?php namespace app; class FixtureVersion { const VERSION="new"; }');
writeFixture($package . '/files/config/project.php', "<?php return ['version'=>'{$to}'];\n");
writeFixture($package . '/sql/data/fixture.sql', "INSERT INTO `la_config` (`type`,`name`,`value`) VALUES ('system_fixture','sql_once','1');\n");
writeFixture($package . '/menus/README', 'No menu replacement');
writeFixture($package . '/rollback/README', 'Filesystem rollback exercised by runtime updater');
// Match the official stager: signed source identities adopt installed historical SQL, never replay it.
$baseline=[];foreach(glob($site.'/app/apps/*/migrations/*.sql') as $file)$baseline[substr($file,strlen($site)+1)]=hash_file('sha256',$file);
writeFixture($package.'/files/config/runtime_migration_baseline.php','<?php return '.var_export($baseline,true).';');
foreach(['aigc_short_drama','aigc_image'] as $code)copyFixture($site.'/app/apps/'.$code.'/manifest.json',$package.'/files/app/apps/'.$code.'/manifest.json');
if($migration){
    foreach(['app','config','route','extend','scripts'] as $dir)copyFixture('/repo/'.$dir,$package.'/files/'.$dir);
    foreach(['runtime-loader.php','direct-runtime-loader.php','runtime-retention.php'] as $file)copyFixture('/repo/'.$file,$package.'/files/'.$file);
}
if ($case === 'dependency-failure') {
    // Real candidate Composer autoload executes and throws in its fresh PHP subprocess.
    $autoloadPath = $package . '/composer-runtime/vendor/autoload.php';
    $autoload = file_get_contents($autoloadPath);
    $autoload = str_replace('<?php', "<?php\nthrow new \\RuntimeException('fixture dependency initialization failed');", $autoload);
    writeFixture($autoloadPath, $autoload);
}
if (in_array($case,['finalize-failure','precommit-exit'],true)) {
    copyFixture($site . '/app/apps/aigc_short_drama/manifest.json', $package . '/files/app/apps/aigc_short_drama/manifest.json');
    $registry = file_get_contents($site . '/app/common/service/app/AppRegistryService.php');
    // Insert only at the actual method body; candidate code remains otherwise production code.
    $count = 0;
    $registry = preg_replace('/(public static function installFromLocalWithResult\([^\{]*\{)/s', ($case==='precommit-exit'?"$1\n        exit(79);":"$1\n        throw new \\RuntimeException('fixture candidate application sync failed');"), $registry, 1, $count);
    verify($count === 1, 'Candidate AppRegistry fault was not inserted');
    writeFixture($package . '/files/app/common/service/app/AppRegistryService.php', $registry);
}
if(is_file($package.'/files/config/install.lock'))unlink($package.'/files/config/install.lock');
if($case==='commit-reply-failure') {
    $source=file_get_contents('/repo/app/common/service/update/SystemPackageUpdateService.php');
    $at=strpos($source,'public function completeBootstrap(');$head=substr($source,0,$at);$tail=substr($source,$at);
    $tail=preg_replace('/Db::commit\(\);/',"Db::commit(); throw new \\RuntimeException('fixture committed reply lost');",$tail,1);
    writeFixture($package.'/files/app/common/service/update/SystemPackageUpdateService.php',$head.$tail);
}
if($case==='confirm-failure') {
    $runner=file_get_contents('/repo/scripts/composer-runtime-runner.php');
    $needle= <<<'NEEDLE'
} elseif ($mode==='--direct-confirm') {
NEEDLE;
    $fault= <<<'FAULT'
if(!is_file($siteRoot.'/runtime/confirm-failed-once')){file_put_contents($siteRoot.'/runtime/confirm-failed-once','1');throw new RuntimeException('fixture confirmation unavailable');}
FAULT;
    $runner=str_replace($needle,$needle."\n".$fault,$runner);
    $needle= <<<'NEEDLE'
elseif($mode==='--bootstrap-complete')$result=['passed'=>true,'completion'=>$service->completeBootstrap($argv[3],$binding)];
NEEDLE;
    $fault= <<<'FAULT'
elseif($mode==='--bootstrap-complete'){$result=['passed'=>true,'completion'=>$service->completeBootstrap($argv[3],$binding)];throw new RuntimeException('fixture completion reply lost');}
FAULT;
    $runner=str_replace($needle,$fault,$runner);
    writeFixture($package.'/files/scripts/composer-runtime-runner.php',$runner);
}
$manifest = ['version'=>$to,'target_version'=>$to,'base_version'=>$from,'product_code'=>UpdateSourceClient::PRODUCT_CODE,'package_mode'=>'incremental',
    'included_versions'=>[$from,$to],'full_replace_dirs'=>[],'delete_files'=>[],'sql_order'=>['sql/data/fixture.sql'],
    'composer_runtime'=>['schema'=>1,'min_updater_version'=>'1.0.391','min_php'=>'8.2.0','payload_dir'=>'composer-runtime',
        'required_extensions'=>['json','pdo_mysql'],'probe_classes'=>['think\\App']]];
writeFixture($package . '/update.json', json_encode($manifest));
$hashes=[]; foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($package, FilesystemIterator::SKIP_DOTS)) as $file)
    if ($file->isFile()) $hashes[substr($file->getPathname(),strlen($package)+1)] = hash_file('sha256',$file->getPathname());
writeFixture($package . '/signature.json', json_encode(['sha256'=>$hashes]));
$archive = $site . '/package.tar';
$tar = new PharData($archive); $tar->buildFromDirectory($package); unset($tar);
if(getenv('FIXTURE_OFFICIAL_ARCHIVE')){$archive=getenv('FIXTURE_OFFICIAL_ARCHIVE');$zip=new ZipArchive();verify($zip->open($archive)===true,'Official ZIP unavailable');$manifest=json_decode($zip->getFromName('update.json'),true,512,JSON_THROW_ON_ERROR);$zip->close();verify($manifest['base_version']===$from && $manifest['version']===$to,'Official version chain differs');}
$id = Db::name('update_package')->insertGetId(['package_id'=>'fixture-' . $case,'type'=>'system','source'=>'cloud','app_code'=>'','version'=>$to,
    'format'=>getenv('FIXTURE_OFFICIAL_ARCHIVE')?'zip':'tar','local_path'=>$archive,'sha256'=>hash_file('sha256',$archive),'package_size'=>filesize($archive),
    'manifest_json'=>'{}','status'=>'downloaded','create_time'=>time(),'update_time'=>time()]);
$context = $license->verifiedSiteContext(); $now = time();
$response = (object)['code'=>1,'msg'=>'fixture','server_time'=>$now,'data'=>['product_code'=>UpdateSourceClient::PRODUCT_CODE,
    'version'=>$to,'target_version'=>$to,'sha256'=>hash_file('sha256',$archive),'format'=>getenv('FIXTURE_OFFICIAL_ARCHIVE')?'zip':'tar',
    'site_grant'=>['license_no'=>$context['license_no'],'license_version'=>$context['license_version'],'domains'=>[$context['domain']],
        'machine_fingerprint_hash'=>$context['machine_fingerprint_hash'],'version_id'=>392,'issued_at'=>$now,'expires_at'=>$now+3600]]];
openssl_sign(SignedLicenseProtocol::json($response), $signature, $key, OPENSSL_ALGO_SHA256); $response->signature=base64_encode($signature);
Db::name('system_package_grant')->insert(['package_id'=>$id,'context_key'=>SystemUpdateProtocol::contextKey($context,UpdateSourceClient::getSource()),
    'response_json'=>SignedLicenseProtocol::json($response),'create_time'=>$now,'update_time'=>$now]);
$runtime = new ComposerRuntimeUpdateService($site);if(!$canonical)$runtime->ensureBaseline();
$pointer = $canonical?null:file_get_contents($site . '/.composer-runtime/current.json');
$original = []; foreach (['app/FixtureVersion.php','app/common/service/app/AppRegistryService.php','config/project.php','upgrade/version.json','.env','public/uploads/business.bin','runtime/task-lease.json'] as $path)
    $original[$path] = file_get_contents($site . '/' . $path);
$service = new SystemPackageUpdateService(); $preflight = $service->preflight($id);
if ($case === 'dependency-failure') verify(!$preflight['passed'], 'Broken dependency was accepted');
else verify($preflight['passed'], 'Real package preflight failed: ' . implode(';',$preflight['errors']));
if ($case === 'metadata-failure') {
    // Actual MySQL trigger rejects the last task-success write after files/pointer/marker publication.
    Db::execute("CREATE TRIGGER fixture_metadata_failure BEFORE UPDATE ON la_update_task FOR EACH ROW BEGIN IF NEW.action='apply' AND NEW.status='success' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture final metadata failure'; END IF; END");
}
$failure = null;
try { $result = $service->apply($id); } catch (Throwable $e) { $failure = $e; }
if (in_array($case,['success','commit-reply-failure','confirm-failure'],true)) {
    if($case==='confirm-failure')verify($failure!==null && is_file($site.'/runtime/direct-update-active.json'),'Unknown confirmation was not preserved');
    else verify($failure === null, 'Real apply failed: ' . ($failure?->getMessage() ?? ''));
    verify(Db::name('update_package')->where('id',$id)->value('status') === 'applied', 'Success package status not persisted');
    verify(Db::name('update_task')->where(['package_id'=>$id,'action'=>'apply'])->value('status') === 'success', 'Success task status not persisted');
    if($canonical||$migration){if($canonical)verify(!file_exists($site.'/.composer-runtime'),'Canonical update created snapshots');if(!getenv('FIXTURE_OFFICIAL_ARCHIVE'))verify(str_contains(file_get_contents($site.'/app/FixtureVersion.php'),'new'),'Canonical code not updated');}
    else {verify(file_get_contents($site . '/.composer-runtime/current.json') !== $pointer, 'Success pointer did not switch');verify(file_get_contents($site.'/app/FixtureVersion.php')===$original['app/FixtureVersion.php'],'Immutable apply changed canonical baseline code');}
    freshFixtureGeneration($site,$canonical||$migration);
    verify(str_contains(file_get_contents($site . '/config/project.php'),$to), 'Success config version not installed');
    verify(json_decode(file_get_contents($site . '/upgrade/version.json'),true)['version']===$to, 'Success version marker not installed');
} else {
    verify($failure !== null, 'Injected failure was accepted');
    $expected = ['dependency-failure'=>'fixture dependency initialization failed','finalize-failure'=>'fixture candidate application sync failed','metadata-failure'=>'fixture final metadata failure','precommit-exit'=>($migration?'PHP CLI':'子进程')][$case];
    verify(str_contains($failure->getMessage(),$expected), 'Wrong failure: ' . $failure->getMessage());
    verify(Db::name('update_package')->where('id',$id)->value('status') === 'apply_failed', 'Failure package status not persisted');
    verify(Db::name('update_task')->where(['package_id'=>$id,'action'=>'apply'])->value('status') === 'failed', 'Failure task status not persisted');
    verify($canonical?!file_exists($site.'/.composer-runtime'):file_get_contents($site . '/.composer-runtime/current.json') === $pointer, 'Failure changed runtime pointer');
    foreach (['app/FixtureVersion.php','app/common/service/app/AppRegistryService.php','config/project.php','upgrade/version.json'] as $path)
        verify(file_get_contents($site . '/' . $path) === $original[$path], 'Failure did not restore ' . $path);
    verify(!is_file($site.'/runtime/direct-update-active.json'),'Rejected legacy completion left canonical recovery journal');
    if($case==='metadata-failure')verify(fixtureMetadata()===$metadata,'Final metadata failure did not roll back all application/tenant/menu/plan metadata');
}
foreach (['.env','public/uploads/business.bin','runtime/task-lease.json'] as $path)
    verify(file_get_contents($site . '/' . $path) === $original[$path], 'Protected state changed: ' . $path);
foreach ($business as $table=>$rows) verify(Db::name($table)->order('id')->select()->toArray() === $rows, 'Business records changed: ' . $table);
$sqlCount = Db::name('config')->where(['type'=>'system_fixture','name'=>'sql_once'])->count();
verify($sqlCount === ($case === 'dependency-failure' || getenv('FIXTURE_OFFICIAL_ARCHIVE') ? 0 : 1), 'Migration execution count incorrect');
if ($case === 'metadata-failure') {
    Db::execute('DROP TRIGGER fixture_metadata_failure');
    $service->apply($id);
    verify(Db::name('update_package')->where('id',$id)->value('status') === 'applied', 'Retry did not complete');
    verify(Db::name('config')->where(['type'=>'system_fixture','name'=>'sql_once'])->count() === 1, 'Retry replayed completed SQL');
    freshFixtureGeneration($site,$canonical||$migration);
    foreach ($business as $table=>$rows) verify(Db::name($table)->order('id')->select()->toArray() === $rows, 'Retry changed business records: ' . $table);
    echo "PASS final metadata failure retry: actual service apply completed without replaying SQL or altering business records\n";
}
echo 'PASS real SystemPackageUpdateService ' . ($migration?'legacy402-':($canonical?'canonical-':'')) . $case . ': signed preflight/apply, DB statuses, files/pointer/version, six business tables and protected site state; SQL count=' . $sqlCount . "\n";

if($case==='success' && getenv('FIXTURE_APP_PACKAGES')==='1') {
    passthru(escapeshellarg(PHP_BINARY).' /repo/tests/runtime-updater/system-service.app-package.php '.escapeshellarg($site),$appExit);
    verify($appExit===0,'Canonical application package fixture failed');
}
