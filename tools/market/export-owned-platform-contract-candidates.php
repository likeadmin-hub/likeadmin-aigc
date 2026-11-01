<?php
/** Local immutable source artifact + exact saved declarations -> inactive bundle on stdout. No runtime/HTTP/DB/Git. */
require dirname(__DIR__,2).'/vendor/autoload.php';
use app\common\service\power\MarketOwnedPlatformContractBuilder as Builder;
use app\common\service\power\MarketOwnedPlatformPreparationVerification as Verification;
$verify=false;$currentPath=null;$publicPath=null;$routePath=null;
foreach(array_slice($argv,3)as$argument){
    if($argument==='--verify-preparation'&&!$verify)$verify=true;
    elseif(str_starts_with($argument,'--current-execution=')&&$currentPath===null)$currentPath=substr($argument,20);
    elseif(str_starts_with($argument,'--current-public=')&&$publicPath===null)$publicPath=substr($argument,17);
    elseif(str_starts_with($argument,'--smart-route-context=')&&$routePath===null)$routePath=substr($argument,22);
    else{fwrite(STDERR,"Invalid owned-platform export option\n");exit(1);}
}
if($argc<3){fwrite(STDERR,"Usage: php export-owned-platform-contract-candidates.php original-snapshot.json source-artifact.json [--current-execution=local.json] [--current-public=local.json] [--smart-route-context=local.json] [--verify-preparation]\n");exit(1);}
try{
    foreach(array_filter([$argv[1],$argv[2],$currentPath,$publicPath,$routePath],static fn($path)=>$path!==null)as$path)
        if(preg_match('~^[a-z][a-z0-9+.-]*://~i',$path)||!is_file($path)||filesize($path)>8*1024*1024)
            throw new InvalidArgumentException('Expected bounded local evidence files');
    $snapshot=json_decode(file_get_contents($argv[1]),true,64,JSON_THROW_ON_ERROR);
    $artifact=file_get_contents($argv[2]);
    $bundle=Builder::build($snapshot,$artifact,$currentPath===null?null:file_get_contents($currentPath),
        $publicPath===null?null:file_get_contents($publicPath),$routePath===null?null:file_get_contents($routePath));
    if($verify)$bundle['preparation_verification']=Verification::verify($bundle,$snapshot,$artifact);
    echo json_encode($bundle,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
    if(isset($bundle['preparation_verification'])&&!$bundle['preparation_verification']['success'])exit(2);
}catch(Throwable){fwrite(STDERR,"Owned-platform candidate export failed; inspect exact identities, original declarations and pinned local evidence privately\n");exit(1);}
