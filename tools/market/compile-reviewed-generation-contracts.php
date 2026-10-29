<?php
/** Compile an independently reviewed bundle to stdout. Never writes/enables the catalogue, performs HTTP or accesses DB. */
require dirname(__DIR__,2).'/vendor/autoload.php';
use app\common\service\power\MarketGenerationContractReview as Review;
if ($argc!==4 || $argv[3]!=='--reviewed') {
    fwrite(STDERR,"Usage: php compile-reviewed-generation-contracts.php bundle.json source-artifact --reviewed\n");exit(1);
}
try {
    foreach ([$argv[1],$argv[2]] as $path) {
        if (preg_match('~^[a-z][a-z0-9+.-]*://~i',$path) || !is_file($path) || filesize($path)>8*1024*1024) throw new InvalidArgumentException('Invalid local evidence file');
    }
    $bundle=json_decode(file_get_contents($argv[1]),true,64,JSON_THROW_ON_ERROR);
    echo json_encode(Review::reviewedCatalogue($bundle,file_get_contents($argv[2])),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR,"Reviewed contract compilation failed; check identity, source, complete schemas and supported material paths\n");exit(1);
}
