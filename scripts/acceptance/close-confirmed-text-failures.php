<?php
declare(strict_types=1);
/** Local operator reconciliation. Evidence comes from read-only upstream task and account-log exports. */
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\power\MarketTextUsageReview;
$o=getopt('',['tenant:','user:','database:','evidence:','out:','apply']);
$tenant=(int)($o['tenant']??0);$user=(int)($o['user']??0);$file=(string)($o['evidence']??'');$out=(string)($o['out']??'');
if($tenant<=0||$user<=0||!$file||!$out||config('database.connections.mysql.hostname')!=='127.0.0.1'
    ||Db::query('SELECT DATABASE() AS db')[0]['db']!==($o['database']??''))throw new RuntimeException('Require explicit local database, owner, evidence and output');
$e=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
if(($e['source_database']??'')===''||($e['tenant_id']??0)!==$tenant||($e['user_id']??0)!==$user||empty($e['items']))throw new RuntimeException('Invalid evidence manifest');
$preview=[];$ids=[];$units=[];
foreach($e['items']as$item){$id=(int)$item['consumption_id'];$unit=(int)$item['unit_id'];if(isset($ids[$id]))throw new RuntimeException('Duplicate consumption');
    $preview[]=MarketTextUsageReview::resolveFailedPlanning($tenant,$user,$id,$unit,$item['proof']);$ids[$id]=$id;$units[]=$unit;}
$save=static function(string $path,array $body):void{
    if(!is_dir(dirname($path)))mkdir(dirname($path),0700,true);
    file_put_contents($path,json_encode($body,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),LOCK_EX);chmod($path,0600);
};
$result=['database'=>$o['database'],'tenant_id'=>$tenant,'user_id'=>$user,'apply'=>isset($o['apply']),'preview'=>$preview,'results'=>[]];
if(isset($o['apply'])){
    $backup=$out.'.before.json';if(!is_file($backup))$save($backup,[
        'evidence_sha256'=>hash_file('sha256',$file),
        'consumptions'=>Db::name('ai_consumption_log')->where(['tenant_id'=>$tenant,'user_id'=>$user])->whereIn('id',array_values($ids))->select()->toArray(),
        'units'=>Db::name('aigc_short_drama_planning_unit')->where(['tenant_id'=>$tenant,'user_id'=>$user])->whereIn('id',$units)->select()->toArray()]);
    foreach($e['items']as$item)$result['results'][]=MarketTextUsageReview::resolveFailedPlanning($tenant,$user,(int)$item['consumption_id'],(int)$item['unit_id'],$item['proof'],true);
}
$save($out,$result);echo json_encode(['apply'=>$result['apply'],'count'=>count($preview),'dispositions'=>array_column($preview,'disposition'),'user_additional_charge'=>0,'tenant_additional_charge'=>0],JSON_UNESCAPED_UNICODE)."\n";
