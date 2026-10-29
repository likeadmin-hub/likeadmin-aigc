<?php
/** Offline evidence ledger. No runtime initialization, database, HTTP or active contract writes. */
require dirname(__DIR__,2).'/vendor/autoload.php';
use app\common\service\power\MarketGenerationContractReview as Review;
$fixtures=dirname(__DIR__,2).'/tests/fixtures/';
$read=static fn($name)=>json_decode(file_get_contents($fixtures.$name),true,512,JSON_THROW_ON_ERROR);
echo json_encode(Review::ledger($read('canvas-generation-market-contracts.json'),$read('market-legacy-declaration-review-20261029.json'),
    $read('market-legacy-published-guidance-review-20261029.json'),$read('market-platform-contract-flow-review-20261029.json')),
    JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
