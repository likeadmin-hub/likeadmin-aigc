<?php
/** Local immutable source artifact + exact saved declarations -> inactive bundle on stdout. No runtime/HTTP/DB/Git. */
require dirname(__DIR__,2).'/vendor/autoload.php';
use app\common\service\power\MarketOwnedPlatformContractBuilder as Builder;
if($argc!==3){fwrite(STDERR,"Usage: php export-owned-platform-contract-candidates.php original-snapshot.json source-artifact.json\n");exit(1);}
try{
    foreach([$argv[1],$argv[2]]as$path)
        if(preg_match('~^[a-z][a-z0-9+.-]*://~i',$path)||!is_file($path)||filesize($path)>8*1024*1024)
            throw new InvalidArgumentException('Expected bounded local evidence files');
    $snapshot=json_decode(file_get_contents($argv[1]),true,64,JSON_THROW_ON_ERROR);
    $artifact=file_get_contents($argv[2]);
    echo json_encode(Builder::build($snapshot,$artifact),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
}catch(Throwable){fwrite(STDERR,"Owned-platform candidate export failed; inspect exact identities, original declarations and pinned local evidence privately\n");exit(1);}
