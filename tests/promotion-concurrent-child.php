<?php
require __DIR__.'/Support/OemDisposable.php';
$input=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR);
request()->tenantId=(int)$input['tenant_id'];request()->source=app\common\enum\AdminTerminalEnum::PLATFORM;
try{$order=app\common\service\promotion\PackagePricingService::create($input['tenant_id'],$input['user_id'],4,'recharge',$input['package_id']);echo json_encode(['ok'=>true,'order'=>$order]);}
catch(RuntimeException $e){echo json_encode(['ok'=>false,'reason'=>$e->getMessage()]);}
