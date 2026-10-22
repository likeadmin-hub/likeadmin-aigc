<?php
namespace app\tenantapi\controller\finance;
use app\tenantapi\controller\BaseAdminController;
use app\common\service\virtualpay\VirtualPayConfigService as Config;
class VirtualPayController extends BaseAdminController
{
    public function detail()
    {
        try{return $this->data(Config::detail($this->tenantId,(int)$this->request->get('env',0)));}
        catch(\Exception $e){return $this->fail(\app\common\service\virtualpay\VirtualPayPolicy::error($e));}
    }
    public function save()
    {
        try{
            if($this->request->post('enabled',0))(new \app\common\service\license\MiniprogramAccessService())->assertEnabled();
            return $this->data(Config::save($this->tenantId,(int)$this->request->post('env',0),$this->request->post()));
        }catch(\Exception $e){return $this->fail(\app\common\service\virtualpay\VirtualPayPolicy::error($e));}
    }
    public function generateVault()
    {
        try { return $this->data(Config::generateVault($this->tenantId, (int)$this->request->post('env', 0), $this->request->post())); }
        catch (\Exception $e) { return $this->fail(\app\common\service\virtualpay\VirtualPayPolicy::error($e)); }
    }
    public function saveProduct()
    {
        try{Config::saveProduct($this->tenantId,(int)$this->request->post('env',0),$this->request->post());return $this->success('道具绑定已保存，请确认已在微信平台发布');}
        catch(\Exception $e){return $this->fail(\app\common\service\virtualpay\VirtualPayPolicy::error($e));}
    }
    public function orders()
    {
        try { return $this->data(\app\common\service\virtualpay\VirtualPayAdminService::orders($this->tenantId,(int)$this->request->get('page_no',1),(string)$this->request->get('state',''))); }
        catch (\Throwable $e) { return $this->fail(\app\common\service\virtualpay\VirtualPayPolicy::error($e)); }
    }
    public function retry()
    {
        try { \app\common\service\virtualpay\VirtualPayAdminService::retry($this->tenantId,$this->adminId,(int)$this->request->post('id'));return $this->success('已安排重新核对，请稍后刷新'); }
        catch (\Throwable $e) { return $this->fail(\app\common\service\virtualpay\VirtualPayPolicy::error($e)); }
    }
    public function refund()
    {
        try { $i=(new \app\common\service\virtualpay\VirtualPayRefundService())->request($this->tenantId,$this->adminId,(int)$this->request->post('id'));return $this->data(['refund_status'=>$i['refund_status'],'message'=>$i['refund_status']==='manual'?$i['last_error']:'已提交退款核对，确认渠道退款完成后才会回收未使用权益']); }
        catch (\Throwable $e) { return $this->fail(\app\common\service\virtualpay\VirtualPayPolicy::error($e)); }
    }
    public function resolve()
    {
        try { \app\common\service\virtualpay\VirtualPayAdminService::resolve($this->tenantId,$this->adminId,(int)$this->request->post('id'),(string)$this->request->post('note'));return $this->success('处理说明已保存'); }
        catch (\Throwable $e) { return $this->fail(\app\common\service\virtualpay\VirtualPayPolicy::error($e)); }
    }
}
