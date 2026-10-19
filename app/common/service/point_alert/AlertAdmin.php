<?php
namespace app\common\service\point_alert;
use app\common\service\open_platform\OpenVault;
use RuntimeException;

final class AlertAdmin
{
 public static function preview(int $owner,int $admin,array $p):array
 {
  $s=AlertStore::settings($owner);$result=AlertService::preview($owner,$p['rules']??[]);
  $result['token']=OpenVault::seal(AlertStore::encode(['owner'=>$owner,'admin'=>$admin,'revision'=>$s['revision'],'expires'=>time()+300]));return $result;
 }
 public static function enqueue(int $owner,int $admin,string $token):array
 {
  $t=AlertStore::json(OpenVault::open($token));$s=AlertStore::settings($owner);
  if(($t['owner']??-1)!==$owner||($t['admin']??0)!==$admin||($t['revision']??-1)!==$s['revision']||($t['expires']??0)<time())throw new RuntimeException('检查预览已过期，请重新预览');
  if(!$s['rules']['enabled'])throw new RuntimeException('请先启用并保存预警规则');
  $result=AlertService::scan($owner,500);return $result+['message'=>'已提交分批检查，实际发送仍受次数和间隔限制'];
 }
}
