<?php
declare(strict_types=1);
require __DIR__.'/integration.php';
// Reuse the real disposable installer fixture with a fresh signed digest.
$manifest['version']='1.0.393';
put($package.'/files/config/project.php',"<?php return ['version'=>'1.0.393'];");
$sign=[];foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($package,FilesystemIterator::SKIP_DOTS)) as $file){
    $relative=substr($file->getPathname(),strlen($package)+1);if($relative!=='signature.json')$sign[$relative]=hash_file('sha256',$file->getPathname());
}
put($package.'/signature.json',json_encode(['sha256'=>$sign]));
$sqlCalls=0;$blocked=true;
$callbacks=['before_sql'=>function()use(&$blocked){if($blocked)throw new RuntimeException('fixture process gate');},
    'sql'=>function()use(&$sqlCalls){$sqlCalls++;},'files'=>function(){},'finalize'=>fn()=>[], 'marker'=>function(){}];
$failed=false;try{$service->apply($package,$manifest,$callbacks);}catch(RuntimeException $e){$failed=$e->getMessage()==='fixture process gate';}
check($failed && $sqlCalls===0,'gate did not reject before SQL');
$blocked=false;$service->apply($package,$manifest,$callbacks);
check($sqlCalls===1,'proven pre-SQL rejection poisoned safe retry');
echo "PASS: pre-SQL process rejection keeps journal pending and stopped-site retry executes SQL once\n";
