<?php
/** Pure local JSON -> pending matrix. No application bootstrap, database, network or enablement. */
require dirname(__DIR__,2).'/vendor/autoload.php';

$path=$argv[1]??'';
if($path===''||!is_file($path)){fwrite(STDERR,"Usage: php tools/market/build-acceptance-matrix.php audit-with-request-probes.json\n");exit(1);}
try{
    $audit=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    echo json_encode(\app\common\service\power\MarketGenerationAcceptanceMatrix::build($audit),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
}catch(\Throwable$e){fwrite(STDERR,"Cannot build acceptance matrix: invalid or incomplete local audit input.\n");exit(1);}
