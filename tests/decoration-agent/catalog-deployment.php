<?php
// Load only the packaged application files, without a site-root resources directory.
$source = dirname(__DIR__, 2) . '/app/common/service/decorate/agent';
$temporary = sys_get_temp_dir() . '/decoration-catalog-' . bin2hex(random_bytes(8));
$staged = $temporary . '/app/common/service/decorate/agent';
mkdir($staged . '/catalog', 0700, true);
try {
    copy($source . '/DecorationAgentCatalog.php', $staged . '/DecorationAgentCatalog.php');
    copy($source . '/catalog/mobile-catalog.json', $staged . '/catalog/mobile-catalog.json');
    require $staged . '/DecorationAgentCatalog.php';
    $catalog = \app\common\service\decorate\agent\DecorationAgentCatalog::all();
    if (count($catalog['components']) !== 47 || strlen($catalog['hash']) !== 64) {
        throw new RuntimeException('Packaged decoration catalog is incomplete');
    }
    $magic = \app\common\service\decorate\agent\DecorationAgentCatalog::definition('magic-grid');
    if (($magic['lists']['data']['fields']['border_radius']['unit'] ?? '') !== 'rpx' ||
        !str_contains($magic['content_fields']['grid']['help'] ?? '', 'column_weights')) {
        throw new RuntimeException('Magic region presentation contract is missing');
    }
    echo "PASS: isolated immutable application catalog, 47 contracts, magic region properties and verified SHA256\n";
} finally {
    foreach ([$staged . '/DecorationAgentCatalog.php', $staged . '/catalog/mobile-catalog.json'] as $file) {
        if (is_file($file)) unlink($file);
    }
    foreach ([$staged . '/catalog', $staged, dirname($staged), dirname($staged, 2), dirname($staged, 3), dirname($staged, 4), $temporary] as $directory) {
        if (is_dir($directory)) rmdir($directory);
    }
}
