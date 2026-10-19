<?php
namespace app\api\controller;
use app\common\service\point_alert\{AlertStore,AlertPolicy};
final class PointAlertController extends BaseApiController
{
 public function lists(){return $this->data(AlertStore::inbox((int)$this->request->tenantId,$this->userId,(int)$this->request->get('page_no',1)));}
 public function read(){return $this->data(['read'=>AlertStore::readInbox((int)$this->request->tenantId,$this->userId,(int)$this->request->post('id',0))]);}
 public function preferences(){return $this->data($this->snapshot());}
 public function savePreferences(){return $this->data(AlertStore::saveContacts((int)$this->request->tenantId,$this->userId,$this->request->post()));}
 private function snapshot():array
 {
  $tenant=(int)$this->request->tenantId;$a=AlertStore::account($tenant,$this->userId);$s=AlertStore::settings($tenant);
  $d=$a?AlertPolicy::decide($s['rules'],[],AlertPolicy::cents($a['balance']),time()):[];
  return ['sms_enabled'=>AlertStore::contacts($tenant,$this->userId)['sms_enabled'],'has_mobile'=>!empty($a['mobile']),'low_balance'=>!empty($s['rules']['enabled'])&&isset($d['threshold']),'balance'=>$a['balance']??'0.00','unit'=>$s['unit']];
 }
}
