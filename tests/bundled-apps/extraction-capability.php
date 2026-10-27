<?php
require dirname(__DIR__, 2) . '/app/common/service/update/PackageExtractService.php';
use app\common\service\update\PackageExtractService;
$none = ['zip_archive'=>false, 'phar_data'=>false, 'command'=>['unzip'=>false,'7z'=>false,'tar'=>false]];
$cases = [
    [$none, 'zip', false],
    [array_replace($none, ['phar_data'=>true]), 'zip', false],
    [array_replace($none, ['phar_data'=>true]), 'tar.gz', true],
    [array_replace($none, ['zip_archive'=>true]), 'zip', true],
    [array_replace($none, ['zip_archive'=>true]), 'tar.gz', false],
    [array_replace($none, ['command'=>['unzip'=>true]]), 'zip', true],
    [array_replace($none, ['command'=>['7z'=>true]]), 'zip', true],
    [array_replace($none, ['command'=>['tar'=>true]]), 'tar.gz', true],
    [array_replace($none, ['zip_archive'=>true]), 'rar', false],
];
foreach ($cases as [$environment,$format,$expected]) {
    if (PackageExtractService::supportsFormat($environment,$format) !== $expected) {
        fwrite(STDERR, "Failed archive capability: $format\n");
        exit(1);
    }
}
echo "Archive preflight capability cases passed\n";
