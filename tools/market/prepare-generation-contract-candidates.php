<?php
/** Usage: php prepare-generation-contract-candidates.php reviewed-bundle.json original-source-artifact
 * Validates evidence binding and outputs candidates only. Never activates contracts, evaluates source or calls HTTP/DB.
 */
require dirname(__DIR__,2).'/vendor/autoload.php';
use app\common\service\power\MarketGenerationContractReview as Review;
if ($argc !== 3) { fwrite(STDERR,"Expected reviewed bundle JSON and original source artifact\n");exit(1); }
try {
    foreach ([$argv[1],$argv[2]] as $path) if (preg_match('~^[a-z][a-z0-9+.-]*://~i',$path)) throw new InvalidArgumentException('合同证据必须是本地文件');
    if (!is_file($argv[1]) || !is_file($argv[2]) || filesize($argv[1]) > 8*1024*1024 || filesize($argv[2]) > 8*1024*1024) throw new InvalidArgumentException('合同证据文件不可读或过大');
    $json=@file_get_contents($argv[1]);$artifact=@file_get_contents($argv[2]);
    if ($json === false || $artifact === false) throw new InvalidArgumentException('合同证据文件不可读');
    $bundle=json_decode($json,true,64,JSON_THROW_ON_ERROR);
    echo json_encode(Review::candidates($bundle,$artifact),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
} catch (Throwable $e) {
    // Parser paths and rejected data may contain private values.
    fwrite(STDERR,"Contract candidate validation failed; review identity, evidence, schema and registered protocol locally\n");exit(1);
}
