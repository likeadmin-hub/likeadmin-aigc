<?php
namespace think\facade {class Log {public static array $errors=[];public static function error($message):void {self::$errors[]=$message;}}}
namespace {
require dirname(__DIR__,2).'/app/common/service/update/UpdateCustomerMessage.php';
use app\common\service\update\UpdateCustomerMessage as Message;
foreach([
    "站点更新子进程失败: SQLSTATE[42S22]: Column not found: 1054 Unknown column 'storage_scope' in 'field list'",
    "SQLSTATE[42S02]: Base table or view not found: 1146 Table 'customer.la_tenant_file' doesn't exist",
] as $raw){
    $result=Message::present(['error'=>$raw,'error_code'=>'SYSTEM_UPDATE_FAILED']);
    if(!str_contains($result['error'],'系统数据结构') || str_contains($result['error'],'数据库连接') || str_contains($result['error'],'storage_scope') || str_contains($result['error'],'SQLSTATE'))throw new RuntimeException('Schema errors must have a readable, accurate schema message');
    if(!in_array('[系统更新] '.$raw,\think\facade\Log::$errors,true) || $result['error_code']!=='SYSTEM_UPDATE_FAILED')throw new RuntimeException('Original diagnostic and API error code must be preserved');
}
foreach (["SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column 'preflight_json' at row 1", "Data too long for column 'manifest_json' at row 1"] as $raw) {
    $result=Message::present(['error'=>$raw,'error_code'=>'SYSTEM_UPDATE_FAILED']);
    if (!str_contains($result['error'],'字段容量') || str_contains($result['error'],'数据库连接') || str_contains($result['error'],'preflight_json') || str_contains($result['error'],'SQLSTATE')) throw new RuntimeException('Capacity errors must not claim a connection failure or expose fields');
    if (!in_array('[系统更新] '.$raw,\think\facade\Log::$errors,true)) throw new RuntimeException('Original capacity diagnostic missing');
}
$raw='SQLSTATE[HY000]: General error: 2006 MySQL server has gone away';
if(!str_contains(Message::format($raw),'数据库连接'))throw new RuntimeException('Real connection errors must retain the connection guidance');
echo "PASS schema errors distinguished from connection failures; diagnostic logs preserved\n";
}
