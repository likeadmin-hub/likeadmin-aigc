<?php
/** Local immutable source artifact + exact saved declarations -> inactive bundle on stdout. No runtime/HTTP/DB/Git. */
require dirname(__DIR__,2).'/vendor/autoload.php';
use app\common\service\power\MarketOwnedPlatformContractBuilder as Builder;
use app\common\service\power\MarketOwnedPlatformPreparationVerification as Verification;
if($argc!==3&& !($argc===4&&$argv[3]==='--verify-preparation')){fwrite(STDERR,"Usage: php export-owned-platform-contract-candidates.php original-snapshot.json source-artifact.json [--verify-preparation]\n");exit(1);}
try{
    foreach([$argv[1],$argv[2]]as$path)
        if(preg_match('~^[a-z][a-z0-9+.-]*://~i',$path)||!is_file($path)||filesize($path)>8*1024*1024)
            throw new InvalidArgumentException('Expected bounded local evidence files');
    $snapshot=json_decode(file_get_contents($argv[1]),true,64,JSON_THROW_ON_ERROR);
    $artifact=file_get_contents($argv[2]);
    $bundle=Builder::build($snapshot,$artifact);
    if($argc===4)$bundle['preparation_verification']=Verification::verify($bundle,$snapshot,$artifact);
    echo json_encode($bundle,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
    if(isset($bundle['preparation_verification'])&&!$bundle['preparation_verification']['success'])exit(2);
}catch(Throwable){fwrite(STDERR,"Owned-platform candidate export failed; inspect exact identities, original declarations and pinned local evidence privately\n");exit(1);}
