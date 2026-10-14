<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
try {
 if(count($argv)<4)throw new RuntimeException('Usage: php authorize-system-392-recovery.php SITE_ROOT REPLACEMENT_PACKAGE_ID ORIGINAL_DIGEST [--authorize]');
 $site=realpath($argv[1]);if(!$site)throw new RuntimeException('Site root unavailable');
 require $site.'/runtime-loader.php';(new AigcRuntimeApp($site))->initialize();
 require_once dirname(__DIR__).'/app/common/service/update/System392RecoveryService.php';
 $proof=app\common\service\update\System392RecoveryService::authorize((int)$argv[2],$argv[3],in_array('--authorize',$argv,true));
 echo json_encode(['passed'=>true,'authorized'=>in_array('--authorize',$argv,true),'proof'=>$proof],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
} catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
