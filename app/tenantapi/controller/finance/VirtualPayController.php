<?php
namespace app\tenantapi\controller\finance;
use app\tenantapi\controller\BaseAdminController;
use app\common\service\virtualpay\VirtualPayConfigService as Config;
class VirtualPayController extends BaseAdminController
{
    public function detail()
    {
        try{return $this->data(Config::detail($this->tenantId,(int)$this->request->get('env',0)));}
        catch(\Exception $e){return $this->fail($e->getMessage());}
    }
    public function save()
    {
        try{
            if($this->request->post('enabled',0))(new \app\common\service\license\MiniprogramAccessService())->assertEnabled();
            return $this->data(Config::save($this->tenantId,(int)$this->request->post('env',0),$this->request->post()));
        }catch(\Exception $e){return $this->fail($e->getMessage());}
    }
    public function saveProduct()
    {
        try{Config::saveProduct($this->tenantId,(int)$this->request->post('env',0),$this->request->post());return $this->success('道具绑定已保存，请确认已在微信平台发布');}
        catch(\Exception $e){return $this->fail($e->getMessage());}
    }
}
