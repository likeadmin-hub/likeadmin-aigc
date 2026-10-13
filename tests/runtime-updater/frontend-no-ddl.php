<?php
declare(strict_types=1);
namespace app\common\service\database {
    class RuntimeMetadataTransactionGate { public static function assertMetadata():void {throw new \RuntimeException('Unexpected schema/metadata preparation in frontend-only update');} }
}
namespace {
    require dirname(__DIR__,2).'/app/common/service/update/ProjectUpdateProcessGate.php';
    require dirname(__DIR__,2).'/app/common/service/update/SystemPackageUpdateService.php';
    $site=sys_get_temp_dir().'/aigc-no-ddl-'.bin2hex(random_bytes(5));mkdir($site);mkdir($site.'/files');mkdir($site.'/files/public');
    file_put_contents($site.'/files/public/index.php','<?php // HTTP entry');
    file_put_contents($site.'/update.json',json_encode(['version'=>'1.0.395','package_mode'=>'incremental','sql_order'=>[],'full_replace_dirs'=>[],'delete_files'=>[]]));
    function root_path(): string {return $GLOBALS['site'].'/';}
    $GLOBALS['AIGC_RUNTIME_GENERATION_OVERRIDE']='fixture';
    try {
        $result=(new app\common\service\update\SystemPackageUpdateService())->prepareBootstrapSql($site,['runtime_metadata_unchanged'=>true]);
        if($result!==[])throw new RuntimeException('Frontend-only preparation returned migrations');
        echo "PASS: verified frontend-only update skips all incidental schema/app migration preparation\n";
    } finally {unlink($site.'/files/public/index.php');rmdir($site.'/files/public');rmdir($site.'/files');unlink($site.'/update.json');rmdir($site);}
}
