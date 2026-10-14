<?php
if(getenv('OEM_DISPOSABLE')!=='1' || !str_starts_with((string)getenv('PHP_DATABASE_DATABASE'),'oem_e2e_') || getenv('PHP_DATABASE_HOSTNAME')!=='oem-mysql') throw new RuntimeException('Disposable OEM acceptance only');
$oemRoot=dirname(__DIR__,3); require $oemRoot.'/runtime-loader.php';
$app=new \AigcRuntimeApp($oemRoot); $app->setBaseEnvName('oem-disposable')->setEnvName('oem-disposable')->initialize();
require __DIR__.'/Gateway.php'; $app->instance(\app\common\service\brand\BrandChannelGateway::class,new OemAcceptanceGateway());
$app->console->run();
