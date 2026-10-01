<?php

declare(strict_types=1);

use app\common\service\app\AppRegistryService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/vendor/topthink/framework/src/helper.php';

// Keep the shipped frontend builds and register their bridge scripts before
// both images become read-only. Missing package assets are copied at build time.
new \think\App(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
foreach (glob(root_path() . 'app/apps/*/manifest.json') ?: [] as $path) {
    $manifest = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    AppRegistryService::installPublicAssets($manifest, dirname($path), true);
}
