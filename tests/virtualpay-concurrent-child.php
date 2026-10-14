<?php
require __DIR__.'/Support/OemDisposable.php';require __DIR__.'/Support/OemFixtures.php';require __DIR__.'/Support/VirtualPayFixture.php';
$i=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR);
request()->tenantId=(int)$i['tenant_id'];request()->source=app\common\enum\AdminTerminalEnum::PLATFORM;
$g=new app\common\service\virtualpay\VirtualPayGateway(static fn()=>virtualQuery($i));
(new app\common\service\virtualpay\VirtualPayService($g))->reconcile($i);echo 'verified';
