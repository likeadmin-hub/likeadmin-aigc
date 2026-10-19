<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
try {
    $GLOBALS['AIGC_DIRECT_RECOVERY_CHILD']=true;
    require dirname(__DIR__).'/runtime-loader.php';
    // No application initialize, database connection or business command.
    if (is_file(dirname(__DIR__).'/runtime/direct-deployment.json')) {
        $collector=new \app\common\service\update\LegacyRuntimeCleanupService(dirname(__DIR__));
        // The triggering HTTP request still holds its reader when this child
        // starts. Wait briefly in the background; never bypass an active lease.
        for($attempt=0;$attempt<25;$attempt++){
            $result=$collector->collect();
            if(($result['status']??'')!=='deferred'||!in_array($result['reason']??'',['site_reader_or_update_active','legacy_reader_active'],true))break;
            usleep(200000);
        }
        echo json_encode($result,JSON_THROW_ON_ERROR)."\n";
        exit;
    }
    $manager=new \app\common\service\update\ComposerRuntimeUpdateService(dirname(__DIR__),PHP_BINARY);
    echo json_encode($manager->collectRuntimeGarbage(),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $e) {
    echo json_encode(['status'=>'deferred','reason'=>'cleanup_bootstrap_unavailable'])."\n";
    exit(1);
}
