<?php
declare(strict_types=1);
namespace think\facade {
 class Db { public static function execute(string $sql): void { $GLOBALS['executed_sql'][]=$sql; } }
}
namespace {
 function base_path(): string { return $GLOBALS['candidate_root'].'/app/'; }
 function config(string $key) { return 'fixture_'; }
 require __DIR__.'/../../app/common/service/database/SqlMigrationExecutor.php';
 require __DIR__.'/../../app/common/service/database/RuntimeMigrationPlan.php';
 require __DIR__.'/../../app/common/service/app/AppRegistryService.php';
 require __DIR__.'/../../app/common/service/update/SystemPackageUpdateService.php';
 use app\common\service\update\SystemPackageUpdateService;
 function ownerCheck(bool $value,string $label): void { if(!$value)throw new RuntimeException($label); }
 $root=sys_get_temp_dir().'/app-sql-owner-'.bin2hex(random_bytes(6));$GLOBALS['candidate_root']=$root.'/candidate';
 $write=function(string $p,string $value)use($root){$path=$root.'/'.$p;if(!is_dir(dirname($path)))mkdir(dirname($path),0700,true);file_put_contents($path,$value);};
 $mirror='sql/structure/20261011_open_platform_docs.sql';$app='app/apps/open_platform/';$sql="INSERT INTO la_open_platform_document (tenant_id) VALUES (0);\n";
 $metadata=json_encode(['code'=>'open_platform','is_builtin'=>1]);
 $reset=function()use($write,$mirror,$app,$sql,$metadata){$write('package/'.$mirror,$sql);$write('package/files/'.$app.'migrations/'.basename($mirror),$sql);$write('candidate/'.$app.'migrations/'.basename($mirror),$sql);$write('package/files/'.$app.'manifest.json',$metadata);$write('candidate/'.$app.'manifest.json',$metadata);};
 $service=new SystemPackageUpdateService();$method=new ReflectionMethod($service,'runtimeAppSqlOwners');$method->setAccessible(true);$apply=new ReflectionMethod($service,'applySqlGroup');$apply->setAccessible(true);$manifest=['package_mode'=>'incremental','sql_order'=>[$mirror,'sql/structure/workspace.sql']];
 try {
  $reset();$write('package/sql/structure/workspace.sql','CREATE TABLE IF NOT EXISTS la_fixture_workspace(id int);');
  $owners=$method->invoke($service,$root.'/package',$manifest);ownerCheck(count($owners)===1&&$owners[$mirror]['app_code']==='open_platform','Exact app source not owned');
  $GLOBALS['executed_sql']=[];$apply->invoke($service,$root.'/package',$manifest,'structure',$owners);
  ownerCheck(count($GLOBALS['executed_sql'])===1&&str_contains($GLOBALS['executed_sql'][0],'fixture_workspace'),'App SQL executed before registry metadata transaction');
  foreach(['missing-manifest','not-builtin','candidate-manifest-drift','source-drift','candidate-drift','ambiguous-owner','unclassifiable'] as $case){
   $reset();$other=$root.'/package/files/app/apps/other';if(is_dir($other)){$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($other,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $file)$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());rmdir($other);}
   if($case==='missing-manifest')unlink($root.'/package/files/'.$app.'manifest.json');
   if($case==='not-builtin')$write('package/files/'.$app.'manifest.json',json_encode(['code'=>'open_platform','is_builtin'=>0]));
   if($case==='candidate-manifest-drift')$write('candidate/'.$app.'manifest.json',json_encode(['code'=>'open_platform','is_builtin'=>0]));
   if($case==='source-drift')$write('package/files/'.$app.'migrations/'.basename($mirror),'INSERT INTO la_wrong VALUES (1);');
   if($case==='candidate-drift')$write('candidate/'.$app.'migrations/'.basename($mirror),'INSERT INTO la_wrong VALUES (1);');
   if($case==='ambiguous-owner'){$write('package/files/app/apps/other/manifest.json',json_encode(['code'=>'other','is_builtin'=>1]));$write('package/files/app/apps/other/migrations/'.basename($mirror),$sql);}
   if($case==='unclassifiable'){$bad='UPDATE la_open_platform_document d JOIN la_other o ON d.id=o.id SET d.status=1;';$write('package/'.$mirror,$bad);$write('package/files/'.$app.'migrations/'.basename($mirror),$bad);$write('candidate/'.$app.'migrations/'.basename($mirror),$bad);}
   try{$method->invoke($service,$root.'/package',$manifest);throw new LogicException('Unsafe owner accepted: '.$case);}catch(RuntimeException $e){}
  }
  echo "PASS: exact builtin/candidate bytes defer app SQL to registry; workspace executes once; missing, nonbuiltin, drift, ambiguity and unsafe plans fail closed\n";
 } finally {$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $file)$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());rmdir($root);}
}
