#!/usr/bin/env node
// Acceptance test only: actual 390 vendor/PHP8 worker, actual production payload/PHP8.2 activator.
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import assert from 'node:assert/strict'
import {spawn,spawnSync} from 'node:child_process'
const args=process.argv.slice(2), option=name=>args[args.indexOf(name)+1]
for(const name of ['--baseline','--bridge','--composer'])if(!args.includes(name))throw new Error('Required '+name)
const baseline=path.resolve(option('--baseline')),bridge=path.resolve(option('--bridge')),composer=path.resolve(option('--composer'))
const temporary=fs.mkdtempSync(path.join(os.tmpdir(),'aigc-bridge-process-')),site=path.join(temporary,'site')
fs.mkdirSync(site)
for(const relative of ['app','config','route','extend','vendor','composer.json','composer.lock'])if(fs.existsSync(path.join(baseline,relative)))fs.cpSync(path.join(baseline,relative),path.join(site,relative),{recursive:true})
fs.cpSync(path.join(bridge,'files'),site,{recursive:true})
fs.mkdirSync(path.join(site,'runtime/tasks'),{recursive:true});fs.writeFileSync(path.join(site,'runtime/tasks/preserve.txt'),'KEEP_TASK');fs.writeFileSync(path.join(site,'.env'),'KEEP_ENV')
fs.writeFileSync(path.join(site,'worker-test.php'),String.raw`<?php
namespace app\common\service\ai {
class AiTaskJobService {
    public static $claims=0; public static $finished=[];
    public static function claim(...$args){ if(++self::$claims!==1)throw new \RuntimeException('claimed after epoch switch');return [['id'=>1,'job_type'=>'fixture','consumption_id'=>0,'result_asset_id'=>0,'attempts'=>1],['id'=>2,'job_type'=>'fixture','consumption_id'=>0,'result_asset_id'=>0,'attempts'=>1]]; }
    public static function run($job){ if($job['id']===1){echo "READY\n";fflush(STDOUT);fgets(STDIN);}return true; }
    public static function succeed($job){self::$finished[]=$job['id'];}
}}
namespace {
require '/site/runtime-loader.php';
$app=new AigcRuntimeApp('/site');
foreach(require $GLOBALS['AIGC_RUNTIME_VENDOR'].'/services.php' as $service)if(!class_exists($service))throw new RuntimeException('old service missing');
if(class_exists('NeuronAI\\Agent\\Agent'))throw new RuntimeException('bridge unexpectedly loads new SDK');
class BridgeOptions extends \think\console\Input { public function __construct(){parent::__construct([]);}public function getOption(string $name){return ['worker'=>'result','app'=>'fixture','sleep'=>1,'lease'=>90,'batch'=>2][$name]??null;} }
$command=new \app\common\command\AiTaskWorker();$method=new ReflectionMethod($command,'execute');$method->setAccessible(true);$method->invoke($command,new BridgeOptions(),new \think\console\Output('buffer'));
if(\app\common\service\ai\AiTaskJobService::$finished!==[1,2]||\app\common\service\ai\AiTaskJobService::$claims!==1)throw new RuntimeException('batch not drained correctly');
if(!aigc_runtime_generation_changed())throw new RuntimeException('worker did not observe pointer switch');
echo 'DRAINED_OLD_BATCH_AND_EXIT PHP='.PHP_VERSION."\n";
}
`)
fs.writeFileSync(path.join(site,'activate-test.php'),String.raw`<?php
require '/site/app/common/service/update/ComposerRuntimeUpdateService.php';
function treeCopy($source,$target){if(is_dir($source)){@mkdir($target,0755,true);foreach(scandir($source) as $name)if($name!=='.'&&$name!=='..')treeCopy($source.'/'.$name,$target.'/'.$name);}else copy($source,$target);}
$package='/tmp/package';treeCopy('/production',$package.'/composer-runtime');@mkdir($package.'/files/config',0755,true);file_put_contents($package.'/files/config/project.php',"<?php return ['version'=>'1.0.392'];");
$hashes=[];foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($package,FilesystemIterator::SKIP_DOTS)) as $file)if($file->isFile())$hashes[substr($file->getPathname(),strlen($package)+1)]=hash_file('sha256',$file->getPathname());file_put_contents($package.'/signature.json',json_encode(['sha256'=>$hashes]));
$manifest=['version'=>'1.0.392','base_version'=>'1.0.391','package_mode'=>'incremental','full_replace_dirs'=>[],'delete_files'=>[],'sql_order'=>[],'composer_runtime'=>['schema'=>1,'min_updater_version'=>'1.0.391','min_php'=>'8.2.0','payload_dir'=>'composer-runtime','required_extensions'=>['ctype','curl','dom','fileinfo','filter','json','mbstring','openssl','pdo','pdo_mysql','tokenizer','xml','zip'],'probe_classes'=>['think\\App','NeuronAI\\Agent\\Agent']]];
$manager=new \app\common\service\update\ComposerRuntimeUpdateService('/site',PHP_BINARY);$manager->preflight($package,$manifest);$result=$manager->apply($package,$manifest,['sql'=>function(){},'files'=>function()use($package){copy($package.'/files/config/project.php','/site/config/project.php');},'finalize'=>function(){return [];},'marker'=>function(){}]);
if(file_get_contents('/site/.env')!=='KEEP_ENV'||file_get_contents('/site/runtime/tasks/preserve.txt')!=='KEEP_TASK')throw new RuntimeException('persistent state changed');
echo 'ACTIVATED_PRODUCTION_NEURON_4.1.9 PHP='.PHP_VERSION.' '.$result['generation']."\n";
`)
const dockerCommon=['run','--rm','--network','none','--read-only','--tmpfs','/tmp:rw,size=768m','-v',site+':/site:rw']
const workerContainer='aigc-bridge-worker-'+path.basename(temporary).toLowerCase()
let output='',error='',child
try {
    child=spawn('docker',[...dockerCommon,'--name',workerContainer,'-i','php:8.0-cli','php','/site/worker-test.php'],{stdio:['pipe','pipe','pipe']})
    child.stdout.on('data',data=>output+=data);child.stderr.on('data',data=>error+=data)
    const exit=new Promise(resolve=>child.on('close',code=>resolve(code)))
    await new Promise((resolve,reject)=>{const timer=setTimeout(()=>reject(new Error('worker readiness timeout: '+error)),60000);child.stdout.on('data',()=>{if(output.includes('READY\n')){clearTimeout(timer);resolve()}});child.on('close',code=>{clearTimeout(timer);reject(new Error('worker exited before readiness '+code+': '+error))})})
    const activated=spawnSync('docker',[...dockerCommon,'-v',composer+':/production:ro','likeadmin-install-worker-test:local','php','/site/activate-test.php'],{encoding:'utf8',timeout:60000})
    assert.equal(activated.status,0,activated.stderr);console.log(activated.stdout.trim())
    child.stdin.end('continue\n')
    const timer=setTimeout(()=>child.kill('SIGKILL'),15000)
    assert.equal(await exit,0,error);clearTimeout(timer)
    assert.match(output,/DRAINED_OLD_BATCH_AND_EXIT PHP=8\.0\./);console.log(output.trim())
    const fresh=spawnSync('docker',[...dockerCommon,'likeadmin-install-worker-test:local','php','-r',"require '/site/runtime-loader.php'; if(!class_exists('NeuronAI\\\\Agent\\\\Agent'))exit(2); echo 'FRESH_PROCESS_NEURON PHP='.PHP_VERSION;"],{encoding:'utf8',timeout:30000})
    assert.equal(fresh.status,0,fresh.stderr);console.log(fresh.stdout)
    console.log('PASS: real PHP8.0 bridge worker drains claimed batch and exits after actual PHP8.2 production runtime activation; fresh process loads Neuron')
} finally {spawnSync('docker',['rm','-f',workerContainer],{stdio:'ignore',timeout:10000});if(child&&child.exitCode===null)child.kill('SIGKILL');fs.rmSync(temporary,{recursive:true,force:true})}
