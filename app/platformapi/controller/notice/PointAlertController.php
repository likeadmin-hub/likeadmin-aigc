<?php
namespace app\platformapi\controller\notice;
use app\platformapi\controller\BaseAdminController;
use app\common\service\point_alert\{AlertStore,AlertAdmin};
final class PointAlertController extends BaseAdminController
{
 private function owner():int { return 0; }
 private function run(callable $action) { try { return $this->data($action()); } catch(\InvalidArgumentException|\RuntimeException $e) { return $this->fail($e->getMessage()); } }
 public function rules(){return $this->run(fn()=>AlertStore::settings($this->owner()));}
 public function save(){return $this->run(fn()=>AlertStore::saveRules($this->owner(),$this->request->post('rules',[]),(int)$this->request->post('revision',0)));}
 public function records(){return $this->run(fn()=>AlertStore::records($this->owner(),$this->request->get()));}
 public function detail(){return $this->run(fn()=>AlertStore::detail($this->owner(),(int)$this->request->get('id',0)));}
 public function preview(){return $this->run(fn()=>AlertAdmin::preview($this->owner(),$this->adminId,$this->request->post()));}
 public function check(){return $this->run(fn()=>AlertAdmin::enqueue($this->owner(),$this->adminId,(string)$this->request->post('token','')));}
 
}
