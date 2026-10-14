<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
try {
    require dirname(__DIR__).'/runtime-loader.php';
    // No application initialize, database connection or business command.
    $manager=new \app\common\service\update\ComposerRuntimeUpdateService(dirname(__DIR__),PHP_BINARY);
    echo json_encode($manager->collectRuntimeGarbage(),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $e) {
    echo json_encode(['status'=>'deferred','reason'=>'cleanup_bootstrap_unavailable'])."\n";
    exit(1);
}
