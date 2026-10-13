<?php
declare(strict_types=1);
require __DIR__.'/Support/OemDisposable.php'; require __DIR__.'/Support/OemFixtures.php';
use think\facade\Db;
$operation=$argv[1]??''; $input=json_decode($argv[2]??'',true,512,JSON_THROW_ON_ERROR);
if(!str_starts_with((string)getenv('PHP_DATABASE_DATABASE'),'oem_fault_')) throw new RuntimeException('Own concurrency database required');
if($operation==='payment') { Db::startTrans(); echo json_encode(['connection'=>Db::query('SELECT CONNECTION_ID() AS id',[],true)[0]['id']]).PHP_EOL; flush(); \app\common\service\brand\BrandPaymentService::recordVerified($input); Db::commit(); echo "PASS payment\n"; }
elseif($operation==='create') echo json_encode(\app\common\service\brand\TenantBrandService::createBrandOrder($input['seller'],$input['user'],4,$input['params']),JSON_THROW_ON_ERROR).PHP_EOL;
else throw new RuntimeException('Unknown disposable operation');
